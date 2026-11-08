<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();

// Check if admin exists
$st = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador'");
if ($st->fetchColumn() > 0) {
    echo "Admin already exists\n";
    $st = $pdo->query("SELECT id, nombre, correo, rol, activo, institucion_id FROM usuarios WHERE rol = 'administrador'");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        echo "  id=" . $row["id"] . " nombre=" . $row["nombre"] . " correo=" . $row["correo"] . " rol=" . $row["rol"] . " activo=" . $row["activo"] . " inst=" . $row["institucion_id"] . "\n";
    }
    exit;
}

// Create user first (without institution)
$hash = password_hash("Admin123!", PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo, creado_en) VALUES (?, ?, ?, 'administrador', 1, NOW())")
    ->execute(array("Angel", "angelzamora@test.com", $hash));
$userId = $pdo->lastInsertId();
echo "User created with id: $userId\n";

// Create institution
$pdo->prepare("INSERT INTO instituciones (nombre, tipo, domicilio, entidad, creada_por) VALUES (?, 'clinica', '', '01', ?)")
    ->execute(array("Mi Clinica", $userId));
$instId = $pdo->lastInsertId();
echo "Institution created with id: $instId\n";

// Link user to institution
$pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE id = ?")
    ->execute(array($instId, $userId));
echo "User linked to institution\n";

echo "\nLogin: angelzamora@test.com / Admin123!\n";
