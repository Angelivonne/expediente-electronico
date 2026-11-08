<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'");
if ($st->fetchColumn() > 0) {
    echo "Admin already exists\n";
    exit;
}
$pdo->prepare("INSERT INTO usuarios (nombre, rol, activo, creado_en) VALUES (?, 'administrador', 1, NOW())")
    ->execute(array('Admin'));
echo "Admin created with id: " . $pdo->lastInsertId() . "\n";
$st = $pdo->query("SELECT COUNT(*) FROM instituciones");
if ($st->fetchColumn() == 0) {
    $pdo->prepare("INSERT INTO instituciones (nombre, tipo) VALUES (?, 'clinica')")
        ->execute(array('Mi Clinica'));
    echo "Institution created\n";
}
