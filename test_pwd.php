<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SELECT id, correo, clave_hash FROM usuarios WHERE correo = 'angelzamora@test.com'");
$user = $st->fetch(PDO::FETCH_ASSOC);
echo "User found: " . ($user ? "YES" : "NO") . "\n";
if ($user) {
    echo "ID: " . $user["id"] . "\n";
    echo "Email: " . $user["correo"] . "\n";
    echo "Hash: " . substr($user["clave_hash"], 0, 20) . "...\n";
    echo "Password verify (Admin123!): " . (password_verify("Admin123!", $user["clave_hash"]) ? "TRUE" : "FALSE") . "\n";
}
