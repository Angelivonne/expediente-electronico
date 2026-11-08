<?php
define("RESPUESTA_JSON", false);
require_once __DIR__ . "/app/nucleo/inicio.php";
echo "After inicio.php\n";
echo "usuarioActual: " . var_export(usuarioActual(), true) . "\n";
echo "institucionId: " . var_export(institucionId(), true) . "\n";
echo "rutaInicio: " . rutaInicio() . "\n";
echo "URL_BASE: " . URL_BASE . "\n";

