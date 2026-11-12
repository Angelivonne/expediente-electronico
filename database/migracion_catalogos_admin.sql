-- ==========================================================================
-- Expediente Expediente — Migración: Catálogos de referencia (Administración)
-- Ejecutar DESPUÉS de database/migracion_medicamentos_cie10_incapacidad.sql
-- Todas las tablas pertenecen a una institución (institucion_id).
-- Repetirlo no duplica nada (IF NOT EXISTS).
-- ==========================================================================

USE clinica;

-- --------------------------------------------------------
-- A. CATÁLOGOS CLÍNICOS
-- --------------------------------------------------------

-- Procedimientos médicos (diagnósticos y terapéuticos)
CREATE TABLE IF NOT EXISTS catalogo_procedimientos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_proc_clave (institucion_id, clave),
  UNIQUE KEY uq_proc_uuid (uuid),
  INDEX idx_proc_institucion (institucion_id, nombre),
  CONSTRAINT fk_proc_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Especialidades médicas
CREATE TABLE IF NOT EXISTS catalogo_especialidades (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_esp_clave (institucion_id, clave),
  UNIQUE KEY uq_esp_uuid (uuid),
  INDEX idx_esp_institucion (institucion_id, nombre),
  CONSTRAINT fk_esp_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Tipos de consulta
CREATE TABLE IF NOT EXISTS catalogo_tipos_consulta (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tc_clave (institucion_id, clave),
  UNIQUE KEY uq_tc_uuid (uuid),
  INDEX idx_tc_institucion (institucion_id, nombre),
  CONSTRAINT fk_tc_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Tipos de atención
CREATE TABLE IF NOT EXISTS catalogo_tipos_atencion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ta_clave (institucion_id, clave),
  UNIQUE KEY uq_ta_uuid (uuid),
  INDEX idx_ta_institucion (institucion_id, nombre),
  CONSTRAINT fk_ta_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- B. CATÁLOGOS FARMACÉUTICOS
-- --------------------------------------------------------

-- Principios activos
CREATE TABLE IF NOT EXISTS catalogo_principios_activos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pa_clave (institucion_id, clave),
  UNIQUE KEY uq_pa_uuid (uuid),
  INDEX idx_pa_institucion (institucion_id, nombre),
  CONSTRAINT fk_pa_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Unidades de medida (debe ir antes de concentraciones)
CREATE TABLE IF NOT EXISTS catalogo_unidades_medida (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(10)  NOT NULL,
  nombre          VARCHAR(40)  NOT NULL,
  simbolo         VARCHAR(10)  NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_um_clave (institucion_id, clave),
  UNIQUE KEY uq_um_uuid (uuid),
  INDEX idx_um_institucion (institucion_id, nombre),
  CONSTRAINT fk_um_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Formas farmacéuticas
CREATE TABLE IF NOT EXISTS catalogo_formas_farmaceuticas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ff_clave (institucion_id, clave),
  UNIQUE KEY uq_ff_uuid (uuid),
  INDEX idx_ff_institucion (institucion_id, nombre),
  CONSTRAINT fk_ff_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Concentraciones
CREATE TABLE IF NOT EXISTS catalogo_concentraciones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  valor           VARCHAR(60)  NOT NULL,
  unidad_id       INT UNSIGNED NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conc_valor (institucion_id, valor),
  UNIQUE KEY uq_conc_uuid (uuid),
  INDEX idx_conc_institucion (institucion_id, valor),
  CONSTRAINT fk_conc_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id),
  CONSTRAINT fk_conc_unidad FOREIGN KEY (unidad_id) REFERENCES catalogo_unidades_medida (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Vías de administración
CREATE TABLE IF NOT EXISTS catalogo_vias_admin (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(60)  NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_va_clave (institucion_id, clave),
  UNIQUE KEY uq_va_uuid (uuid),
  INDEX idx_va_institucion (institucion_id, nombre),
  CONSTRAINT fk_va_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Categorías farmacológicas
CREATE TABLE IF NOT EXISTS catalogo_categorias_farmaco (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id  INT UNSIGNED NULL,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_catf_clave (institucion_id, clave),
  UNIQUE KEY uq_catf_uuid (uuid),
  INDEX idx_catf_institucion (institucion_id, nombre),
  CONSTRAINT fk_catf_institucion FOREIGN KEY (institucion_id) REFERENCES instituciones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- C. CATÁLOGOS GEOGRÁFICOS Y DEMOGRÁFICOS
-- --------------------------------------------------------

-- Entidades federativas
CREATE TABLE IF NOT EXISTS catalogo_entidades (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           CHAR(2)      NOT NULL,
  nombre          VARCHAR(80)  NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ent_clave (clave),
  INDEX idx_ent_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Municipios
CREATE TABLE IF NOT EXISTS catalogo_municipios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entidad_id      INT UNSIGNED NOT NULL,
  clave           VARCHAR(5)   NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_mun_clave (entidad_id, clave),
  INDEX idx_mun_entidad (entidad_id),
  INDEX idx_mun_nombre (nombre),
  CONSTRAINT fk_mun_entidad FOREIGN KEY (entidad_id) REFERENCES catalogo_entidades (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Localidades
CREATE TABLE IF NOT EXISTS catalogo_localidades (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  municipio_id    INT UNSIGNED NOT NULL,
  clave           VARCHAR(5)   NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_loc_clave (municipio_id, clave),
  INDEX idx_loc_municipio (municipio_id),
  INDEX idx_loc_nombre (nombre),
  CONSTRAINT fk_loc_municipio FOREIGN KEY (municipio_id) REFERENCES catalogo_municipios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Códigos postales
CREATE TABLE IF NOT EXISTS catalogo_codigos_postales (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          CHAR(5)      NOT NULL,
  localidad_id    INT UNSIGNED NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cp_codigo (codigo),
  INDEX idx_cp_localidad (localidad_id),
  CONSTRAINT fk_cp_localidad FOREIGN KEY (localidad_id) REFERENCES catalogo_localidades (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Nacionalidades
CREATE TABLE IF NOT EXISTS catalogo_nacionalidades (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(5)   NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_nac_clave (clave),
  INDEX idx_nac_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Lenguas indígenas
CREATE TABLE IF NOT EXISTS catalogo_lenguas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(10)  NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  familia         VARCHAR(80)  NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_len_clave (clave),
  INDEX idx_len_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Religiones
CREATE TABLE IF NOT EXISTS catalogo_religiones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(10)  NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_rel_clave (clave),
  INDEX idx_rel_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- D. CATÁLOGOS ADMINISTRATIVOS Y HOSPITALARIOS
-- --------------------------------------------------------

-- Establecimientos de salud
CREATE TABLE IF NOT EXISTS catalogo_establecimientos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  tipo            VARCHAR(60)  NULL,
  direccion       VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_est_clave (clave),
  UNIQUE KEY uq_est_uuid (uuid),
  INDEX idx_est_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- CLUES
CREATE TABLE IF NOT EXISTS catalogo_clues (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(10)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  entidad_id      INT UNSIGNED NULL,
  municipio_id    INT UNSIGNED NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  UNIQUE KEY uq_clues_clave (clave),
  UNIQUE KEY uq_clues_uuid (uuid),
  INDEX idx_clues_entidad (entidad_id),
  CONSTRAINT fk_clues_entidad FOREIGN KEY (entidad_id) REFERENCES catalogo_entidades (id),
  CONSTRAINT fk_clues_municipio FOREIGN KEY (municipio_id) REFERENCES catalogo_municipios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Tipos de personal
CREATE TABLE IF NOT EXISTS catalogo_tipos_personal (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tp_clave (clave),
  UNIQUE KEY uq_tp_uuid (uuid),
  INDEX idx_tp_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Formación académica
CREATE TABLE IF NOT EXISTS catalogo_formacion_academica (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(120) NOT NULL,
  nivel           VARCHAR(40)  NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fa_clave (clave),
  UNIQUE KEY uq_fa_uuid (uuid),
  INDEX idx_fa_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Material de curación
CREATE TABLE IF NOT EXISTS catalogo_material_curacion (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mc_clave (clave),
  UNIQUE KEY uq_mc_uuid (uuid),
  INDEX idx_mc_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Instrumental y equipo médico
CREATE TABLE IF NOT EXISTS catalogo_instrumental (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(200) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inst_clave (clave),
  UNIQUE KEY uq_inst_uuid (uuid),
  INDEX idx_inst_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Clasificación de discapacidades (CIF)
CREATE TABLE IF NOT EXISTS catalogo_cif (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(10)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cif_codigo (codigo),
  UNIQUE KEY uq_cif_uuid (uuid),
  INDEX idx_cif_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- CNIS catálogos adicionales (según Acuerdo CSG)
-- --------------------------------------------------------
-- Auxiliares de diagnóstico
CREATE TABLE IF NOT EXISTS catalogo_auxiliares_diagnostico (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aux_clave (clave),
  UNIQUE KEY uq_aux_uuid (uuid),
  INDEX idx_aux_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Osteosíntesis y endoprótesis
CREATE TABLE IF NOT EXISTS catalogo_osteosintesis (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ost_clave (clave),
  UNIQUE KEY uq_ost_uuid (uuid),
  INDEX idx_ost_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Nutriología
CREATE TABLE IF NOT EXISTS catalogo_nutriologia (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nut_clave (clave),
  UNIQUE KEY uq_nut_uuid (uuid),
  INDEX idx_nut_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Insumos para salud pública
CREATE TABLE IF NOT EXISTS catalogo_insumos_salud_publica (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_isp_clave (clave),
  UNIQUE KEY uq_isp_uuid (uuid),
  INDEX idx_isp_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Acupuntura
CREATE TABLE IF NOT EXISTS catalogo_acupuntura (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acu_clave (clave),
  UNIQUE KEY uq_acu_uuid (uuid),
  INDEX idx_acu_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Medicamentos herbolarios
CREATE TABLE IF NOT EXISTS catalogo_herbolarios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_her_clave (clave),
  UNIQUE KEY uq_her_uuid (uuid),
  INDEX idx_her_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Medicamentos homeopáticos
CREATE TABLE IF NOT EXISTS catalogo_homeopaticos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_homeo_clave (clave),
  UNIQUE KEY uq_homeo_uuid (uuid),
  INDEX idx_homeo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- --------------------------------------------------------
-- Terminologías clínicas (NOM-024)
-- --------------------------------------------------------
-- SNOMED CT
CREATE TABLE IF NOT EXISTS catalogo_snomed (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_snomed_codigo (codigo),
  UNIQUE KEY uq_snomed_uuid (uuid),
  INDEX idx_snomed_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- LOINC
CREATE TABLE IF NOT EXISTS catalogo_loinc (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_loinc_codigo (codigo),
  UNIQUE KEY uq_loinc_uuid (uuid),
  INDEX idx_loinc_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- CIE-11
CREATE TABLE IF NOT EXISTS catalogo_cie11 (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo          VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(160) NOT NULL,
  descripcion     VARCHAR(300) NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  uuid            CHAR(36)     CHARACTER SET ascii NOT NULL DEFAULT (UUID()),
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cie11_codigo (codigo),
  UNIQUE KEY uq_cie11_uuid (uuid),
  INDEX idx_cie11_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
