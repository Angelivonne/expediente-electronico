<?php
/**
 * Auto-reparación de esquema: columnas que faltan en tablas ya existentes (Railway, despliegues
 * antiguos). Se ejecuta en cada arranque y es segura de repetir: solo añade lo que falta.
 *
 * Estrategia definitiva: recorre TODAS las tablas de la base de datos actual y agrega
 * `institucion_id` a las que no la tengan. Esto cubre cualquier consulta SQL directa que use
 * la columna (hay +100 lugares en el código), no solo las entidades del motor.
 *
 * El esquema ideal vive en database/esquema.sql. Es un no-op en bases de datos ya al día.
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
        return;
    }
    if ($existe > 0) {
        return;
    }
    try {
        db()->exec("ALTER TABLE `$tabla` ADD COLUMN $definicion");
        error_log('[reparar_esquema] añadido ' . $tabla . '.' . $columna);
    } catch (Throwable $e) {
        error_log('[reparar_esquema] fallo ' . $tabla . '.' . $columna . ': ' . $e->getMessage());
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

/** Tablas del sistema de MySQL que nunca deben tocarse. */
$tablasSistema = [
    'information_schema' => true,
    'mysql' => true,
    'performance_schema' => true,
    'sys' => true,
];

// Obtener todas las tablas de la base de datos actual
$tablas = [];
try {
    $consulta = db()->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    foreach ($consulta->fetchAll() as $fila) {
        $nombre = (string) $fila['TABLE_NAME'];
        if (isset($tablasSistema[$nombre])) {
            continue;
        }
        $tablas[] = $nombre;
    }
} catch (Throwable $e) {
    error_log('[reparar_esquema] no se pudo listar tablas: ' . $e->getMessage());
}

// Agregar institucion_id a todas las tablas de datos que no la tengan
foreach ($tablas as $tabla) {
    if ($tabla === 'configuracion') {
        repararColumnaFaltante('configuracion', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 0 FIRST');
    } else {
        repararColumnaFaltante($tabla, 'institucion_id', 'INT UNSIGNED NULL');
    }
}

// Otras columnas útiles del esquema actual (idempotentes)
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'foto', 'VARCHAR(255) NULL');

// (Añadir aquí más reparaciones cuando el esquema evolucione.)
