<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$sqlFile = __DIR__ . '/database/esquema.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

// Replace old DB name with new one
$sql = str_replace('expediente_expediente', DB_NOMBRE, $sql);
// Remove USE statement
$sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);
// Remove IF NOT EXISTS from ADD COLUMN (not supported in MySQL < 8.0.29)
$sql = preg_replace('/ADD COLUMN IF NOT EXISTS/i', 'ADD COLUMN', $sql);
// Remove IF NOT EXISTS from ADD INDEX
$sql = preg_replace('/ADD INDEX IF NOT EXISTS/i', 'ADD INDEX', $sql);

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Use multi_query with error handling
$mysqli->multi_query($sql);
$count = 0;
$errors = 0;
do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
    $count++;
    if ($mysqli->error) {
        $errors++;
        echo "Error #$errors: " . $mysqli->error . "\n";
    }
} while ($mysqli->more_results() && $mysqli->next_result());

echo "Completed. Statements: $count, Errors: $errors\n";
$mysqli->close();
