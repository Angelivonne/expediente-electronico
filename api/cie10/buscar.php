<?php
/**
 * Búsqueda de diagnósticos CIE-10 para la paleta de la lupa.
 * GET q (texto). Responde JSON {total, diagnosticos: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));

$where      = 'institucion_id = ? AND activo = 1';
$parametros = [institucionId()];
$terminos   = terminosBusqueda($busqueda);
if ($terminos) {
    [$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda([
        'codigo',
        "REPLACE(codigo, '.', '')",
        'descripcion',
        'capitulo',
    ], $busqueda);
    $where .= " AND $filtroBusqueda";
    $parametros = array_merge($parametros, $parametrosBusqueda);
}

$total = db()->prepare("SELECT COUNT(*) FROM cie10 WHERE $where");
$total->execute($parametros);

$consulta = db()->prepare(
    "SELECT id, codigo, descripcion, capitulo
       FROM cie10 WHERE $where ORDER BY codigo LIMIT 20"
);
$consulta->execute($parametros);

$diagnosticos = array_map(function (array $d): array {
    return [
        'id'     => (int) $d['id'],
        'nombre' => trim("{$d['codigo']} · {$d['descripcion']}"),
        'codigo' => (string) $d['codigo'],
        'descripcion' => (string) $d['descripcion'],
        'capitulo' => (string) ($d['capitulo'] ?? ''),
        'detalle'=> trim("Código: {$d['codigo']}" . ($d['capitulo'] ? ' · Capítulo: ' . $d['capitulo'] : '')),
        'url'    => URL_BASE . '/cie10/nuevo.php?id=' . (int) $d['id'],
    ];
}, $consulta->fetchAll());

echo json_encode(['total' => (int) $total->fetchColumn(), 'diagnosticos' => $diagnosticos], JSON_UNESCAPED_UNICODE);
