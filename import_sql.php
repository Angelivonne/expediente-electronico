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
// Remove USE statement (we're already connected to the right DB)
$sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Use multi_query
if ($mysqli->multi_query($sql)) {
    $count = 0;
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
        $count++;
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "Executed successfully. Queries: $count\n";
    if ($mysqli->error) {
        echo "Last error: " . $mysqli->error . "\n";
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}
$mysqli->close();
