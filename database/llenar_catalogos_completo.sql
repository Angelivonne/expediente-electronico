-- ==========================================================================
-- Expediente Expediente - Llenar catálogos con datos reales de México
-- ==========================================================================

USE clinica;

-- --------------------------------------------------------
-- A. CATÁLOGOS CLÍNICOS
-- --------------------------------------------------------

-- Especialidades médicas
INSERT IGNORE INTO catalogo_especialidades (clave, nombre, descripcion, activo) VALUES
('MED', 'Medicina interna', 'Atención médica general de adultos', 1),
('PED', 'Pediatría', 'Atención a niños y adolescentes', 1),
('CAR', 'Cardiología', 'Corazón y sistema circulatorio', 1),
('GIN', 'Ginecología', 'Salud reproductiva femenina', 1),
('DER', 'Dermatología', 'Piel, cabello y uñas', 1),
('TRA', 'Traumatología y ortopedia', 'Huesos, músculos y articulaciones', 1),
('OFT', 'Oftalmología', 'Ojos y visión', 1),
('OTR', 'Otorrinolaringología', 'Oído, nariz y garganta', 1),
('PSI', 'Psiquiatría', 'Salud mental', 1),
('URG', 'Medicina de urgencias', 'Atención de emergencias', 1);

-- Tipos de consulta
INSERT IGNORE INTO catalogo_tipos_consulta (clave, nombre, activo) VALUES
('CONSULTA_GENERAL', 'Consulta general', 1),
('CONSULTA_CONTROL', 'Consulta de control', 1),
('CONSULTA_URGENCIA', 'Consulta de urgencia', 1),
('CONSULTA_INTERCONSULTA', 'Interconsulta', 1),
('CONSULTA_REFERENCIA', 'Referencia', 1);

-- Tipos de atención
INSERT IGNORE INTO catalogo_tipos_atencion (clave, nombre, activo) VALUES
('CONSULTA_EXTERNA', 'Consulta externa', 1),
('URGENCIAS', 'Urgencias', 1),
('HOSPITALIZACION', 'Hospitalización', 1),
('INTERCONSULTA', 'Interconsulta', 1),
('REFERENCIA', 'Referencia', 1);

-- Procedimientos médicos
INSERT IGNORE INTO catalogo_procedimientos (clave, nombre, descripcion, activo) VALUES
('PROC001', 'Consulta médica', 'Consulta médica general', 1),
('PROC002', 'Curación', 'Curación de heridas', 1),
('PROC003', 'Inyección', 'Aplicación de inyección', 1),
('PROC004', 'Sutura', 'Sutura de heridas', 1),
('PROC005', 'Electrocardiograma', 'Estudio de actividad eléctrica del corazón', 1);

-- --------------------------------------------------------
-- B. CATÁLOGOS FARMACÉUTICOS
-- --------------------------------------------------------

-- Formas farmacéuticas
INSERT IGNORE INTO catalogo_formas_farmaceuticas (clave, nombre, descripcion, activo) VALUES
('COMPRIMIDO', 'Comprimido', 'Forma farmacéutica sólida', 1),
('CAPSULA', 'Cápsula', 'Cápsula de gelatina', 1),
('JARABE', 'Jarabe', 'Solución líquida endulzada', 1),
('SUSPENSION', 'Suspensión', 'Suspensión farmacéutica', 1),
('INYECTABLE', 'Inyectable', 'Solución inyectable', 1),
('CREMA', 'Crema', 'Crema tópica', 1),
('UNGUENTO', 'Ungüento', 'Ungüento tópico', 1),
('GOTAS', 'Gotas', 'Gotas oftálmicas u óticas', 1),
('AMPOLLA', 'Ampolla', 'Ampolla inyectable', 1),
('TABLETA', 'Tableta', 'Tableta farmacéutica', 1);

-- Vías de administración (sin columna descripcion)
INSERT IGNORE INTO catalogo_vias_admin (clave, nombre, activo) VALUES
('ORAL', 'Oral', 1),
('INTRAVENOSA', 'Intravenosa', 1),
('INTRAMUSCULAR', 'Intramuscular', 1),
('SUBCUTANEA', 'Subcutánea', 1),
('TOPICA', 'Tópica', 1),
('OFALMICA', 'Oftálmica', 1),
('OTICA', 'Ótica', 1),
('NASAL', 'Nasal', 1),
('RECTAL', 'Rectal', 1),
('VAGINAL', 'Vaginal', 1),
('INHALADA', 'Inhalada', 1),
('SUBLINGUAL', 'Sublingual', 1);

