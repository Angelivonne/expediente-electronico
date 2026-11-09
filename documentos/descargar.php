<?php
/**
 * Descarga oficial de documentos del proyecto COA-SI-2135 (FEMECI)
 * Permite descargar el Anexo 11 y el Registro Completo de 200 Prompts.
 */
$doc = $_GET['doc'] ?? 'registro_completo';

$archivos = [
    'registro_completo' => [
        'ruta' => __DIR__ . '/Registro_completo_prompts_IA_COA-SI-2135.pdf',
        'nombre' => 'Registro_completo_prompts_IA_COA-SI-2135.pdf',
    ],
    'anexo_impreso' => [
        'ruta' => __DIR__ . '/Anexo_IA_impreso_COA-SI-2135.pdf',
        'nombre' => 'Anexo_IA_impreso_COA-SI-2135.pdf',
    ],
];

if (!isset($archivos[$doc])) {
    http_response_code(404);
    die('Documento no encontrado.');
}

$archivo = $archivos[$doc];
if (!file_exists($archivo['ruta'])) {
    // Si aún no está en la carpeta de documentos, buscar en Descargas como respaldo
    $rutaRespaldo = 'C:/Users/abelM/Downloads/' . $archivo['nombre'];
    if (file_exists($rutaRespaldo)) {
        $archivo['ruta'] = $rutaRespaldo;
    } else {
        http_response_code(404);
        die('El archivo aún no ha sido generado.');
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $archivo['nombre'] . '"');
header('Content-Length: ' . filesize($archivo['ruta']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($archivo['ruta']);
exit;
