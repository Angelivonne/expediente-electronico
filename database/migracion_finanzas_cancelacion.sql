-- Finanzas: cancelación de facturas (motivo y fecha)
ALTER TABLE finanzas_facturas ADD COLUMN IF NOT EXISTS cancelada_en DATETIME NULL AFTER estado;
ALTER TABLE finanzas_facturas ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL AFTER cancelada_en;