-- Unidades de medida (tiene columna simbolo, no descripcion)
INSERT IGNORE INTO catalogo_unidades_medida (clave, nombre, simbolo, activo) VALUES
('MG', 'Miligramo', 'mg', 1),
('G', 'Gramo', 'g', 1),
('ML', 'Mililitro', 'ml', 1),
('L', 'Litro', 'L', 1),
('UI', 'Unidad Internacional', 'UI', 1),
('MCG', 'Microgramo', 'mcg', 1),
('MMOL', 'Milimol', 'mmol', 1),
('MEQ', 'Miliequivalente', 'mEq', 1);

-- Concentraciones (tiene unidad_id)
INSERT IGNORE INTO catalogo_concentraciones (valor, unidad_id, activo)
SELECT CONCAT(valor, ' ', u.simbolo), u.id, 1
FROM (
    SELECT '500' as valor UNION ALL SELECT '250' UNION ALL SELECT '100' UNION ALL SELECT '200' UNION ALL SELECT '10' UNION ALL SELECT '5' UNION ALL SELECT '1' UNION ALL SELECT '25' UNION ALL SELECT '50' UNION ALL SELECT '75'
) v
JOIN catalogo_unidades_medida u ON u.simbolo IN ('mg', 'ml', 'UI', 'mcg', 'mmol')
WHERE u.simbolo IN ('mg', 'ml', 'UI', 'mcg', 'mmol')
LIMIT 50;

-- Categorías farmacológicas
INSERT IGNORE INTO catalogo_categorias_farmaco (clave, nombre, descripcion, activo) VALUES
('ANALGESICOS', 'Analgésicos', 'Medicamentos para el dolor', 1),
('ANTIBIOTICOS', 'Antibióticos', 'Antibacterianos', 1),
('ANTIINFLAMATORIOS', 'Antiinflamatorios', 'Antiinflamatorios no esteroideos', 1),
('ANTIHIERTENSIVOS', 'Antihipertensivos', 'Para la presión arterial', 1),
('ANTIDIABETICOS', 'Antidiabéticos', 'Para la diabetes', 1),
('BRONCODILATADORES', 'Broncodilatadores', 'Para las vías respiratorias', 1),
('DIURETICOS', 'Diuréticos', 'Para eliminar líquidos', 1),
('VITAMINAS', 'Vitaminas', 'Suplementos vitamínicos', 1);

-- Principios activos
INSERT IGNORE INTO catalogo_principios_activos (clave, nombre, descripcion, activo) VALUES
('PARACETAMOL', 'Paracetamol', 'Analgésico y antipirético', 1),
('IBUPROFENO', 'Ibuprofeno', 'Antiinflamatorio no esteroideo', 1),
('AMOXICILINA', 'Amoxicilina', 'Antibiótico betalactámico', 1),
('OMEPRAZOL', 'Omeprazol', 'Inhibidor de bomba de protones', 1),
('METFORMINA', 'Metformina', 'Antidiabético oral', 1),
('LOSARTAN', 'Losartán', 'Antihipertensivo', 1),
('ENLAPRIL', 'Enalapril', 'Inhibidor de la ECA', 1),
('SIMVASTATINA', 'Simvastatina', 'Estatina', 1),
('LORATADINA', 'Loratadina', 'Antihistamínico', 1),
('DICLOFENACO', 'Diclofenaco', 'Antiinflamatorio', 1);

-- --------------------------------------------------------
-- C. CATÁLOGOS GEOGRÁFICOS Y DEMOGRÁFICOS
-- --------------------------------------------------------

