-- Finanzas de la clínica (un solo motor: tablas + entidades del motor)
-- Movimientos de ingreso/gasto, conceptos y métodos de pago.

CREATE TABLE IF NOT EXISTS finanzas_conceptos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(40) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL DEFAULT 'ingreso',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_fin_concepto_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS finanzas_movimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT NOT NULL,
  paciente_id INT NULL,
  tipo ENUM('ingreso','gasto') NOT NULL,
  concepto VARCHAR(120) NOT NULL,
  descripcion VARCHAR(255) NULL,
  monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  metodo ENUM('efectivo','tarjeta','transferencia','cheque','otro') NOT NULL DEFAULT 'efectivo',
  fecha DATE NOT NULL,
  referencia VARCHAR(80) NULL,
  estado ENUM('pagado','pendiente','cancelado') NOT NULL DEFAULT 'pagado',
  creado_por INT NULL,
  actualizado_por INT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fin_inst_fecha (institucion_id, fecha),
  KEY idx_fin_tipo (tipo),
  KEY idx_fin_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO finanzas_conceptos (clave, nombre, tipo) VALUES
  ('consulta_general', 'Consulta general', 'ingreso'),
  ('consulta_especialidad', 'Consulta de especialidad', 'ingreso'),
  ('procedimiento', 'Procedimiento', 'ingreso'),
  ('estudio', 'Estudio o auxiliar de diagnóstico', 'ingreso'),
  ('venta_producto', 'Venta de producto', 'ingreso'),
  ('anticipo', 'Anticipo de paciente', 'ingreso'),
  ('renta', 'Renta del consultorio', 'gasto'),
  ('nomina', 'Nómina y honorarios', 'gasto'),
  ('insumos', 'Insumos y material de curación', 'gasto'),
  ('servicios', 'Servicios (luz, agua, internet)', 'gasto'),
  ('mantenimiento', 'Mantenimiento y equipo', 'gasto'),
  ('impuestos', 'Impuestos y trámites', 'gasto'),
  ('otro', 'Otro', 'gasto');
