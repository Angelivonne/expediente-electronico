<?php
/**
 * Búsqueda de medicamentos para la paleta de la lupa.
 * GET q (texto). Responde JSON {total, medicamentos: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));

$where      = 'institucion_id = :inst AND activo = 1';
$parametros = ['inst' => institucionId()];
if ($busqueda !== '') {
    // Busca cada palabra por separado: ignora mayúsculas, comas, puntos, barras y espacios extra.
    // Separa también número y unidad para que "850mg" encuentre "850 mg".
    $textoTokens = mb_strtolower(preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $busqueda), 'UTF-8');
    $terminos = preg_split('/[^\p{L}\p{N}]+/u', $textoTokens, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terminos = array_slice(array_values(array_unique($terminos)), 0, 8);
    $camposBusqueda = ['clave', 'clave_compendio', 'nombre', 'forma_farmaceutica', 'presentacion', 'concentracion', 'via'];
    if (!$terminos) {
        $where .= ' AND 1 = 0';
    }
    foreach ($terminos as $i => $termino) {
        $coincidencias = [];
        $patron = patronContiene($termino);
        foreach ($camposBusqueda as $j => $columna) {
            $parametro = "med_{$i}_{$j}";
            $coincidencias[] = "$columna LIKE :$parametro";
            $parametros[$parametro] = $patron;
        }
        $where .= ' AND (' . implode(' OR ', $coincidencias) . ')';
    }
}

$total = db()->prepare("SELECT COUNT(*) FROM medicamentos WHERE $where");
$total->execute($parametros);

$consulta = db()->prepare(
    "SELECT id, clave, clave_compendio, nombre, forma_farmaceutica, presentacion, concentracion, via, laboratorio
       FROM medicamentos WHERE $where ORDER BY nombre, clave LIMIT 20"
);
$consulta->execute($parametros);

$medicamentos = array_map(function (array $m): array {
    $nombre = trim((string) ($m['nombre'] ?? ''));
    $claveCompendio = trim((string) ($m['clave_compendio'] ?? ''));
    if ($claveCompendio !== '') {
        $textoNombre = $claveCompendio;
        if ($nombre !== '') {
            $textoNombre = $nombre . ' · ' . $claveCompendio;
        }
    } else {
        $textoNombre = $nombre;
    }
    return [
        'id'     => (int) $m['id'],
        'nombre' => $textoNombre,
        'principio' => $nombre,
        'clave_compendio' => $claveCompendio,
        'presentacion' => (string) ($m['presentacion'] ?? ''),
        'forma' => (string) ($m['forma_farmaceutica'] ?? ''),
        'concentracion' => (string) ($m['concentracion'] ?? ''),
        'via' => (string) ($m['via'] ?? ''),
        'grupo' => (string) ($m['grupo_terapeutico'] ?? ''),
        'descripcion_compendio' => (string) ($m['descripcion_compendio'] ?? ''),
        'detalle'=> trim("Clave: {$m['clave']}" . ($m['clave_compendio'] ? ' · Compendio: ' . $m['clave_compendio'] : '') . ' · ' . ($m['via'] ?? '')),
        'url'    => URL_BASE . '/medicamentos/nuevo.php?id=' . (int) $m['id'],
    ];
}, $consulta->fetchAll());

echo json_encode(['total' => (int) $total->fetchColumn(), 'medicamentos' => $medicamentos], JSON_UNESCAPED_UNICODE);