-- Entidades federativas (32 estados)
-- Claves de entidad de dos letras usadas por CURP (RENAPO).
INSERT IGNORE INTO catalogo_entidades (clave, nombre, activo) VALUES
('AS', 'Aguascalientes', 1),
('BC', 'Baja California', 1),
('BS', 'Baja California Sur', 1),
('CC', 'Campeche', 1),
('CS', 'Chiapas', 1),
('CH', 'Chihuahua', 1),
('DF', 'Ciudad de México', 1),
('CL', 'Coahuila', 1),
('CM', 'Colima', 1),
('DG', 'Durango', 1),
('GT', 'Guanajuato', 1),
('GR', 'Guerrero', 1),
('HG', 'Hidalgo', 1),
('JC', 'Jalisco', 1),
('MC', 'Estado de México', 1),
('MN', 'Michoacán', 1),
('MS', 'Morelos', 1),
('NT', 'Nayarit', 1),
('NL', 'Nuevo León', 1),
('OC', 'Oaxaca', 1),
('PL', 'Puebla', 1),
('QT', 'Querétaro', 1),
('QR', 'Quintana Roo', 1),
('SP', 'San Luis Potosí', 1),
('SL', 'Sinaloa', 1),
('SR', 'Sonora', 1),
('TC', 'Tabasco', 1),
('TS', 'Tamaulipas', 1),
('TL', 'Tlaxcala', 1),
('VZ', 'Veracruz', 1),
('YN', 'Yucatán', 1),
('ZS', 'Zacatecas', 1);

-- Municipios (ejemplos principales por entidad)
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo) VALUES
-- Aguascalientes (id=25)
(25, '001', 'Aguascalientes', 1),
(25, '002', 'Asientos', 1),
(25, '003', 'Calvillo', 1),
(25, '004', 'Jesús María', 1),
(25, '005', 'Pabellón de Arteaga', 1),
-- Baja California (id=9)
(9, '001', 'Mexicali', 1),
(9, '002', 'Ensenada', 1),
(9, '003', 'Tijuana', 1),
(9, '004', 'Tecate', 1),
(9, '005', 'Rosarito', 1),
-- Guanajuato (id=30)
(30, '001', 'Acámbaro', 1),
(30, '002', 'San Miguel de Allende', 1),
(30, '003', 'Dolores Hidalgo', 1),
(30, '004', 'León', 1),
(30, '005', 'Celaya', 1),
-- Guerrero (id=24)
(24, '001', 'Acapulco', 1),
(24, '002', 'Chilpancingo', 1),
(24, '003', 'Iguala', 1),
(24, '004', 'Taxco', 1),
(24, '005', 'Zihuatanejo', 1),
-- Jalisco (id=14)
(14, '001', 'Guadalajara', 1),
(14, '002', 'Zapopan', 1),
(14, '003', 'Tlaquepaque', 1),
(14, '004', 'Tonalá', 1),
(14, '005', 'Tlajomulco', 1),
-- Ciudad de México (id=7)
(7, '001', 'Cuauhtémoc', 1),
(7, '002', 'Miguel Hidalgo', 1),
(7, '003', 'Benito Juárez', 1),
(7, '004', 'Venustiano Carranza', 1),
(7, '005', 'Gustavo A. Madero', 1);

-- Localidades (ejemplos principales)
INSERT IGNORE INTO catalogo_localidades (municipio_id, clave, nombre, activo) VALUES
(1, '0001', 'Aguascalientes', 1),
(1, '0002', 'Asientos', 1),
(1, '0003', 'Calvillo', 1),
(6, '0001', 'Mexicali', 1),
(6, '0002', 'Ensenada', 1),
(6, '0003', 'Tijuana', 1),
(11, '0001', 'León', 1),
(11, '0002', 'Celaya', 1),
(11, '0003', 'Irapuato', 1),
(12, '0001', 'Guadalajara', 1),
(12, '0002', 'Zapopan', 1),
(12, '0003', 'Tlaquepaque', 1),
(12, '0004', 'Tonalá', 1),
(12, '0005', 'Tlajomulco', 1),
(24, '0001', 'Chilpancingo', 1),
(24, '0002', 'Acapulco', 1),
(24, '0003', 'Iguala', 1),
(24, '0004', 'Taxco', 1),
(24, '0005', 'Zihuatanejo', 1);

-- Códigos postales (ejemplos principales)
INSERT IGNORE INTO catalogo_codigos_postales (codigo, localidad_id, activo) VALUES
('20000', 1, 1),
('20100', 1, 1),
('20120', 1, 1),
('20130', 1, 1),
('20140', 1, 1),
('22000', 6, 1),
('22100', 6, 1),
('22200', 7, 1),
('47000', 11, 1),
('47100', 11, 1),
('44000', 12, 1),
('44100', 12, 1),
('44200', 13, 1),
('44300', 14, 1),
('44400', 15, 1),
('06000', 24, 1),
('06100', 25, 1),
('06200', 26, 1),
('06300', 27, 1),
('06400', 28, 1);

