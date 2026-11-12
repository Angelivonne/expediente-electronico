<?php
/**
 * Actividad de la sección abierta (Pacientes, Agenda, ...) para el panel lateral de "Última edición".
 * Mismos datos que la actividad de una página: resumen (tarjeta), detalle (lista de notificaciones)
 * y estadísticas de los últimos 28 días, todo tomado de la bitácora de la institución.
 *
 * GET: api/actividad_seccion.php?seccion=pacientes
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responderSeccion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$seccion = (string) ($_GET['seccion'] ?? '');

if (in_array($seccion, ['catalogos', 'equipo'], true)) {
    require_once __DIR__ . '/../app/datos/catalogos_sistema.php';
    $tablasCatalogo = array_values(array_unique(array_map(
        static fn (array $catalogo): string => (string) $catalogo['tabla'],
        catalogosSistema()
    )));
    if (in_array($seccion, ['catalogos', 'equipo'], true)) {
        $tablasCatalogo[] = 'catalogos_meta';
    }
} else {
    $tablasCatalogo = [];
}

$secciones = [
    'pacientes' => ['permiso' => 'ver_expediente', 'tablas' => ['pacientes', 'historial_clinico', 'imagenes_medicas']],
    'agenda' => ['permiso' => 'agenda', 'tablas' => ['citas']],
    'camillas' => ['permiso' => 'ver_expediente', 'tablas' => ['camillas', 'kardex_medicaciones', 'kardex_administraciones']],
    'farmacia' => ['permiso' => 'farmacia', 'tablas' => ['farmacia_lotes', 'farmacia_movimientos']],
    'finanzas' => ['permiso' => 'finanzas', 'tablas' => ['finanzas_movimientos', 'finanzas_facturas', 'finanzas_recurrentes', 'finanzas_presupuestos', 'finanzas_cierres', 'servicios_clinicos']],
    'catalogos' => ['permiso' => 'ver_expediente', 'tablas' => $tablasCatalogo],
    'conexiones' => ['permiso' => 'conexiones', 'tablas' => ['conexiones_clinicas', 'conexiones_autorizaciones']],
    'datos' => ['permiso' => 'ver_expediente', 'tablas' => ['historial_clinico', 'informes_morbilidad']],
    'equipo' => ['permiso' => 'ver_expediente', 'tablas' => []],
    'sistema' => ['permiso' => 'configurar', 'tablas' => null],
];
if ($seccion === 'equipo') {
    $tablasEquipo = ['pacientes', 'camillas', ...$tablasCatalogo];
    if (puede('ver_expediente')) {
        $tablasEquipo[] = 'informes_morbilidad';
    }
    if (puede('historial')) {
        $tablasEquipo = [...$tablasEquipo, 'historial_clinico', 'imagenes_medicas'];
    }
    if (puede('kardex')) {
        $tablasEquipo = [...$tablasEquipo, 'kardex_medicaciones', 'kardex_administraciones'];
    }
    if (puede('agenda')) {
        $tablasEquipo[] = 'citas';
    }
    if (puede('finanzas')) {
        $tablasEquipo = [...$tablasEquipo, 'finanzas_movimientos', 'finanzas_facturas', 'finanzas_recurrentes', 'finanzas_presupuestos', 'finanzas_cierres', 'servicios_clinicos'];
    }
    if (puede('conexiones')) {
        $tablasEquipo = [...$tablasEquipo, 'conexiones_clinicas', 'conexiones_autorizaciones'];
    }
    if (puede('farmacia')) {
        $tablasEquipo = [...$tablasEquipo, 'farmacia_lotes', 'farmacia_movimientos'];
    }
    $secciones['equipo']['tablas'] = array_values(array_unique($tablasEquipo));
}

if (!isset($secciones[$seccion])) {
    responderSeccion(400, ['ok' => false, 'error' => 'Sección de actividad no válida.']);
}
if (!puede($secciones[$seccion]['permiso']) || ($seccion === 'conexiones' && !puede('ver_expediente'))) {
    responderSeccion(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.']);
}

$tablas = $secciones[$seccion]['tablas'];
if ($seccion === 'pacientes' && !puede('historial')) {
    $tablas = ['pacientes'];
}
if ($seccion === 'camillas' && !puede('kardex')) {
    $tablas = ['camillas'];
}
$usuarioId = (int) usuarioActual()['id'];
$donde     = $tablas
    ? 'b.tabla IN (' . implode(',', array_fill(0, count($tablas), '?')) . ') AND b.institucion_id = ?'
    : 'b.institucion_id = ?';
$parametros = $tablas ? [...$tablas, institucionId()] : [institucionId()];
$periodos = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];
$periodo = is_string($_GET['periodo'] ?? null) && (isset($periodos[$_GET['periodo']]) || $_GET['periodo'] === 'todo')
    ? $_GET['periodo']
    : '30d';
$personaFiltro = is_string($_GET['persona'] ?? null) ? mb_substr(trim($_GET['persona']), 0, 100) : '';
$soloCambios = ($_GET['cambios'] ?? '') === '1';
$antesId = filter_var($_GET['antes'] ?? null, FILTER_VALIDATE_INT);
$condiciones = [$donde];
$parametrosFilas = $parametros;
if ($soloCambios) {
    $condiciones[] = "b.accion IN ('crear', 'actualizar', 'eliminar')";
}
if (isset($periodos[$periodo])) {
    $condiciones[] = 'b.creado_en >= ?';
    $parametrosFilas[] = (new DateTime('-' . $periodos[$periodo] . ' days'))->format('Y-m-d H:i:s');
}
if ($personaFiltro !== '') {
    $condiciones[] = 'u.nombre LIKE ?';
    $parametrosFilas[] = '%' . $personaFiltro . '%';
}
if ($antesId !== false && $antesId !== null && $antesId > 0) {
    $condiciones[] = 'b.id < ?';
    $parametrosFilas[] = $antesId;
}
$dondeFilas = implode(' AND ', $condiciones);

$consulta = db()->prepare(
    "SELECT b.id, b.tabla, b.registro_id, b.accion, b.campo, b.valor_anterior, b.valor_nuevo, b.creado_en,
            u.id AS usuario_id, u.nombre, u.foto
       FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
      WHERE $dondeFilas
      ORDER BY b.id DESC LIMIT 101"
);
$consulta->execute($parametrosFilas);
$filas = $consulta->fetchAll();
$hayMas = count($filas) > 100;
if ($hayMas) {
    array_pop($filas);
}
$cursorActividad = $filas ? (int) $filas[array_key_last($filas)]['id'] : null;

// Nombres de los pacientes consultados (para decir «abrió el expediente de …»)
$idsPaciente = [];
foreach ($filas as $f) {
    if ($f['tabla'] === 'pacientes') {
        $idsPaciente[(int) $f['registro_id']] = true;
    }
}
$idsPaciente = array_keys($idsPaciente);
$porPaciente = [];
if ($idsPaciente) {
    $nombres = db()->prepare(
        'SELECT id, CONCAT(nombre, " ", primer_apellido) AS nombre FROM pacientes WHERE institucion_id = ? AND id IN ('
        . implode(',', array_fill(0, count($idsPaciente), '?')) . ')'
    );
    $nombres->execute([institucionId(), ...$idsPaciente]);
    foreach ($nombres->fetchAll() as $fila) {
        $porPaciente[(int) $fila['id']] = trim((string) $fila['nombre']);
    }
}

/** Nombre legible del campo en la bitácora; no muestra identificadores internos crudos. */
function etiquetaCampoSeccion(string $campo): string
{
    $etiquetas = [
        'nombre' => 'nombre', 'primer_apellido' => 'primer apellido', 'segundo_apellido' => 'segundo apellido',
        'fecha_nacimiento' => 'fecha de nacimiento', 'sexo' => 'sexo', 'telefono' => 'teléfono',
        'correo' => 'correo electrónico', 'direccion' => 'domicilio', 'folio' => 'folio',
        'tipo' => 'tipo de registro', 'fecha' => 'fecha del registro', 'titulo' => 'título',
        'detalle' => 'detalle clínico', 'inicio' => 'fecha y hora', 'fin' => 'hora de término',
        'estado' => 'estado', 'motivo' => 'motivo', 'notas' => 'notas', 'contenido' => 'contenido',
        'profesional_id' => 'profesional asignado', 'paciente_id' => 'paciente asignado',
    ];
    return $etiquetas[$campo] ?? ($campo !== '' ? str_replace('_', ' ', $campo) : 'registro');
}

