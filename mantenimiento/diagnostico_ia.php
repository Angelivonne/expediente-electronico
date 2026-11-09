<?php
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/configuracion.php';
require_once __DIR__ . '/../app/nucleo/institucion.php';
require_once __DIR__ . '/../app/servicios/ia/asistente.php';

$token = $_GET['token'] ?? '';
if ($token !== 'seeder100femeci' && PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Acceso denegado");
}

header('Content-Type: text/plain; charset=UTF-8');

echo "=== DIAGNÓSTICO IA Y CONFIGURACIÓN ===\n\n";

$pdo = db();

// Check configuracion table
$q = $pdo->query("SELECT institucion_id, clave, CASE WHEN clave LIKE '%clave%' THEN CONCAT(LEFT(valor, 15), '... (len: ', LENGTH(valor), ')') ELSE valor END AS val, actualizado_en FROM configuracion ORDER BY clave");
$filas = $q->fetchAll(PDO::FETCH_ASSOC);
echo "Registros en tabla 'configuracion':\n";
foreach ($filas as $f) {
    echo "  [Inst: {$f['institucion_id']}] {$f['clave']} = {$f['val']} (actualizado: {$f['actualizado_en']})\n";
}

echo "\n--- Asistente Configuración ---\n";
$cfg = configAsistente();
echo "Proveedor: " . var_export($cfg['proveedor'], true) . "\n";
echo "Servicio: " . var_export(config('ia_servicio'), true) . "\n";
echo "Modelo: " . var_export($cfg['modelo'], true) . "\n";
echo "URL: " . var_export($cfg['url'], true) . "\n";
echo "Clave raw en BD: " . var_export(config('ia_clave') ? substr(config('ia_clave'), 0, 15) . '...' : null, true) . "\n";
echo "Clave descifrada: " . var_export($cfg['clave'] ? substr($cfg['clave'], 0, 8) . '... (len: ' . strlen($cfg['clave']) . ')' : null, true) . "\n";
echo "Asistente configurado(): " . var_export(asistenteConfigurado($cfg), true) . "\n";

echo "\n--- Llave de Cifrado ---\n";
echo "Archivo llave: " . ARCHIVO_LLAVE . "\n";
echo "Existe archivo: " . var_export(file_exists(ARCHIVO_LLAVE), true) . "\n";
if (file_exists(ARCHIVO_LLAVE)) {
    echo "Tamaño archivo llave: " . filesize(ARCHIVO_LLAVE) . " bytes\n";
}

echo "\n--- Foto de la Clínica / Sidebar ---\n";
$foto = config('foto_clinica');
echo "Ruta guardada en BD: " . var_export($foto, true) . "\n";
if ($foto) {
    $rutaReal = __DIR__ . '/../' . $foto;
    echo "Ruta completa: $rutaReal\n";
    echo "Existe en disco: " . var_export(file_exists($rutaReal), true) . "\n";
}

echo "\n--- Catálogos ---\n";
try {
    $tablas = $pdo->query("SHOW TABLES LIKE 'catalogo_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tablas catalogo_*: " . implode(', ', $tablas) . "\n";
} catch (Throwable $e) {
    echo "Error catalogos: " . $e->getMessage() . "\n";
}