-- Nacionalidades
INSERT IGNORE INTO catalogo_nacionalidades (clave, nombre, activo) VALUES
('MEX', 'Mexicana', 1),
('USA', 'Estadounidense', 1),
('CAN', 'Canadiense', 1),
('ESP', 'Española', 1),
('COL', 'Colombiana', 1),
('ARG', 'Argentina', 1),
('CHL', 'Chilena', 1),
('PER', 'Peruana', 1),
('CUB', 'Cubana', 1),
('VEN', 'Venezolana', 1),
('GTM', 'Guatemalteca', 1),
('SLV', 'Salvadoreña', 1),
('HND', 'Hondureña', 1),
('NIC', 'Nicaragüense', 1),
('CRI', 'Costarricense', 1),
('PAN', 'Panameña', 1),
('ECU', 'Ecuatoriana', 1),
('BOL', 'Boliviana', 1),
('PRY', 'Paraguaya', 1),
('URY', 'Uruguaya', 1),
('BRA', 'Brasileña', 1),
('OTR', 'Otra', 1);

-- Lenguas indígenas
INSERT IGNORE INTO catalogo_lenguas (clave, nombre, familia, activo) VALUES
('NAH', 'Náhuatl', 'Uto-azteca', 1),
('MAY', 'Maya', 'Maya', 1),
('ZAP', 'Zapoteco', 'Oto-mangue', 1),
('MIX', 'Mixteco', 'Oto-mangue', 1),
('OTM', 'Otomí', 'Oto-mangue', 1),
('TSO', 'Tzotzil', 'Maya', 1),
('TSJ', 'Tzeltal', 'Maya', 1),
('MAZ', 'Mazateco', 'Oto-mangue', 1),
('CHI', 'Chinanteco', 'Oto-mangue', 1),
('TOT', 'Totonaco', 'Totonaco-tepehua', 1),
('TAR', 'Tarahumara', 'Uto-azteca', 1),
('HUE', 'Huasteco', 'Maya', 1),
('POP', 'Popoluca', 'Mixe-Zoque', 1),
('ZOO', 'Zoque', 'Mixe-Zoque', 1),
('CHO', 'Chocholteco', 'Oto-mangue', 1),
('Ixc', 'Ixcateco', 'Oto-mangue', 1),
('MAT', 'Matlatzinca', 'Oto-mangue', 1),
('POP', 'Popoloca', 'Oto-mangue', 1),
('CHO', 'Chocho', 'Oto-mangue', 1);

-- Religiones
INSERT IGNORE INTO catalogo_religiones (clave, nombre, activo) VALUES
('CAT', 'Católica', 1),
('PRO', 'Protestante', 1),
('EVG', 'Evangélica', 1),
('JUD', 'Judía', 1),
('ISL', 'Islámica', 1),
('BUD', 'Budista', 1),
('HIND', 'Hindú', 1),
('IND', 'Indígena', 1),
('ATE', 'Atea / no religiosa', 1),
('OTR', 'Otra', 1);

-- --------------------------------------------------------
-- D. CATÁLOGOS ADMINISTRATIVOS Y HOSPITALARIOS
-- --------------------------------------------------------

-- Establecimientos de salud
INSERT IGNORE INTO catalogo_establecimientos (clave, nombre, tipo, direccion, activo) VALUES
('EST001', 'Hospital General de México', 'Hospital General', 'Dr. Balmis 138, Doctores, Cuauhtémoc, CDMX', 1),
('EST002', 'Hospital Infantil de México Federico Gómez', 'Hospital Infantil', 'Calzada México-Tacuba 236, CDMX', 1),
('EST003', 'Hospital de Especialidades del CMN Siglo XXI', 'Hospital de Especialidades', 'Av. Cuauhtémoc 330, Colonia Doctores, CDMX', 1),
('EST004', 'HIMFG', 'Hospital Materno Infantil', 'Av. Insurgentes Sur 3325, CDMX', 1),
('EST005', 'Centro de Salud Urbano', 'Centro de Salud', 'Dirección variable', 1);

