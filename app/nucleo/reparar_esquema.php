<?php
/**
 * Auto-reparación de esquema: columnas y tablas que faltan en bases ya existentes (Railway, despliegues
 * antiguos). Se ejecuta en cada arranque y es segura de repetir: solo añade lo que falta de forma idempotente.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

/** Cache en memoria de tablas existentes. */
function tablaExiste(string $tabla): bool
{
    static $cacheTablas = [];
    if (array_key_exists($tabla, $cacheTablas)) {
        return $cacheTablas[$tabla];
    }
    try {
        $consulta = db()->prepare("SHOW TABLES LIKE ?");
        $consulta->execute([$tabla]);
        $cacheTablas[$tabla] = (bool) $consulta->fetchColumn();
    } catch (Throwable $e) {
        $cacheTablas[$tabla] = false;
    }
    return $cacheTablas[$tabla];
}

/** Cache en memoria de columnas existentes. */
function columnaExiste(string $tabla, string $columna, bool $forzarRecarga = false): bool
{
    static $cacheColumnas = [];
    $clave = $tabla . '.' . $columna;
    if (!$forzarRecarga && array_key_exists($clave, $cacheColumnas)) {
        return $cacheColumnas[$clave];
    }
    try {
        $consulta = db()->prepare("SHOW COLUMNS FROM `$tabla` LIKE ?");
        $consulta->execute([$columna]);
        $cacheColumnas[$clave] = (bool) $consulta->fetchColumn();
    } catch (Throwable $e) {
        // Fallback a information_schema
        try {
            $consulta = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $consulta->execute([$tabla, $columna]);
            $cacheColumnas[$clave] = (int) $consulta->fetchColumn() > 0;
        } catch (Throwable $e2) {
            if (!$forzarRecarga) {
                $cacheColumnas[$clave] = false;
            }
        }
    }
    return $cacheColumnas[$clave] ?? false;
}

function repararColumnaFaltante(string $tabla, string $columna, string $definicion): void
{
    if (!tablaExiste($tabla)) {
        return;
    }
    if (columnaExiste($tabla, $columna)) {
        return;
    }
    try {
        $sqlCol = (stripos($definicion, $columna) !== false || str_starts_with(trim($definicion), '`'))
            ? $definicion
            : "`$columna` $definicion";
        db()->exec("ALTER TABLE `$tabla` ADD COLUMN $sqlCol");
        // Forzar recarga de caché para que el nuevo estado sea visible en esta ejecución
        $confirmar = columnaExiste($tabla, $columna, true);
        if ($confirmar) {
            error_log('[reparar_esquema] OK añadido ' . $tabla . '.' . $columna);
        } else {
            // El ALTER corrió sin excepción pero la columna sigue sin aparecer — loguear como fallo crítico
            error_log('[reparar_esquema] CRITICO: ALTER TABLE corrió sin error pero columna sigue faltando: ' . $tabla . '.' . $columna);
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Si ya existe la columna por alguna condición de carrera, ignorar silenciosamente
        if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
            columnaExiste($tabla, $columna, true); // forzar recarga de caché
            return;
        }
        // Cualquier otro fallo lo logueamos con prominencia para no perderlo
        error_log('[reparar_esquema] ERROR ALTER TABLE `' . $tabla . '` ADD COLUMN ' . $columna . ': ' . $msg);
    }
}

