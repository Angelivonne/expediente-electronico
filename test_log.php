<?php
$dirs = array(
    __DIR__ . "/privado/registros",
    __DIR__ . "/privado",
    __DIR__ . "/app/../privado/registros",
);
foreach ($dirs as $d) {
    echo "Dir: $d - exists: " . (is_dir($d) ? "YES" : "NO") . "\n";
    if (is_dir($d)) {
        $files = scandir($d);
        echo "  Files: " . implode(", ", $files) . "\n";
    }
}
// Try to create the directory and log
$dir = __DIR__ . "/privado/registros";
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
    echo "Created dir: $dir - " . (is_dir($dir) ? "OK" : "FAILED") . "\n";
}
echo "error_log setting: " . ini_get("error_log") . "\n";
echo "log_errors: " . ini_get("log_errors") . "\n";

