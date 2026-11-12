<?php
/**
 * Auto-reparación de esquema: columnas y tablas que faltan en bases ya existentes (Railway, despliegues
 * antiguos). Se ejecuta en cada arranque y es segura de repetir: solo añade lo que falta de forma idempotente.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

/** Cache en memoria de tablas existentes. RENDIMIENTO: carga todas las tablas de la base
 *  en UNA sola consulta la primera vez que se pregunta por cualquier tabla. */
function tablaExiste(string $tabla): bool
{
    static $cacheTablas = null;
    if ($cacheTablas === null) {
        $cacheTablas = [];
        try {
            $consulta = db()->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
            $consulta->execute([]);
            foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $nombreTabla) {
                $cacheTablas[strtolower((string) $nombreTabla)] = true;
            }
        } catch (Throwable $e) {
            $cacheTablas = null;
        }
    }
    if ($cacheTablas !== null) {
        return isset($cacheTablas[strtolower($tabla)]);
    }
    // Respaldo si information_schema no respondió
    try {
        $consulta = db()->query("SHOW TABLES LIKE " . db()->quote($tabla));
        return (bool) $consulta->fetchColumn();
    } catch (Throwable $e2) {
        return false;
    }
}

/** Cache en memoria de columnas existentes. RENDIMIENTO: carga todas las columnas de la
 *  tabla en UNA sola consulta (antes hacia una consulta por columna: ~30 consultas por página). */