// 0. Tablas esenciales del sistema que pudieron faltar en despliegues sin migraciones ejecutadas
try {
    $pdo = db();

    if (!tablaExiste('servicios_clinicos')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS servicios_clinicos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            nombre VARCHAR(120) NOT NULL,
            descripcion VARCHAR(255) NULL,
            precio DECIMAL(12,2) NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT NULL,
            actualizado_por INT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_servicios_institucion (institucion_id, activo, nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('plantillas')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS plantillas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            usuario_id INT UNSIGNED NULL,
            titulo VARCHAR(160) NOT NULL,
            contenido MEDIUMTEXT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_plantillas_inst (institucion_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('plantillas_fila')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS plantillas_fila (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pagina_id INT UNSIGNED NOT NULL,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            usuario_id INT UNSIGNED NULL,
            nombre VARCHAR(120) NOT NULL,
            valores JSON NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_plantillas_fila_pag (pagina_id, institucion_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('comentarios')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comentarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            entidad VARCHAR(40) NOT NULL,
            registro_id INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NULL,
            texto TEXT NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resuelto_en DATETIME NULL,
            KEY idx_comentarios_entidad (institucion_id, entidad, registro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('registro_versiones')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS registro_versiones (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            entidad VARCHAR(40) NOT NULL,
            registro_id INT UNSIGNED NOT NULL,
            usuario_id INT UNSIGNED NULL,
            datos JSON NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_versiones_reg (institucion_id, entidad, registro_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('firmas_clinicas')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS firmas_clinicas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            usuario_id INT UNSIGNED NOT NULL,
            archivo VARCHAR(255) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_firmas_clinicas (institucion_id, usuario_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('conexiones_clinicas')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS conexiones_clinicas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_a_id INT UNSIGNED NOT NULL,
            institucion_b_id INT UNSIGNED NOT NULL,
            solicitada_por INT UNSIGNED NULL,
            estado ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conexiones_ab (institucion_a_id, institucion_b_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('conexiones_autorizaciones')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS conexiones_autorizaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conexion_id INT UNSIGNED NOT NULL,
            paciente_id INT UNSIGNED NOT NULL,
            autorizada_por INT UNSIGNED NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conexiones_aut (conexion_id, paciente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('catalogos_meta')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalogos_meta (
            clave VARCHAR(60) PRIMARY KEY,
            fuente VARCHAR(255) NULL,
            version VARCHAR(40) NULL,
            vigencia VARCHAR(60) NULL,
            responsable VARCHAR(120) NULL,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('cie10')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cie10 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NULL,
            codigo VARCHAR(10) NOT NULL,
            descripcion VARCHAR(500) NOT NULL,
            capitulo VARCHAR(255) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            KEY idx_cie10_codigo (codigo),
            KEY idx_cie10_inst (institucion_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('medicamentos')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS medicamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NULL,
            clave VARCHAR(40) NULL,
            clave_compendio VARCHAR(40) NULL,
            nombre VARCHAR(300) NOT NULL,
            forma_farmaceutica VARCHAR(120) NULL,
            presentacion VARCHAR(255) NULL,
            concentracion VARCHAR(120) NULL,
            via VARCHAR(100) NULL,
            laboratorio VARCHAR(160) NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            foto VARCHAR(120) NULL,
            actualizado_en DATETIME NULL,
            KEY idx_med_nombre (nombre(100)),
            KEY idx_med_inst (institucion_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
} catch (Throwable $e) {
    error_log('[reparar_esquema] error creando tablas base: ' . $e->getMessage());
}

// 1. Lista de tablas que requieren institucion_id
// IMPORTANTE: si añades una nueva tabla con institucion_id, agrégala aquí también.
$tablasConInstitucion = [
    'usuarios', 'pacientes', 'historial_clinico', 'paginas', 'pagina_filas', 'citas',
    'conversiones', 'invitaciones', 'sincronizacion_cola', 'bitacora',
    'users', 'notas_medicas', 'recetas', 'estudios',
    'medicamentos', 'cie10', 'comentarios', 'registro_versiones',
    'finanzas_movimientos', 'finanzas_conceptos', 'finanzas_cierres',
    'finanzas_facturas', 'finanzas_presupuestos', 'finanzas_recurrentes',
    'servicios_clinicos', 'imagenes_medicas', 'plantillas', 'plantillas_fila',
    'firmas_clinicas', 'agentes', 'agentes_memoria', 'agentes_conversaciones',
    'catalogo_procedimientos', 'catalogo_especialidades', 'catalogo_tipos_consulta',
    'catalogo_tipos_atencion', 'catalogo_principios_activos', 'catalogo_formas_farmaceuticas',
    'catalogo_vias_admin', 'catalogo_categorias_farmaco', 'catalogo_unidades_medida',
    'catalogo_concentraciones'
];

foreach ($tablasConInstitucion as $tabla) {
    repararColumnaFaltante($tabla, 'institucion_id', 'INT UNSIGNED NULL');
}

// 2. configuracion: requiere institucion_id NOT NULL DEFAULT 0 y clave primaria compuesta
if (tablaExiste('configuracion') && !columnaExiste('configuracion', 'institucion_id')) {
    try {
        db()->exec("ALTER TABLE `configuracion` ADD COLUMN institucion_id INT UNSIGNED NOT NULL DEFAULT 0 FIRST");
        // Intentar actualizar la clave primaria a compuesta
        try {
            db()->exec("ALTER TABLE `configuracion` DROP PRIMARY KEY, ADD PRIMARY KEY (institucion_id, clave)");
        } catch (Throwable $e2) {
            // Ignorar si la clave primaria ya era compuesta o no se pudo alterar
        }
        error_log('[reparar_esquema] configuracion.institucion_id añadido');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') === false && stripos($msg, 'already exists') === false) {
            error_log('[reparar_esquema] fallo configuracion.institucion_id: ' . $msg);
        }
    }
}

// 3. Descubrir tablas adicionales vía information_schema (fallback dinámico)
try {
    $consulta = db()->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    $yaReparadas = array_flip($tablasConInstitucion);
    $yaReparadas['configuracion'] = true;
    foreach ($consulta->fetchAll() as $fila) {
        $nombre = (string) $fila['TABLE_NAME'];
        if (!isset($yaReparadas[$nombre])) {
            repararColumnaFaltante($nombre, 'institucion_id', 'INT UNSIGNED NULL');
        }
    }
} catch (Throwable $e) {
    // information_schema puede no ser accesible en ciertos entornos compartidos
}

// 4. Columnas clave del modelo de usuarios y pacientes
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'foto', 'VARCHAR(255) NULL');
repararColumnaFaltante('usuarios', 'fecha_nacimiento', 'DATE NULL');
repararColumnaFaltante('usuarios', 'especialidad', 'VARCHAR(120) NULL');
repararColumnaFaltante('usuarios', 'cedula_especialidad', 'VARCHAR(20) NULL');
repararColumnaFaltante('usuarios', 'cedula_estado', "ENUM('pendiente','verificada','no_coincide','no_encontrada') NULL");
repararColumnaFaltante('usuarios', 'cedula_motivo', 'VARCHAR(255) NULL');
repararColumnaFaltante('usuarios', 'cedula_titulo', 'VARCHAR(255) NULL');
repararColumnaFaltante('usuarios', 'cedula_revisada_en', 'DATETIME NULL');
repararColumnaFaltante('usuarios', 'registro_origen', "ENUM('fundador','codigo','invitacion') NULL");
repararColumnaFaltante('usuarios', 'telefono', 'VARCHAR(30) NULL');
repararColumnaFaltante('usuarios', 'celular', 'VARCHAR(30) NULL');
repararColumnaFaltante('usuarios', 'direccion', 'VARCHAR(255) NULL');
repararColumnaFaltante('usuarios', 'actualizado_en', 'DATETIME NULL');
repararColumnaFaltante('usuarios', 'eliminado_en', 'DATETIME NULL');
repararColumnaFaltante('pagina_filas', 'notas', 'MEDIUMTEXT NULL');

// 5. Columnas esenciales de finanzas
repararColumnaFaltante('finanzas_movimientos', 'vence_en', 'DATE NULL');
repararColumnaFaltante('finanzas_movimientos', 'servicio_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'establecimiento_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'factura_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'referencia', 'VARCHAR(80) NULL');
repararColumnaFaltante('finanzas_movimientos', 'estado', "VARCHAR(20) NOT NULL DEFAULT 'pagado'");
repararColumnaFaltante('finanzas_movimientos', 'metodo', "VARCHAR(30) NULL");
repararColumnaFaltante('finanzas_movimientos', 'descripcion', "VARCHAR(255) NULL");
