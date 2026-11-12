-- Normaliza las claves del catálogo de entidades a las claves CURP de dos letras.
-- Conserva los id existentes para no romper las relaciones con municipios.
START TRANSACTION;

-- Libera las claves que cambian de entidad antes de asignar sus valores finales.
UPDATE catalogo_entidades SET clave = 'QX' WHERE nombre = 'Querétaro' AND clave = 'QR';
UPDATE catalogo_entidades SET clave = 'SX' WHERE nombre = 'San Luis Potosí' AND clave = 'SL';

UPDATE catalogo_entidades
   SET clave = CASE nombre
       WHEN 'Aguascalientes' THEN 'AS'
       WHEN 'Baja California' THEN 'BC'
       WHEN 'Campeche' THEN 'CC'
       WHEN 'Chiapas' THEN 'CS'
       WHEN 'Ciudad de México' THEN 'DF'
       WHEN 'Coahuila' THEN 'CL'
       WHEN 'Durango' THEN 'DG'
       WHEN 'Estado de México' THEN 'MC'
       WHEN 'Guanajuato' THEN 'GT'
       WHEN 'Guerrero' THEN 'GR'
       WHEN 'Hidalgo' THEN 'HG'
       WHEN 'Jalisco' THEN 'JC'
       WHEN 'Michoacán' THEN 'MN'
       WHEN 'Morelos' THEN 'MS'
       WHEN 'Nayarit' THEN 'NT'
       WHEN 'Nuevo León' THEN 'NL'
       WHEN 'Oaxaca' THEN 'OC'
       WHEN 'Puebla' THEN 'PL'
       WHEN 'Querétaro' THEN 'QT'
       WHEN 'Quintana Roo' THEN 'QR'
       WHEN 'San Luis Potosí' THEN 'SP'
       WHEN 'Sinaloa' THEN 'SL'
       WHEN 'Sonora' THEN 'SR'
       WHEN 'Tabasco' THEN 'TC'
       WHEN 'Tlaxcala' THEN 'TL'
       WHEN 'Veracruz' THEN 'VZ'
       WHEN 'Yucatán' THEN 'YN'
       WHEN 'Zacatecas' THEN 'ZS'
       ELSE clave
   END
 WHERE nombre IN (
       'Aguascalientes', 'Baja California', 'Campeche', 'Chiapas', 'Ciudad de México',
       'Coahuila', 'Durango', 'Estado de México', 'Guanajuato', 'Guerrero', 'Hidalgo',
       'Jalisco', 'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla',
       'Querétaro', 'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco',
       'Tlaxcala', 'Veracruz', 'Yucatán', 'Zacatecas'
   );

INSERT INTO catalogo_entidades (clave, nombre, activo) VALUES
    ('BS', 'Baja California Sur', 1),
    ('CH', 'Chihuahua', 1),
    ('CM', 'Colima', 1),
    ('TS', 'Tamaulipas', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), activo = 1;

COMMIT;