function valorSeccion(array $f, ?string $valor): ?string
{
    if ($valor === null) {
        return null;
    }
    $camposProtegidos = [
        'detalle', 'notas', 'contenido', 'diagnostico_ingreso', 'alergias', 'medicamentos_actuales',
        'medicamento', 'dosis', 'via', 'frecuencia', 'horario', 'indicaciones', 'observaciones',
        'motivo_consulta', 'padecimientos_cronicos', 'antecedentes_heredofamiliares',
        'antecedentes_personales_patologicos', 'antecedentes_personales_no_patologicos',
        'antecedentes_ginecoobstetricos', 'habitos_sustancias', 'inmunizaciones',
        'metadatos', 'archivo', 'sha256', 'nombre_original', 'finalidad',
        'consentimiento_archivo', 'consentimiento_sha256', 'consentimiento_nombre',
    ];
    if ($f['tabla'] === 'historial_clinico' || in_array($f['campo'], $camposProtegidos, true)) {
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

/** Describe el movimiento en lenguaje claro y mantiene los valores clínicos fuera del feed. */
function descripcionSeccion(array $f, array $porPaciente, bool $esTu): string
{
    $nombre = $porPaciente[(int) $f['registro_id']] ?? null;
    $entidad = match ($f['tabla']) {
        'pacientes' => 'expediente', 'historial_clinico' => 'registro clínico', 'citas' => 'cita',
        'imagenes_medicas' => 'imagen médica', 'camillas' => 'cama o camilla',
        'kardex_medicaciones' => 'indicación de medicamento', 'kardex_administraciones' => 'registro de dosis',
        'finanzas_movimientos' => 'movimiento financiero', 'finanzas_facturas' => 'factura',
        'finanzas_recurrentes' => 'movimiento recurrente', 'finanzas_presupuestos' => 'presupuesto',
        'servicios_clinicos' => 'servicio clínico', 'conexiones_clinicas' => 'conexión entre clínicas',
        'conexiones_autorizaciones' => 'autorización de expediente',
        'farmacia_lotes', 'farmacia_movimientos' => 'movimiento de farmacia',
        'informes_morbilidad' => 'informe de morbilidad',
        default => 'registro',
    };
    $articulo = match ($f['tabla']) {
        'pacientes' => 'del expediente', 'historial_clinico' => 'del registro clínico',
        'citas' => 'de la cita', 'imagenes_medicas' => 'de la imagen médica',
        'camillas' => 'de la cama o camilla', 'finanzas_movimientos' => 'del movimiento financiero',
        'conexiones_clinicas' => 'de la conexión clínica',
        default => 'del registro',
    };
    return match (true) {
        $f['accion'] === 'crear' => ($esTu ? 'registraste' : 'registró') . ' un nuevo ' . $entidad,
        $f['accion'] === 'eliminar' => ($esTu ? 'eliminaste' : 'eliminó') . ' un ' . $entidad,
        $f['accion'] === 'actualizar' && (string) $f['campo'] !== '' => ($esTu ? 'actualizaste' : 'actualizó')
            . ' el campo «' . etiquetaCampoSeccion((string) $f['campo']) . '» ' . $articulo,
        $f['accion'] === 'actualizar' => $esTu ? 'actualizaste un registro' : 'actualizó un registro',
        $f['tabla'] === 'pacientes' && $nombre !== null => $esTu
            ? "abriste el expediente de {$nombre}" : "abrió el expediente de {$nombre}",
        $f['tabla'] === 'citas' => $esTu ? 'consultaste la agenda' : 'consultó la agenda',
        $f['accion'] === 'salida' => $esTu ? 'cerraste sesión' : 'cerró sesión',
        $f['accion'] === 'exportar' => $esTu ? 'exportaste un informe autorizado' : 'exportó un informe autorizado',
        default => $esTu ? 'consultaste la sección' : 'consultó la sección',
    };
}

function detalleSeccion(array $f): ?string
{
    if ($f['accion'] !== 'actualizar' || $f['campo'] === null) {
        return null;
    }
    if ($f['tabla'] === 'historial_clinico' || in_array($f['campo'], [
        'detalle', 'notas', 'contenido', 'diagnostico_ingreso', 'alergias', 'medicamentos_actuales',
        'medicamento', 'dosis', 'via', 'frecuencia', 'horario', 'indicaciones', 'observaciones',
        'motivo_consulta', 'padecimientos_cronicos', 'antecedentes_heredofamiliares',
        'antecedentes_personales_patologicos', 'antecedentes_personales_no_patologicos',
        'antecedentes_ginecoobstetricos', 'habitos_sustancias', 'inmunizaciones',
        'metadatos', 'archivo', 'sha256', 'nombre_original', 'finalidad',
        'consentimiento_archivo', 'consentimiento_sha256', 'consentimiento_nombre',
    ], true)) {
        return 'El contenido clínico no se muestra en el registro de actividad.';
    }
    $antes = valorSeccion($f, $f['valor_anterior']);
    $despues = valorSeccion($f, $f['valor_nuevo']);
    return $antes !== null || $despues !== null
        ? 'Antes: ' . ($antes ?? '—') . '  →  Ahora: ' . ($despues ?? '—')
        : 'La bitácora registró el campo, pero no conservó sus valores anterior y nuevo.';
}

function enlaceActividadSeccion(
    array $f,
    array $codigosCamilla,
    array $rutasKardex,
    array $rutasCitas,
    array $rutasImagenes,
    string $seccion
): string
{
    $id = (int) $f['registro_id'];
    return match ($f['tabla']) {
        'pacientes' => URL_BASE . '/pacientes/ver.php?id=' . $id,
        'historial_clinico' => URL_BASE . '/pacientes/evento.php?id=' . $id,
        'imagenes_medicas' => $rutasImagenes[$id] ?? URL_BASE . '/pacientes/',
        'citas' => $rutasCitas[$id] ?? URL_BASE . '/agenda/?cita=' . $id,
        'camillas' => isset($codigosCamilla[$id])
            ? URL_BASE . '/camillas/?q=' . rawurlencode($codigosCamilla[$id])
            : URL_BASE . '/camillas/',
        'kardex_medicaciones', 'kardex_administraciones' => $rutasKardex[$f['tabla'] . ':' . $id] ?? URL_BASE . '/camillas/kardex.php',
        'finanzas_facturas' => URL_BASE . '/finanzas/facturacion.php',
        'farmacia_lotes', 'farmacia_movimientos' => URL_BASE . '/farmacia/',
        'informes_morbilidad' => URL_BASE . '/pacientes/datos.php',
        'finanzas_recurrentes' => URL_BASE . '/finanzas/recurrentes.php',
        'finanzas_presupuestos' => URL_BASE . '/finanzas/presupuestos.php',
        'finanzas_cierres' => URL_BASE . '/finanzas/cierre.php',
        'servicios_clinicos' => URL_BASE . '/finanzas/servicios.php?id=' . $id,
        'conexiones_clinicas', 'conexiones_autorizaciones' => URL_BASE . '/conexiones/',
        default => match ($seccion) {
            'catalogos' => URL_BASE . '/catalogos/',
            'datos' => URL_BASE . '/pacientes/datos.php',
            'finanzas' => URL_BASE . '/finanzas/',
            'equipo' => URL_BASE . '/catalogos/',
            default => URL_BASE . '/tablero/',
        },
    };
}

$codigosCamilla = [];
$rutasKardex = [];
$rutasCitas = [];
$rutasImagenes = [];
$idsCamilla = array_values(array_unique(array_map(
    static fn (array $fila): int => (int) $fila['registro_id'],
    array_filter($filas, static fn (array $fila): bool => $fila['tabla'] === 'camillas')
)));
if ($idsCamilla) {
    $consultaCodigos = db()->prepare(
        'SELECT id, codigo FROM camillas WHERE institucion_id = ? AND id IN ('
        . implode(',', array_fill(0, count($idsCamilla), '?')) . ')'
    );
    $consultaCodigos->execute([institucionId(), ...$idsCamilla]);
    $codigosCamilla = array_column($consultaCodigos->fetchAll(PDO::FETCH_ASSOC), 'codigo', 'id');
}
$idsKardex = [];
foreach ($filas as $fila) {
    if (in_array($fila['tabla'], ['kardex_medicaciones', 'kardex_administraciones'], true)) {
        $idsKardex[$fila['tabla']][] = (int) $fila['registro_id'];
    }
}
foreach ($idsKardex as $tablaKardex => $ids) {
    $ids = array_values(array_unique($ids));
    $consultaKardex = db()->prepare(
        'SELECT id, paciente_id FROM `' . $tablaKardex . '` WHERE institucion_id = ? AND id IN ('
        . implode(',', array_fill(0, count($ids), '?')) . ')'
    );
    $consultaKardex->execute([institucionId(), ...$ids]);
    foreach ($consultaKardex->fetchAll(PDO::FETCH_ASSOC) as $filaKardex) {
        $rutasKardex[$tablaKardex . ':' . (int) $filaKardex['id']] = URL_BASE
            . '/camillas/kardex.php?paciente_id=' . (int) $filaKardex['paciente_id'];
    }
}
$idsCitas = array_values(array_unique(array_map(
    static fn (array $fila): int => (int) $fila['registro_id'],
    array_filter($filas, static fn (array $fila): bool => $fila['tabla'] === 'citas')
)));
if ($idsCitas) {
    $consultaCitas = db()->prepare(
        'SELECT id, inicio FROM citas WHERE institucion_id = ? AND id IN ('
        . implode(',', array_fill(0, count($idsCitas), '?')) . ')'
    );
    $consultaCitas->execute([institucionId(), ...$idsCitas]);
    foreach ($consultaCitas->fetchAll(PDO::FETCH_ASSOC) as $filaCita) {
        $idCita = (int) $filaCita['id'];
        $rutasCitas[$idCita] = URL_BASE . '/agenda/?fecha='
            . rawurlencode(substr((string) $filaCita['inicio'], 0, 10)) . '&cita=' . $idCita;
    }
}
$idsImagenes = array_values(array_unique(array_map(
    static fn (array $fila): int => (int) $fila['registro_id'],
    array_filter($filas, static fn (array $fila): bool => $fila['tabla'] === 'imagenes_medicas')
)));
if ($idsImagenes) {
    $consultaImagenes = db()->prepare(
        'SELECT id, paciente_id FROM imagenes_medicas WHERE institucion_id <=> ? AND id IN ('
        . implode(',', array_fill(0, count($idsImagenes), '?')) . ')'
    );
    $consultaImagenes->execute([institucionId(), ...$idsImagenes]);
    foreach ($consultaImagenes->fetchAll(PDO::FETCH_ASSOC) as $filaImagen) {
        $rutasImagenes[(int) $filaImagen['id']] = URL_BASE . '/pacientes/ver.php?id='
            . (int) $filaImagen['paciente_id'] . '#imagenes-medicas';
    }
}

$detalle = [];
$resumen = ['edicion' => null, 'creacion' => null];
$huboEdicion = false;
foreach ($filas as $f) {
    $persona = [
        'usuario_id' => (int) $f['usuario_id'],
        'nombre'     => $f['nombre'] ?? 'Sistema',
        'tu'         => (int) $f['usuario_id'] === $usuarioId,
        'foto'       => $f['foto'] ? URL_BASE . '/' . $f['foto'] : null,
        'fecha'      => date('c', strtotime($f['creado_en'])),
        'hace'       => hace($f['creado_en']),
        'url'        => enlaceActividadSeccion($f, $codigosCamilla, $rutasKardex, $rutasCitas, $rutasImagenes, $seccion),
    ];
    $texto = descripcionSeccion($f, $porPaciente, (int) $f['usuario_id'] === $usuarioId);
    $detalleCambio = detalleSeccion($f);
    if ($f['accion'] === 'crear') {
        $resumen['creacion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        $huboEdicion = true;
    } else {
        if ($f['accion'] !== 'acceso') {
            $huboEdicion = true;
            $resumen['edicion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        } elseif ($resumen['edicion'] === null && $resumen['creacion'] === null) {
            $resumen['edicion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        }
    }

    $detalle[] = $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
}
$resumen['etiqueta_edicion'] = $huboEdicion ? 'Editada por' : 'Última consulta de';
$resumen['etiqueta_creacion'] = 'Creada por';

$desde = (new DateTime('-28 days'))->format('Y-m-d H:i:s');
$cuenta = function (string $acciones) use ($donde, $parametros, $desde): int {
    $c = db()->prepare("SELECT COUNT(*) FROM bitacora b WHERE $donde AND b.accion IN ($acciones) AND b.creado_en >= ?");
    $c->execute([...$parametros, $desde]);
    return (int) $c->fetchColumn();
};
$cuentaDistinta = function (string $acciones) use ($donde, $parametros, $desde): int {
    $c = db()->prepare("SELECT COUNT(DISTINCT b.usuario_id) FROM bitacora b WHERE $donde AND b.accion IN ($acciones) AND b.creado_en >= ?");
    $c->execute([...$parametros, $desde]);
    return (int) $c->fetchColumn();
};

$estadisticas = [
    'vistas'     => $cuenta("'acceso'"),
    'visitantes' => $cuentaDistinta("'acceso'"),
    'ediciones'  => $cuenta("'crear','actualizar','eliminar'"),
    'editores'   => $cuentaDistinta("'crear','actualizar','eliminar'"),
];

// Consultas por día (últimos 28 días) para la gráfica de barras
$porDia = db()->prepare(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS total FROM bitacora b
      WHERE $donde AND b.accion = 'acceso' AND b.creado_en >= ?
      GROUP BY DATE(creado_en)"
);
$porDia->execute([...$parametros, $desde]);
$mapa = array_column($porDia->fetchAll(), 'total', 'dia');
$estadisticas['dias'] = [];
for ($i = 27; $i >= 0; $i--) {
    $dia = (new DateTime("-$i days"))->format('Y-m-d');
    $estadisticas['dias'][] = ['dia' => $dia, 'total' => (int) ($mapa[$dia] ?? 0)];
}

responderSeccion(200, [
    'ok' => true,
    'resumen' => $resumen,
    'detalle' => $detalle,
    'estadisticas' => $estadisticas,
    'periodo' => $periodo,
    'persona' => $personaFiltro,
    'hay_mas' => $hayMas,
    'cursor' => $cursorActividad,
]);
