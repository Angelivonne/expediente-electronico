<?php
/**
 * Auto-reparación de esquema: columnas que faltan en tablas ya existentes (Railway, despliegues
 * antiguos). Se ejecuta en cada arranque y es segura de repetir: solo añade lo que falta.
 *
 * Descubre automáticamente qué tablas necesitan `institucion_id` consultando el registro de
 * entidades (entidadesMotor) y los catálogos del sistema, además de tablas conocidas que la
 * usan directamente en SQL. El esquema ideal vive en database/esquema.sql.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../servicios/motor/entidades.php';

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

/** Cache de existencia de columnas por petición para no repetir consultas en cada listado. */
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

// --- Descubrir tablas que necesitan institucion_id desde el registro de entidades ---
$tablasInstitucion = [];
try {
    $entidades = entidadesMotor();
    foreach ($entidades as $entidad) {
        $almacen = $entidad['almacen'] ?? [];
        if (!empty($almacen['institucion']) && !empty($almacen['tabla'])) {
            $tablasInstitucion[$almacen['tabla']] = true;
        }
    }
    // Catálogos del sistema: algunos también declosan institucion
    if (function_exists('catalogosSistema')) {
        foreach (catalogosSistema() as $clave => $catalogo) {
            if (!empty($catalogo['institucion']) && !empty($catalogo['tabla'])) {
                $tablasInstitucion[$catalogo['tabla']] = true;
            }
        }
    }
} catch (Throwable $e) {
    error_log('[reparar_esquema] no se pudo leer entidadesMotor/catalogosSistema: ' . $e->getMessage());
}

// Tablas adicionales conocidas que usan institucion_id directamente en SQL
$tablasExtra = [
    'usuarios' => true,
    'bitacora' => true,
    'sincronizacion_cola' => true,
];

// Reparar todas las tablas descubiertas + extras
foreach (array_merge($tablasInstitucion, $tablasExtra) as $tabla => $_) {
    repararColumnaFaltante($tabla, 'institucion_id', 'INT UNSIGNED NULL');
}

// configuracion usa NOT NULL DEFAULT 0 (tiene filas existentes en despliegues viejos)
repararColumnaFaltante('configuracion', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 0');

// Otras columnas útiles del esquema actual
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'paciente_id', 'INT UNSIGNED NULL');
repararColumnaFaltante('usuarios', 'foto', 'VARCHAR(255) NULL');

// (Añadir aquí más reparaciones cuando el esquema evolucione.)
