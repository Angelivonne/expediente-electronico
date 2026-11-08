<?php
require_once __DIR__ . '/config/config.php';

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, '', DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->query("DROP DATABASE IF EXISTS " . DB_NOMBRE);
$mysqli->query("CREATE DATABASE " . DB_NOMBRE . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$mysqli->select_db(DB_NOMBRE);
$mysqli->set_charset('utf8mb4');

$sqlFile = __DIR__ . '/database/esquema.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

// Replace old DB name with new one
$sql = str_replace('expediente_expediente', DB_NOMBRE, $sql);
// Remove USE statement
$sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);
// Remove IF NOT EXISTS from all ALTER TABLE patterns
$sql = preg_replace('/ADD (COLUMN|INDEX|UNIQUE INDEX|UNIQUE KEY|FOREIGN KEY|KEY|CONSTRAINT) IF NOT EXISTS/i', 'ADD $1', $sql);
$sql = preg_replace('/ADD (INDEX|UNIQUE INDEX|UNIQUE KEY|KEY) (\w+) IF NOT EXISTS/i', 'ADD $1 $2', $sql);
// Handle: ADD CONSTRAINT name IF NOT EXISTS FOREIGN KEY
$sql = preg_replace('/ADD CONSTRAINT (\w+) IF NOT EXISTS/i', 'ADD CONSTRAINT $1', $sql);
// Handle: ADD FOREIGN KEY IF NOT EXISTS
$sql = preg_replace('/ADD FOREIGN KEY IF NOT EXISTS/i', 'ADD FOREIGN KEY', $sql);
$sql = preg_replace('/FOREIGN KEY IF NOT EXISTS/i', 'FOREIGN KEY', $sql);

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
    try {
        $hasMore = $mysqli->more_results();
    } catch (Throwable $e) {
        $hasMore = false;
    }
    if ($hasMore) {
        try {
            $mysqli->next_result();
        } catch (Throwable $e) {
            $errors++;
            echo "Next result error: " . $e->getMessage() . "\n";
            break;
        }
    }
} while ($hasMore);

echo "Completed. Statements: $count, Errors: $errors\n";
$mysqli->close();
