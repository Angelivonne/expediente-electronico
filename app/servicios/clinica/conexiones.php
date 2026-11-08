<?php
/** Transporte permitido para el módulo de conexiones entre instituciones. */
function conexionesCanalProtegido(): bool
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    if ($https) {
        return true;
    }

    // En la instalación local, HTTP solo se acepta si el navegador y Apache se hablan por loopback.
    // Esto permite probar la aplicación en localhost sin abrir intercambio clínico por la red.
    $host = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));
    $cliente = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && in_array($cliente, ['127.0.0.1', '::1'], true);
}
