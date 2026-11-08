<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/app/servicios/cuenta/smtp.php";

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

$ok = guardarConfiguracionSmtp($cfg);
echo $ok ? "SMTP configurado correctamente\n" : "Error al guardar SMTP\n";

// Test: send a test email
$resultado = enviarCorreo('angelzamora33331@gmail.com', 'Prueba de correo - Expediente', 'Este es un correo de prueba del sistema Expediente Clinico.');
echo $resultado ? "Correo de prueba enviado OK\n" : "Error al enviar correo de prueba\n";
