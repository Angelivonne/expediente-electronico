-- Versiones de registros del motor: guarda el estado ANTERIOR de cada cambio.
-- Así se puede auditar y restaurar cualquier registro (pacientes, catálogos, medicamentos…).
-- Ejecutar como root una sola vez:  mysql -u root -p expediente_expediente < database/migracion_versiones.sql

CREATE TABLE IF NOT EXISTS registro_versiones (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  institucion_id INT UNSIGNED NOT NULL,
  entidad        VARCHAR(40)  NOT NULL,
  registro_id    INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL DEFAULT 0,
  datos          LONGTEXT     NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_versiones_registro (entidad, registro_id, id),
  KEY idx_versiones_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, DELETE ON expediente_expediente.registro_versiones TO 'expediente_app'@'localhost';
FLUSH PRIVILEGES;
