<?php
/**
 * Visualizador e impresión formal de documentos y análisis clínicos generados por la IA.
 * Formato formal: Arial 12pt, texto 100% negro (#000000), márgenes de 2.5 cm,
 * sin colores innecesarios, compatible con NOM-004-SSA3-2012 y NOM-024-SSA3-2012.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
exigirPermiso('ver_expediente');

$doc = basename(trim($_GET['doc'] ?? ''));
if ($doc === '' || !preg_match('/^[a-zA-Z0-9_\-]+\.html$/', $doc)) {
    mensajeFlash('error', 'Documento no válido.');
    redirigir('/pacientes/');
}

$rutaDoc = __DIR__ . '/../privado/documentos/' . $doc;
if (!file_exists($rutaDoc)) {
    mensajeFlash('error', 'El documento solicitado no existe o expiró.');
    redirigir('/pacientes/');
}

$contenido = file_get_contents($rutaDoc);
echo $contenido;
exit;
