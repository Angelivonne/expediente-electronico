-- Farmacia operativa por institución.
-- Las existencias se actualizan dentro de transacciones y cada cambio deja un movimiento inmutable.
-- En instalaciones nuevas estas tablas también se crean desde app/nucleo/reparar_esquema.php.

CREATE TABLE IF NOT EXISTS farmacia_lotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  medicamento_id INT UNSIGNED NOT NULL,
  codigo_lote VARCHAR(80) NOT NULL,
  caducidad DATE NOT NULL,
  unidad VARCHAR(40) NOT NULL,
  existencia DECIMAL(12,3) NOT NULL DEFAULT 0,
  costo_unitario DECIMAL(12,2) NULL,
  creado_por INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_farmacia_lote (institucion_id, medicamento_id, codigo_lote, caducidad, unidad),
  KEY idx_farmacia_existencia (institucion_id, existencia),
  KEY idx_farmacia_caducidad (institucion_id, caducidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS farmacia_movimientos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  lote_id BIGINT UNSIGNED NOT NULL,
  medicamento_id INT UNSIGNED NOT NULL,
  tipo ENUM('entrada', 'ajuste_entrada', 'ajuste_salida', 'merma', 'dispensacion') NOT NULL,
  cantidad DECIMAL(12,3) NOT NULL,
  paciente_id INT UNSIGNED NULL,
  receta_id INT UNSIGNED NULL,
  confirmacion_revisada TINYINT(1) NOT NULL DEFAULT 0,
  usuario_id INT UNSIGNED NOT NULL,
  operacion_id CHAR(48) NOT NULL,
  nota VARCHAR(500) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_farmacia_operacion (operacion_id),
  KEY idx_farmacia_mov_fecha (institucion_id, creado_en),
  KEY idx_farmacia_mov_lote (lote_id, creado_en),
  KEY idx_farmacia_mov_paciente (institucion_id, paciente_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
