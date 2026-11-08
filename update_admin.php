<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$hash = password_hash("Admin123!", PASSWORD_DEFAULT);
$pdo->prepare("UPDATE usuarios SET correo = ?, clave_hash = ?, nombre = ? WHERE rol = 'administrador' AND id = 1")
    ->execute(array("angelzamora@test.com", $hash, "Angel"));
echo "Admin actualizado
";
echo "Correo: angelzamora@test.com
";
echo "Clave: Admin123!
";
