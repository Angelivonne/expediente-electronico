<?php
/**
 * Dictado por voz. Dos modos:
 *   - texto: el navegador ya transcribió (Web Speech API) y aquí solo se extraen campos y medicamentos.
 *   - audio: se transcribe con el servicio local Whisper large-v3 (puerto 8766).
 * En ambos se devuelven los campos del registro y los medicamentos reconocidos del catálogo.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!puede('historial') && !puede('editar_paciente')) {
    responder(403, ['ok' => false, 'error' => 'Tu rol no puede dictar en el expediente.']);
}
if (!dentroDelLimite('dictado:usuario:' . usuarioActual()['id'], 900, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiadas solicitudes de dictado. Espera unos minutos.']);
}

/* ---------- MODO TEXTO: Web Speech API del navegador ya transcribió ----------
   El navegador reconoce la voz en tiempo real (parcial, palabra por palabra) y manda aquí
   SOLO el texto; PHP extrae los campos sin depender del servicio local de audio.
   Petición: POST JSON {"modo":"texto","texto":"..."} → {ok, texto, campos}. */
$entradaJson = json_decode(file_get_contents('php://input') ?: '', true);
if (is_array($entradaJson) && ($entradaJson['modo'] ?? '') === 'texto') {
    $textoDictado = trim((string) ($entradaJson['texto'] ?? ''));
    if ($textoDictado === '') {
        responder(400, ['ok' => false, 'error' => 'No llegó el texto dictado.']);
    }
    // Una consulta larga no debe perder ni el inicio (nombre) ni el final (últimos campos).
    if (mb_strlen($textoDictado) > 60000) {
        $textoDictado = mb_substr($textoDictado, 0, 10000) . ' ' . mb_substr($textoDictado, -50000);
    }
    require_once __DIR__ . '/../../app/servicios/clinica/dictado.php';
    require_once __DIR__ . '/../../app/servicios/clinica/receta_dictado.php';
    // El STT escribe los fármacos como suenan: se corrigen contra el catálogo antes de
    // mostrar el texto y de extraer los campos.
    $textoDictado = recetaDictadoCorregirTexto($textoDictado);
    $campos = camposDeDictado($textoDictado);
    $medicamentos = medicamentosDeDictado($textoDictado);
    responder(200, [
        'ok'    => true,
        'texto' => $textoDictado,
        'campos' => $campos,
        'medicamentos' => $medicamentos,
        'avisos' => dictadoAvisos($textoDictado, $campos, $medicamentos),
        'duracion' => null,
    ]);
}

$audio = $_FILES['audio'] ?? null;
if (!$audio || $audio['error'] !== UPLOAD_ERR_OK || $audio['size'] === 0) {
    responder(400, ['ok' => false, 'error' => 'No llegó el audio.']);
}
if ($audio['size'] > 25 * 1024 * 1024) {
    responder(413, ['ok' => false, 'error' => 'La grabación es demasiado larga.']);
}
$tipo = (new finfo(FILEINFO_MIME_TYPE))->file($audio['tmp_name']);
if (!preg_match('#^(audio|video)/#', $tipo)) {
    responder(415, ['ok' => false, 'error' => 'El archivo no es audio.']);
}

// Guardar audio temporalmente (solo extensiones de audio conocidas)
$extension = strtolower(pathinfo((string) $audio['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['webm', 'wav', 'mp3', 'ogg', 'oga', 'm4a', 'mp4', 'caf', 'flac', 'aac'], true)) {
    $extension = 'webm';
}
$archivoTemporal = sys_get_temp_dir() . '/dictado_' . bin2hex(random_bytes(8)) . '.' . $extension;
if (!move_uploaded_file($audio['tmp_name'], $archivoTemporal)) {
    responder(500, ['ok' => false, 'error' => 'Error al guardar audio temporal.']);
}

// Llamar al servicio STT local (modelo cargado en memoria, más rápido)
$sttUrl = rtrim(getenv('STT_URL') ?: 'http://127.0.0.1:8766', '/') . '/';
$ch = curl_init($sttUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => file_get_contents($archivoTemporal),
    CURLOPT_HTTPHEADER => ['Content-Type: audio/webm'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 120,
]);
$respuesta = curl_exec($ch);
$codigoHttp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

@unlink($archivoTemporal);

$resultado = json_decode($respuesta, true);

if (!$resultado || !($resultado['ok'] ?? false)) {
    $error = is_array($resultado) ? (string) ($resultado['error'] ?? '') : '';
    if ($error === '') {
        $error = 'El transcriptor local no respondió. Abre iniciar_servicio_stt.bat y deja la ventana abierta.';
    }
    responder(500, ['ok' => false, 'error' => 'Error de transcripción: ' . $error]);
}

$textoTranscrito = (string) ($resultado['texto'] ?? '');
require_once __DIR__ . '/../../app/servicios/clinica/dictado.php';
require_once __DIR__ . '/../../app/servicios/clinica/receta_dictado.php';
$textoTranscrito = recetaDictadoCorregirTexto($textoTranscrito);
// PHP es el extractor autoritativo de los campos clínicos. Del STT solo se conservan
// metadatos de conversación; sus reglas antiguas no deben sobreescribir la extracción.
$clavesContexto = [
    'seccion', 'seccion_confianza', 'secciones_sub',
    'hablante_rol', 'hablante_genero', 'hablante_confianza',
    'hablante_se_autoidentifica', 'hablante_mencionado_por_tercero',
    'contexto_tipo', 'contexto_direccion', 'contexto_tiempo_verbal',
    'contexto_es_pregunta', 'contexto_es_respuesta', 'contexto_es_instruccion',
    'contexto_es_diagnostico', 'contexto_es_prescripcion', 'contexto_urgencia',
];
$camposVoz = (array) ($resultado['campos'] ?? []);
$metadatosConversacion = array_intersect_key($camposVoz, array_flip($clavesContexto));
$campos = array_merge($metadatosConversacion, camposDeDictado($textoTranscrito));
$medicamentos = medicamentosDeDictado($textoTranscrito);

responder(200, [
    'ok'    => true,
    'texto' => $textoTranscrito,
    'campos' => $campos,
    'medicamentos' => $medicamentos,
    'avisos' => dictadoAvisos($textoTranscrito, $campos, $medicamentos),
    'duracion' => null,
]);

