<?php
/**
 * Auto-reparación de esquema: columnas que faltan en tablas ya existentes (Railway, despliegues
 * antiguos). Se ejecuta una vez al arrancar y es segura de repetir: solo añade lo que falta.
 *
 * El esquema ideal vive en database/esquema.sql. En un despliegue donde una versión previa creó
 * la tabla sin una columna, esta reparación la agrega con ALTER TABLE. No modifica ni elimina
 * nada existente. Es un no-op en bases de datos ya al día.
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

// --- Columnas institucion_id añadidas en un esquema posterior a la creación de la tabla.
// En un despliegue antiguo (Railway, copia de safety) la tabla puede existir sin ellas, y el
// código que las usa falla con "Unknown column 'institucion_id'". Cada reparación es idempotente.
// Bitácora de auditoría: institución de quien hizo el movimiento.
repararColumnaFaltante('bitacora', 'institucion_id', 'INT UNSIGNED NULL AFTER usuario_id');

// Tablas de datos que se aíslan por institución.
repararColumnaFaltante('pacientes',     'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('historial_clinico', 'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('paginas',       'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('citas',         'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('conversiones',  'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('invitaciones',  'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('configuracion', 'institucion_id', 'INT UNSIGNED NOT NULL DEFAULT 0 FIRST');

// Tabla de sincronización: la columna institucion_id se creó en un esquema posterior.
repararColumnaFaltante('sincronizacion_cola', 'institucion_id', 'INT UNSIGNED NULL AFTER uuid');

// Portal del paciente / cuentas: ligas y multi-institución.
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL AFTER rol');
repararColumnaFaltante('usuarios', 'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('usuarios', 'paciente_id', 'INT UNSIGNED NULL AFTER rol');
repararColumnaFaltante('usuarios', 'foto', 'VARCHAR(255) NULL AFTER correo');

// Registros clínicos/financieros con aislamiento por institución cuando aplique.
repararColumnaFaltante('notas_medicas', 'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('recetas', 'institucion_id', 'INT UNSIGNED NULL AFTER id');
repararColumnaFaltante('estudios', 'institucion_id', 'INT UNSIGNED NULL AFTER id');

// (Añadir aquí más reparaciones cuando el esquema evolucione.)