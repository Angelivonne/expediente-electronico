-- Finanzas: presupuestos mensuales y gastos/ingresos recurrentes (mismo motor)

CREATE TABLE IF NOT EXISTS finanzas_presupuestos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  anio SMALLINT NOT NULL,
  mes TINYINT NOT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL,
  concepto VARCHAR(120) NOT NULL DEFAULT '',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  notas VARCHAR(255) NULL,
  creado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fin_presupuesto (institucion_id, anio, mes, tipo, concepto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finanzas_recurrentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  concepto VARCHAR(120) NOT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL DEFAULT 'gasto',
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  metodo ENUM('efectivo','tarjeta','transferencia','cheque','otro') NOT NULL DEFAULT 'transferencia',
  dia_del_mes TINYINT NOT NULL DEFAULT 1,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  notas VARCHAR(255) NULL,
  creado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fin_recurrente_activo (institucion_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