-- Tipos de personal
INSERT IGNORE INTO catalogo_tipos_personal (clave, nombre, descripcion, activo) VALUES
('MED', 'Médico', 'Médico cirujano', 1),
('ENF', 'Enfermería', 'Personal de enfermería', 1),
('ADM', 'Administrativo', 'Personal administrativo', 1),
('INT', 'Interno', 'Médico interno', 1),
('RES', 'Residente', 'Médico residente', 1),
('FAR', 'Farmacéutico', 'Farmacéutico', 1),
('LAB', 'Laboratorista', 'Técnico de laboratorio', 1),
('IMG', 'Imagenología', 'Técnico en imagenología', 1);

-- Formación académica
INSERT IGNORE INTO catalogo_formacion_academica (clave, nombre, nivel, activo) VALUES
('LIC', 'Licenciatura', 'Licenciatura', 1),
('ESP', 'Especialidad', 'Especialidad médica', 1),
('SUB', 'Subespecialidad', 'Subespecialidad médica', 1),
('MAE', 'Maestría', 'Maestría', 1),
('DOC', 'Doctorado', 'Doctorado', 1);

-- Material de curación
INSERT IGNORE INTO catalogo_material_curacion (clave, nombre, descripcion, activo) VALUES
('GASA', 'Gasas estériles', 'Gasas estériles 10x10 cm', 1),
('VEND', 'Vendas elásticas', 'Vendas elásticas 8 cm', 1),
('ALGOD', 'Algodón hidrófilo', 'Algodón hidrófilo 500 g', 1),
('ESPAR', 'Esparadrapo', 'Esparadrapo hipoalergénico', 1),
('ANTISEP', 'Antiséptico', 'Antiséptico clorhexidina 2%', 1),
('CURAS', 'Kit de curación', 'Kit completo de curación', 1),
('GUANTES', 'Guantes de látex', 'Guantes de látex estériles', 1),
('MASCARILLA', 'Mascarilla quirúrgica', 'Mascarilla quirúrgica 3 capas', 1);

-- Instrumental y equipo médico
INSERT IGNORE INTO catalogo_instrumental (clave, nombre, descripcion, activo) VALUES
('ESTET', 'Estetoscopio', 'Estetoscopio doble campana', 1),
('TENSIOM', 'Tensiómetro aneroide', 'Tensiómetro aneroide de brazos', 1),
('TERMO', 'Termómetro digital', 'Termómetro digital oral', 1),
('OXIM', 'Oxímetro de pulso', 'Oxímetro de pulso digital', 1),
('BISTURI', 'Bisturí desechable', 'Bisturí desechable hoja #10', 1),
('PINZA', 'Pinzas hemostáticas', 'Pinzas hemostáticas Kelly', 1),
('TIJERAS', 'Tijeras quirúrgicas', 'Tijeras quirúrgicas rectas', 1),
('AGUJAS', 'Agujas hipodérmicas', 'Agujas hipodérmicas estériles', 1);

-- Clasificación CIF
INSERT IGNORE INTO catalogo_cif (codigo, nombre, descripcion, activo) VALUES
('b1', 'Funciones del sistema nervioso', 'Funciones mentales y del sistema nervioso', 1),
('b2', 'Funciones sensoriales y del dolor', 'Vista, oído, dolor', 1),
('b3', 'Funciones de la voz y el habla', 'Comunicación verbal', 1),
('b4', 'Funciones del sistema cardiovascular', 'Corazón y circulación', 1),
('b5', 'Funciones del sistema digestivo', 'Digestión y metabolismo', 1),
('b6', 'Funciones del sistema genitourinario', 'Sistema genitourinario', 1),
('b7', 'Funciones neuromusculoesqueléticas', 'Movimiento y postura', 1),
('b8', 'Funciones de la piel y estructuras relacionadas', 'Piel y estructuras relacionadas', 1),
('d1', 'Aprendizaje y aplicación del conocimiento', 'Capacidades cognitivas', 1),
('d2', 'Tareas y demandas generales', 'Actividades diarias', 1);

