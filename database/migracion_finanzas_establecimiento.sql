-- Finanzas: movimiento ligado al establecimiento (sucursal)
ALTER TABLE finanzas_movimientos ADD COLUMN IF NOT EXISTS establecimiento_id INT NULL AFTER profesional_id;
