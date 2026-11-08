<?php
/**
 * Reparación de texto corrupto (mojibake) en la base de datos.
 * Repara «CardiologÃ­a» → «Cardiología» en historial_clinico (título y detalle JSON), pacientes,
 * paginas y bitacora con seguridad: saca un RESPALDO JSON de las filas originales antes de cambiar nada.
 *
 * Uso desde consola:  php mantenimiento/reparar_codificacion.php
 * Uso desde el navegador: mantenimien-to/reparar_codificacion.php (solo administrador; botón "Reparar")
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';

// En web: solo administrador, solo POST con CSRF (los GET muestran la pantalla informativa).
$esAdminWeb = php_sapi_name() !== 'cli';
if ($esAdminWeb) {
    if (!puede('configurar')) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Solo administrador</title><p style="font:16px system-ui">Esta pantalla solo puede usarla el administrador.</p>';
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Solo por formulario</title><p style="font:16px system-ui">Ejecuta la reparación desde el botón de Configuración.</p>';
        exit;
    }
}

$esAdminWeb = php_sapi_name() !== 'cli';

/** Tablas y columnas de texto que se revisan (y su llave primaria). */
const TABLAS_TEXTO = [
    ['historial_clinico', 'id', ['titulo']],
    ['pacientes', 'id', ['nombre', 'domicilio', 'alergias', 'padecimientos_cronicos', 'medicamentos_actuales', 'motivo_consulta', 'contacto_emergencia']],
    ['paginas', 'id', ['titulo']],
    ['bitacora', 'id', ['valor_anterior', 'valor_nuevo', 'campo']],
];
const CAMPO_JSON = ['historial_clinico' => ['detalle'], 'paginas' => ['contenido']];

/**
 * Repara una tabla completa; devuelve también cuántas filas firmadas se omitieron.
 * El detalle JSON (historial_clinico.detalle) se decodifica, se sanea profundo y se vuelve a guardar.
 */
function repararTabla(string $tabla, string $llave, array $columnasTexto, array $columnasJson): array
{
    $pdo = db();
    $cols = array_merge([$llave], $columnasTexto, $columnasJson);
    $filas = $pdo->prepare('SELECT ' . implode(', ', $cols) . " FROM $tabla");
    $filas->execute();
    $todas = $filas->fetchAll(PDO::FETCH_ASSOC);

    // Una firma, incluso revocada, conserva la huella del contenido histórico.
    $firmados = [];
    if ($tabla === 'historial_clinico' && $pdo->query("SHOW TABLES LIKE 'firmas_clinicas'")->fetchColumn()) {
        $consultaFirmas = $pdo->query('SELECT historial_id FROM firmas_clinicas');
        $firmados = array_fill_keys(array_map('intval', $consultaFirmas->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    $respaldo = [];
    $cambios = [];
    $filasReparadas = 0;
    $filasOmitidasFirmadas = 0;
    $camposReparados = 0;

    foreach ($todas as $fila) {
        $nuevos = [];
        $respaldoFila = [];
        foreach ($columnasTexto as $col) {
            $original = (string) ($fila[$col] ?? '');
            $sano = sanoTexto($original);
            if ($sano !== $original) {
                $nuevos[$col] = $sano;
                $respaldoFila[] = ['tabla' => $tabla, 'id' => (int) $fila[$llave], 'col' => $col, 'antes' => $original];
            }
        }
        foreach ($columnasJson as $col) {
            $bruto = (string) ($fila[$col] ?? '');
            if ($bruto === '') {
                continue;
            }
            $datos = json_decode($bruto, true);
            if (!is_array($datos) || !$datos) {
                continue;
            }
            $sano = saneaProfundo($datos);
            if ($sano !== $datos) {
                $nuevos[$col] = json_encode($sano, JSON_UNESCAPED_UNICODE);
                $respaldoFila[] = ['tabla' => $tabla, 'id' => (int) $fila[$llave], 'col' => $col, 'antes' => mb_substr($bruto, 0, 4000)];
            }
        }
        if ($nuevos) {
            if ($tabla === 'historial_clinico' && isset($firmados[(int) $fila[$llave]])) {
                $filasOmitidasFirmadas++;
                continue;
            }
            array_push($respaldo, ...$respaldoFila);
            $camposReparados += count($respaldoFila);
            $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($nuevos)));
            $upd = $pdo->prepare("UPDATE $tabla SET $set WHERE $llave = ?");
            $upd->execute([...array_values($nuevos), (int) $fila[$llave]]);
            $filasReparadas++;
            $cambios[] = $fila[$llave];
        }
    }

    $rutaRespaldo = null;
    if ($respaldo) {
        $dir = __DIR__ . '/../privado/respaldos';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = null;
        }
        if ($dir !== null) {
            $ruta = $dir . '/codificacion-' . $tabla . '-' . date('Ymd-His') . '.json';
            escribirArchivoAtomico($ruta, json_encode($respaldo, JSON_UNESCAPED_UNICODE));
            $rutaRespaldo = $ruta;
        }
    }
    return [$filasReparadas, $camposReparados, $rutaRespaldo, $cambios, $filasOmitidasFirmadas];
}

$reporte = [];
$hayCambios = false;
foreach (TABLAS_TEXTO as [$tabla, $llave, $columnas]) {
    [$filas, $campos, $respaldo, $ids, $omitidasFirmadas] = repararTabla($tabla, $llave, $columnas, CAMPO_JSON[$tabla] ?? []);
    $reporte[] = ['tabla' => $tabla, 'filas' => $filas, 'campos' => $campos, 'respaldo' => $respaldo, 'ids' => count($ids), 'omitidas_firmadas' => $omitidasFirmadas];
    if ($filas > 0) {
        $hayCambios = true;
    }
    if ($esAdminWeb) {
        registrarBitacora('sistema', 0, 'actualizar', 'reparar_codificacion', $tabla, "$filas filas, $campos campos");
    }
}

$total = array_sum(array_column($reporte, 'filas'));
$camposTotal = array_sum(array_column($reporte, 'campos'));
$omitidasTotal = array_sum(array_column($reporte, 'omitidas_firmadas'));

if (php_sapi_name() === 'cli') {
    echo "Reparación de codificación terminada." . PHP_EOL;
    foreach ($reporte as $r) {
        echo "  {$r['tabla']}: {$r['filas']} fila(s), {$r['campos']} campo(s) reparados" . ($r['respaldo'] ? ' · respaldo: ' . basename($r['respaldo']) : '') . ($r['omitidas_firmadas'] ? ' · ' . $r['omitidas_firmadas'] . ' fila(s) firmadas omitidas' : '') . PHP_EOL;
    }
    echo "Total: $total fila(s), $camposTotal campo(s); $omitidasTotal fila(s) firmadas omitidas." . PHP_EOL;
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'total' => $total, 'campos' => $camposTotal, 'omitidas_firmadas' => $omitidasTotal, 'reporte' => $reporte], JSON_UNESCAPED_UNICODE);
