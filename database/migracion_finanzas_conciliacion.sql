-- Finanzas: vínculo factura ↔ movimiento y conciliación de cierre
ALTER TABLE finanzas_movimientos ADD COLUMN IF NOT EXISTS factura_id INT NULL AFTER estado;
