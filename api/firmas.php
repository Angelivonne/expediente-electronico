<?php
/**
 * Firmas digitales de una nota (sello NOM-024: cédula + hora + huella SHA-256).
 *   GET:  api/firmas.php?pagina=id            estado de las firmas de la nota
 *   POST {accion: firmar,    pagina: id}      sella la versión actual de la nota
 *   POST {accion: revocar,   firma: id, motivo}  rompe el sello (quien firmó o un administrador)
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../app/servicios/espacio/firmas.php';

header('Content-Type: application/json; charset=UTF-8');

function responderFirma(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

/** Nota del usuario con nivel 'editar' (o 'ver' para solo consultar), o error. */
$notaOError = function (int $id, string $nivel = 'editar') use ($usuarioId): array {
    $pagina = cargarPagina($id, $usuarioId);
    if (!$pagina || $pagina['en_papelera'] || $pagina['tipo'] !== 'nota') {
        responderFirma(404, ['ok' => false, 'error' => 'La nota no existe o no tienes acceso.']);
    }
    if (!nivelAlcanza($pagina, $nivel)) {
        responderFirma(403, ['ok' => false, 'error' => 'No tienes permiso para hacer esto en esta nota.']);
    }
    return $pagina;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pagina = $notaOError((int) ($_GET['pagina'] ?? 0), 'ver');
    responderFirma(200, ['ok' => true] + estadoFirmas($pagina, $usuarioId));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderFirma(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('firmas:usuario:' . $usuarioId, 60, 600)) {
    responderFirma(429, ['ok' => false, 'error' => 'Demasiadas firmas seguidas. Espera un momento.']);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$accion  = (string) ($entrada['accion'] ?? '');

switch ($accion) {
    case 'firmar':
        $pagina = $notaOError((int) ($entrada['pagina'] ?? 0));
        if (notaFirmada((int) $pagina['id'])) {
            responderFirma(409, ['ok' => false, 'error' => 'Esta nota ya está firmada.']);
        }
        $r = firmarPagina($pagina, $usuarioId);
        if (!$r['ok']) {
            responderFirma(403, ['ok' => false, 'error' => $r['error']]);
        }
        responderFirma(200, ['ok' => true] + estadoFirmas($pagina, $usuarioId));

    case 'revocar':
        $pagina = $notaOError((int) ($entrada['pagina'] ?? 0));
        $r = revocarFirma((int) ($entrada['firma'] ?? 0), $usuarioId, (string) ($entrada['motivo'] ?? ''));
        if (!$r['ok']) {
            responderFirma(403, ['ok' => false, 'error' => $r['error']]);
        }
        responderFirma(200, ['ok' => true] + estadoFirmas($pagina, $usuarioId));

    default:
        responderFirma(400, ['ok' => false, 'error' => 'Acción no válida.']);
}