function columnaExiste(string $tabla, string $columna, bool $forzarRecarga = false): bool
{
    static $cacheColumnas = [];
    static $cachePorTabla = [];
    $clave = $tabla . '.' . $columna;
    if (!$forzarRecarga && array_key_exists($clave, $cacheColumnas)) {
        return $cacheColumnas[$clave];
    }
    try {
        if ($forzarRecarga || !array_key_exists($tabla, $cachePorTabla)) {
            $consulta = db()->prepare(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ?"
            );
            $consulta->execute([$tabla]);
            $todas = [];
            foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $nombreColumna) {
                $todas[strtolower((string) $nombreColumna)] = true;
            }
            $cachePorTabla[$tabla] = $todas;
        }
        $cacheColumnas[$clave] = isset($cachePorTabla[$tabla][strtolower($columna)]);
    } catch (Throwable $e) {
        try {
            $consulta = db()->query("SHOW COLUMNS FROM `$tabla` LIKE " . db()->quote($columna));
            $cacheColumnas[$clave] = (bool) $consulta->fetchColumn();
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

// RENDIMIENTO: la reparación completa solo debe correr una vez al día (o si cambió la base).
// Antes se ejecutaba en CADA petición y hacía cientos de consultas a information_schema,
// lo que volvía lenta toda la aplicación. Si la marca es del día y de la misma base, se
// omite todo el trabajo con `return` (las funciones ya quedaron definidas arriba).
// Para forzar una re-verificación manual: borra el archivo esquema_verificado.json de la carpeta temporal.
$marcaEsquemaRuta = sys_get_temp_dir() . '/esquema_verificado.json';
$huellaEsquema = DB_HOST . ':' . DB_PUERTO . '/' . DB_NOMBRE;
$esquemaVerificado = false;
if (is_file($marcaEsquemaRuta)) {
    $marcaEsquema = json_decode((string) @file_get_contents($marcaEsquemaRuta), true) ?: [];
    $esquemaVerificado = (($marcaEsquema['fecha'] ?? '') === date('Y-m-d')
        && ($marcaEsquema['base'] ?? '') === $huellaEsquema);
}
if ($esquemaVerificado) {
    return;
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

    if (!tablaExiste('equipo_tiempo_activo')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS equipo_tiempo_activo (
            usuario_id INT UNSIGNED NOT NULL,
            institucion_id INT UNSIGNED NOT NULL,
            fecha DATE NOT NULL,
            sesion_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            inicio_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ultimo_latido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            segundos_activos INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (usuario_id, fecha, sesion_hash),
            KEY idx_equipo_tiempo_institucion_dia (institucion_id, fecha, usuario_id),
            CONSTRAINT fk_equipo_tiempo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

        if (!tablaExiste('bitacora')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bitacora (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tabla VARCHAR(40) NOT NULL,
            registro_id INT UNSIGNED NOT NULL,
            accion VARCHAR(40) NOT NULL DEFAULT 'crear',
            campo VARCHAR(60) NULL,
            valor_anterior TEXT NULL,
            valor_nuevo TEXT NULL,
            usuario_id INT UNSIGNED NULL,
            institucion_id INT UNSIGNED NULL,
            ip VARCHAR(45) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bitacora_reg (tabla, registro_id),
            KEY idx_bitacora_fecha (tabla, registro_id, creado_en)
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

    if (!tablaExiste('retroalimentacion_equipo')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS retroalimentacion_equipo (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL,
            autor_id INT UNSIGNED NOT NULL,
            persona_id INT UNSIGNED NOT NULL,
            tipo ENUM('positivo','atencion') NOT NULL,
            texto VARCHAR(1000) NOT NULL,
            estado ENUM('pendiente','compartido','cerrado') NOT NULL DEFAULT 'pendiente',
            revisado_por INT UNSIGNED NULL,
            revisado_en DATETIME NULL,
            compartido_en DATETIME NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_feedback_persona (institucion_id, persona_id, estado, creado_en),
            KEY idx_feedback_autor (institucion_id, autor_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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

    if (!tablaExiste('farmacia_lotes')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS farmacia_lotes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL,
            medicamento_id INT UNSIGNED NOT NULL,
            codigo_lote VARCHAR(80) NOT NULL,
            caducidad DATE NOT NULL,
            unidad VARCHAR(40) NOT NULL,
            existencia DECIMAL(12,3) NOT NULL DEFAULT 0,
            costo_unitario DECIMAL(12,2) NULL,
            creado_por INT UNSIGNED NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_farmacia_lote (institucion_id, medicamento_id, codigo_lote, caducidad, unidad),
            KEY idx_farmacia_existencia (institucion_id, existencia),
            KEY idx_farmacia_caducidad (institucion_id, caducidad)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('farmacia_movimientos')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS farmacia_movimientos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL,
            lote_id BIGINT UNSIGNED NOT NULL,
            medicamento_id INT UNSIGNED NOT NULL,
            tipo ENUM('entrada', 'ajuste_entrada', 'ajuste_salida', 'merma', 'dispensacion') NOT NULL,
            cantidad DECIMAL(12,3) NOT NULL,
            paciente_id INT UNSIGNED NULL,
            receta_id INT UNSIGNED NULL,
            confirmacion_revisada TINYINT(1) NOT NULL DEFAULT 0,
            usuario_id INT UNSIGNED NOT NULL,
            operacion_id CHAR(48) NOT NULL,
            nota VARCHAR(500) NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_farmacia_operacion (operacion_id),
            KEY idx_farmacia_mov_fecha (institucion_id, creado_en),
            KEY idx_farmacia_mov_lote (lote_id, creado_en),
            KEY idx_farmacia_mov_paciente (institucion_id, paciente_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Auto-crear todas las tablas de catálogos si no existen
    $catalogosTablas = [
        'catalogo_procedimientos' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_especialidades' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(200) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_tipos_consulta' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_tipos_atencion' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_principios_activos' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_formas_farmaceuticas' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(120) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_vias_admin' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_categorias_farmaco' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(120) NOT NULL, descripcion VARCHAR(255) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_unidades_medida' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(80) NOT NULL, simbolo VARCHAR(20) NOT NULL DEFAULT '', activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_concentraciones' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(100) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_dispositivos_medicos' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_osteosintesis' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_agentes_diagnosticos' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_nutriologia' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_insumos_salud_publica' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_acupuntura' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_herbolarios' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_homeopaticos' => "clave VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_snomed' => "codigo VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_loinc' => "codigo VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
        'catalogo_cie11' => "codigo VARCHAR(40) NOT NULL, nombre VARCHAR(160) NOT NULL, descripcion VARCHAR(300) NULL, activo TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($catalogosTablas as $tablaCat => $colsDef) {
        if (!tablaExiste($tablaCat)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `$tablaCat` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                institucion_id INT UNSIGNED NULL,
                $colsDef,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cat_inst (institucion_id, activo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    if (!tablaExiste('camillas')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS camillas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            codigo VARCHAR(30) NOT NULL,
            area ENUM('urgencias','hospitalizacion','cirugia','terapia_intensiva') NOT NULL DEFAULT 'hospitalizacion',
            piso VARCHAR(40) NULL,
            tipo ENUM('camilla','cama_censable','cama_no_censable','cuna','incubadora') NOT NULL DEFAULT 'cama_censable',
            estado ENUM('disponible','ocupada','limpieza','mantenimiento','reservada') NOT NULL DEFAULT 'disponible',
            paciente_id INT UNSIGNED NULL,
            ingreso_en DATETIME NULL,
            diagnostico_ingreso VARCHAR(255) NULL,
            medico_id INT UNSIGNED NULL,
            notas TEXT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inst_area (institucion_id, area),
            INDEX idx_paciente (paciente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    try {
        $columnaEstado = $pdo->query("SHOW COLUMNS FROM `camillas` LIKE 'estado'")->fetch(PDO::FETCH_ASSOC);
        $tipoEstado = (string) ($columnaEstado['Type'] ?? '');
        if (preg_match('/^enum\\((.*)\\)$/i', $tipoEstado, $partesEnum)) {
            preg_match_all("/'([^']*)'/", $partesEnum[1], $valoresEnum);
            $estadosExistentes = $valoresEnum[1] ?? [];
            $faltantesEstado = array_diff(['mantenimiento', 'reservada'], $estadosExistentes);
            if ($faltantesEstado) {
                $estadosCenso = array_values(array_unique(array_merge($estadosExistentes, ['mantenimiento', 'reservada'])));
                $valoresEstado = implode(',', array_map(static fn (string $estado): string => $pdo->quote($estado), $estadosCenso));
                $pdo->exec("ALTER TABLE `camillas` MODIFY `estado` ENUM($valoresEstado) NOT NULL DEFAULT 'disponible'");
                error_log('[reparar_esquema] camillas.estado ampliado para estados operativos: ' . implode(', ', $faltantesEstado));
            }
        }
    } catch (Throwable $e) {
        error_log('[reparar_esquema] ERROR ampliando camillas.estado: ' . $e->getMessage());
    }

    if (!tablaExiste('camillas_movimientos')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS camillas_movimientos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            camilla_id INT NOT NULL,
            paciente_id INT NOT NULL,
            tipo_movimiento ENUM('ingreso','alta','traslado') NOT NULL,
            camilla_origen_id INT NULL,
            motivo VARCHAR(255) NULL,
            usuario_id INT UNSIGNED NULL,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_camilla (camilla_id),
            INDEX idx_paciente (paciente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('kardex_medicaciones')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kardex_medicaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            paciente_id INT UNSIGNED NOT NULL,
            camilla_id INT NULL,
            medicamento VARCHAR(150) NOT NULL,
            dosis VARCHAR(80) NOT NULL,
            via VARCHAR(50) NOT NULL,
            frecuencia VARCHAR(80) NOT NULL,
            horario VARCHAR(100) NULL,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NULL,
            indicaciones TEXT NULL,
            estado ENUM('activo', 'suspendido', 'completado') NOT NULL DEFAULT 'activo',
            medico_prescribe VARCHAR(120) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_paciente (paciente_id),
            INDEX idx_camilla (camilla_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    if (!tablaExiste('kardex_administraciones')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS kardex_administraciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            institucion_id INT UNSIGNED NOT NULL DEFAULT 1,
            medicacion_id INT NOT NULL,
            paciente_id INT UNSIGNED NOT NULL,
            turno ENUM('matutino', 'vespertino', 'nocturno') NOT NULL DEFAULT 'matutino',
            fecha DATE NOT NULL,
            hora_programada VARCHAR(10) NOT NULL,
            hora_aplicacion DATETIME NULL,
            estado ENUM('pendiente', 'aplicado', 'omitido', 'rechazado') NOT NULL DEFAULT 'pendiente',
            enfermero_nombre VARCHAR(120) NULL,
            observaciones VARCHAR(255) NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_med (medicacion_id),
            INDEX idx_pac_fecha (paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Auto-sincronizar fotos de la clínica y subidas persistentes
    $dirPrivado = __DIR__ . '/../../privado/subidas';
    $dirPublico = __DIR__ . '/../../assets/subidas';
    if (!is_dir($dirPrivado)) { @mkdir($dirPrivado, 0777, true); }
    if (!is_dir($dirPublico)) { @mkdir($dirPublico, 0777, true); }
    if (is_dir($dirPrivado)) {
        foreach (scandir($dirPrivado) as $arch) {
            if ($arch !== '.' && $arch !== '..' && !file_exists("$dirPublico/$arch")) {
                @copy("$dirPrivado/$arch", "$dirPublico/$arch");
            }
        }
    }

} catch (Throwable $e) {
    error_log('[reparar_esquema] error creando tablas base: ' . $e->getMessage());
}

repararColumnaFaltante('farmacia_movimientos', 'confirmacion_revisada', 'TINYINT(1) NOT NULL DEFAULT 0');

// 1. Lista de tablas que requieren institucion_id
// IMPORTANTE: si añades una nueva tabla con institucion_id, agrégala aquí también.
$tablasConInstitucion = [
    'usuarios', 'pacientes', 'historial_clinico', 'paginas', 'pagina_filas', 'citas',
    'conversiones', 'invitaciones', 'sincronizacion_cola', 'bitacora',
    'users', 'notas_medicas', 'recetas', 'estudios', 'camillas', 'camillas_movimientos', 'kardex_medicaciones', 'kardex_administraciones',
    'medicamentos', 'cie10', 'comentarios', 'registro_versiones',
    'farmacia_lotes', 'farmacia_movimientos',
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
repararColumnaFaltante('presencia', 'cursor_x', 'DECIMAL(6,5) NULL');
repararColumnaFaltante('presencia', 'cursor_y', 'DECIMAL(6,5) NULL');
repararColumnaFaltante('presencia', 'cursor_en', 'DATETIME NULL');

// 5. Columnas esenciales de finanzas
repararColumnaFaltante('finanzas_movimientos', 'vence_en', 'DATE NULL');
repararColumnaFaltante('finanzas_movimientos', 'servicio_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'establecimiento_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'factura_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('finanzas_movimientos', 'referencia', 'VARCHAR(80) NULL');
repararColumnaFaltante('finanzas_movimientos', 'estado', "VARCHAR(20) NOT NULL DEFAULT 'pagado'");
repararColumnaFaltante('finanzas_movimientos', 'metodo', "VARCHAR(30) NULL");
repararColumnaFaltante('finanzas_movimientos', 'descripcion', "VARCHAR(255) NULL");


// =========================================================================
// PREVENCIÓN CRÍTICA DE ERROR 1054 / SQLSTATE 42S22 (Column not found)
// Asegurar todas las columnas consultadas en perfil de paciente y sistema
// =========================================================================

// Firmas clínicas (requeridas en ver.php)
repararColumnaFaltante('firmas_clinicas', 'historial_id', 'INT NOT NULL DEFAULT 0');
repararColumnaFaltante('firmas_clinicas', 'usuario_id', 'INT NOT NULL DEFAULT 0');
repararColumnaFaltante('firmas_clinicas', 'usuario_nombre', 'VARCHAR(120) NOT NULL DEFAULT ""');
repararColumnaFaltante('firmas_clinicas', 'cedula', 'VARCHAR(20) NULL');
repararColumnaFaltante('firmas_clinicas', 'huella', 'CHAR(64) NOT NULL DEFAULT ""');
repararColumnaFaltante('firmas_clinicas', 'firmado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
repararColumnaFaltante('firmas_clinicas', 'revocada_en', 'DATETIME NULL');
repararColumnaFaltante('firmas_clinicas', 'motivo_revocacion', 'VARCHAR(255) NULL');

// Comentarios
repararColumnaFaltante('comentarios', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('comentarios', 'entidad', 'VARCHAR(40) NOT NULL DEFAULT "pacientes"');
repararColumnaFaltante('comentarios', 'registro_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
repararColumnaFaltante('comentarios', 'usuario_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('comentarios', 'texto', 'TEXT NOT NULL');
repararColumnaFaltante('comentarios', 'resuelto_en', 'DATETIME NULL');
repararColumnaFaltante('comentarios', 'creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

// Citas
repararColumnaFaltante('citas', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('citas', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('citas', 'profesional_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('citas', 'inicio', 'DATETIME NOT NULL');
repararColumnaFaltante('citas', 'fin', 'DATETIME NOT NULL');
repararColumnaFaltante('citas', 'motivo', 'VARCHAR(200) NOT NULL DEFAULT ""');
repararColumnaFaltante('citas', 'notas', 'TEXT NULL');
repararColumnaFaltante('citas', 'estado', "ENUM('programada','confirmada','atendida','cancelada','no_asistio') NOT NULL DEFAULT 'programada'");
repararColumnaFaltante('citas', 'recordatorio_min', 'SMALLINT UNSIGNED NULL');
repararColumnaFaltante('citas', 'avisar_paciente', 'TINYINT(1) NOT NULL DEFAULT 0');
repararColumnaFaltante('citas', 'recordatorio_enviado_en', 'DATETIME NULL');
repararColumnaFaltante('citas', 'recordatorio_visto_en', 'DATETIME NULL');
repararColumnaFaltante('citas', 'creado_por', 'INT UNSIGNED NULL');

// Asegurar todas las columnas de la tabla pacientes (prevencion 1054)
repararColumnaFaltante('pacientes', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('pacientes', 'folio', 'VARCHAR(30) NULL');
repararColumnaFaltante('pacientes', 'curp', 'VARCHAR(20) NULL');
repararColumnaFaltante('pacientes', 'nombre', 'VARCHAR(80) NOT NULL DEFAULT ""');
repararColumnaFaltante('pacientes', 'primer_apellido', 'VARCHAR(60) NOT NULL DEFAULT ""');
repararColumnaFaltante('pacientes', 'segundo_apellido', 'VARCHAR(60) NULL');
repararColumnaFaltante('pacientes', 'fecha_nacimiento', 'DATE NULL');
repararColumnaFaltante('pacientes', 'sexo', "ENUM('H','M','X') NOT NULL DEFAULT 'H'");
repararColumnaFaltante('pacientes', 'entidad_nacimiento', 'CHAR(2) NULL');
repararColumnaFaltante('pacientes', 'nacionalidad', 'VARCHAR(40) NULL');
repararColumnaFaltante('pacientes', 'telefono', 'VARCHAR(30) NULL');
repararColumnaFaltante('pacientes', 'correo', 'VARCHAR(120) NULL');
repararColumnaFaltante('pacientes', 'domicilio', 'VARCHAR(200) NULL');
repararColumnaFaltante('pacientes', 'municipio_estado', 'VARCHAR(120) NULL');
repararColumnaFaltante('pacientes', 'contacto_emergencia', 'VARCHAR(120) NULL');
repararColumnaFaltante('pacientes', 'telefono_emergencia', 'VARCHAR(30) NULL');
repararColumnaFaltante('pacientes', 'alergias', 'VARCHAR(255) NULL');
repararColumnaFaltante('pacientes', 'grupo_sanguineo', 'VARCHAR(12) NULL');
repararColumnaFaltante('pacientes', 'padecimientos_cronicos', 'VARCHAR(255) NULL');
repararColumnaFaltante('pacientes', 'medicamentos_actuales', 'VARCHAR(255) NULL');
repararColumnaFaltante('pacientes', 'motivo_consulta', 'TEXT NULL');
repararColumnaFaltante('pacientes', 'confirmacion_datos', 'TINYINT(1) NOT NULL DEFAULT 0');
repararColumnaFaltante('pacientes', 'extras', 'JSON NULL');
repararColumnaFaltante('pacientes', 'foto', 'VARCHAR(255) NULL');
repararColumnaFaltante('pacientes', 'creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
repararColumnaFaltante('pacientes', 'actualizado_en', 'DATETIME NULL');
repararColumnaFaltante('pacientes', 'eliminado_en', 'DATETIME NULL');

// Columnas de bitacora
repararColumnaFaltante('bitacora', 'tabla', 'VARCHAR(40) NOT NULL DEFAULT "pacientes"');
repararColumnaFaltante('bitacora', 'registro_id', 'INT UNSIGNED NOT NULL DEFAULT 0');
repararColumnaFaltante('bitacora', 'accion', 'VARCHAR(40) NOT NULL DEFAULT "crear"');
repararColumnaFaltante('bitacora', 'campo', 'VARCHAR(60) NULL');
repararColumnaFaltante('bitacora', 'valor_anterior', 'TEXT NULL');
repararColumnaFaltante('bitacora', 'valor_nuevo', 'TEXT NULL');
repararColumnaFaltante('bitacora', 'usuario_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('bitacora', 'institucion_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('bitacora', 'ip', 'VARCHAR(45) NULL');
repararColumnaFaltante('bitacora', 'creado_en', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

// Historial clínico
repararColumnaFaltante('historial_clinico', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('historial_clinico', 'paciente_id', 'INT UNSIGNED NOT NULL');
repararColumnaFaltante('historial_clinico', 'usuario_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('historial_clinico', 'tipo', 'VARCHAR(40) NOT NULL DEFAULT "consulta"');
repararColumnaFaltante('historial_clinico', 'fecha', 'DATETIME NOT NULL');
repararColumnaFaltante('historial_clinico', 'detalle', 'JSON NULL');

// El ENUM de tipos debe admitir todo lo que ofrece el selector de notas. Se deriva de
// TIPOS_REGISTRO para que agregar un tipo allá no vuelva a dejar la base desincronizada.
// Ampliar un ENUM conserva los valores ya guardados; solo se ejecuta si falta alguno.
if (tablaExiste('historial_clinico') && columnaExiste('historial_clinico', 'tipo')) {
    try {
        require_once __DIR__ . '/../datos/registros_clinicos.php';
        $tipoColumna = (string) db()->query("SHOW COLUMNS FROM `historial_clinico` LIKE 'tipo'")->fetchColumn(1);
        if (stripos($tipoColumna, 'enum(') === 0) {
            $faltantes = array_filter(array_keys(TIPOS_REGISTRO), static fn ($t) => !str_contains($tipoColumna, "'" . $t . "'"));
            if ($faltantes) {
                $valores = implode(',', array_map(static fn ($t) => db()->quote($t), array_keys(TIPOS_REGISTRO)));
                db()->exec("ALTER TABLE `historial_clinico` MODIFY `tipo` ENUM($valores) NOT NULL");
                error_log('[reparar_esquema] historial_clinico.tipo ampliado con: ' . implode(', ', $faltantes));
            }
        }
    } catch (Throwable $e) {
        error_log('[reparar_esquema] ERROR ampliando historial_clinico.tipo: ' . $e->getMessage());
    }
}

// Conexiones clínicas y autorizaciones
repararColumnaFaltante('conexiones_clinicas', 'institucion_a_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('conexiones_clinicas', 'institucion_b_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('conexiones_clinicas', 'solicitada_por', 'INT UNSIGNED NULL');
repararColumnaFaltante('conexiones_clinicas', 'estado', "ENUM('pendiente','conectada','rechazada','revocada') NOT NULL DEFAULT 'pendiente'");
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_archivo', 'VARCHAR(255) NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_sha256', 'CHAR(64) NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_nombre', 'VARCHAR(255) NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_version', 'VARCHAR(40) NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_a_confirmado', 'DATETIME NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_a_usuario', 'INT UNSIGNED NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_b_confirmado', 'DATETIME NULL');
repararColumnaFaltante('conexiones_clinicas', 'acuerdo_b_usuario', 'INT UNSIGNED NULL');

repararColumnaFaltante('conexiones_autorizaciones', 'institucion_origen_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('conexiones_autorizaciones', 'institucion_destino_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
repararColumnaFaltante('conexiones_autorizaciones', 'alcance', "ENUM('resumen','expediente') NOT NULL DEFAULT 'resumen'");
repararColumnaFaltante('conexiones_autorizaciones', 'finalidad', 'VARCHAR(500) NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'consentimiento_archivo', 'VARCHAR(255) NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'consentimiento_sha256', 'CHAR(64) NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'consentimiento_nombre', 'VARCHAR(255) NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'firmado_en', 'DATETIME NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'vence_en', 'DATETIME NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'revocado_en', 'DATETIME NULL');
repararColumnaFaltante('conexiones_autorizaciones', 'creado_por', 'INT UNSIGNED NULL');

if (!tablaExiste('consentimientos_morbilidad')) {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS consentimientos_morbilidad (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[reparar_esquema] No se pudieron crear las tablas de consentimiento de morbilidad: ' . $e->getMessage());
    }
}
if (!tablaExiste('consentimientos_morbilidad_eventos')) {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS consentimientos_morbilidad_eventos (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[reparar_esquema] No se pudo crear el historial de consentimientos de morbilidad: ' . $e->getMessage());
    }
}

// Todo el esquema pasó la verificación de hoy: guardar la marca para que las
// siguientes peticiones del día se salten este proceso (ver arriba).
@file_put_contents($marcaEsquemaRuta, json_encode(['fecha' => date('Y-m-d'), 'base' => $huellaEsquema]));
