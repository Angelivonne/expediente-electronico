<?php
/** Descarga controlada de convenios y consentimientos resguardados fuera del acceso público. */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/conexiones.php';
exigirPermiso('ver_expediente');
$tipo = (string) ($_GET['tipo'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$institucion = (int) institucionId();
$archivo = null;
$canalProtegido = conexionesCanalProtegido();
if ($tipo === 'convenio' && puede('configurar')) {
    if (!$canalProtegido) { http_response_code(403); exit('Los documentos requieren HTTPS o acceso local desde localhost en esta computadora.'); }
    $c = db()->prepare('SELECT acuerdo_archivo AS ruta, acuerdo_nombre AS nombre FROM conexiones_clinicas WHERE id = ? AND (institucion_a_id = ? OR institucion_b_id = ?)');
    $c->execute([$id, $institucion, $institucion]); $archivo = $c->fetch() ?: null;
} elseif ($tipo === 'consentimiento') {
    exigirPermiso('historial');
    if (!$canalProtegido) { http_response_code(403); exit('La autorización requiere HTTPS o acceso local desde localhost en esta computadora.'); }
    $c = db()->prepare('SELECT consentimiento_archivo AS ruta, consentimiento_nombre AS nombre FROM conexiones_autorizaciones
                         WHERE id = ? AND (institucion_origen_id = ? OR (institucion_destino_id = ? AND revocado_en IS NULL AND vence_en >= NOW()))');
    $c->execute([$id, $institucion, $institucion]); $archivo = $c->fetch() ?: null;
}
if (!$archivo || !preg_match('~^privado/conexiones/[a-f0-9]{40}\.pdf$~', (string) $archivo['ruta'])) {
    http_response_code(404); exit('El documento no está disponible para esta institución.');
}
$ruta = dirname(__DIR__) . '/' . $archivo['ruta'];
if (!is_file($ruta)) { http_response_code(404); exit('El documento resguardado no está disponible.'); }
$nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $archivo['nombre']));
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: attachment; filename="' . ($nombre ?: 'documento-firmado.pdf') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
readfile($ruta);
