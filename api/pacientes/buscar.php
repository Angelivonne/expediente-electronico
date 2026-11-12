<?php
/**
 * Búsqueda de pacientes para la paleta de la lupa.
 * GET q (texto), orden ('recientes' | 'nombre'), pagina (1..n), solo_local (1).
 * Responde JSON {total, pagina, pacientes: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/clinica/conexiones.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));
$orden    = ($_GET['orden'] ?? '') === 'nombre' ? 'nombre' : 'recientes';
$paginaRecibida = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$pagina = min(1000000, max(1, $paginaRecibida === false ? 1 : $paginaRecibida));
$soloDisponiblesCamillas = ($_GET['disponibles_camillas'] ?? '') === '1';
$soloLocales = ($_GET['solo_local'] ?? '') === '1' || $soloDisponiblesCamillas;
$salto = ($pagina - 1) * 20;

$where      = 'institucion_id = ? AND eliminado_en IS NULL';
$parametros = [institucionId()];
if ($soloDisponiblesCamillas) {
    $where .= " AND NOT EXISTS (
        SELECT 1 FROM camillas c
         WHERE c.institucion_id = pacientes.institucion_id
           AND c.paciente_id = pacientes.id
           AND c.estado = 'ocupada'
    )";
}
$terminos   = terminosBusqueda($busqueda);
if ($terminos) {
    [$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda([
        "CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)",
        'curp',
        'folio',
        "REPLACE(folio, '-', '')",
    ], $busqueda);
    $where .= " AND $filtroBusqueda";
    $parametros = array_merge($parametros, $parametrosBusqueda);
}

$total = db()->prepare("SELECT COUNT(*) FROM pacientes WHERE $where");
$total->execute($parametros);

$ordenSql = $orden === 'nombre'
    ? 'primer_apellido, segundo_apellido, nombre, id'
    : 'creado_en DESC, id DESC';
$columnasPaciente = $soloLocales
    ? 'id, folio, nombre, primer_apellido, segundo_apellido, creado_en'
    : 'id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, creado_en';
$consulta = db()->prepare(
    "SELECT $columnasPaciente FROM pacientes WHERE $where ORDER BY $ordenSql LIMIT 20 OFFSET $salto"
);
$consulta->execute($parametros);

$pacientes = array_map(function (array $p) use ($soloLocales): array {
    $nombre = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
    if ($soloLocales) {
        return [
            'id' => (int) $p['id'],
            'folio' => (string) $p['folio'],
            'nombre' => $nombre,
        ];
    }
    $detalle = ['Expediente ' . preg_replace('/\D/', '', $p['folio']), edad($p['fecha_nacimiento']) . ' años', SEXOS[$p['sexo']] ?? ''];
    if ($p['curp']) {
        $detalle[] = $p['curp'];
    }
    return [
        'id'        => (int) $p['id'],
        'folio'     => (string) $p['folio'],
        'nombre'    => $nombre,
        'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
        'detalle'   => implode(' · ', array_filter($detalle)),
        'hace'      => hace($p['creado_en']),
    ];
}, $consulta->fetchAll());

// Los pacientes de otras clínicas solo aparecen al escribir una búsqueda y si existe
// autorización vigente para esta institución. Nunca se enumeran en la lupa vacía.
$compartidos = [];
if (!$soloLocales && $terminos && puede('conexiones') && conexionesCanalProtegido()) {
    [$filtroCompartido, $parametrosCompartido] = condicionBusqueda([
        "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
        'p.folio',
        'p.curp',
        "REPLACE(p.folio, '-', '')",
    ], $busqueda);
    $consultaCompartidos = db()->prepare(
        "SELECT a.id AS autorizacion_id, a.alcance, a.vence_en, x.acuerdo_version, p.id AS paciente_id,
                p.nombre, p.primer_apellido, p.segundo_apellido, o.nombre AS clinica_origen
           FROM conexiones_autorizaciones a
           JOIN conexiones_clinicas x ON x.id = a.conexion_id AND x.estado = 'conectada'
           JOIN pacientes p ON p.id = a.paciente_id
                            AND p.institucion_id = a.institucion_origen_id
                            AND p.eliminado_en IS NULL
           JOIN instituciones o ON o.id = a.institucion_origen_id
          WHERE a.institucion_destino_id = ?
            AND a.revocado_en IS NULL AND a.vence_en >= NOW()
            AND ((x.institucion_a_id = a.institucion_origen_id AND x.institucion_b_id = a.institucion_destino_id)
              OR (x.institucion_b_id = a.institucion_origen_id AND x.institucion_a_id = a.institucion_destino_id))
            AND $filtroCompartido
          ORDER BY (a.alcance = 'expediente') DESC, a.vence_en DESC, p.primer_apellido, p.nombre
          LIMIT 100"
    );
    $consultaCompartidos->execute(array_merge([institucionId()], $parametrosCompartido));

    // Una persona puede tener más de una autorización activa: mostrar una sola vez,
    // usando el alcance más amplio que el paciente autorizó y que sigue vigente.
    $porPaciente = [];
    foreach ($consultaCompartidos->fetchAll() as $fila) {
        $idPaciente = (int) $fila['paciente_id'];
        $anterior = $porPaciente[$idPaciente] ?? null;
        if ($anterior === null
            || ($fila['alcance'] === 'expediente' && $anterior['alcance'] !== 'expediente')
            || ($fila['alcance'] === $anterior['alcance'] && $fila['vence_en'] > $anterior['vence_en'])) {
            $porPaciente[$idPaciente] = $fila;
        }
    }
    $compartidos = array_map(static function (array $p): array {
        $nombre = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
        $alcance = $p['alcance'] === 'expediente' ? 'Expediente completo' : 'Resumen clínico';
        $prefijoDemo = str_starts_with((string) $p['acuerdo_version'], 'DEMO-')
            ? 'DEMO · '
            : '';
        return [
            'autorizacion' => (int) $p['autorizacion_id'],
            'nombre' => $nombre,
            'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
            'detalle' => $prefijoDemo . 'Se encontró en la clínica ' . $p['clinica_origen'] . ' · ' . $alcance . ' · vigente hasta ' . fechaCorta($p['vence_en']),
        ];
    }, array_values($porPaciente));
    registrarBitacora('conexiones_autorizaciones', 0, 'acceso', 'busqueda_pacientes', null, 'Coincidencias autorizadas: ' . count($compartidos));
}

// La conexión DEMO permite a su clínica destino buscar los registros ficticios de Clínica
// por localhost o por un canal HTTPS protegido. No representa consentimiento clínico.
if (!$soloLocales && $terminos && institucionId() === 9 && puede('conexiones') && conexionesCanalProtegido()) {
    [$filtroDemo, $parametrosDemo] = condicionBusqueda([
        "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
        'p.folio',
        'p.curp',
        "REPLACE(p.folio, '-', '')",
    ], $busqueda);
    $consultaDemo = db()->prepare(
        "SELECT x.id AS conexion_id, p.id AS paciente_id, p.nombre, p.primer_apellido, p.segundo_apellido,
                o.nombre AS clinica_origen
           FROM conexiones_clinicas x
           JOIN pacientes p ON p.institucion_id = x.institucion_a_id AND p.eliminado_en IS NULL
           JOIN instituciones o ON o.id = x.institucion_a_id
          WHERE x.institucion_a_id = 1 AND x.institucion_b_id = 9
            AND x.estado = 'conectada' AND x.acuerdo_version = 'DEMO-prueba-no-legal-v1'
            AND NOT EXISTS (
                SELECT 1 FROM conexiones_autorizaciones a
                 WHERE a.conexion_id = x.id AND a.institucion_destino_id = x.institucion_b_id
                   AND a.paciente_id = p.id AND a.revocado_en IS NULL AND a.vence_en >= NOW()
            )
            AND $filtroDemo
          ORDER BY p.primer_apellido, p.segundo_apellido, p.nombre"
    );
    $consultaDemo->execute($parametrosDemo);
    $pacientesDemo = $consultaDemo->fetchAll();
    foreach ($pacientesDemo as $p) {
        $nombre = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
        $compartidos[] = [
            'demo' => true,
            'paciente_demo_id' => (int) $p['paciente_id'],
            'nombre' => $nombre,
            'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
            'detalle' => 'DEMO · Se encontró en la clínica ' . $p['clinica_origen'] . ' · solo lectura · prueba local',
        ];
    }
    if ($pacientesDemo) {
        registrarBitacora('conexiones_clinicas', (int) $pacientesDemo[0]['conexion_id'], 'acceso', 'busqueda_demo_local', null, 'Coincidencias DEMO: ' . count($pacientesDemo));
    }
}

$paginas   = [];
if (!$soloLocales) {
    // Páginas del espacio de trabajo a las que el usuario tiene acceso
    require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
    $usuarioId = (int) usuarioActual()['id'];
    foreach (paginasDelEspacio($usuarioId) as $p) {
        $titulo = $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'];
        if (!$p['accesible'] || $p['en_papelera'] || ($terminos && !coincidenTerminosBusqueda($titulo, $terminos))) {
            continue;
        }
        $ruta = array_map(fn ($r) => $r['titulo'] !== '' ? $r['titulo'] : TIPOS_PAGINA[$r['tipo']]['nueva'], array_slice(rutaPagina($p['id'], $usuarioId), 0, -1));
        $paginas[] = ['id' => $p['id'], 'titulo' => $titulo, 'icono' => 'r_' . TIPOS_PAGINA[$p['tipo']]['icono'], 'hace' => hace($p['actualizado_en']),
                      'ruta' => ($p['compartida_efectiva'] ? 'Compartido' : 'Privado') . ($ruta ? ' / ' . implode(' / ', $ruta) : ''), 'fecha' => $p['actualizado_en']];
    }
    usort($paginas, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));
}

echo json_encode(['total' => (int) $total->fetchColumn() + count($compartidos), 'pagina' => $pagina, 'pacientes' => $pacientes,
                  'compartidos' => $compartidos, 'paginas' => array_slice($paginas, 0, 8)], JSON_UNESCAPED_UNICODE);
