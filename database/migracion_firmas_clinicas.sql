-- Firmas electrónicas de entradas del historial clínico (NOM-024: cédula, sello y huella)
CREATE TABLE IF NOT EXISTS firmas_clinicas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  historial_id INT NOT NULL,
  institucion_id INT NOT NULL,
  usuario_id INT NOT NULL,
  usuario_nombre VARCHAR(120) NOT NULL,
  cedula VARCHAR(10) NULL,
  huella CHAR(64) NOT NULL,
  firmado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revocada_en DATETIME NULL,
  motivo_revocacion VARCHAR(255) NULL,
  UNIQUE KEY uq_firma_historial (historial_id),
  KEY idx_firma_inst (institucion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
