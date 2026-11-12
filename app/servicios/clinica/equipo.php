<?php
/**
 * Perfiles del personal: identidad, actividad (bitácora), páginas y conteos de trabajo.
 */
require_once __DIR__ . '/../../servicios/espacio/paginas.php';

/** Todo el personal de la institución (sin las cuentas de paciente). Ordena por conexión reciente. */
function personalEquipo(): array
{
    $q = db()->prepare(
        "SELECT u.id, u.nombre, u.correo, u.foto, u.rol, u.cedula_profesional, u.activo, u.ultimo_acceso, u.creado_en,
                p.presencia_en, COALESCE(p.presencia_en, u.ultimo_acceso) AS ultimo_visto,
                (u.activo = 1 AND p.presencia_en >= NOW() - INTERVAL 60 SECOND) AS en_linea
           FROM usuarios u
           LEFT JOIN (SELECT usuario_id, MAX(visto_en) AS presencia_en FROM presencia GROUP BY usuario_id) p ON p.usuario_id = u.id
          WHERE u.institucion_id = ? AND u.rol <> 'paciente'
             AND (u.activo = 1 OR u.ultimo_acceso IS NULL)
             AND u.correo NOT LIKE 'retirada+%@cuentas.invalid'
          ORDER BY en_linea DESC, (ultimo_visto IS NULL), ultimo_visto DESC, u.nombre"
    );
    $q->execute([institucionId()]);
    return $q->fetchAll();
}

/** Un perfil por id (siempre de esta institución y nunca de paciente). */
function personaEquipo(int $id): ?array
{
    $q = db()->prepare(
        "SELECT u.id, u.nombre, u.correo, u.foto, u.firma, u.rol, u.cedula_profesional, u.activo, u.ultimo_acceso, u.creado_en, u.paciente_id,
                p.presencia_en, COALESCE(p.presencia_en, u.ultimo_acceso) AS ultimo_visto,
                (u.activo = 1 AND p.presencia_en >= NOW() - INTERVAL 60 SECOND) AS en_linea
           FROM usuarios u
           LEFT JOIN (SELECT usuario_id, MAX(visto_en) AS presencia_en FROM presencia GROUP BY usuario_id) p ON p.usuario_id = u.id
          WHERE u.id = ? AND u.institucion_id = ? AND u.rol <> 'paciente'
            AND u.correo NOT LIKE 'retirada+%@cuentas.invalid'"
    );
    $q->execute([$id, institucionId()]);
    $p = $q->fetch();
    return $p ?: null;
}

/** Convierte nombres internos de campos a etiquetas que entiende el equipo. */
function etiquetaCampoEquipo(string $tabla, ?string $campo): string
{
    $campo = trim((string) $campo);
    $etiquetas = [
        'nombre' => 'nombre', 'primer_apellido' => 'primer apellido', 'segundo_apellido' => 'segundo apellido',
        'fecha_nacimiento' => 'fecha de nacimiento', 'sexo' => 'sexo', 'telefono' => 'teléfono',
        'correo' => 'correo electrónico', 'direccion' => 'domicilio', 'folio' => 'folio',
        'tipo' => 'tipo de registro', 'fecha' => 'fecha del registro', 'titulo' => 'título',
        'detalle' => 'detalle clínico', 'inicio' => 'fecha y hora', 'fin' => 'hora de término',
        'estado' => 'estado', 'motivo' => 'motivo', 'notas' => 'notas', 'contenido' => 'contenido',
        'rol' => 'rol', 'activo' => 'estado de la cuenta', 'firma' => 'firma', 'foto' => 'foto de perfil',
        'permisos' => 'permisos de acceso', 'ubicacion' => 'ubicación', 'papelera' => 'estado de la página',
    ];
    return $etiquetas[$campo] ?? ($campo !== '' ? str_replace('_', ' ', $campo) : 'registro');
}

/** Los textos clínicos y los secretos no se copian al resumen de actividad. */
function valorBitacoraEquipo(?string $valor, string $tabla, string $campo): ?string
{
    if ($valor === null) {
        return null;
    }
    if ($tabla === 'historial_clinico' || in_array($campo, ['detalle', 'notas', 'contenido', 'clave_hash', 'token_hash', 'ia_clave', 'secreto'], true)) {
        return '[contenido protegido]';
    }
    $valor = trim(preg_replace('/\s+/u', ' ', strip_tags($valor)) ?? '');
    if ($valor === '') {
        return '(vacío)';
    }
    if (is_array(json_decode($valor, true))) {
        return '[datos estructurados]';
    }
    return mb_strimwidth($valor, 0, 110, '…', 'UTF-8');
}

