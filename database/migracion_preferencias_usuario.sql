-- Preferencias visuales privadas por usuario (no altera las tablas clínicas).
USE clinica;

CREATE TABLE IF NOT EXISTS preferencias_usuario (
  usuario_id INT UNSIGNED NOT NULL PRIMARY KEY,
  tema ENUM('oscuro','claro','sistema') NOT NULL DEFAULT 'oscuro',
  densidad ENUM('comoda','compacta') NOT NULL DEFAULT 'comoda',
  tamano_texto ENUM('normal','grande') NOT NULL DEFAULT 'normal',
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_preferencias_usuario_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vistas de tabla por usuario y entidad (filtros y orden serializados, sin datos de pacientes).
CREATE TABLE IF NOT EXISTS vistas_tabla_usuario (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  entidad VARCHAR(60) NOT NULL,
  nombre VARCHAR(80) NOT NULL,
  estado JSON NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vista_tabla_usuario (usuario_id, entidad, nombre),
  KEY idx_vista_tabla_entidad (usuario_id, entidad),
  CONSTRAINT fk_vistas_tabla_usuario FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Flujo operativo: el estado actual se actualiza; cada movimiento queda en un libro append-only.
CREATE TABLE IF NOT EXISTS flujo_atencion (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  paciente_id INT UNSIGNED NOT NULL,
  etapa VARCHAR(40) NOT NULL,
  abierto_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_en DATETIME NULL,
  actualizado_por INT UNSIGNED NOT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_flujo_institucion_etapa (institucion_id, cerrado_en, etapa, actualizado_en),
  KEY idx_flujo_paciente (institucion_id, paciente_id, cerrado_en),
  CONSTRAINT fk_flujo_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_flujo_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS flujo_atencion_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flujo_id BIGINT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NOT NULL,
  paciente_id INT UNSIGNED NOT NULL,
  etapa_anterior VARCHAR(40) NULL,
  etapa_nueva VARCHAR(40) NOT NULL,
  motivo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NOT NULL,
  usuario_nombre VARCHAR(120) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_flujo_eventos (institucion_id, flujo_id, creado_en),
  KEY idx_flujo_eventos_paciente (institucion_id, paciente_id, creado_en),
  CONSTRAINT fk_flujo_eventos_flujo FOREIGN KEY (flujo_id) REFERENCES flujo_atencion (id),
  CONSTRAINT fk_flujo_eventos_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_flujo_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE flujo_atencion_eventos
  ADD COLUMN IF NOT EXISTS usuario_nombre VARCHAR(120) NOT NULL DEFAULT 'Personal';

CREATE TABLE IF NOT EXISTS cirugias_programadas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_id INT UNSIGNED NOT NULL,
  paciente_id INT UNSIGNED NOT NULL,
  programada_en DATETIME NOT NULL,
  duracion_min SMALLINT UNSIGNED NOT NULL,
  procedimiento VARCHAR(160) NOT NULL,
  quirofano VARCHAR(80) NOT NULL,
  cirujano_id INT UNSIGNED NOT NULL,
  anestesiologo_id INT UNSIGNED NULL,
  estado ENUM('programada','preoperatoria','lista','en_curso','recuperacion','finalizada','cancelada') NOT NULL DEFAULT 'programada',
  creado_por INT UNSIGNED NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cirugias_agenda (institucion_id, programada_en, estado),
  KEY idx_cirugias_paciente (institucion_id, paciente_id, programada_en),
  CONSTRAINT fk_cirugias_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_cirugias_cirujano FOREIGN KEY (cirujano_id) REFERENCES usuarios (id),
  CONSTRAINT fk_cirugias_anestesiologo FOREIGN KEY (anestesiologo_id) REFERENCES usuarios (id),
  CONSTRAINT fk_cirugias_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cirugias_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cirugia_id BIGINT UNSIGNED NOT NULL,
  institucion_id INT UNSIGNED NOT NULL,
  tipo ENUM('programacion','estado') NOT NULL,
  estado_anterior VARCHAR(24) NULL,
  estado_nuevo VARCHAR(24) NOT NULL,
  fecha_anterior DATETIME NULL,
  fecha_nueva DATETIME NOT NULL,
  motivo VARCHAR(255) NULL,
  usuario_id INT UNSIGNED NOT NULL,
  usuario_nombre VARCHAR(120) NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cirugias_eventos (institucion_id, cirugia_id, creado_en),
  CONSTRAINT fk_cirugias_evento_cirugia FOREIGN KEY (cirugia_id) REFERENCES cirugias_programadas (id),
  CONSTRAINT fk_cirugias_evento_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
