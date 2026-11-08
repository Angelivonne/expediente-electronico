<?php
/**
 * Búsqueda de pacientes para la paleta de la lupa.
 * GET q (texto), orden ('recientes' | 'nombre'). Responde JSON {total, pacientes: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/clinica/conexiones.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));
$orden    = ($_GET['orden'] ?? '') === 'nombre' ? 'nombre' : 'recientes';

$where      = 'institucion_id = ? AND eliminado_en IS NULL';
$parametros = [institucionId()];
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
    ? 'primer_apellido, segundo_apellido, nombre'
    : 'creado_en DESC, id DESC';
$consulta = db()->prepare(
    "SELECT id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, creado_en
       FROM pacientes WHERE $where ORDER BY $ordenSql LIMIT 20"
);
$consulta->execute($parametros);

$pacientes = array_map(function (array $p): array {
    $detalle = ['Expediente ' . preg_replace('/\D/', '', $p['folio']), edad($p['fecha_nacimiento']) . ' años', SEXOS[$p['sexo']] ?? ''];
    if ($p['curp']) {
        $detalle[] = $p['curp'];
    }
    return [
        'id'        => (int) $p['id'],
        'nombre'    => trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}"),
        'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
        'detalle'   => implode(' · ', array_filter($detalle)),
        'hace'      => hace($p['creado_en']),
    ];
}, $consulta->fetchAll());

// Los pacientes de otras clínicas solo aparecen al escribir una búsqueda y si existe
// autorización vigente para esta institución. Nunca se enumeran en la lupa vacía.
$compartidos = [];
if ($terminos && puede('conexiones') && conexionesCanalProtegido()) {
    [$filtroCompartido, $parametrosCompartido] = condicionBusqueda([
        "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
        'p.folio',
        'p.curp',
        "REPLACE(p.folio, '-', '')",
    ], $busqueda);
    $consultaCompartidos = db()->prepare(
        "SELECT a.id AS autorizacion_id, a.alcance, a.vence_en, p.id AS paciente_id,
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
        return [
            'autorizacion' => (int) $p['autorizacion_id'],
            'nombre' => $nombre,
            'iniciales' => mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1)),
            'detalle' => 'De ' . $p['clinica_origen'] . ' · ' . $alcance . ' · vigente hasta ' . fechaCorta($p['vence_en']),
        ];
    }, array_values($porPaciente));
    registrarBitacora('conexiones_autorizaciones', 0, 'acceso', 'busqueda_pacientes', null, 'Coincidencias autorizadas: ' . count($compartidos));
}

// Páginas del espacio de trabajo a las que el usuario tiene acceso
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
$usuarioId = (int) usuarioActual()['id'];
$paginas   = [];
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

echo json_encode(['total' => (int) $total->fetchColumn() + count($compartidos), 'pacientes' => $pacientes,
                  'compartidos' => $compartidos, 'paginas' => array_slice($paginas, 0, 8)], JSON_UNESCAPED_UNICODE);
