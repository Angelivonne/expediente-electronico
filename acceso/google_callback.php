<?php
/**
 * Callback de Google OAuth: intercambia el c?digo por un token, obtiene el perfil
 * y crea o inicia sesi?n con la cuenta correspondiente.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/acceso.php';

if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    redirigir('/acceso/');
}

// Verificar state (protecci?n CSRF)
$state = (string) ($_GET['state'] ?? '');
if ($state === '' || !hash_equals((string) ($_SESSION['google_oauth_state'] ?? ''), $state)) {
    unset($_SESSION['google_oauth_state']);
    redirigir('/acceso/?error=estado');
}
unset($_SESSION['google_oauth_state']);

// Error de Google
if (isset($_GET['error'])) {
    redirigir('/acceso/?error=google');
}

$codigo = (string) ($_GET['code'] ?? '');
if ($codigo === '' || !function_exists('curl_init')) {
    redirigir('/acceso/?error=codigo');
}

// Intercambiar c?digo por token
$curl = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($curl, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $codigo,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => URL_ORIGEN . URL_BASE . '/acceso/google_callback.php',
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$cuerpo = curl_exec($curl);
$error  = curl_errno($curl);
$codigoHttp = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($cuerpo === false || $error || $codigoHttp !== 200) {
    redirigir('/acceso/?error=token');
}
$token = json_decode((string) $cuerpo, true);
if (!is_array($token) || empty($token['access_token'])) {
    redirigir('/acceso/?error=token');
}

// Obtener perfil del usuario
$curl = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($curl, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$cuerpo = curl_exec($curl);
$error  = curl_errno($curl);
$codigoHttp = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($cuerpo === false || $error || $codigoHttp !== 200) {
    redirigir('/acceso/?error=perfil');
}
$perfil = json_decode((string) $cuerpo, true);
if (!is_array($perfil) || empty($perfil['email'])) {
    redirigir('/acceso/?error=perfil');
}

$correo = mb_strtolower(trim((string) $perfil['email']));
$nombre = trim((string) ($perfil['name'] ?? $perfil['given_name'] ?? ''));
if ($nombre === '') {
    $nombre = strstr($correo, '@', true) ?: 'Usuario Google';
}

// Buscar usuario existente por correo
$consulta = db()->prepare('SELECT id, nombre, rol, activo FROM usuarios WHERE correo = ? LIMIT 1');
$consulta->execute([$correo]);
$usuario = $consulta->fetch();

if ($usuario) {
    if (!$usuario['activo']) {
        redirigir('/acceso/?error=inactivo');
    }
    // Iniciar sesi?n
    iniciarSesionSegura();
    $_SESSION['usuario']    = ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'], 'rol' => $usuario['rol']];
    $_SESSION['inicio']     = time();
    $_SESSION['regenerada'] = time();
    $_SESSION['ultimo_uso'] = time();
    db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([(int) $usuario['id']]);
    redirigir(rutaInicio());
}

// Crear cuenta nueva (rol recepcion por defecto, activa)
$claveHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
db()->prepare('INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo, creado_en) VALUES (?, ?, ?, ?, 1, NOW())')
    ->execute([$nombre, $correo, $claveHash, 'recepcion']);
$nuevoId = (int) db()->lastInsertId();

iniciarSesionSegura();
$_SESSION['usuario']    = ['id' => $nuevoId, 'nombre' => $nombre, 'rol' => 'recepcion'];
$_SESSION['inicio']     = time();
$_SESSION['regenerada'] = time();
$_SESSION['ultimo_uso'] = time();
redirigir(rutaInicio());
