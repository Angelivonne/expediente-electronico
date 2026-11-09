<?php
/**
 * Endpoint de migración de emergencia.
 * Acceder una sola vez en Railway para aplicar los ALTER TABLE que faltan.
 * URL: https://web-production-cdeee.up.railway.app/migrar_ahora.php
 */

// Protección mínima con token secreto
$tokenEsperado = 'femeci_migrar_2026';
$tokenRecibido = $_GET['token'] ?? '';

if ($tokenRecibido !== $tokenEsperado) {
    http_response_code(403);
    echo "Acceso denegado. Parámetro ?token= requerido.";
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "=== MIGRACIÓN DE EMERGENCIA ===\n";
echo "Host: " . DB_HOST . "\n";
echo "DB: " . DB_NOMBRE . "\n\n";

$pdo = db();

$tablas = [
    'pacientes', 'citas', 'usuarios', 'historial_clinico', 'bitacora', 'comentarios',
    'finanzas_movimientos', 'finanzas_facturas', 'servicios_clinicos', 'plantillas',
    'paginas', 'pagina_filas', 'conversiones', 'invitaciones', 'sincronizacion_cola',
    'notas_medicas', 'recetas', 'estudios', 'registro_versiones', 'plantillas_fila',
    'firmas_clinicas', 'agentes', 'agentes_memoria', 'agentes_conversaciones',
    'finanzas_conceptos', 'finanzas_cierres', 'finanzas_presupuestos', 'finanzas_recurrentes',
    'imagenes_medicas', 'catalogo_procedimientos', 'catalogo_especialidades',
    'catalogo_tipos_consulta', 'catalogo_tipos_atencion',
];

$ok = 0; $fallo = 0; $yaExiste = 0;

foreach ($tablas as $tabla) {
    $st = $pdo->query("SHOW TABLES LIKE '$tabla'");
    if (!$st->fetch()) {
        echo "SKIP $tabla (no existe)\n";
        continue;
    }
    $st = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE 'institucion_id'");
    if ($st->fetch()) {
        echo "OK   $tabla.institucion_id ya existe\n";
        $yaExiste++;
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN institucion_id INT UNSIGNED NULL");
        echo "ADD  $tabla.institucion_id ANADIDO\n";
        $ok++;
    } catch (Throwable $e) {
        echo "ERR  $tabla: " . $e->getMessage() . "\n";
        $fallo++;
    }
}

echo "\n=== RESUMEN ===\n";
echo "Ya existian: $yaExiste\n";
echo "Anadidos ahora: $ok\n";
echo "Fallos: $fallo\n";

echo "\n=== TEST FINAL ===\n";
try {
    $st = $pdo->query("SELECT COUNT(*) FROM pacientes");
    echo "SELECT COUNT(*) FROM pacientes: " . $st->fetchColumn() . " registros\n";
    $st = $pdo->query("SHOW COLUMNS FROM pacientes LIKE 'institucion_id'");
    echo "institucion_id en pacientes: " . ($st->fetch() ? "EXISTE" : "FALTA") . "\n";
} catch (Throwable $e) {
    echo "FALLO: " . $e->getMessage() . "\n";
}

echo "\nMIGRACION COMPLETADA. Ya puedes borrar este archivo.\n";
