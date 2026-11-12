<?php
/**
 * Flujo operativo de atención. El estado actual es una proyección; los eventos no se modifican.
 */
const ETAPAS_FLUJO_PREDETERMINADAS = [
    ['clave' => 'registro', 'nombre' => 'Registro'],
    ['clave' => 'espera', 'nombre' => 'En espera'],
    ['clave' => 'consulta', 'nombre' => 'Consulta'],
    ['clave' => 'observacion', 'nombre' => 'Observación'],
    ['clave' => 'hospitalizacion', 'nombre' => 'Hospitalización'],
    ['clave' => 'cirugia', 'nombre' => 'Cirugía'],
    ['clave' => 'recuperacion', 'nombre' => 'Recuperación'],
    ['clave' => 'referencia', 'nombre' => 'Referencia / traslado'],
    ['clave' => 'egreso', 'nombre' => 'Egreso'],
];

function etapasFlujo(): array
{
    $guardado = config('flujo_etapas');
    if ($guardado === null || $guardado === '') {
        return ETAPAS_FLUJO_PREDETERMINADAS;
    }

    $etapas = json_decode($guardado, true);
    if (!is_array($etapas)) {
        throw new RuntimeException('La configuración de etapas del flujo no es válida. Revisa Configuración.');
    }
    return validarEtapasFlujo($etapas);
}

