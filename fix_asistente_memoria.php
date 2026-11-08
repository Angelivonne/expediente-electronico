<?php
require_once __DIR__ . "/config/config.php";
$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, DB_NOMBRE, DB_PUERTO);
if ($mysqli->connect_error) { die("Connection failed: " . $mysqli->connect_error . "\n"); }
$mysqli->set_charset("utf8mb4");
$sql = "CREATE TABLE IF NOT EXISTS asistente_memoria (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NULL,
  clave VARCHAR(80) NOT NULL,
  valor TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_asistente_memoria_usuario (usuario_id, clave),
  CONSTRAINT fk_asistente_memoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB";
if ($mysqli->query($sql)) {
    echo "asistente_memoria created OK\n";
} else {
    echo "Error: " . $mysqli->error . "\n";
}
$mysqli->close();

