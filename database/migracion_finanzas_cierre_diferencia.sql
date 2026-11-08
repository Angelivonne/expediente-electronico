-- Cierre de caja: diferencia contra movimientos reales (conciliación automática)
ALTER TABLE finanzas_cierres ADD COLUMN IF NOT EXISTS diferencia_total DECIMAL(12,2) NULL AFTER total;
