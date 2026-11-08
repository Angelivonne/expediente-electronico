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
        $existe = $consulta->fetchColumn();
    } catch (Throwable $e) {
        return; // sin BD o sin permisos: no es falla crítica al arrancar
    }
    if ((int) $existe > 0) {
        return;
    }
    try {
        db()->exec("ALTER TABLE `$tabla` ADD COLUMN $definicion");
    } catch (Throwable $e) {
        // Columna ya existe (condición de carrera) o no se puede añadir: ignora.
    }
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

// Portal del paciente: cuenta ligada a su propio expediente (solo lectura).
repararColumnaFaltante('users', 'paciente_id', 'INT UNSIGNED NULL AFTER rol');

// (Añadir aquí más reparaciones cuando el esquema evolucione.)