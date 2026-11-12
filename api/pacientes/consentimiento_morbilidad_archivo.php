<?php

require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/clinica/consentimiento_morbilidad.php';

exigirPermiso('consentimiento_morbilidad');
exigirPermiso('ver_expediente');
$eventoId = (int) ($_GET['evento'] ?? 0);
$consulta = db()->prepare(
    'SELECT evidencia_archivo, evidencia_nombre, evidencia_mime, evidencia_sha256
     FROM consentimientos_morbilidad_eventos
     WHERE id = ? AND institucion_id = ? AND evidencia_archivo IS NOT NULL'
);
$consulta->execute([$eventoId, institucionId()]);
$evidencia = $consulta->fetch(PDO::FETCH_ASSOC);
if (!$evidencia) {
    http_response_code(404);
    exit('No se encontró la evidencia solicitada.');
}

$ruta = rutaEvidenciaConsentimientoMorbilidad((int) institucionId(), (string) $evidencia['evidencia_archivo']);
if (!$ruta || !hash_equals((string) $evidencia['evidencia_sha256'], (string) hash_file('sha256', $ruta))) {
    error_log('[consentimiento_morbilidad] Evidencia ausente o con huella distinta para el evento ' . $eventoId);
    http_response_code(409);
    exit('La evidencia no está disponible o su integridad no pudo verificarse.');
}

registrarBitacora('consentimientos_morbilidad', $eventoId, 'consultar', 'evidencia');
header('Content-Type: ' . $evidencia['evidencia_mime']);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode((string) $evidencia['evidencia_nombre']));
header('Content-Length: ' . (string) filesize($ruta));
header('Cache-Control: no-store');
readfile($ruta);
exit;
