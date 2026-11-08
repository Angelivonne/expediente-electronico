<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'");
if ($st->fetchColumn() > 0) {
    echo "Admin already exists\n";
} else {
    $pdo->prepare("INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo, creado_en) VALUES (?, ?, ?, 'administrador', 1, NOW())")
        ->execute(array("Admin", "admin@expediente.local", password_hash("admin123", PASSWORD_DEFAULT)));
    echo "Admin created with id: " . $pdo->lastInsertId() . "\n";
}
$st = $pdo->query("SELECT COUNT(*) FROM instituciones");
if ($st->fetchColumn() == 0) {
    // Get admin id
    $adminId = $pdo->query("SELECT id FROM usuarios WHERE rol = 'administrador' LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO instituciones (nombre, tipo, domicilio, entidad, creada_por) VALUES (?, 'clinica', '', '01', ?)")
        ->execute(array("Mi Clinica", $adminId));
    $instId = $pdo->lastInsertId();
    echo "Institution created with id: " . $instId . "\n";
    $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE rol = 'administrador'")
        ->execute(array($instId));
    echo "Admin linked to institution\n";
} else {
    echo "Institution already exists\n";
}
