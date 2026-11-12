<?php
/**
 * Búsqueda de camas y camillas para la paleta común del expediente.
 * GET q (texto). Responde JSON {total, camillas: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));
$terminos = terminosBusqueda($busqueda);
if (!$terminos) {
    echo json_encode(['total' => 0, 'camillas' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

[$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda([
    'c.codigo',
    'c.piso',
    'c.area',
    'c.tipo',
    'c.estado',
    'c.diagnostico_ingreso',
    'c.notas',
    "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
    'p.folio',
    'p.curp',
    'p.alergias',
], $busqueda);
$parametros = array_merge([institucionId()], $parametrosBusqueda);
$pdo = db();

$total = $pdo->prepare(
    "SELECT COUNT(*)
       FROM camillas c
       LEFT JOIN pacientes p ON p.id = c.paciente_id
                            AND p.institucion_id = c.institucion_id
                            AND p.eliminado_en IS NULL
      WHERE c.institucion_id = ? AND $filtroBusqueda"
);
$total->execute($parametros);

$consulta = $pdo->prepare(
    "SELECT c.codigo, c.area, c.estado, c.paciente_id,
            p.nombre, p.primer_apellido, p.segundo_apellido, p.folio
       FROM camillas c
       LEFT JOIN pacientes p ON p.id = c.paciente_id
                            AND p.institucion_id = c.institucion_id
                            AND p.eliminado_en IS NULL
      WHERE c.institucion_id = ? AND $filtroBusqueda
      ORDER BY FIELD(c.area, 'urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'), c.codigo
      LIMIT 20"
);
$consulta->execute($parametros);

$areas = [
    'urgencias' => 'Urgencias',
    'hospitalizacion' => 'Hospitalización',
    'cirugia' => 'Cirugía',
    'terapia_intensiva' => 'Terapia intensiva',
];
$estados = [
    'disponible' => 'Disponible',
    'ocupada' => 'Ocupada',
    'limpieza' => 'En limpieza',
    'mantenimiento' => 'En mantenimiento',
    'reservada' => 'Reservada',
];
$camillas = array_map(static function (array $c) use ($areas, $estados): array {
    $paciente = trim(implode(' ', array_filter([
        $c['nombre'],
        $c['primer_apellido'],
        $c['segundo_apellido'],
    ])));
    $detalle = array_filter([
        $areas[$c['area']] ?? ucfirst((string) $c['area']),
        $estados[$c['estado']] ?? ucfirst((string) $c['estado']),
        $paciente !== '' ? $paciente : null,
        $c['folio'] ? 'Expediente ' . preg_replace('/\D/', '', (string) $c['folio']) : null,
    ]);

    return [
        'nombre' => (string) $c['codigo'],
        'detalle' => implode(' · ', $detalle),
    ];
}, $consulta->fetchAll(PDO::FETCH_ASSOC));

echo json_encode(['total' => (int) $total->fetchColumn(), 'camillas' => $camillas], JSON_UNESCAPED_UNICODE);
