-- Plantillas propias: cualquier nota se puede guardar como plantilla del equipo y reutilizarse.
-- Ejecutar como root una sola vez:  mysql -u root -p expediente_expediente < database/migracion_plantillas.sql

CREATE TABLE IF NOT EXISTS plantillas (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institucion_id INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL DEFAULT 0,
  titulo         VARCHAR(200) NOT NULL,
  contenido      LONGTEXT     NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_plantillas_institucion (institucion_id, activo, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.plantillas TO 'expediente_app'@'localhost';
FLUSH PRIVILEGES;
