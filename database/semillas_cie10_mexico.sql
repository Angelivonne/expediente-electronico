-- ==========================================================================
-- Expediente Expediente — Semilla de CIE-10 (diagnósticos reales oficiales México)
-- Ejecutar DESPUÉS de database/migracion_medicamentos_cie10_incapacidad.sql
-- Claves CIE-10 oficiales (OMS / SSA México). Sin datos inventados.
-- Requiere: institución con id conocido. Cambia SET @INST por tu id.
-- Repetirlo no duplica (INSERT IGNORE por uq_cie10_codigo).
-- ==========================================================================

USE expediente_expediente;

SET @INST := NULL;  -- ejemplo: SET @INST := 1;

INSERT IGNORE INTO cie10
  (institucion_id, codigo, descripcion, capitulo, activo)
VALUES
  (@INST, 'E11',   'Diabetes mellitus tipo 2',                           'IV. Endocrino, nutricional y metabólico', 1),
  (@INST, 'E11.9', 'Diabetes mellitus tipo 2 sin complicaciones',        'IV. Endocrino, nutricional y metabólico', 1),
  (@INST, 'I10',   'Hipertensión esencial (primaria)',                   'IX. Sistema circulatorio', 1),
  (@INST, 'E78.5', 'Hiperlipidemia no especificada',                     'IV. Endocrino, nutricional y metabólico', 1),
  (@INST, 'E66.0', 'Obesidad debida a exceso de calorías',               'IV. Endocrino, nutricional y metabólico', 1),
  (@INST, 'J45.9', 'Asma, no especificada',                              'X. Sistema respiratorio', 1),
  (@INST, 'J06.9', 'Infección aguda de las vías respiratorias superiores, no especificada', 'X. Sistema respiratorio', 1),
  (@INST, 'I25.10','Enfermedad aterosclerótica de la arteria coronaria nativa no especificada', 'IX. Sistema circulatorio', 1),
  (@INST, 'F32.9', 'Episodio depresivo, no especificado',                'V. Trastornos mentales y del comportamiento', 1),
  (@INST, 'G40.9', 'Epilepsia, no especificada',                         'VI. Sistema nervioso', 1),
  (@INST, 'M54.5', 'Dolor lumbar',                                       'XIII. Osteoarticular y tejido conectivo', 1),
  (@INST, 'M79.3', 'Panniculitis, no especificada',                      'XIII. Osteoarticular y tejido conectivo', 1),
  (@INST, 'N30.0', 'Cistitis aguda',                                     'XIV. Sistema genitourinario', 1),
  (@INST, 'O80',   'Parto espontáneo',                                   'XV. Embarazo, parto y puerperio', 1),
  (@INST, 'Z00.0', 'Examen médico general',                             'XXI. Factores que influyen en el estado de salud', 1),
  (@INST, 'Z01.0', 'Examen de los ojos y la visión',                     'XXI. Factores que influyen en el estado de salud', 1),
  (@INST, 'Z13.1', 'Examen de detección de diabetes mellitus',           'XXI. Factores que influyen en el estado de salud', 1),
  (@INST, 'A09',   'Diarrea y gastroenteritis presuntamente de origen infeccioso', 'I. Enfermedades infecciosas y parasitarias', 1),
  (@INST, 'B34.9', 'Infección viral, no especificada',                   'I. Enfermedades infecciosas y parasitarias', 1),
  (@INST, 'J18.9', 'Neumonía, no especificada',                          'X. Sistema respiratorio', 1),
  (@INST, 'K21.9', 'Enfermedad por reflujo gastroesofágico sin esofagitis', 'XI. Sistema digestivo', 1),
  (@INST, 'K29.7', 'Gastritis, no especificada',                          'XI. Sistema digestivo', 1),
  (@INST, 'L30.9', 'Dermatitis, no especificada',                         'XII. Piel y tejido celular subcutáneo', 1),
  (@INST, 'H66.9', 'Otitis media, no especificada',                       'VIII. Oído y apófisis mastoides', 1),
  (@INST, 'H10.9', 'Conjuntivitis, no especificada',                      'VII. Ojo y anexos', 1),
  (@INST, 'R05',   'Tos',                                               'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'R06.0', 'Falta de aliento',                                   'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'R07.9', 'Dolor en el pecho, no especificado',                 'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'R10.4', 'Otro dolor abdominal especificado',                  'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'R55',   'Síncope y colapso',                                 'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'R56.8', 'Otras convulsiones no febriales',                   'XVIII. Síntomas, signos y hallazgos anómalos', 1),
  (@INST, 'T14.9', 'Lesión, no especificada',                            'XIX. Lesiones, envenenamientos y otras consecuencias externas', 1);
