<?php
/**
 * Automatización de verificación del proyecto.
 *
 *   php mantenimiento/verificar_todo.php
 *
 * Corre las pruebas del motor y de la conversión, revisa la integridad de los catálogos y resume
 * el estado. Sirve antes de entregar cambios o de migrar datos: una sola orden dice si algo se rompió.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require_once $raiz . '/config/config.php';
require_once $raiz . '/config/db.php';
require_once $raiz . '/app/nucleo/funciones.php';

$fallas = 0;
$lineas = [];

$reportar = function (string $nombre, bool $ok, string $detalle = '') use (&$fallas, &$lineas): void {
    if (!$ok) {
        $fallas++;
    }
    $lineas[] = sprintf('%-34s %-4s %s', $nombre, $ok ? 'OK' : 'FALLA', $detalle);
};

/* 1. Pruebas en archivos aparte: se ejecutan como procesos y se lee su código de salida */
$pruebas = [
    'Motor (lectura)'            => 'probar_motor.php',
    'Motor (altas y cambios)'    => 'probar_motor_crud.php',
    'Conversión ida y vuelta'    => 'probar_conversion.php',
    'Herramientas de la IA'      => 'probar_ia.php',
];
foreach ($pruebas as $nombre => $archivo) {
    $ruta = __DIR__ . '/' . $archivo;
    if (!is_file($ruta)) {
        $reportar($nombre, false, 'no existe ' . $archivo);
        continue;
    }
    $salida = [];
    $codigo = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);
    $ultima = trim((string) end($salida));
    $reportar($nombre, $codigo === 0, mb_substr($ultima, 0, 70));
}

/* 2. Integridad de los catálogos de ubicación */
$consultas = [
    'Municipios con entidad'   => ['SELECT COUNT(*) FROM catalogo_municipios WHERE entidad_id IS NULL', 0],
    'Localidades con municipio'=> ['SELECT COUNT(*) FROM catalogo_localidades WHERE municipio_id IS NULL', 0],
];
foreach ($consultas as $nombre => [$sql, $esperado]) {
    $valor = (int) db()->query($sql)->fetchColumn();
    $reportar($nombre, $valor === $esperado, $valor === 0 ? 'sin huérfanos' : "$valor huérfanos");
}
$municipios = (int) db()->query('SELECT COUNT(*) FROM catalogo_municipios')->fetchColumn();
$reportar('Municipios cargados', $municipios >= 31, "$municipios registros");

/* 3. Estado general de la base */
$tablas = (int) db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
$reportar('Tablas en la base', $tablas >= 60, "$tablas tablas");

$pacientes = (int) db()->query('SELECT COUNT(*) FROM pacientes WHERE eliminado_en IS NULL')->fetchColumn();
$folio = (string) (db()->query('SELECT folio FROM pacientes ORDER BY id DESC LIMIT 1')->fetchColumn() ?: '—');
$lineas[] = sprintf('%-34s %-4s %s', 'Pacientes activos', '—', "$pacientes · último folio $folio");

/* 4. Resumen */
echo str_repeat('=', 74) . "\n";
echo " VERIFICACIÓN GENERAL DEL PROYECTO\n";
echo str_repeat('=', 74) . "\n";
foreach ($lineas as $linea) {
    echo ' ' . $linea . "\n";
}
echo str_repeat('-', 74) . "\n";
echo $fallas === 0
    ? " Todo en orden.\n"
    : " $fallas revisión(es) con problemas.\n";
exit($fallas === 0 ? 0 : 1);
