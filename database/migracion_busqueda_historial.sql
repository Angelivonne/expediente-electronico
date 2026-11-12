-- FULLTEXT auxiliar para buscar en títulos y contenido JSON de notas clínicas.
-- No actualiza ni altera las filas inmutables de historial_clinico.
-- El instalador y el actualizador local la aplican automáticamente; también puede ejecutarse
-- con una cuenta que tenga CREATE, ALTER, TRIGGER y REFERENCES sobre esta base.

CREATE TABLE IF NOT EXISTS historial_clinico_busqueda (
  historial_id INT UNSIGNED NOT NULL PRIMARY KEY,
  texto        LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  FULLTEXT INDEX ft_historial_busqueda_texto (texto),
  CONSTRAINT fk_historial_busqueda_registro
    FOREIGN KEY (historial_id) REFERENCES historial_clinico (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @indice_busqueda_historial := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'historial_clinico_busqueda'
     AND INDEX_NAME = 'ft_historial_busqueda_texto'
);
SET @sql_busqueda_historial := IF(
  @indice_busqueda_historial > 0,
  'SELECT 1',
  'ALTER TABLE historial_clinico_busqueda ADD FULLTEXT INDEX ft_historial_busqueda_texto (texto)'
);
PREPARE stmt_busqueda_historial FROM @sql_busqueda_historial;
EXECUTE stmt_busqueda_historial;
DEALLOCATE PREPARE stmt_busqueda_historial;

DROP TRIGGER IF EXISTS historial_busqueda_ai;
DROP TRIGGER IF EXISTS historial_busqueda_au;

DELIMITER //
CREATE TRIGGER historial_busqueda_ai AFTER INSERT ON historial_clinico FOR EACH ROW
BEGIN
  INSERT INTO historial_clinico_busqueda (historial_id, texto)
  VALUES (NEW.id, CONVERT(CONCAT_WS(' ', NEW.titulo, CAST(NEW.detalle AS CHAR)) USING utf8mb4))
  ON DUPLICATE KEY UPDATE texto = VALUES(texto);
END//

CREATE TRIGGER historial_busqueda_au AFTER UPDATE ON historial_clinico FOR EACH ROW
BEGIN
  INSERT INTO historial_clinico_busqueda (historial_id, texto)
  VALUES (NEW.id, CONVERT(CONCAT_WS(' ', NEW.titulo, CAST(NEW.detalle AS CHAR)) USING utf8mb4))
  ON DUPLICATE KEY UPDATE texto = VALUES(texto);
END//
DELIMITER ;

INSERT INTO historial_clinico_busqueda (historial_id, texto)
SELECT id, CONVERT(CONCAT_WS(' ', titulo, CAST(detalle AS CHAR)) USING utf8mb4)
  FROM historial_clinico
ON DUPLICATE KEY UPDATE texto = VALUES(texto);
