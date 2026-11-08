<?php
/**
 * Auto-reparación de esquema: columnas que faltan en tablas ya existentes (Railway, despliegues
 * antiguos). Se ejecuta en cada arranque y es segura de repetir: solo añade lo que falta.
 *
 * Estrategia definitiva: intenta añadir `institucion_id` a una lista hardcodeada de todas las
 * tablas que la usan directamente en SQL (hay +100 consultas directas), y también descubre
 * tablas extra vía information_schema como fallback. No depende de que el registro de entidades
 * esté cargado ni de que information_schema sea accesible.
 *
 * Es un no-op en bases de datos ya al día.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';

function repararColumnaFaltante(string $tabla, string $columna, string $definicion): void
{
    try {
        $consulta = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $consulta->execute([$tabla, $columna]);
        $existe = (int) $consulta->fetchColumn();
    } catch (Throwable $e) {
        error_log('[reparar_esquema] verificar ' . $tabla . '.' . $columna . ': ' . $e->getMessage());
        $existe = -1; // no se pudo verificar: intenta añadir de todos modos
    }
    if ($existe > 0) {
        return;
    }
    try {
        db()->exec("ALTER TABLE `$tabla` ADD COLUMN $definicion");
        error_log('[reparar_esquema] añadido ' . $tabla . '.' . $columna);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') === false && stripos($msg, 'already exists') === false) {
            error_log('[reparar_esquema] fallo ' . $tabla . '.' . $columna . ': ' . $msg);
        }
    }
}

/** Cache de existencia de columnas por petición. */
function columnaExiste(string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = $tabla . '.' . $columna;
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }
    try {
        $consulta = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $consulta->execute([$tabla, $columna]);
        $cache[$clave] = (int) $consulta->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$clave] = false;
    }
    return $cache[$clave];
}

// Lista hardcodeada de tablas que usan institucion_id directamente en SQL.
// Cubre cualquier consulta directa, no solo las del motor.
$tablasConInstitucion = [
    'usuarios', 'pacientes', 'historial_clinico', 'paginas', 'citas',
    'conversiones', 'invitaciones', 'sincronizacion_cola', 'bitacora',
    'users', 'notas_medicas', 'recetas', 'estudios',
    'medicamentos', 'cie10', 'comentarios', 'registro_versiones',
    'finanzas_movimientos', 'finanzas_conceptos', 'finanzas_cierres',
    'finanzas_facturas', 'finanzas_presupuestos', 'finanzas_recurrentes',
    'servicios_clinicos', 'imagenes_medicas', 'pagina_filas',
];

foreach ($tablasConInstitucion as $tabla) {
    repararColumnaFaltante($tabla, 'institucion_id', 'INT UNSIGNED NULL');
}

// configuracion tiene filas en despliegues viejos: NOT NULL DEFAULT 0
repararColumnaFaltante('configuracion', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 0 FIRST');

// Descubrir tablas adicionales vía information_schema (fallback, puede fallar sin problemas)
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
    error_log('[reparar_esquema] information_schema no accesible: ' . $e->getMessage());
}

// Otras columnas útiles del esquema actual (idempotentes)
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'foto', 'VARCHAR(255) NULL');

// (Añadir aquí más reparaciones cuando el esquema evolucione.)
