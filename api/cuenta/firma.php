<?php
/**
 * Firma dibujada (canvas) de quien tiene la sesión.
 * El navegador manda la firma como dataURL PNG (se dibuja con el dedo, mouse o lápiz);
 * aquí se decodifica, se valida por su contenido real y se guarda con nombre aleatorio
 * en assets/subidas/firmas/. Una firma vacía (solo fondo transparente) se rechaza.
 *
 * POST JSON {"accion":"guardar","imagen":"data:image/png;base64,…"} → {ok, url}
 * POST JSON {"accion":"borrar"}                                     → {ok}
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
$usuarioId = (int) usuarioActual()['id'];
if (!dentroDelLimite('firma:usuario:' . $usuarioId, 40, 3600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios de firma. Espera un rato.']);
}

$entrada = json_decode(file_get_contents('php://input') ?: '', true);
$accion  = is_array($entrada) ? (string) ($entrada['accion'] ?? '') : '';

$anterior = db()->prepare('SELECT firma FROM usuarios WHERE id = ?');
$anterior->execute([$usuarioId]);
$rutaAnterior = (string) $anterior->fetchColumn();

$borrarArchivo = function () use ($rutaAnterior): void {
    if ($rutaAnterior !== '' && preg_match('#^assets/subidas/firmas/firma-[0-9a-f]{16}\.png$#', $rutaAnterior)) {
        @unlink(__DIR__ . '/../../' . $rutaAnterior);
    }
};

if ($accion === 'borrar') {
    $borrarArchivo();
    db()->prepare('UPDATE usuarios SET firma = NULL WHERE id = ?')->execute([$usuarioId]);
    registrarBitacora('usuarios', $usuarioId, 'actualizar', 'firma', $rutaAnterior ?: null, null);
    responder(200, ['ok' => true]);
}

if ($accion !== 'guardar') {
    responder(400, ['ok' => false, 'error' => 'Acción desconocida.']);
}

$dataUrl = (string) ($entrada['imagen'] ?? '');
if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
    responder(415, ['ok' => false, 'error' => 'La firma debe ser una imagen PNG.']);
}
$binario = (string) base64_decode(str_replace(' ', '+', $m[1]), true);
if ($binario === '' || strlen($binario) > 300 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La firma es demasiado grande.']);
}

// Validación por contenido real: debe ser un PNG con transparencia (canvas) y tamaño razonable
$info = @getimagesizefromstring($binario);
if (!$info || ($info[2] !== IMAGETYPE_PNG) || $info[0] < 100 || $info[1] < 40) {
    responder(415, ['ok' => false, 'error' => 'La imagen de la firma no es válida.']);
}

// Una firma sin tinta (todo transparente o todo blanco) no se acepta
$imagen = @imagecreatefromstring($binario);
if (!$imagen) {
    responder(415, ['ok' => false, 'error' => 'La imagen de la firma no es válida.']);
}
$conTinta = false;
$ancho = imagesx($imagen);
$alto  = imagesy($imagen);
for ($y = 0; $y < $alto && !$conTinta; $y += 3) {
    for ($x = 0; $x < $ancho; $x += 3) {
        $rgba = imagecolorat($imagen, $x, $y);
        $alfa = ($rgba >> 24) & 0x7F;           // 127 = totalmente transparente
        if ($alfa < 100) {
            $rojo = ($rgba >> 16) & 0xFF;
            $verde = ($rgba >> 8) & 0xFF;
            $azul = $rgba & 0xFF;
            if ($rojo < 230 || $verde < 230 || $azul < 230) {   // algo oscuro = trazo
                $conTinta = true;
                break 2;
            }
        }
    }
}
imagedestroy($imagen);
if (!$conTinta) {
    responder(400, ['ok' => false, 'error' => 'La firma está vacía: dibújala antes de guardarla.']);
}

$carpeta = __DIR__ . '/../../assets/subidas/firmas';
if (!is_dir($carpeta)) {
    @mkdir($carpeta, 0775, true);
}
$nombre = 'firma-' . bin2hex(random_bytes(8)) . '.png';
if (!escribirArchivoAtomico("$carpeta/$nombre", $binario)) {
    responder(500, ['ok' => false, 'error' => 'No se pudo guardar la firma.']);
}

$borrarArchivo();
db()->prepare('UPDATE usuarios SET firma = ? WHERE id = ?')->execute(["assets/subidas/firmas/$nombre", $usuarioId]);
registrarBitacora('usuarios', $usuarioId, 'actualizar', 'firma', $rutaAnterior ?: null, "assets/subidas/firmas/$nombre");

responder(200, ['ok' => true, 'url' => URL_BASE . "/assets/subidas/firmas/$nombre"]);
