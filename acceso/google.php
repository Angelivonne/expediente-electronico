<?php
/**
 * Iniciar sesi?n con Google: redirige al consentimiento de Google.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';

if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    redirigir('/acceso/');
}

$estado = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $estado;

$parametros = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => URL_ORIGEN . URL_BASE . '/acceso/google_callback.php',
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $estado,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);
redirigir('https://accounts.google.com/o/oauth2/v2/auth?' . $parametros);
