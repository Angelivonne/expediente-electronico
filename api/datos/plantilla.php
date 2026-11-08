<?php
/** Descarga plantillas CSV seguras para los destinos del motor de conversión. */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/conversion/comun.php';
require_once __DIR__ . '/../../app/servicios/conversion/catalogos.php';

function errorPlantilla(int $estado, string $mensaje): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorPlantilla(405, 'Método no permitido.');
}
exigirPermiso('importar_exportar');

$destino = (string) ($_GET['destino'] ?? '');
$nombre = 'plantilla';
if ($destino === 'pacientes') {
    if (!puede('editar_paciente')) {
        errorPlantilla(403, 'Tu rol no permite importar pacientes.');
    }
    $encabezados = array_keys(CAMPOS_IMPORTACION);
    $nombre = 'plantilla-pacientes';
} elseif ($destino === 'pagina') {
    $encabezados = ['Nombre', 'Columna 2'];
    $nombre = 'plantilla-base-de-datos';
} elseif ($destino === 'catalogo') {
    if (!puede('configurar')) {
        errorPlantilla(403, 'Tu rol no permite importar catálogos del sistema.');
    }
    $clave = (string) ($_GET['catalogo'] ?? '');
    $catalogo = detalleCatalogoImportacion($clave);
    if (!$catalogo) {
        errorPlantilla(404, 'Ese catálogo no admite importación directa o ya no está disponible.');
    }
    $encabezados = array_map(static fn ($campo) => $campo['campo'], array_values($catalogo['campos']));
    $nombre = 'plantilla-' . $clave;
} else {
    errorPlantilla(400, 'Elige un destino válido.');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '.csv"');
header('Cache-Control: private, no-store, max-age=0');
echo "\xEF\xBB\xBF";
$salida = fopen('php://output', 'wb');
fputcsv($salida, $encabezados, ',', '"', '');
fclose($salida);
exit;
