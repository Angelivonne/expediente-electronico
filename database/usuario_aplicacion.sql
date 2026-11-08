-- ==========================================================================
-- Usuario de MySQL con privilegios mínimos para la aplicación (misma base: expediente_expediente).
-- Ejecutar como root y guardar la contraseña en privado/bd.php:
--   <?php return ['usuario' => 'expediente_app', 'clave' => 'LA_CONTRASEí‘A'];
-- La bitácora y el historial clínico solo admiten INSERT: no se pueden modificar ni borrar.
-- ==========================================================================

CREATE USER IF NOT EXISTS 'expediente_app'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEí‘A';
CREATE USER IF NOT EXISTS 'expediente_app'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEí‘A';

-- Datos que se crean y actualizan
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.pacientes             TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.usuarios              TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.codigos_verificacion  TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.configuracion         TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.dictados_calificacion TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.invitaciones          TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Inmutables (NOM-004 5.10 y trazabilidad NOM-024)
GRANT SELECT, INSERT ON expediente_expediente.bitacora          TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT ON expediente_expediente.historial_clinico TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Espacio de trabajo, límites de intentos y presencia (se pueden borrar)
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.limites           TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.paginas           TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.pagina_filas      TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.paginas_favoritas TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.pagina_permisos   TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.presencia         TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Agenda (las citas se cancelan, no se borran)
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.citas             TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Notificaciones (se marcan como leídas o se archivan; no se borran)
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.notificaciones    TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Conversiones de datos (importar/exportar): registro inmutable, solo INSERT
GRANT SELECT, INSERT ON expediente_expediente.conversiones TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Peticiones idempotentes de la cola sin conexión (se borran solas a los 7 días)
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.peticiones_idempotentes TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Sincronización con el servidor central: la cola la llenan triggers (corren con los privilegios de root,
-- su DEFINER); la app marca acuses, reintentos y borra lo enviado hace más de 30 días.
-- Sin DELETE en pacientes, citas ni historial: allí no hay bajas físicas que aplicar.
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.sincronizacion_cola TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Catálogos de la clínica: la app los lee (autocompletado del formulario) y corrige sus renglones
-- en línea desde /cie10/ y /medicamentos/ (api/catalogos/actualizar.php y nuevo.php).
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.medicamentos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.cie10        TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
-- Firmas digitales de las notas (NOM-024 6.9): se firman y se revocan; nunca se borran.
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.firmas               TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Los demás catálogos (procedimientos, especialidades, vías, municipios€¦) viven como páginas del
-- espacio de trabajo (paginas + pagina_filas), no como tablas propias: no necesitan permisos aquí.

-- ==========================================================================
-- Respaldos (app/servicios/sistema/respaldos.php): usuario aparte, solo para mysqldump y para restaurar.
-- La aplicación normal nunca lo usa. Contraseña en privado/respaldo_bd.php:
--   <?php return ['usuario' => 'expediente_respaldo', 'clave' => 'LA_CONTRASEí‘A'];
-- Restaurar recrea las tablas, por eso necesita CREATE/DROP/ALTER sobre esta base (y solo esta).
-- ==========================================================================
CREATE USER IF NOT EXISTS 'expediente_respaldo'@'localhost' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEí‘A';
CREATE USER IF NOT EXISTS 'expediente_respaldo'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CONTRASEí‘A';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES, SHOW VIEW, CREATE VIEW, TRIGGER, EVENT, CREATE ROUTINE, ALTER ROUTINE, EXECUTE
  ON expediente_expediente.* TO 'expediente_respaldo'@'localhost', 'expediente_respaldo'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Instituciones y códigos de acceso (database/esquema.sql, sección "Varias instituciones").
-- El historial clínico y las conversiones siguen siendo de solo inserción: solo se permite
-- asignar institucion_id (las filas de antes pasan a la institución que las adopta).
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.instituciones       TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.codigos_acceso      TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT         ON expediente_expediente.codigos_acceso_usos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON expediente_expediente.historial_clinico  TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON expediente_expediente.conversiones       TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Notificaciones push (database/esquema.sql, sección "Notificaciones push del sistema"):
-- suscripciones, preferencias, cola de envíos y antirrepetición; se crean, cambian y borran.
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.push_suscripciones   TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.push_preferencias    TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.push_cola            TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.push_avisos_enviados TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Funciones clínicas complementarias: imágenes, memoria del asistente y recuperación de claves.
GRANT SELECT, INSERT, DELETE ON expediente_expediente.imagenes_medicas    TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.asistente_memoria TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.recuperaciones_clave TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';

-- Catálogos de referencia del proyecto (database/migracion_catalogos_admin.sql), tabla por tabla.
-- La app los lee en su página y los corrige desde su editor; se listan uno por uno porque
-- el comodín con guion bajo no se puede usar en GRANT desde la consola.
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_acupuntura TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_auxiliares_diagnostico TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_categorias_farmaco TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_cie11 TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_cif TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_clues TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_codigos_postales TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_concentraciones TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_entidades TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_especialidades TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_establecimientos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_formacion_academica TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_formas_farmaceuticas TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_herbolarios TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_homeopaticos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_instrumental TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_insumos_salud_publica TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_lenguas TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_localidades TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_loinc TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_material_curacion TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_municipios TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_nacionalidades TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_nutriologia TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_osteosintesis TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_principios_activos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_procedimientos TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_religiones TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_snomed TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_tipos_atencion TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_tipos_consulta TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_tipos_personal TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_unidades_medida TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.catalogo_vias_admin TO 'expediente_app'@'localhost', 'expediente_app'@'127.0.0.1';