/** enlace de la bitácora a la pantalla del registro que se movió. */
function enlaceDeBitacora(string $tabla, int $id): ?string
{
    return match ($tabla) {
        'pacientes'          => URL_BASE . '/pacientes/ver.php?id=' . $id,
        'historial_clinico'  => URL_BASE . '/pacientes/evento.php?id=' . $id,
        'paginas'            => URL_BASE . '/paginas/ver.php?id=' . $id,
        'citas'              => URL_BASE . '/agenda/',
        'usuarios', 'sistema', 'instituciones', 'configuracion' => URL_BASE . '/configuracion/',
        default              => null,
    };
}

/** Actividad reciente con campo y valores de auditoría, dentro de la institución actual. */
function actividadPersona(int $id, int $limite = 12): array
{
    $q = db()->prepare(
        'SELECT tabla, registro_id, accion, campo, valor_anterior, valor_nuevo, creado_en
           FROM bitacora
          WHERE usuario_id = ? AND institucion_id = ?
          ORDER BY id DESC LIMIT ' . (int) $limite
    );
    $q->execute([$id, institucionId()]);
    $entidades = [
        'pacientes' => 'un expediente', 'historial_clinico' => 'un registro clínico', 'paginas' => 'una página',
        'citas' => 'una cita', 'usuarios' => 'una cuenta del personal', 'imagenes_medicas' => 'una imagen médica',
    ];
    $relaciones = [
        'pacientes' => 'del expediente', 'historial_clinico' => 'del registro clínico', 'paginas' => 'de la página',
        'citas' => 'de la cita', 'usuarios' => 'de la cuenta del personal', 'imagenes_medicas' => 'de la imagen médica',
    ];
    $acciones = ['crear' => 'Registró', 'actualizar' => 'Actualizó', 'eliminar' => 'Eliminó',
                 'acceso' => 'Consultó', 'salida' => 'Cerró sesión'];
    $lista = [];
    foreach ($q->fetchAll() as $b) {
        $tabla = (string) $b['tabla'];
        $campo = etiquetaCampoEquipo($tabla, $b['campo']);
        $entidad = $entidades[$tabla] ?? 'un ' . str_replace('_', ' ', $tabla);
        $relacion = $relaciones[$tabla] ?? 'del registro';
        $texto = match ($b['accion']) {
            'actualizar' => 'Actualizó el campo «' . $campo . '» ' . $relacion,
            'crear' => 'Registró ' . $entidad,
            'eliminar' => 'Eliminó ' . $entidad,
            'acceso' => 'Consultó ' . ($tabla === 'pacientes' ? 'el expediente' : $entidad),
            'salida' => 'Cerró sesión',
            default => ($acciones[$b['accion']] ?? ucfirst((string) $b['accion'])) . ' el ' . $entidad,
        };
        $detalle = null;
        if ($b['accion'] === 'actualizar' && $b['campo'] !== null) {
            if (in_array($tabla, ['pacientes', 'historial_clinico'], true) && !puede('ver_expediente')) {
                $detalle = 'Detalle omitido: tu cuenta no tiene permiso para ver el expediente.';
            } elseif ($tabla === 'historial_clinico' || in_array($b['campo'], ['detalle', 'notas', 'contenido'], true)) {
                $detalle = 'El contenido clínico no se copia aquí. Abre el registro para revisarlo con los permisos correspondientes.';
            } else {
                $antes = valorBitacoraEquipo($b['valor_anterior'], $tabla, (string) $b['campo']);
                $despues = valorBitacoraEquipo($b['valor_nuevo'], $tabla, (string) $b['campo']);
                $detalle = $antes !== null || $despues !== null
                    ? 'Antes: ' . ($antes ?? '—') . '  →  Ahora: ' . ($despues ?? '—')
                    : 'La bitácora registró el campo, pero no conservó sus valores anterior y nuevo.';
            }
        }
        $lista[] = [
            'texto' => $texto,
            'detalle' => $detalle,
            'enlace' => enlaceDeBitacora($tabla, (int) $b['registro_id']),
            'cuando' => $b['creado_en'],
        ];
    }
    return $lista;
}

