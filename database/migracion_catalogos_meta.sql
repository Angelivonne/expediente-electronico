-- Metadatos de catálogo persistidos: fuente, versión, vigencia y responsable
CREATE TABLE IF NOT EXISTS catalogos_meta (
  clave VARCHAR(64) NOT NULL PRIMARY KEY,
  fuente VARCHAR(160) NULL,
  version VARCHAR(80) NULL,
  vigencia DATE NULL,
  responsable VARCHAR(120) NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  usuario_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
