<?php
$logFile = __DIR__ . "/privado/registros/php-errores.log";
if (file_exists($logFile)) {
    echo file_get_contents($logFile);
} else {
    echo "No log file found\n";
}
// Also try app/nucleo path
$alt = __DIR__ . "/app/../privado/registros/php-errores.log";
if (file_exists($alt)) {
    echo "\n--- alt path ---\n";
    echo file_get_contents($alt);
}

