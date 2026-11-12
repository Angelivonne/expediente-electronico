<?php
/**
 * Corrige mojibake (UTF-8 leído como Latin-1) en archivos del proyecto. Solo CLI.
 * Uso: php mantenimiento/fix_encoding.php [directorio]
 */
if (php_sapi_name() !== 'cli') {
    die('Este script solo se ejecuta desde la línea de comandos.');
}

$dir = $argv[1] ?? dirname(__DIR__);
$extensiones = ['php', 'css', 'js', 'sql', 'md', 'html', 'xml', 'json'];
$corregidos = 0;
$revisados = 0;

$patrones = [
    'á' => 'á', 'é' => 'é', 'í' => 'í', 'ó' => 'ó', 'ú' => 'ú',
    'ñ' => 'ñ', '·' => '·', '»' => '»', '«' => '«',
    '' => '', 'Á"' => 'Ó', 'Áš' => 'Ú', 'Á' => 'Á',
    'Á¼' => 'ü', 'Á¶' => 'ö', 'Á¤' => 'ä',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $archivo) {
    if (!$archivo->isFile()) continue;
    $ext = strtolower($archivo->getExtension());
    if (!in_array($ext, $extensiones)) continue;
    
    $ruta = $archivo->getPathname();
    $revisados++;
    
    $contenido = file_get_contents($ruta);
    if ($contenido === false) continue;
    
    $original = $contenido;
    foreach ($patrones as $corrupto => $correcto) {
        $contenido = str_replace($corrupto, $correcto, $contenido);
    }
    
    if ($contenido !== $original) {
        // Verificar que el resultado es UTF-8 válido
        if (mb_check_encoding($contenido, 'UTF-8')) {
            file_put_contents($ruta, $contenido);
            $corregidos++;
            echo "Corregido: $ruta\n";
        } else {
            echo "ERROR (encoding inválido): $ruta\n";
        }
    }
}

echo "\nArchivos revisados: $revisados\n";
echo "Archivos corregidos: $corregidos\n";
echo "Listo.\n";