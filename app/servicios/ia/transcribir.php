<?php
/**
 * Transcripción de voz a texto usando Parakeet TDT 0.6B v3.
 * Llama al script Python transcribir_parakeet.py que usa transcribe-cpp.
 */

require_once __DIR__ . '/../../../config/stt.php';

header('Content-Type: application/json; charset=utf-8');

// Solo aceptar POST con archivo de audio
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No se recibió archivo de audio']);
    exit;
}

$archivoTemporal = $_FILES['audio']['tmp_name'];
$tamano = filesize($archivoTemporal);

// Validar tamaño (max 10 MB)
if ($tamano > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'Archivo demasiado grande (max 10 MB)']);
    exit;
}

// Validar tipo MIME
$tipoMime = mime_content_type($archivoTemporal);
$tiposPermitidos = ['audio/webm', 'audio/ogg', 'audio/wav', 'audio/mpeg', 'audio/mp4', 'audio/x-wav'];
if (!in_array($tipoMime, $tiposPermitidos)) {
    echo json_encode(['ok' => false, 'error' => 'Tipo de archivo no permitido: ' . $tipoMime]);
    exit;
}

// Mover a directorio temporal con extensión correcta
$extension = pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION) ?: 'webm';
$archivoDestino = sys_get_temp_dir() . '/stt_' . uniqid() . '.' . $extension;
if (!move_uploaded_file($archivoTemporal, $archivoDestino)) {
    echo json_encode(['ok' => false, 'error' => 'Error al guardar archivo temporal']);
    exit;
}

// Ejecutar Python
$pythonCmd = 'python';
$scriptPath = __DIR__ . '/transcribir_parakeet.py';
$comando = sprintf(
    '%s %s %s 2>&1',
    escapeshellarg($pythonCmd),
    escapeshellarg($scriptPath),
    escapeshellarg($archivoDestino)
);

exec($comando, $salida, $codigoSalida);

// Limpiar archivo temporal
@unlink($archivoDestino);

$resultado = json_decode(implode("\n", $salida), true);

if (!$resultado) {
    echo json_encode(['ok' => false, 'error' => 'Error al procesar transcripción: ' . implode("\n", $salida)]);
    exit;
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
