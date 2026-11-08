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
// Remove IF NOT EXISTS from ADD COLUMN, ADD INDEX, ADD UNIQUE INDEX, ADD UNIQUE KEY, ADD FOREIGN KEY
$sql = preg_replace('/ADD (COLUMN|INDEX|UNIQUE INDEX|UNIQUE KEY|FOREIGN KEY|KEY|CONSTRAINT) IF NOT EXISTS/i', 'ADD $1', $sql);
// Also handle ADD INDEX IF NOT EXISTS with index name before IF NOT EXISTS
$sql = preg_replace('/ADD INDEX (\w+) IF NOT EXISTS/i', 'ADD INDEX $1', $sql);
$sql = preg_replace('/ADD UNIQUE INDEX (\w+) IF NOT EXISTS/i', 'ADD UNIQUE INDEX $1', $sql);
$sql = preg_replace('/ADD UNIQUE KEY (\w+) IF NOT EXISTS/i', 'ADD UNIQUE KEY $1', $sql);

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
        echo "  Stmt: " . substr($mysqli->error_list[0]['sqlstate'] ?? '', 0, 200) . "\n";
    }
} while ($mysqli->more_results() && $mysqli->next_result());

echo "Completed. Statements: $count, Errors: $errors\n";
$mysqli->close();
