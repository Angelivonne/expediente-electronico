-- Comentarios por registro del expediente (notas breves del equipo, con autor y fecha).
-- Un comentario no sustituye una nota clínica: sirve para pendientes y avisos entre el personal.
-- Ejecutar como root una sola vez:  mysql -u root -p expediente_expediente < database/migracion_comentarios.sql

CREATE TABLE IF NOT EXISTS comentarios (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institucion_id INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  entidad        VARCHAR(40)  NOT NULL,
  registro_id    INT UNSIGNED NOT NULL,
  texto          VARCHAR(1000) NOT NULL,
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_comentarios_registro (institucion_id, entidad, registro_id, creado_en),
  KEY idx_comentarios_usuario (usuario_id),
  KEY idx_comentarios_creado (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE ON expediente_expediente.comentarios TO 'expediente_app'@'localhost';
FLUSH PRIVILEGES;
