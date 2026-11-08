<?php
require_once __DIR__ . "/config/config.php";
$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) { die("Connection failed: " . $mysqli->connect_error . "\n"); }
$mysqli->set_charset("utf8mb4");
$sql = "CREATE TABLE IF NOT EXISTS finanzas_conceptos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(40) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL DEFAULT 'ingreso',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_fin_concepto_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if ($mysqli->query($sql)) {
    echo "OK: finanzas_conceptos created\n";
} else {
    echo "ERROR: " . $mysqli->error . "\n";
}
$st = $mysqli->query("SHOW TABLES LIKE 'finanzas_conceptos'");
echo "Table exists: " . ($st->num_rows > 0 ? "YES" : "NO") . "\n";
$mysqli->close();
