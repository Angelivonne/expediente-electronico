-- Consentimiento trazable para exportar estadísticas agregadas de morbilidad.
-- Idempotente: puede ejecutarse más de una vez.
CREATE TABLE IF NOT EXISTS consentimientos_morbilidad (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  paciente_id INT UNSIGNED NOT NULL,
  version VARCHAR(20) NOT NULL,
  texto_consentimiento TEXT NOT NULL,
  texto_sha256 CHAR(64) NOT NULL,
  destinatario VARCHAR(200) NOT NULL,
  otorgante_tipo ENUM('paciente','representante') NOT NULL,
  otorgante_nombre VARCHAR(160) NOT NULL,
  relacion VARCHAR(100) NULL,
  fecha_otorgamiento DATE NOT NULL,
  estado ENUM('vigente','revocado') NOT NULL DEFAULT 'vigente',
  capturado_por INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revocado_en DATETIME NULL,
  revocado_por INT UNSIGNED NULL,
  KEY idx_cons_morb_vigencia (institucion_id, paciente_id, estado),
  KEY idx_cons_morb_destino (institucion_id, version, estado, destinatario(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consentimientos_morbilidad_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  consentimiento_id BIGINT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NOT NULL,
  paciente_id INT UNSIGNED NOT NULL,
  tipo ENUM('otorgado','revocado') NOT NULL,
  usuario_id INT UNSIGNED NOT NULL,
  solicitante_tipo ENUM('paciente','representante') NOT NULL,
  solicitante_nombre VARCHAR(160) NOT NULL,
  relacion VARCHAR(100) NULL,
  motivo VARCHAR(500) NULL,
  evidencia_archivo VARCHAR(80) NULL,
  evidencia_nombre VARCHAR(200) NULL,
  evidencia_mime VARCHAR(40) NULL,
  evidencia_sha256 CHAR(64) NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cons_morb_eventos (institucion_id, paciente_id, creado_en),
  KEY idx_cons_morb_evento_consentimiento (consentimiento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
