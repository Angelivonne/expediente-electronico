-- Conexiones entre instituciones y autorizaciones individuales de intercambio.
-- Aplicar una sola vez sobre expediente_expediente antes de habilitar /conexiones/.

CREATE TABLE IF NOT EXISTS conexiones_clinicas (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institucion_a_id      INT UNSIGNED NOT NULL,
  institucion_b_id      INT UNSIGNED NOT NULL,
  solicitada_por        INT UNSIGNED NOT NULL,
  estado                ENUM('pendiente','conectada','rechazada','revocada') NOT NULL DEFAULT 'pendiente',
  acuerdo_archivo       VARCHAR(255) NULL,
  acuerdo_sha256        CHAR(64) NULL,
  acuerdo_nombre        VARCHAR(255) NULL,
  acuerdo_version       VARCHAR(40) NULL,
  acuerdo_a_confirmado  DATETIME NULL,
  acuerdo_a_usuario     INT UNSIGNED NULL,
  acuerdo_b_confirmado  DATETIME NULL,
  acuerdo_b_usuario     INT UNSIGNED NULL,
  creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conexion_par (institucion_a_id, institucion_b_id),
  KEY idx_conexion_b_estado (institucion_b_id, estado),
  CONSTRAINT fk_conexion_a FOREIGN KEY (institucion_a_id) REFERENCES instituciones (id),
  CONSTRAINT fk_conexion_b FOREIGN KEY (institucion_b_id) REFERENCES instituciones (id),
  CONSTRAINT fk_conexion_solicitante FOREIGN KEY (solicitada_por) REFERENCES usuarios (id),
  CONSTRAINT fk_conexion_acuerdo_a FOREIGN KEY (acuerdo_a_usuario) REFERENCES usuarios (id),
  CONSTRAINT fk_conexion_acuerdo_b FOREIGN KEY (acuerdo_b_usuario) REFERENCES usuarios (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conexiones_autorizaciones (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conexion_id           BIGINT UNSIGNED NOT NULL,
  institucion_origen_id INT UNSIGNED NOT NULL,
  institucion_destino_id INT UNSIGNED NOT NULL,
  paciente_id           INT UNSIGNED NOT NULL,
  alcance               ENUM('resumen','expediente') NOT NULL,
  finalidad             VARCHAR(500) NOT NULL,
  consentimiento_archivo VARCHAR(255) NOT NULL,
  consentimiento_sha256 CHAR(64) NOT NULL,
  consentimiento_nombre VARCHAR(255) NOT NULL,
  firmado_en            DATETIME NOT NULL,
  vence_en              DATETIME NOT NULL,
  revocado_en           DATETIME NULL,
  creado_por            INT UNSIGNED NOT NULL,
  creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_autorizacion_busqueda (institucion_destino_id, paciente_id, vence_en, revocado_en),
  KEY idx_autorizacion_origen (institucion_origen_id, paciente_id, revocado_en),
  CONSTRAINT fk_autorizacion_conexion FOREIGN KEY (conexion_id) REFERENCES conexiones_clinicas (id),
  CONSTRAINT fk_autorizacion_origen FOREIGN KEY (institucion_origen_id) REFERENCES instituciones (id),
  CONSTRAINT fk_autorizacion_destino FOREIGN KEY (institucion_destino_id) REFERENCES instituciones (id),
  CONSTRAINT fk_autorizacion_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes (id),
  CONSTRAINT fk_autorizacion_autor FOREIGN KEY (creado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- Privilegios mínimos para que la aplicación lea, registre y revoque vínculos y autorizaciones.
-- Ejecutar este archivo con una cuenta administradora de MySQL (el usuario expediente_app no crea tablas).
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.conexiones_clinicas TO 'expediente_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON expediente_expediente.conexiones_autorizaciones TO 'expediente_app'@'localhost';
