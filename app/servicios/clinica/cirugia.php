<?php

const ESTADOS_CIRUGIA = [
    'programada' => 'Programada',
    'preoperatoria' => 'Preoperatoria',
    'lista' => 'Lista para iniciar',
    'en_curso' => 'En curso',
    'recuperacion' => 'Recuperación',
    'finalizada' => 'Finalizada',
    'cancelada' => 'Cancelada',
];

const TRANSICIONES_CIRUGIA = [
    'programada' => ['preoperatoria', 'cancelada'],
    'preoperatoria' => ['lista', 'cancelada'],
    'lista' => ['en_curso', 'cancelada'],
    'en_curso' => ['recuperacion'],
    'recuperacion' => ['finalizada'],
    'finalizada' => [],
    'cancelada' => [],
];

function crearCirugiaProgramada(
    int $pacienteId,
    string $fecha,
    int $duracion,
    string $procedimiento,
    string $quirofano,
    int $cirujanoId,
    ?int $anestesiologoId,
    int $institucionId,
    int $usuarioId,
    string $usuarioNombre
): int {
    $inicio = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha);
    if (!$inicio || $inicio->format('Y-m-d H:i:s') !== $fecha || $inicio <= new DateTimeImmutable()) {
        throw new DomainException('Selecciona una fecha y hora futura válidas.');
    }
    if ($duracion < 15 || $duracion > 480 || $duracion % 15 !== 0) {
        throw new DomainException('La duración debe ser de 15 a 480 minutos, en bloques de 15.');
    }
    $procedimiento = trim($procedimiento);
    $quirofano = trim($quirofano);
    if ($procedimiento === '' || mb_strlen($procedimiento) > 160 || $quirofano === '' || mb_strlen($quirofano) > 80) {
        throw new DomainException('Indica el procedimiento y el quirófano o sala asignada.');
    }
    if ($anestesiologoId === $cirujanoId) {
        throw new DomainException('El cirujano y el anestesiólogo deben ser personas distintas.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $bloqueo = $pdo->prepare('SELECT id FROM instituciones WHERE id = ? FOR UPDATE');
        $bloqueo->execute([$institucionId]);
        if (!$bloqueo->fetchColumn()) {
            throw new DomainException('La institución ya no está disponible.');
        }
        $paciente = $pdo->prepare(
            'SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE'
        );
        $paciente->execute([$pacienteId, $institucionId]);
        if (!$paciente->fetchColumn()) {
            throw new DomainException('El paciente no existe en esta institución o fue dado de baja.');
        }
        $personal = $pdo->prepare(
            "SELECT id FROM usuarios
              WHERE id = ? AND institucion_id = ? AND activo = 1 AND rol = 'medico' FOR UPDATE"
        );
        $personal->execute([$cirujanoId, $institucionId]);
        if (!$personal->fetchColumn()) {
            throw new DomainException('Selecciona un cirujano activo de esta institución.');
        }
        if ($anestesiologoId !== null) {
            $personal->execute([$anestesiologoId, $institucionId]);
            if (!$personal->fetchColumn()) {
                throw new DomainException('Selecciona un anestesiólogo activo de esta institución.');
            }
        }

        $conflicto = $pdo->prepare(
            "SELECT id FROM cirugias_programadas
              WHERE institucion_id = ? AND estado NOT IN ('cancelada','finalizada')
                AND programada_en < DATE_ADD(?, INTERVAL ? MINUTE)
                AND DATE_ADD(programada_en, INTERVAL duracion_min MINUTE) > ?
                AND (cirujano_id = ? OR cirujano_id = ? OR anestesiologo_id = ?
                     OR anestesiologo_id = ? OR quirofano = ?)
              LIMIT 1 FOR UPDATE"
        );
        $conflicto->execute([
            $institucionId, $fecha, $duracion, $fecha, $cirujanoId, $anestesiologoId,
            $cirujanoId, $anestesiologoId, $quirofano,
        ]);
        if ($conflicto->fetchColumn()) {
            throw new DomainException('El horario se cruza con otra cirugía del equipo o del quirófano. Revisa la agenda.');
        }

        $pdo->prepare(
            "INSERT INTO cirugias_programadas
                (institucion_id, paciente_id, programada_en, duracion_min, procedimiento, quirofano,
                 cirujano_id, anestesiologo_id, estado, creado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'programada', ?)"
        )->execute([
            $institucionId, $pacienteId, $fecha, $duracion, $procedimiento, $quirofano,
            $cirujanoId, $anestesiologoId, $usuarioId,
        ]);
        $cirugiaId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO cirugias_eventos
                (cirugia_id, institucion_id, tipo, estado_anterior, estado_nuevo, fecha_anterior, fecha_nueva, motivo, usuario_id, usuario_nombre)
             VALUES (?, ?, 'programacion', NULL, 'programada', NULL, ?, ?, ?, ?)"
        )->execute([$cirugiaId, $institucionId, $fecha, $procedimiento, $usuarioId, $usuarioNombre]);
        $pdo->commit();
        return $cirugiaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function cambiarEstadoCirugia(int $cirugiaId, string $estadoNuevo, string $motivo, int $institucionId, int $usuarioId, string $usuarioNombre): void
{
    if (!isset(ESTADOS_CIRUGIA[$estadoNuevo])) {
        throw new DomainException('El estado quirúrgico seleccionado no es válido.');
    }
    $motivo = trim($motivo);
    if (mb_strlen($motivo) > 255) {
        throw new DomainException('El motivo no puede exceder 255 caracteres.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $consulta = $pdo->prepare(
            'SELECT estado, programada_en FROM cirugias_programadas
              WHERE id = ? AND institucion_id = ? FOR UPDATE'
        );
        $consulta->execute([$cirugiaId, $institucionId]);
        $actual = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!$actual) {
            throw new DomainException('La cirugía ya no existe en esta institución.');
        }
        if (!in_array($estadoNuevo, TRANSICIONES_CIRUGIA[$actual['estado']] ?? [], true)) {
            throw new DomainException('Ese cambio no está permitido desde el estado actual.');
        }
        $pdo->prepare('UPDATE cirugias_programadas SET estado = ? WHERE id = ? AND institucion_id = ?')
            ->execute([$estadoNuevo, $cirugiaId, $institucionId]);
        $pdo->prepare(
            "INSERT INTO cirugias_eventos
                (cirugia_id, institucion_id, tipo, estado_anterior, estado_nuevo, fecha_anterior, fecha_nueva, motivo, usuario_id, usuario_nombre)
             VALUES (?, ?, 'estado', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $cirugiaId, $institucionId, $actual['estado'], $estadoNuevo, $actual['programada_en'],
            $actual['programada_en'], $motivo !== '' ? $motivo : null, $usuarioId, $usuarioNombre,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
