<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();

// Add seccion column to paginas
try {
    $pdo->exec("ALTER TABLE paginas ADD COLUMN seccion VARCHAR(40) NULL AFTER paciente_id");
    echo "Added seccion to paginas\n";
} catch (Throwable $e) {
    echo "paginas.seccion: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE paginas ADD UNIQUE KEY uk_pagina_seccion (institucion_id, seccion)");
    echo "Added uk_pagina_seccion\n";
} catch (Throwable $e) {
    echo "uk_pagina_seccion: " . $e->getMessage() . "\n";
}

// Add any other missing columns from ALTER TABLE statements
// Check asistente_memoria table
$pdo->exec("CREATE TABLE IF NOT EXISTS asistente_memoria (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(80)  NOT NULL,
  valor           TEXT         NOT NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_asistente_memoria_usuario (usuario_id, clave),
  CONSTRAINT fk_asistente_memoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB");

echo "Done\n";
