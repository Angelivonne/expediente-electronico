<?php
/**
 * Migración directa ejecutada con: railway run php migrar_columnas.php
 * Se conecta usando las variables de entorno de Railway (DB_HOST = red interna)
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$pdo = db();
echo "Conexion OK\n";
echo "Host: " . DB_HOST . "\n";
echo "DB: " . DB_NOMBRE . "\n\n";

$tablas = [
    'pacientes', 'citas', 'usuarios', 'historial_clinico', 'bitacora', 'comentarios',
    'finanzas_movimientos', 'finanzas_facturas', 'servicios_clinicos', 'plantillas',
    'paginas', 'pagina_filas', 'conversiones', 'invitaciones', 'sincronizacion_cola',
    'notas_medicas', 'recetas', 'estudios', 'registro_versiones', 'plantillas_fila',
    'firmas_clinicas', 'agentes', 'agentes_memoria', 'agentes_conversaciones',
    'finanzas_conceptos', 'finanzas_cierres', 'finanzas_presupuestos', 'finanzas_recurrentes',
    'imagenes_medicas', 'catalogo_procedimientos', 'catalogo_especialidades',
];

$ok = 0; $fallo = 0; $yaExiste = 0;

foreach ($tablas as $tabla) {
    // Verificar si existe la tabla
    $st = $pdo->query("SHOW TABLES LIKE '$tabla'");
    if (!$st->fetch()) {
        echo "SKIP $tabla (tabla no existe)\n";
        continue;
    }
    // Verificar si existe la columna
    $st = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE 'institucion_id'");
    if ($st->fetch()) {
        echo "OK $tabla.institucion_id ya existe\n";
        $yaExiste++;
        continue;
    }
    // Añadir columna
    try {
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN institucion_id INT UNSIGNED NULL");
        echo "EXITO $tabla.institucion_id ANADIDO\n";
        $ok++;
    } catch (Throwable $e) {
        echo "ERROR $tabla: " . $e->getMessage() . "\n";
        $fallo++;
    }
}

echo "\n=== RESUMEN ===\n";
echo "Ya existian: $yaExiste\n";
echo "Anadidos: $ok\n";
echo "Fallos: $fallo\n";

// Test final en pacientes
echo "\n=== TEST FINAL ===\n";
try {
    $st = $pdo->query("SELECT COUNT(*) FROM pacientes c WHERE c.institucion_id IS NOT NULL OR 1=1");
    echo "QUERY pacientes OK: " . $st->fetchColumn() . " registros totales\n";
} catch (Throwable $e) {
    echo "QUERY pacientes FALLO: " . $e->getMessage() . "\n";
}
