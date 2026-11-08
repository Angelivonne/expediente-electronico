<?php
require_once __DIR__ . "/config/config.php";
$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) { die("Connection failed: " . $mysqli->connect_error . "\n"); }
$mysqli->set_charset("utf8mb4");

// Read all finanzas migration files and extract CREATE TABLE statements
$files = array("migracion_finanzas.sql", "migracion_finanzas_cancelacion.sql", "migracion_finanzas_cierre.sql", "migracion_finanzas_cierre_diferencia.sql", "migracion_finanzas_conciliacion.sql", "migracion_finanzas_establecimiento.sql", "migracion_finanzas_facturacion.sql", "migracion_finanzas_presupuesto.sql", "migracion_finanzas_profesional.sql");
$created = 0;
foreach ($files as $f) {
    $path = __DIR__ . "/database/" . $f;
    if (!file_exists($path)) continue;
    $sql = file_get_contents($path);
    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/s', $sql, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $stmt = $m[0];
        $table = $m[1];
        // Check if table already exists
        $check = $mysqli->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows > 0) {
            echo "SKIP $table (already exists)\n";
            continue;
        }
        if ($mysqli->query($stmt)) {
            echo "CREATED $table\n";
            $created++;
        } else {
            echo "ERROR $table: " . $mysqli->error . "\n";
        }
    }
}
echo "\nCreated $created new tables.\n";

// List all finanzas tables
$st = $mysqli->query("SHOW TABLES LIKE 'finanzas_%'");
echo "Finanzas tables: " . $st->num_rows . "\n";
while ($row = $st->fetch_row()) echo "  " . $row[0] . "\n";

$mysqli->close();
echo "Done.\n";
