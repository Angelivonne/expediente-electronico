<?php
require_once __DIR__ . '/config/config.php';

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, '', DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

// Drop and recreate
$mysqli->query("DROP DATABASE IF EXISTS " . DB_NOMBRE);
$mysqli->query("CREATE DATABASE " . DB_NOMBRE . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$mysqli->select_db(DB_NOMBRE);
$mysqli->set_charset('utf8mb4');

// Check if usuarios table exists
$res = $mysqli->query("SHOW TABLES LIKE 'usuarios'");
echo "usuarios table exists before import: " . ($res->num_rows > 0 ? "YES" : "NO") . "\n";

// Check columns in usuarios
if ($res->num_rows > 0) {
    $cols = $mysqli->query("SHOW COLUMNS FROM usuarios");
    while ($row = $cols->fetch_assoc()) {
        echo "  col: " . $row['Field'] . "\n";
    }
}

$mysqli->close();
echo "DB reset complete\n";