-- --------------------------------------------------------
-- RESUMEN
-- --------------------------------------------------------
SELECT 'medicamentos' as tabla, COUNT(*) as registros FROM medicamentos
UNION ALL SELECT 'cie10', COUNT(*) FROM cie10
UNION ALL SELECT 'catalogo_especialidades', COUNT(*) FROM catalogo_especialidades
UNION ALL SELECT 'catalogo_tipos_consulta', COUNT(*) FROM catalogo_tipos_consulta
UNION ALL SELECT 'catalogo_tipos_atencion', COUNT(*) FROM catalogo_tipos_atencion
UNION ALL SELECT 'catalogo_procedimientos', COUNT(*) FROM catalogo_procedimientos
UNION ALL SELECT 'catalogo_principios_activos', COUNT(*) FROM catalogo_principios_activos
UNION ALL SELECT 'catalogo_formas_farmaceuticas', COUNT(*) FROM catalogo_formas_farmaceuticas
UNION ALL SELECT 'catalogo_concentraciones', COUNT(*) FROM catalogo_concentraciones
UNION ALL SELECT 'catalogo_vias_admin', COUNT(*) FROM catalogo_vias_admin
UNION ALL SELECT 'catalogo_unidades_medida', COUNT(*) FROM catalogo_unidades_medida
UNION ALL SELECT 'catalogo_categorias_farmaco', COUNT(*) FROM catalogo_categorias_farmaco
UNION ALL SELECT 'catalogo_entidades', COUNT(*) FROM catalogo_entidades
UNION ALL SELECT 'catalogo_municipios', COUNT(*) FROM catalogo_municipios
UNION ALL SELECT 'catalogo_localidades', COUNT(*) FROM catalogo_localidades
UNION ALL SELECT 'catalogo_codigos_postales', COUNT(*) FROM catalogo_codigos_postales
UNION ALL SELECT 'catalogo_nacionalidades', COUNT(*) FROM catalogo_nacionalidades
UNION ALL SELECT 'catalogo_lenguas', COUNT(*) FROM catalogo_lenguas
UNION ALL SELECT 'catalogo_religiones', COUNT(*) FROM catalogo_religiones
UNION ALL SELECT 'catalogo_establecimientos', COUNT(*) FROM catalogo_establecimientos
UNION ALL SELECT 'catalogo_tipos_personal', COUNT(*) FROM catalogo_tipos_personal
UNION ALL SELECT 'catalogo_formacion_academica', COUNT(*) FROM catalogo_formacion_academica
UNION ALL SELECT 'catalogo_material_curacion', COUNT(*) FROM catalogo_material_curacion
UNION ALL SELECT 'catalogo_instrumental', COUNT(*) FROM catalogo_instrumental
UNION ALL SELECT 'catalogo_cif', COUNT(*) FROM catalogo_cif;

-- ==========================================================================
-- Corrección de vínculos de ubicación (01-nov-2026)
-- La semilla original asignó entidad_id con ids supuestos que no coinciden con catalogo_entidades,
-- así que los municipios quedaron colgados de otro estado; además, los municipios de Guanajuato no se
-- insertaron porque su entidad no existía. Estas sentencias son idempotentes.
-- ==========================================================================

-- 1. Municipios de Guanajuato que faltaban (su entidad_id no existía)
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '001', 'Acámbaro', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '002', 'San Miguel de Allende', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '003', 'Dolores Hidalgo', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '004', 'León', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '005', 'Celaya', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';
INSERT IGNORE INTO catalogo_municipios (entidad_id, clave, nombre, activo)
SELECT e.id, '006', 'Irapuato', 1 FROM catalogo_entidades e WHERE e.clave = 'GT';

-- 2. Cada grupo de municipios a su entidad (por clave, no por id supuesto)
UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'AS'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Aguascalientes', 'Asientos', 'Calvillo', 'Jesús María', 'Pabellón de Arteaga');

UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'BC'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Mexicali', 'Ensenada', 'Tijuana', 'Tecate', 'Rosarito');

UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'GT'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Acámbaro', 'San Miguel de Allende', 'Dolores Hidalgo', 'León', 'Celaya', 'Irapuato');

UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'GR'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Acapulco', 'Chilpancingo', 'Iguala', 'Taxco', 'Zihuatanejo');

UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'JC'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Guadalajara', 'Zapopan', 'Tlaquepaque', 'Tonalá', 'Tlajomulco');

UPDATE catalogo_municipios m JOIN catalogo_entidades e ON e.clave = 'DF'
   SET m.entidad_id = e.id
 WHERE m.nombre IN ('Cuauhtémoc', 'Miguel Hidalgo', 'Benito Juárez', 'Venustiano Carranza', 'Gustavo A. Madero');

-- 3. Localidades: su municipio es el que tiene el mismo nombre
UPDATE catalogo_localidades l JOIN catalogo_municipios m ON m.nombre = l.nombre
   SET l.municipio_id = m.id;