-- Finanzas: movimientos ligados al profesional que atendió/cobró
ALTER TABLE finanzas_movimientos ADD COLUMN IF NOT EXISTS profesional_id INT NULL AFTER paciente_id;
