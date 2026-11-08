-- Finanzas: cierre de caja y vencimientos (mismo motor)

-- Fecha de vencimiento para cuentas por cobrar/pagar
ALTER TABLE finanzas_movimientos ADD COLUMN IF NOT EXISTS vence_en DATE NULL AFTER fecha;

-- Cierres de caja registrados (una fila por día/turno cerrado)
CREATE TABLE IF NOT EXISTS finanzas_cierres (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  fecha DATE NOT NULL,
  efectivo DECIMAL(12,2) NOT NULL DEFAULT 0,
  tarjeta DECIMAL(12,2) NOT NULL DEFAULT 0,
  transferencia DECIMAL(12,2) NOT NULL DEFAULT 0,
  cheque DECIMAL(12,2) NOT NULL DEFAULT 0,
  otro DECIMAL(12,2) NOT NULL DEFAULT 0,
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  notas VARCHAR(255) NULL,
  creado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_cierre_fecha (institucion_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
