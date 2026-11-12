<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$raiz = dirname(__DIR__);
$archivos = [
    'mantenimiento/validar_fuentes.php',
    'mantenimiento/actualizar_busqueda_historial.php',
    'database/busqueda_historial_triggers.php',
    'config/config.php',
    'config/db.php',
    'app/nucleo/seguridad.php',
    'app/nucleo/inicio.php',
    'app/nucleo/reparar_esquema.php',
    'app/nucleo/funciones.php',
    'app/vistas/plantilla/iconos.php',
    'app/datos/catalogos.php',
    'app/nucleo/configuracion.php',
    'app/nucleo/institucion.php',
    'pacientes/index.php',
];
$recursos = [
    'database/migracion_busqueda_historial.sql',
    'database/migracion_busqueda_historial_revertir.sql',
];
$errores = [];
$seguridadValida = false;

foreach ($archivos as $relativo) {
    $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_file($ruta)) {
        $errores[] = "$relativo: no existe.";
        continue;
    }

    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        $errores[] = "$relativo: no se pudo leer.";
        continue;
    }
    if ($contenido === '') {
        $errores[] = "$relativo: está vacío.";
        continue;
    }
    if (str_contains($contenido, "\0")) {
        $errores[] = "$relativo: contiene bytes nulos; posible archivo dañado.";
        continue;
    }

    try {
        token_get_all($contenido, TOKEN_PARSE);
        if ($relativo === 'app/nucleo/seguridad.php') {
            $seguridadValida = true;
        }
    } catch (ParseError $e) {
        $errores[] = "$relativo: error de sintaxis PHP: " . $e->getMessage();
    }
}

foreach ($recursos as $relativo) {
    $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativo);
    if (!is_file($ruta) || filesize($ruta) === 0) {
        $errores[] = "$relativo: no existe o está vacío.";
    }
}

$seguridad = $raiz . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'nucleo' . DIRECTORY_SEPARATOR . 'seguridad.php';
if ($seguridadValida) {
    require_once $seguridad;
    if (!function_exists('configurarErrores')) {
        $errores[] = 'app/nucleo/seguridad.php: no define configurarErrores().';
    }
}

if ($errores) {
    fwrite(STDERR, "[ERROR] Validación de archivos PHP esenciales falló:\n");
    foreach ($errores as $error) {
        fwrite(STDERR, "  - $error\n");
    }
    exit(1);
}

fwrite(STDOUT, "[OK] Archivos PHP esenciales íntegros y con sintaxis válida.\n");
