<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/app/servicios/cuenta/acceso.php";
require_once __DIR__ . "/config/db.php";

$cfg = array(
    'activo'     => true,
    'servidor'   => 'smtp.gmail.com',
    'puerto'     => 587,
    'seguridad'  => 'tls',
    'usuario'    => 'angelzamora33331@gmail.com',
    'clave'      => 'dhib gyfn betj jpdn',
    'remitente'  => 'angelzamora33331@gmail.com',
    'nombre'     => 'Expedientes Clinicos',
);

$archivo = __DIR__ . "/privado/correo.php";
$contenido = "<?php\n// Configuracion SMTP. No compartir.\nreturn " . var_export($cfg, true) . ";\n";
if (!is_dir(dirname($archivo))) {
    mkdir(dirname($archivo), 0700, true);
}
$ok = file_put_contents($archivo, $contenido) !== false;
echo $ok ? "SMTP configurado correctamente\n" : "Error al guardar SMTP\n";

if ($ok) {
    require_once __DIR__ . "/app/servicios/cuenta/smtp.php";
    $resultado = enviarCorreo('angelzamora33331@gmail.com', 'Prueba de correo - Expediente', 'Este es un correo de prueba del sistema Expediente Clinico.');
    echo $resultado ? "Correo de prueba enviado OK\n" : "Error al enviar correo de prueba\n";
}
