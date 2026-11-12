-- ==========================================================================
-- Usuarios de MySQL con privilegios mínimos. Antes de ejecutar como root,
-- sustituir __APP_DB_PASSWORD__ y, si se habilitan respaldos, __BACKUP_DB_PASSWORD__.
-- Guardar cada valor en el archivo privado correspondiente.
--   <?php return ['usuario' => 'clinica_app', 'clave' => '__APP_DB_PASSWORD__'];
-- La bitácora y el historial clínico solo admiten INSERT: no se pueden modificar ni borrar.
-- ==========================================================================

CREATE USER IF NOT EXISTS 'clinica_app'@'localhost' IDENTIFIED BY '__APP_DB_PASSWORD__';
CREATE USER IF NOT EXISTS 'clinica_app'@'127.0.0.1' IDENTIFIED BY '__APP_DB_PASSWORD__';
ALTER USER 'clinica_app'@'localhost' IDENTIFIED BY '__APP_DB_PASSWORD__';
ALTER USER 'clinica_app'@'127.0.0.1' IDENTIFIED BY '__APP_DB_PASSWORD__';

-- Datos que se crean y actualizan
GRANT SELECT, INSERT, UPDATE ON clinica.pacientes             TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.usuarios              TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.codigos_verificacion  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.configuracion         TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.dictados_calificacion TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.invitaciones          TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.preferencias_usuario  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.vistas_tabla_usuario TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Inmutables (NOM-004 5.10 y trazabilidad NOM-024)
GRANT SELECT, INSERT ON clinica.bitacora          TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.historial_clinico TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Espacio de trabajo, límites de intentos y presencia (se pueden borrar)
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.limites           TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.paginas           TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.pagina_filas      TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.paginas_favoritas TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.pagina_permisos   TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.presencia         TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
-- Seguimiento aproximado del equipo y retroalimentación moderada (sin borrado desde la app)
GRANT SELECT, INSERT, UPDATE ON clinica.equipo_tiempo_activo      TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.retroalimentacion_equipo   TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- Agenda (las citas se cancelan, no se borran)
GRANT SELECT, INSERT, UPDATE ON clinica.citas             TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.flujo_atencion    TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.flujo_atencion_eventos    TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.cirugias_programadas TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.cirugias_eventos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Notificaciones (se marcan como leídas o se archivan; no se borran)
GRANT SELECT, INSERT, UPDATE ON clinica.notificaciones    TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Conversiones de datos (importar/exportar): registro inmutable, solo INSERT
GRANT SELECT, INSERT ON clinica.conversiones TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Peticiones idempotentes de la cola sin conexión (se borran solas a los 7 días)
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.peticiones_idempotentes TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Sincronización con el servidor central: la cola la llenan triggers (corren con los privilegios de root,
-- su DEFINER); la app marca acuses, reintentos y borra lo enviado hace más de 30 días.
-- Sin DELETE en pacientes, citas ni historial: allí no hay bajas físicas que aplicar.
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.sincronizacion_cola TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Catálogos de la clínica: la app los lee (autocompletado del formulario) y corrige sus renglones
-- en línea desde /cie10/ y /medicamentos/ (api/catalogos/actualizar.php y nuevo.php).
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.medicamentos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.cie10        TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Farmacia: las existencias se actualizan transaccionalmente; el libro de movimientos es de solo inserción.
GRANT SELECT, INSERT, UPDATE ON clinica.farmacia_lotes TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.farmacia_movimientos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
-- Consentimientos: los términos solo se insertan; el estado únicamente puede cambiar para revocar.
GRANT SELECT, INSERT ON clinica.consentimientos_morbilidad TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT UPDATE (estado, revocado_en, revocado_por) ON clinica.consentimientos_morbilidad TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT ON clinica.consentimientos_morbilidad_eventos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
-- Firmas digitales de las notas (NOM-024 6.9): se firman y se revocan; nunca se borran.
GRANT SELECT, INSERT, UPDATE ON clinica.firmas               TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Los demás catálogos (procedimientos, especialidades, vías, municipios€¦) viven como páginas del
-- espacio de trabajo (paginas + pagina_filas), no como tablas propias: no necesitan permisos aquí.

-- ==========================================================================
-- Respaldos (app/servicios/sistema/respaldos.php): usuario aparte, solo para mysqldump y para restaurar.
-- La aplicación normal nunca lo usa. Contraseña en privado/respaldo_bd.php:
--   <?php return ['usuario' => 'clinica_respaldo', 'clave' => '__BACKUP_DB_PASSWORD__'];
-- Restaurar recrea las tablas, por eso necesita CREATE/DROP/ALTER sobre esta base (y solo esta).
-- ==========================================================================
CREATE USER IF NOT EXISTS 'clinica_respaldo'@'localhost' IDENTIFIED BY '__BACKUP_DB_PASSWORD__';
CREATE USER IF NOT EXISTS 'clinica_respaldo'@'127.0.0.1' IDENTIFIED BY '__BACKUP_DB_PASSWORD__';
ALTER USER 'clinica_respaldo'@'localhost' IDENTIFIED BY '__BACKUP_DB_PASSWORD__';
ALTER USER 'clinica_respaldo'@'127.0.0.1' IDENTIFIED BY '__BACKUP_DB_PASSWORD__';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, LOCK TABLES, SHOW VIEW, CREATE VIEW, TRIGGER, EVENT, CREATE ROUTINE, ALTER ROUTINE, EXECUTE
  ON clinica.* TO 'clinica_respaldo'@'localhost', 'clinica_respaldo'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Instituciones y códigos de acceso (database/esquema.sql, sección "Varias instituciones").
-- El historial clínico y las conversiones siguen siendo de solo inserción: solo se permite
-- asignar institucion_id (las filas de antes pasan a la institución que las adopta).
GRANT SELECT, INSERT, UPDATE ON clinica.instituciones       TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.codigos_acceso      TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT         ON clinica.codigos_acceso_usos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON clinica.historial_clinico  TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT UPDATE (institucion_id) ON clinica.conversiones       TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Notificaciones push (database/esquema.sql, sección "Notificaciones push del sistema"):
-- suscripciones, preferencias, cola de envíos y antirrepetición; se crean, cambian y borran.
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.push_suscripciones   TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.push_preferencias    TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.push_cola            TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.push_avisos_enviados TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
FLUSH PRIVILEGES;

-- Funciones clínicas complementarias: imágenes, memoria del asistente y recuperación de claves.
GRANT SELECT, INSERT, DELETE ON clinica.imagenes_medicas    TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.asistente_memoria TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON clinica.recuperaciones_clave TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';

-- Catálogos de referencia del proyecto (database/migracion_catalogos_admin.sql), tabla por tabla.
-- La app los lee en su página y los corrige desde su editor; se listan uno por uno porque
-- el comodín con guion bajo no se puede usar en GRANT desde la consola.
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_acupuntura TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_auxiliares_diagnostico TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_categorias_farmaco TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_cie11 TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_cif TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_clues TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_codigos_postales TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_concentraciones TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_entidades TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_especialidades TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_establecimientos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_formacion_academica TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_formas_farmaceuticas TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_herbolarios TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_homeopaticos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_instrumental TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_insumos_salud_publica TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_lenguas TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_localidades TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_loinc TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_material_curacion TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_municipios TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_nacionalidades TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_nutriologia TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_osteosintesis TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_principios_activos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_procedimientos TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_religiones TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_snomed TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_tipos_atencion TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_tipos_consulta TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_tipos_personal TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_unidades_medida TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON clinica.catalogo_vias_admin TO 'clinica_app'@'localhost', 'clinica_app'@'127.0.0.1';
