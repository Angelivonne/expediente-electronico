<?php
/**
 * Dictado en vivo (navegadores sin Web Speech API, como Zen/Firefox).
 * Recibe un trozo WAV corto (16 kHz, mono, 16 bits) mientras el médico habla y devuelve
 * solo el texto transcrito por el servicio local Whisper (puerto 8766). Los campos y los
 * medicamentos se extraen al final con dictado.php en modo texto, no aquí: esta ruta debe
 * responder rápido para que las palabras aparezcan en pantalla mientras se dicta.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responderVivo(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderVivo(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderVivo(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('historial') && !puede('editar_paciente')) {
    responderVivo(403, ['ok' => false, 'error' => 'Tu rol no puede dictar en el expediente.']);
}
// Cada trozo es una petición: un dictado largo de un trozo por minuto cabe de sobra.
if (!dentroDelLimite('dictado-vivo:usuario:' . usuarioActual()['id'], 1800, 600)) {
    responderVivo(429, ['ok' => false, 'error' => 'Demasiadas solicitudes de dictado. Espera unos minutos.']);
}

$audio = file_get_contents('php://input');
if ($audio === false || strlen($audio) < 100) {
    responderVivo(400, ['ok' => false, 'error' => 'No llegó el audio.']);
}
if (strlen($audio) > 4 * 1024 * 1024) {
    responderVivo(413, ['ok' => false, 'error' => 'El trozo de audio es demasiado grande.']);
}
if (strncmp($audio, 'RIFF', 4) !== 0) {
    responderVivo(415, ['ok' => false, 'error' => 'Se esperaba audio WAV.']);
}

$ch = curl_init('http://127.0.0.1:8766/en-vivo');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $audio,
    CURLOPT_HTTPHEADER => ['Content-Type: audio/wav'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 30,
]);
$respuesta = curl_exec($ch);
curl_close($ch);

$resultado = json_decode((string) $respuesta, true);
if (!is_array($resultado) || !($resultado['ok'] ?? false)) {
    $error = is_array($resultado) ? ($resultado['error'] ?? '') : '';
    responderVivo(502, ['ok' => false, 'error' => $error ?: 'El servicio de transcripción de voz no está disponible en este momento.']);
}

responderVivo(200, ['ok' => true, 'texto' => trim((string) ($resultado['texto'] ?? ''))]);

