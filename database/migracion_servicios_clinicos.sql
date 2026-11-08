-- Catálogo propio de servicios por institución y relación opcional con el movimiento financiero.
-- No incluye datos de ejemplo ni precios precargados.
CREATE TABLE IF NOT EXISTS servicios_clinicos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  descripcion VARCHAR(255) NULL,
  precio DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_por INT NULL,
  actualizado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_servicio_institucion_nombre (institucion_id, nombre),
  KEY idx_servicios_clinicos_activos (institucion_id, activo, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE finanzas_movimientos
  ADD COLUMN servicio_id INT NULL AFTER concepto,
  ADD KEY idx_fin_mov_servicio (servicio_id);

GRANT SELECT, INSERT, UPDATE ON expediente_expediente.servicios_clinicos
  TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
