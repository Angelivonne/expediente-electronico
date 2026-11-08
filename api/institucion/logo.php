<?php
/**
 * Logo de la institución: aparece en el encabezado de las notas y en los documentos impresos.
 *   GET                                → la imagen (solo con sesión)
 *   POST multipart {logo}              → la cambia (permiso configurar)
 *   POST multipart {accion=quitar}     → la quita
 * Se guarda fuera de la carpeta pública (privado/logos) y se sirve con sesión.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

const TIPOS_LOGO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function responderLogo(int $codigo, array $datos): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$archivoActual = basename((string) config('logo_institucion'));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $archivo = CARPETA_LOGOS . '/' . $archivoActual;
    if ($archivoActual === '' || !is_file($archivo)) {
        http_response_code(404);
        exit;
    }
    session_write_close();
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($archivo));
    header('Content-Length: ' . filesize($archivo));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($archivo);
    exit;
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderLogo(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
exigirPermiso('configurar');
if (!dentroDelLimite('logo_institucion:usuario:' . (int) usuarioActual()['id'], 30, 600)) {
    responderLogo(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$borrarAnterior = function () use ($archivoActual): void {
    if ($archivoActual !== '' && preg_match('/^logo-\d+-[0-9a-f]{16}\.(jpg|png|webp)$/', $archivoActual)) {
        @unlink(CARPETA_LOGOS . '/' . $archivoActual);
    }
};

if (($_POST['accion'] ?? '') === 'quitar') {
    $borrarAnterior();
    guardarConfig('logo_institucion', null);
    registrarBitacora('instituciones', (int) institucionId(), 'actualizar', 'logo', $archivoActual ?: null, null, (int) institucionId());
    responderLogo(200, ['ok' => true]);
}

$archivo = $_FILES['logo'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    responderLogo(400, ['ok' => false, 'error' => 'No llegó la imagen.']);
}
if ($archivo['size'] > 2 * 1024 * 1024) {
    responderLogo(413, ['ok' => false, 'error' => 'El logo pesa más de 2 MB.']);
}
$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
$info = @getimagesize($archivo['tmp_name']);
if (!isset(TIPOS_LOGO[$tipo]) || !$info || $info[0] < 32 || $info[1] < 32) {
    responderLogo(415, ['ok' => false, 'error' => 'Usa un logo PNG, JPG o WebP de al menos 32 × 32 píxeles.']);
}
if (!is_dir(CARPETA_LOGOS)) {
    mkdir(CARPETA_LOGOS, 0750, true);
}
$nombre = 'logo-' . (int) institucionId() . '-' . bin2hex(random_bytes(8)) . '.' . TIPOS_LOGO[$tipo];
if (!guardarSubida($archivo['tmp_name'], CARPETA_LOGOS . '/' . $nombre)) {
    responderLogo(500, ['ok' => false, 'error' => 'No se pudo guardar el logo.']);
}
$borrarAnterior();
guardarConfig('logo_institucion', $nombre);
registrarBitacora('instituciones', (int) institucionId(), 'actualizar', 'logo', null, 'logo', (int) institucionId());
responderLogo(200, ['ok' => true, 'url' => URL_BASE . '/api/institucion/logo.php?v=' . substr(md5($nombre), 0, 8)]);
