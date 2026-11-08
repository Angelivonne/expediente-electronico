-- Finanzas: facturación (control fiscal, sin timbrado automático)
CREATE TABLE IF NOT EXISTS finanzas_facturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  folio VARCHAR(40) NOT NULL,
  fecha DATE NOT NULL,
  paciente_id INT NULL,
  rfc VARCHAR(13) NULL,
  razon_social VARCHAR(160) NULL,
  uso_cfdi VARCHAR(10) NULL,
  metodo_pago ENUM('PUE','PPD') NOT NULL DEFAULT 'PUE',
  forma_pago VARCHAR(3) NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  estado ENUM('borrador','emitida','cancelada') NOT NULL DEFAULT 'borrador',
  uuid VARCHAR(40) NULL,
  notas VARCHAR(255) NULL,
  creado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_factura_fecha (institucion_id, fecha),
  KEY idx_fin_factura_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