function validarEtapasFlujo(array $etapas): array
{
    if (count($etapas) < 2 || count($etapas) > 15) {
        throw new InvalidArgumentException('El flujo debe tener entre 2 y 15 etapas.');
    }

    $normalizadas = [];
    $claves = [];
    foreach ($etapas as $etapa) {
        if (!is_array($etapa)) {
            throw new InvalidArgumentException('Cada etapa debe tener una clave y un nombre.');
        }
        $clave = (string) ($etapa['clave'] ?? '');
        $nombre = trim((string) ($etapa['nombre'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $clave) || $nombre === '' || mb_strlen($nombre) > 60) {
            throw new InvalidArgumentException('Usa claves técnicas en minúsculas (2 a 40 caracteres) y nombres de hasta 60 caracteres.');
        }
        if (isset($claves[$clave])) {
            throw new InvalidArgumentException('No repitas la clave de una etapa.');
        }
        $claves[$clave] = true;
        $normalizadas[] = ['clave' => $clave, 'nombre' => $nombre];
    }

    foreach (['registro', 'hospitalizacion', 'cirugia', 'recuperacion', 'egreso'] as $requerida) {
        if (!isset($claves[$requerida])) {
            throw new InvalidArgumentException('Conserva las etapas técnicas registro, hospitalizacion, cirugia, recuperacion y egreso.');
        }
    }
    if (!isset($claves['registro'], $claves['egreso'])
        || $normalizadas[0]['clave'] !== 'registro'
        || $normalizadas[array_key_last($normalizadas)]['clave'] !== 'egreso') {
        throw new InvalidArgumentException('La primera etapa debe ser registro y egreso debe ser la última.');
    }

    $actuales = db()->prepare(
        'SELECT DISTINCT etapa FROM flujo_atencion
          WHERE institucion_id = ? AND cerrado_en IS NULL'
    );
    $actuales->execute([institucionId()]);
    foreach ($actuales->fetchAll(PDO::FETCH_COLUMN) as $etapaActual) {
        if (!isset($claves[(string) $etapaActual])) {
            throw new InvalidArgumentException('No puedes quitar una etapa que todavía tiene pacientes activos.');
        }
    }

    return $normalizadas;
}

function textoEtapasFlujo(array $etapas): string
{
    return implode("\n", array_map(
        static fn (array $etapa): string => $etapa['clave'] . '|' . $etapa['nombre'],
        $etapas
    ));
}

function leerEtapasFlujoTexto(string $texto): array
{
    $etapas = [];
    foreach (preg_split('/\R/u', trim($texto)) ?: [] as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        $partes = explode('|', $linea, 2);
        if (count($partes) !== 2) {
            throw new InvalidArgumentException('Escribe una etapa por línea con el formato clave|Nombre.');
        }
        $etapas[] = ['clave' => trim($partes[0]), 'nombre' => trim($partes[1])];
    }
    return validarEtapasFlujo($etapas);
}

function iniciarFlujoPaciente(int $pacienteId, int $institucionId, int $usuarioId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $paciente = $pdo->prepare(
            'SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE'
        );
        $paciente->execute([$pacienteId, $institucionId]);
        if (!$paciente->fetchColumn()) {
            throw new DomainException('El paciente no existe en esta institución o fue dado de baja.');
        }

        $abierto = $pdo->prepare(
            'SELECT id FROM flujo_atencion
              WHERE paciente_id = ? AND institucion_id = ? AND cerrado_en IS NULL
              LIMIT 1 FOR UPDATE'
        );
        $abierto->execute([$pacienteId, $institucionId]);
        if ($abierto->fetchColumn()) {
            throw new DomainException('Este paciente ya tiene un episodio activo en el flujo.');
        }

        $etapa = etapasFlujo()[0]['clave'];
        $pdo->prepare(
            'INSERT INTO flujo_atencion (institucion_id, paciente_id, etapa, actualizado_por)
             VALUES (?, ?, ?, ?)'
        )->execute([$institucionId, $pacienteId, $etapa, $usuarioId]);
        $flujoId = (int) $pdo->lastInsertId();
        $nombre = (string) (usuarioActual()['nombre'] ?? 'Personal');
        $pdo->prepare(
            'INSERT INTO flujo_atencion_eventos
                (flujo_id, institucion_id, paciente_id, etapa_anterior, etapa_nueva, motivo, usuario_id, usuario_nombre)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
        )->execute([$flujoId, $institucionId, $pacienteId, $etapa, 'Ingreso al flujo de atención', $usuarioId, $nombre]);
        $pdo->commit();
        return $flujoId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function moverFlujoPaciente(
    int $flujoId,
    string $etapaNueva,
    string $motivo,
    int $institucionId,
    int $usuarioId
): void {
    $etapasValidas = array_column(etapasFlujo(), 'clave');
    if (!in_array($etapaNueva, $etapasValidas, true)) {
        throw new DomainException('La etapa seleccionada ya no está disponible.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        registrarMovimientoFlujoEnTransaccion(
            $pdo,
            $flujoId,
            $etapaNueva,
            $motivo,
            $institucionId,
            $usuarioId,
            (string) (usuarioActual()['nombre'] ?? 'Personal')
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function registrarMovimientoFlujoEnTransaccion(
    PDO $pdo,
    int $flujoId,
    string $etapaNueva,
    string $motivo,
    int $institucionId,
    int $usuarioId,
    string $usuarioNombre
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('El movimiento del flujo debe compartir una transacción clínica.');
    }
    if (!in_array($etapaNueva, array_column(etapasFlujo(), 'clave'), true)) {
        throw new DomainException('La etapa seleccionada ya no está disponible.');
    }
    $consulta = $pdo->prepare(
        'SELECT id, paciente_id, etapa FROM flujo_atencion
          WHERE id = ? AND institucion_id = ? AND cerrado_en IS NULL FOR UPDATE'
    );
    $consulta->execute([$flujoId, $institucionId]);
    $flujo = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$flujo) {
        throw new DomainException('El episodio ya no está activo. Actualiza el tablero.');
    }
    if ($flujo['etapa'] === $etapaNueva) {
        throw new DomainException('El paciente ya está en esa etapa.');
    }
    $motivo = trim($motivo);
    if (mb_strlen($motivo) > 255) {
        throw new DomainException('El motivo no puede exceder 255 caracteres.');
    }
    $actualizar = $pdo->prepare(
        'UPDATE flujo_atencion SET etapa = ?, cerrado_en = ' . ($etapaNueva === 'egreso' ? 'NOW()' : 'NULL') . ',
                actualizado_por = ? WHERE id = ? AND institucion_id = ? AND cerrado_en IS NULL'
    );
    $actualizar->execute([$etapaNueva, $usuarioId, $flujoId, $institucionId]);
    if ($actualizar->rowCount() !== 1) {
        throw new DomainException('El episodio cambió en otra sesión. Actualiza el tablero.');
    }
    $pdo->prepare(
        'INSERT INTO flujo_atencion_eventos
            (flujo_id, institucion_id, paciente_id, etapa_anterior, etapa_nueva, motivo, usuario_id, usuario_nombre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $flujoId, $institucionId, (int) $flujo['paciente_id'], (string) $flujo['etapa'],
        $etapaNueva, $motivo !== '' ? $motivo : null, $usuarioId, $usuarioNombre,
    ]);
}

function sincronizarIngresoHospitalizacion(
    PDO $pdo,
    int $pacienteId,
    int $institucionId,
    int $usuarioId,
    string $usuarioNombre
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('El ingreso y el movimiento del flujo deben compartir transacción.');
    }
    $etapas = array_column(etapasFlujo(), 'clave');
    if (!in_array('hospitalizacion', $etapas, true)) {
        throw new RuntimeException('Falta la etapa hospitalizacion en la configuración del flujo.');
    }
    $consulta = $pdo->prepare(
        'SELECT id, etapa FROM flujo_atencion
          WHERE paciente_id = ? AND institucion_id = ? AND cerrado_en IS NULL LIMIT 1 FOR UPDATE'
    );
    $consulta->execute([$pacienteId, $institucionId]);
    $flujo = $consulta->fetch(PDO::FETCH_ASSOC);
    if (!$flujo) {
        $pdo->prepare(
            'INSERT INTO flujo_atencion (institucion_id, paciente_id, etapa, actualizado_por)
             VALUES (?, ?, ?, ?)'
        )->execute([$institucionId, $pacienteId, 'hospitalizacion', $usuarioId]);
        $flujoId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO flujo_atencion_eventos
                (flujo_id, institucion_id, paciente_id, etapa_anterior, etapa_nueva, motivo, usuario_id, usuario_nombre)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
        )->execute([
            $flujoId, $institucionId, $pacienteId, 'hospitalizacion',
            'Ingreso a cama/camilla', $usuarioId, $usuarioNombre,
        ]);
        return;
    }
    if ($flujo['etapa'] !== 'hospitalizacion') {
        registrarMovimientoFlujoEnTransaccion(
            $pdo,
            (int) $flujo['id'],
            'hospitalizacion',
            'Ingreso a cama/camilla',
            $institucionId,
            $usuarioId,
            $usuarioNombre
        );
    }
}

function cerrarFlujoPorAltaHospitalaria(
    PDO $pdo,
    int $pacienteId,
    int $institucionId,
    int $usuarioId,
    string $usuarioNombre
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('El alta y el movimiento del flujo deben compartir transacción.');
    }
    $consulta = $pdo->prepare(
        'SELECT id FROM flujo_atencion
          WHERE paciente_id = ? AND institucion_id = ? AND cerrado_en IS NULL LIMIT 1 FOR UPDATE'
    );
    $consulta->execute([$pacienteId, $institucionId]);
    $flujoId = $consulta->fetchColumn();
    if ($flujoId) {
        registrarMovimientoFlujoEnTransaccion(
            $pdo,
            (int) $flujoId,
            'egreso',
            'Alta hospitalaria / Liberación de cama o camilla',
            $institucionId,
            $usuarioId,
            $usuarioNombre
        );
    }
}
