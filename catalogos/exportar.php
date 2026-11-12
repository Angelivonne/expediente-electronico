<?php
/** Exporta las columnas reales y reimportables de un catálogo a CSV UTF-8. */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/../app/servicios/conversion/csv.php';

$clave = (string) ($_GET['tabla'] ?? $_GET['entidad'] ?? '');
$catalogo = catalogoSistema($clave);
if (!$catalogo) {
    http_response_code(404);
    exit('Ese catálogo no existe.');
}
exigirPermiso('ver_expediente');

$limiteExportaciones = 'exportar:' . (int) usuarioActual()['id'];
if (limiteSuperado($limiteExportaciones, 30)) {
    http_response_code(429);
    exit('Demasiadas exportaciones seguidas. Espera unos minutos.');
}
contarIntento($limiteExportaciones, 600);

$tabla = (string) $catalogo['tabla'];
try {
    $columnas = catalogoColumnasCsv($tabla);
} catch (Throwable $errorEsquema) {
    http_response_code(404);
    exit('La tabla de este catálogo no está disponible.');
}
$campos = catalogoCamposCsv($columnas);
if (!$campos || !isset($columnas['id'])) {
    http_response_code(500);
    exit('El catálogo no tiene columnas exportables.');
}

$busqueda = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$campo = trim((string) ($_GET['campo'] ?? ''));
$valor = trim((string) ($_GET['valor'] ?? ''));
$filtro = ($campo !== '' && $valor !== '') ? [$campo => $valor] : [];
$orden = trim((string) ($_GET['orden'] ?? ''));
$direccion = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$opciones = ['q' => $busqueda, 'filtro' => $filtro, 'orden' => $orden, 'dir' => $direccion, 'limite' => 500, 'pagina' => 1];
$primeraPagina = motorListar($clave, $opciones);
if ((int) $primeraPagina['total'] > 20000) {
    http_response_code(413);
    exit('La exportación supera 20,000 registros. Filtra la tabla para descargar un archivo más pequeño.');
}

$nombreArchivo = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower((string) $catalogo['titulo'])) . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: no-store');
header('X-Registros: ' . (int) $primeraPagina['total']);

$salida = fopen('php://output', 'w');
fwrite($salida, "\xEF\xBB\xBF");
fputcsv($salida, $campos);

$pdo = db();
$totalPaginas = (int) ceil((int) $primeraPagina['total'] / 500);
for ($pagina = 1; $pagina <= $totalPaginas; $pagina++) {
    $opciones['pagina'] = $pagina;
    $lista = $pagina === 1 ? $primeraPagina : motorListar($clave, $opciones);
    $ids = array_values(array_filter(array_map(static fn (array $fila): int => (int) ($fila['id'] ?? 0), $lista['filas'])));
    if (!$ids) {
        continue;
    }

    $marcadores = implode(', ', array_fill(0, count($ids), '?'));
    $seleccion = '`id`, ' . implode(', ', array_map(static fn (string $campo): string => '`' . $campo . '`', $campos));
    $sql = 'SELECT ' . $seleccion . ' FROM `' . $tabla . '` WHERE `id` IN (' . $marcadores . ')';
    $parametros = $ids;
    if (!empty($catalogo['institucion']) && isset($columnas['institucion_id'])) {
        $sql .= ' AND `institucion_id` = ?';
        $parametros[] = (int) institucionId();
    }
    $sql .= ' ORDER BY FIELD(`id`, ' . $marcadores . ')';
    $consulta = $pdo->prepare($sql);
    $consulta->execute(array_merge($parametros, $ids));
    foreach ($consulta as $fila) {
        fputcsv($salida, array_map(static fn (string $campo): string => convProtegerFormula((string) ($fila[$campo] ?? '')), $campos));
    }
}
fclose($salida);
