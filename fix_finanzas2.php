<?php
require_once __DIR__ . "/config/config.php";

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

// Create asistente_memoria
$mysqli->query("CREATE TABLE IF NOT EXISTS asistente_memoria (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NULL,
  clave VARCHAR(80) NOT NULL,
  valor TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_asistente_memoria_usuario (usuario_id, clave),
  CONSTRAINT fk_asistente_memoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB");
echo "asistente_memoria: " . ($mysqli->error ?: "OK") . "\n";

// Run finanzas migrations
$files = array('migracion_finanzas.sql', 'migracion_finanzas_cancelacion.sql', 'migracion_finanzas_cierre.sql', 'migracion_finanzas_cierre_diferencia.sql', 'migracion_finanzas_conciliacion.sql', 'migracion_finanzas_establecimiento.sql', 'migracion_finanzas_facturacion.sql', 'migracion_finanzas_presupuesto.sql', 'migracion_finanzas_profesional.sql');
foreach ($files as $f) {
    $path = __DIR__ . '/database/' . $f;
    if (!file_exists($path)) { echo "SKIP $f (not found)\n"; continue; }
    $sql = file_get_contents($path);
    $sql = str_replace('expediente_expediente', DB_NOMBRE, $sql);
    $sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);
    $mysqli->multi_query($sql);
    $ec = 0;
    do {
        if ($r = $mysqli->store_result()) $r->free();
        if ($mysqli->error) {
            $ec++;
            if ($ec <= 3) echo "  Error ($f): " . $mysqli->error . "\n";
        }
        try { $hm = $mysqli->more_results(); } catch (Throwable $e) { $hm = false; }
        if ($hm) {
            try { $mysqli->next_result(); } catch (Throwable $e) { break; }
        }
    } while ($hm);
    echo "  Ran $f (errors: $ec)\n";
}

// Check tables
$st = $mysqli->query("SHOW TABLES");
$tables = [];
while ($row = $st->fetch_row()) $tables[] = $row[0];
echo "\nTotal tables: " . count($tables) . "\n";
foreach (array('finanzas_movimientos', 'finanzas_cuentas', 'finanzas_categorias', 'asistente_memoria') as $t) {
    echo "  $t: " . (in_array($t, $tables) ? "OK" : "MISSING") . "\n";
}

$mysqli->close();
echo "Done.\n";
