<?php
require_once __DIR__ . "/config/config.php";
$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) { die("Connection failed: " . $mysqli->connect_error . "\n"); }
$mysqli->set_charset("utf8mb4");
$sql = file_get_contents(__DIR__ . "/database/migracion_finanzas.sql");
$sql = str_replace("expediente_expediente", DB_NOMBRE, $sql);
$sql = preg_replace("/USE\s+\w+\s*;/i", "", $sql);
echo "SQL length: " . strlen($sql) . "\n";
if ($mysqli->multi_query($sql)) {
    echo "multi_query OK\n";
    $count = 0;
    do {
        if ($r = $mysqli->store_result()) $r->free();
        $count++;
        if ($mysqli->error) echo "Error: " . $mysqli->error . "\n";
        try { $hm = $mysqli->more_results(); } catch (Throwable $e) { $hm = false; }
        if ($hm) {
            try { $mysqli->next_result(); } catch (Throwable $e) { echo "next_result error: " . $e->getMessage() . "\n"; break; }
        }
    } while ($hm);
    echo "Statements: $count\n";
} else {
    echo "multi_query FAILED: " . $mysqli->error . "\n";
}
$mysqli->close();

