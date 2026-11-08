-- Firma dibujada (canvas) de cada persona del equipo.
-- Guarda la ruta relativa del PNG (assets/subidas/firmas/firma-xxxxxxxx.png); NULL = sin firma.
ALTER TABLE usuarios ADD COLUMN firma VARCHAR(160) NULL AFTER foto;
