<?php
/**
 * Genera los triggers que mantienen el índice FULLTEXT derivado del historial.
 * Uso: php database/busqueda_historial_triggers.php | mysql clinica
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo <<<'SQL'
DELIMITER //
DROP TRIGGER IF EXISTS historial_busqueda_ai //
CREATE TRIGGER historial_busqueda_ai AFTER INSERT ON historial_clinico FOR EACH ROW
BEGIN
  INSERT INTO historial_clinico_busqueda (historial_id, texto)
  VALUES (NEW.id, CONVERT(CONCAT_WS(' ', NEW.titulo, CAST(NEW.detalle AS CHAR)) USING utf8mb4))
  ON DUPLICATE KEY UPDATE texto = VALUES(texto);
END//

DROP TRIGGER IF EXISTS historial_busqueda_au //
CREATE TRIGGER historial_busqueda_au AFTER UPDATE ON historial_clinico FOR EACH ROW
BEGIN
  INSERT INTO historial_clinico_busqueda (historial_id, texto)
  VALUES (NEW.id, CONVERT(CONCAT_WS(' ', NEW.titulo, CAST(NEW.detalle AS CHAR)) USING utf8mb4))
  ON DUPLICATE KEY UPDATE texto = VALUES(texto);
END//
DELIMITER ;
SQL;