/** Cambios auditados y tiempo activo aproximado por día, para la cuadrícula del perfil del personal. */
function actividadDiariaPersona(int $id, int $semanas = 16): array
{
    $semanas = max(1, min(52, $semanas));
    $hoy = new DateTimeImmutable('today');
    $inicio = $hoy->modify('monday this week')->modify('-' . ($semanas - 1) . ' weeks');
    $desde = $inicio->format('Y-m-d');
    $hasta = $hoy->modify('+1 day')->format('Y-m-d');

    $q = db()->prepare(
        "SELECT DATE(creado_en) AS dia, COUNT(*) AS total
           FROM bitacora
          WHERE usuario_id = ? AND institucion_id = ?
            AND accion IN ('crear', 'actualizar', 'eliminar')
            AND creado_en >= ? AND creado_en < ?
          GROUP BY DATE(creado_en)"
    );
    $q->execute([$id, institucionId(), $desde, $hasta]);
    $cambios = array_column($q->fetchAll(), 'total', 'dia');

    $q = db()->prepare(
        'SELECT fecha, SUM(segundos_activos) AS segundos
           FROM equipo_tiempo_activo
          WHERE usuario_id = ? AND institucion_id = ? AND fecha >= ? AND fecha < ?
          GROUP BY fecha'
    );
    $q->execute([$id, institucionId(), $desde, $hasta]);
    $tiempo = array_column($q->fetchAll(), 'segundos', 'fecha');

    $dias = [];
    $maximo = 0;
    for ($i = 0; $i < $semanas * 7; $i++) {
        $fecha = $inicio->modify("+$i days")->format('Y-m-d');
        $total = (int) ($cambios[$fecha] ?? 0);
        $maximo = max($maximo, $total);
        $dias[$fecha] = ['cambios' => $total, 'segundos' => (int) ($tiempo[$fecha] ?? 0)];
    }

    return ['inicio' => $desde, 'hoy' => $hoy->format('Y-m-d'), 'semanas' => $semanas, 'dias' => $dias, 'maximo' => $maximo];
}

/** Páginas del espacio de trabajo de una persona (propias y compartidas con ella) para el bloque «Páginas de …». */
function paginasPersona(int $id, int $limite = 16): array
{
    $q = db()->prepare(
        'SELECT p.id, p.tipo, p.titulo, p.actualizado_en, p.paciente_id, fol.folio AS folio
           FROM paginas p
           LEFT JOIN pacientes fol ON fol.id = p.paciente_id
          WHERE p.propietario_id = ? AND p.eliminado_en IS NULL
                AND (p.paciente_id IS NULL OR p.paciente_id IN (SELECT id FROM pacientes WHERE institucion_id = ?))
          ORDER BY p.actualizado_en DESC LIMIT ' . (int) $limite
    );
    $q->execute([$id, institucionId()]);
    return $q->fetchAll();
}

/** Resumen de actividad real con periodos explícitos y conteos respetando permisos. */
function conteosPersona(int $id): array
{
    $desde = (new DateTime('-30 days'))->format('Y-m-d H:i:s');
    $expedientes = null;
    $registrosClinicos = null;
    $proximasCitas = null;

    if (puede('ver_expediente')) {
        $q = db()->prepare(
            "SELECT COUNT(DISTINCT registro_id) FROM bitacora
              WHERE usuario_id = ? AND institucion_id = ? AND tabla = 'pacientes'
                AND accion = 'acceso' AND creado_en >= ?"
        );
        $q->execute([$id, institucionId(), $desde]);
        $expedientes = (int) $q->fetchColumn();

        $q = db()->prepare(
            'SELECT COUNT(*) FROM historial_clinico h
              JOIN pacientes p ON p.id = h.paciente_id
             WHERE h.autor_id = ? AND p.institucion_id = ? AND h.creado_en >= ?'
        );
        $q->execute([$id, institucionId(), $desde]);
        $registrosClinicos = (int) $q->fetchColumn();
    }

    if (puede('agenda')) {
        $q = db()->prepare(
            "SELECT COUNT(*) FROM citas
              WHERE profesional_id = ? AND institucion_id = ? AND fin >= NOW()
                AND estado IN ('programada', 'confirmada')"
        );
        $q->execute([$id, institucionId()]);
        $proximasCitas = (int) $q->fetchColumn();
    }

    $q = db()->prepare(
        'SELECT COUNT(*) FROM paginas p
          WHERE p.propietario_id = ? AND p.eliminado_en IS NULL
            AND (p.paciente_id IS NULL OR EXISTS (
                SELECT 1 FROM pacientes pa WHERE pa.id = p.paciente_id AND pa.institucion_id = ?
            ))'
    );
    $q->execute([$id, institucionId()]);

    return [
        'expedientes_30d' => $expedientes,
        'registros_clinicos_30d' => $registrosClinicos,
        'citas_proximas' => $proximasCitas,
        'paginas' => (int) $q->fetchColumn(),
    ];
}
