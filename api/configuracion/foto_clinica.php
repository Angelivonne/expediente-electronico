<?php
/**
 * Sube la foto de la clínica que se muestra en la barra lateral (solo administrador).
 * Se valida por su contenido real (no por la extensión) y se guarda con un nombre aleatorio.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('configurar')) {
    responder(403, ['ok' => false, 'error' => 'Solo el administrador puede cambiar la foto.']);
}

$archivo = $_FILES['foto'] ?? null;
if (!$archivo || $archivo['error'] !== UPLOAD_ERR_OK) {
    responder(400, ['ok' => false, 'error' => 'No llegó la imagen.']);
}
if ($archivo['size'] > 8 * 1024 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La imagen pesa más de 8 MB.']);
}

$tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$tipo  = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
$info  = @getimagesize($archivo['tmp_name']);
if (!isset($tipos[$tipo]) || !$info || $info[0] < 200 || $info[1] < 100) {
    responder(415, ['ok' => false, 'error' => 'Usa una imagen JPG, PNG o WebP de al menos 200 × 100 píxeles.']);
}

$carpetaPublica = __DIR__ . '/../../assets/subidas';
$carpetaPrivada = __DIR__ . '/../../privado/subidas';
if (!is_dir($carpetaPublica)) { @mkdir($carpetaPublica, 0777, true); }
if (!is_dir($carpetaPrivada)) { @mkdir($carpetaPrivada, 0777, true); }

$nombre  = 'clinica-' . bin2hex(random_bytes(8)) . '.' . $tipos[$tipo];
$destinoPublico = "$carpetaPublica/$nombre";
$destinoPrivado = "$carpetaPrivada/$nombre";

// Guardar en volumen persistente privado y en carpeta pública
$okPrivado = copy($archivo['tmp_name'], $destinoPrivado);
$okPublico = guardarSubida($archivo['tmp_name'], $destinoPublico);

if (!$okPublico && $okPrivado) {
    @copy($destinoPrivado, $destinoPublico);
    $okPublico = file_exists($destinoPublico);
}

if (!$okPublico && !$okPrivado) {
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar la imagen.']);
}

// Borra la foto anterior (en ambas carpetas)
$anterior = config('foto_clinica');
if ($anterior && preg_match('#^assets/subidas/(clinica-[0-9a-f]{16}\.(jpg|png|webp))$#', $anterior, $m)) {
    @unlink(__DIR__ . '/../../' . $anterior);
    @unlink("$carpetaPrivada/" . $m[1]);
}

guardarConfig('foto_clinica', "assets/subidas/$nombre");
registrarBitacora('configuracion', 0, 'actualizar', 'foto_clinica', $anterior, "assets/subidas/$nombre");

responder(200, ['ok' => true, 'url' => URL_BASE . "/assets/subidas/$nombre"]);
