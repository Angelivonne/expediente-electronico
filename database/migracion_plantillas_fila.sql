-- Plantillas de fila para bases de datos: una fila con valores predefinidos que se reutiliza.
-- Ejecutar como root una sola vez:  mysql -u root -p expediente_expediente < database/migracion_plantillas_fila.sql

CREATE TABLE IF NOT EXISTS plantillas_fila (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pagina_id      INT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id     INT UNSIGNED NOT NULL DEFAULT 0,
  nombre         VARCHAR(80)  NOT NULL,
  valores        LONGTEXT     NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pfila_pagina (pagina_id, institucion_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE ON expediente_expediente.plantillas_fila TO 'expediente_app'@'localhost';
FLUSH PRIVILEGES;
