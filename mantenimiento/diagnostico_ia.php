<?php
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/configuracion.php';
require_once __DIR__ . '/../app/nucleo/reparar_esquema.php';
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

echo "\n--- Verificación Columnas Críticas (Prevención Error 1054) ---\n";
foreach ([
    'firmas_clinicas' => ['historial_id', 'usuario_nombre', 'cedula', 'huella', 'firmado_en', 'revocada_en'],
    'citas' => ['inicio', 'fin', 'motivo', 'estado', 'paciente_id', 'profesional_id'],
    'comentarios' => ['entidad', 'registro_id', 'texto', 'resuelto_en', 'creado_en'],
    'conexiones_autorizaciones' => ['alcance', 'vence_en', 'revocado_en', 'institucion_origen_id', 'institucion_destino_id'],
    'pacientes' => ['institucion_id', 'eliminado_en', 'confirmacion_datos', 'extras', 'foto']
] as $tabla => $cols) {
    echo "Tabla `$tabla`:\n";
    foreach ($cols as $col) {
        $ok = columnaExiste($tabla, $col);
        if (!$ok) {
            // Intentar ejecutar ALTER directamente y capturar el error exacto de MySQL
            try {
                $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$col` VARCHAR(255) NULL");
                $ok = columnaExiste($tabla, $col, true);
                echo "  - $col: REPARADO EN VIVO\n";
            } catch (Throwable $alterError) {
                echo "  - $col: ERROR (" . $alterError->getMessage() . ")\n";
            }
        } else {
            echo "  - $col: OK\n";
        }
    }
}

echo "\n--- Prueba Directa de OpenRouter Chat ---\n";
if (!empty($cfg['clave'])) {
    $modelosTest = [
        $cfg['modelo'],
        'meta-llama/llama-3.3-70b-instruct:free',
        'google/gemini-2.0-flash-lite-preview-02-05:free',
        'google/gemini-2.0-flash-exp:free',
        'deepseek/deepseek-r1:free',
        'qwen/qwen-2.5-72b-instruct'
    ];
    foreach ($modelosTest as $mTest) {
        if (empty($mTest) || $mTest === 'openrouter/free') continue;
        echo "Probando modelo: $mTest\n";
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $cfg['clave'],
                'HTTP-Referer: https://web-production-cdeee.up.railway.app',
                'X-Title: FEMECI Expediente Clinico'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $mTest,
                'messages' => [['role' => 'user', 'content' => 'Di exactamente: FUNCIONA']],
                'max_tokens' => 20
            ])
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        echo "  HTTP $code | Err: $err | Resp: " . substr($resp, 0, 160) . "\n";
        if ($code === 200) {
            echo "  --> EXITOSO CON: $mTest\n";
            break;
        }
    }
}
