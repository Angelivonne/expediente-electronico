-- ==========================================================================
-- Expediente Expediente — Migración: catálogo de medicamentos, CIE-10 e incapacidades
-- Ejecutar en la base de datos clinica existente.
-- Repetirlo no cambia nada (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS).
-- ==========================================================================

USE clinica;

-- --------------------------------------------------------
-- Catálogo de medicamentos de la institución
-- clave_compendio: clave oficial del Compendio Nacional de Insumos (NOM-024 Apéndice A)
-- forma_farmaceutica: forma farmacéutica oficial
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS medicamentos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(30)  NOT NULL,           -- clave interna del catálogo local
  clave_compendio VARCHAR(20)  NULL,               -- clave oficial Compendio Nacional de Insumos (NOM-024 Apéndice A)
  nombre          VARCHAR(160) NOT NULL,           -- nombre comercial o genérico
  forma_farmaceutica VARCHAR(160) NULL,            -- forma farmacéutica oficial
  foto            VARCHAR(255) NULL,               -- ruta de imagen del medicamento (foto)
  presentacion    VARCHAR(400) NULL,               -- comprimido, jarabe, inyectable…
  concentracion   VARCHAR(160) NULL,               -- 500 mg, 10 ml…
  via             VARCHAR(80)  NULL,               -- oral, intravenosa…
  laboratorio     VARCHAR(120) NULL,
  grupo_terapeutico VARCHAR(60) NULL,
  descripcion_compendio MEDIUMTEXT NULL,
  indicaciones_compendio MEDIUMTEXT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  sinc_modificado_en DATETIME(6) NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_medicamentos_clave (institucion_id, clave),
  UNIQUE KEY uq_medicamentos_uuid (uuid),
  INDEX idx_medicamentos_institucion (institucion_id, nombre),
  INDEX idx_medicamentos_compendio (clave_compendio),
  CONSTRAINT fk_medicamentos_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- CIE-10: diagnósticos con clave de catálogo oficial (NOM-024)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS cie10 (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  codigo          CHAR(7)      NOT NULL,           -- E11, I10, J45.9…
  descripcion     VARCHAR(255) NOT NULL,           -- Descripción oficial completa
  capitulo        VARCHAR(130) NULL,               -- Capítulo oficial DGIS
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  sinc_modificado_en DATETIME(6) NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cie10_codigo (institucion_id, codigo),
  UNIQUE KEY uq_cie10_uuid (uuid),
  INDEX idx_cie10_institucion (institucion_id, codigo, descripcion),
  CONSTRAINT fk_cie10_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- Agregar 'incapacidad' al tipo de registro del historial clínico
-- --------------------------------------------------------
ALTER TABLE historial_clinico
  MODIFY tipo ENUM('consulta','receta','hospitalizacion','estudio','urgencias',
                   'interconsulta','referencia','egreso','consentimiento','incapacidad') NOT NULL;

-- --------------------------------------------------------
-- Columnas de sincronización para las nuevas tablas (consistencia con el resto)
-- --------------------------------------------------------
ALTER TABLE medicamentos
  MODIFY COLUMN forma_farmaceutica VARCHAR(160) NULL,
  MODIFY COLUMN presentacion VARCHAR(400) NULL,
  MODIFY COLUMN concentracion VARCHAR(160) NULL,
  MODIFY COLUMN via VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS grupo_terapeutico VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS descripcion_compendio MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS indicaciones_compendio MEDIUMTEXT NULL,
  ADD COLUMN IF NOT EXISTS clave_compendio VARCHAR(20) NULL AFTER clave,
  ADD COLUMN IF NOT EXISTS forma_farmaceutica VARCHAR(160) NULL AFTER concentracion,
  ADD COLUMN IF NOT EXISTS foto VARCHAR(255) NULL AFTER presentacion,
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_medicamentos_uuid (uuid),
  ADD INDEX IF NOT EXISTS idx_medicamentos_compendio (clave_compendio);

ALTER TABLE cie10
  MODIFY COLUMN descripcion VARCHAR(255) NOT NULL,
  MODIFY COLUMN capitulo VARCHAR(130) NULL,
  ADD COLUMN IF NOT EXISTS uuid CHAR(36) CHARACTER SET ascii NOT NULL DEFAULT (UUID()) AFTER id,
  ADD COLUMN IF NOT EXISTS sinc_modificado_en DATETIME(6) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_cie10_uuid (uuid);

-- Marca inicial de sincronización de filas existentes
UPDATE medicamentos SET sinc_modificado_en = COALESCE(actualizado_en, creado_en) WHERE sinc_modificado_en IS NULL;
UPDATE cie10        SET sinc_modificado_en = COALESCE(actualizado_en, creado_en) WHERE sinc_modificado_en IS NULL;
