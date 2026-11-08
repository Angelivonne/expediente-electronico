<?php
/**
 * Configuración general del expediente.
 */

// Ruta pública del proyecto dentro de htdocs (sin diagonal final).
define('URL_BASE', getenv('URL_BASE') !== false ? getenv('URL_BASE') : '/');
// Origen público para los enlaces de los correos (no se toma de la cabecera Host, que se puede falsificar)
define('URL_ORIGEN', getenv('URL_ORIGEN') ?: 'http://localhost');

// Base de datos MySQL: una sola base (expediente_expediente). La app entra con un usuario de privilegios
// mínimos (database/usuario_aplicacion.sql) cuyas credenciales viven en privado/bd.php, nunca root.
define('DB_HOST',    getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PUERTO',  (int)(getenv('DB_PUERTO') ?: 3306));
define('DB_NOMBRE',  getenv('DB_NOMBRE') ?: 'expediente');
define('DB_USUARIO', getenv('DB_USUARIO') ?: 'root');
define('DB_CLAVE',   getenv('DB_CLAVE') ?: '');
// Programas de MySQL para los respaldos (mysqldump) y la restauración (mysql)
define('MYSQL_BIN', 'C:/xampp/mysql/bin');
define('PHP_CLI',   'C:/xampp/php/php.exe');   // lanza el respaldo diario en segundo plano
// Cuentas (id de usuarios) que administran respaldos cuando hay varias instituciones: un respaldo trae la
// base completa. Vacío = cualquier administrador mientras haya una sola institución.
define('ADMINISTRADORES_SERVIDOR', []);

// Envío de correo (código de verificación al iniciar sesión).
//   'archivo': desarrollo; guarda cada correo como .eml en MAIL_CARPETA (fuera de htdocs).
//   'mail':    producción; usa mail() de PHP con el SMTP configurado en php.ini / sendmail.
define('MAIL_MODO',      'archivo');
define('MAIL_CARPETA',   'C:/xampp/mailoutput');
define('MAIL_REMITENTE', 'no-responder@expediente.local');

// Inicio de sesión con Google: el botón aparece solo cuando hay credenciales OAuth configuradas.
define('GOOGLE_CLIENT_ID', '');

// reCAPTCHA v2 (checkbox): protege el inicio de sesi?n y el registro contra bots.
// Obt?n tus claves en https://www.google.com/recaptcha/admin (tipo: reCAPTCHA v2 "No soy un robot")
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '');

// Acceso directo en este equipo: true = se entra como administrador sin escribir contraseña ni código
// (sirve en equipo propio/local). Ponlo en false para exigir el inicio de sesión normal con clave y código.
define('ACCESO_AUTOMATICO', filter_var(getenv('ACCESO_AUTOMATICO') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// Verificación de cédulas en el Registro Nacional de Profesionistas (SEP). El portal oficial exige
// reCAPTCHA y no hay un servicio público: queda vacío hasta contar con un servicio autorizado (HTTPS)
// que responda en el formato del RNP. Vacío = las cédulas quedan "pendientes de verificación".
define('CEDULA_SERVICIO_URL', '');

date_default_timezone_set('America/Mexico_City');
mb_internal_encoding('UTF-8');
