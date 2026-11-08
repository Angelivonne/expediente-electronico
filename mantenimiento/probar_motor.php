<?php
/**
 * Prueba del motor único de datos (solo lectura).
 *
 *   php mantenimiento/probar_motor.php
 *
 * Verifica el registro de entidades y el adaptador de tabla: listar, buscar, obtener, contar,
 * filtrar por institución y describir campos. No escribe nada.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require_once $raiz . '/config/config.php';
require_once $raiz . '/config/db.php';
require_once $raiz . '/app/nucleo/funciones.php';
require_once $raiz . '/app/servicios/motor/almacen.php';

$institucion = (int) db()->query('SELECT id FROM instituciones ORDER BY id LIMIT 1')->fetchColumn();
$fallas = 0;
$ok = function (string $texto): void {
    echo "  ok   $texto\n";
};
$mal = function (string $texto) use (&$fallas): void {
    $fallas++;
    echo "  FALLA $texto\n";
};

foreach (array_keys(entidadesMotor()) as $clave) {
    echo "== $clave ==\n";

    $descripcion = motorDescribir($clave);
    if (!$descripcion || !$descripcion['campos']) {
        $mal('sin descripción de campos');
        continue;
    }
    $ok(count($descripcion['campos']) . ' campos declarados');

    $total = motorContar($clave, ['institucion_id' => $institucion]);
    if ($total <= 0) {
        $ok('sin registros (nada que listar)');
        continue;
    }
    $ok("total: $total");

    $lista = motorListar($clave, ['institucion_id' => $institucion, 'limite' => 3]);
    if (count($lista['filas']) !== min(3, $total)) {
        $mal('la página no trae las filas esperadas');
    } else {
        $ok('lista: ' . count($lista['filas']) . ' filas · página ' . $lista['pagina'] . ' de ' . $lista['paginas']);
    }

    $primera = $lista['filas'][0];
    if ($primera['id'] <= 0 || $primera['titulo'] === '') {
        $mal('la primera fila no trae id o título');
    } else {
        $ok('primera: #' . $primera['id'] . ' · ' . mb_substr($primera['titulo'], 0, 60));
    }

    $obtenida = motorObtener($clave, $primera['id'], ['institucion_id' => $institucion]);
    if (!$obtenida || $obtenida['id'] !== $primera['id']) {
        $mal('obtener por id no coincide');
    } else {
        $ok('obtener por id');
    }

    // Otra institución no debe ver nada (solo en las entidades propias de la clínica)
    $definicion = entidadMotor($clave);
    if (!empty($definicion['almacen']['institucion'])) {
        $ajena = motorContar($clave, ['institucion_id' => 999999]);
        if ($ajena !== 0) {
            $mal("filtra mal por institución ($ajena filas ajenas)");
        } else {
            $ok('aislado por institución');
        }
    } else {
        $ok('catálogo compartido (sin institución)');
    }

    // Búsqueda por un campo declarado
    $texto = trim((string) ($primera['titulo'] ?? ''));
    $texto = mb_substr(preg_replace('/\s+/u', ' ', $texto), 0, 4);
    if ($texto !== '') {
        $buscado = motorListar($clave, ['institucion_id' => $institucion, 'q' => $texto, 'limite' => 5]);
        if ($buscado['total'] < 1) {
            $mal('la búsqueda de «' . $texto . '» no encontró nada');
        } else {
            $ok('búsqueda «' . $texto . '»: ' . $buscado['total'] . ' coincidencias');
        }
    }
}

echo str_repeat('-', 50) . "\n";
echo $fallas === 0 ? "Motor: todas las pruebas pasaron.\n" : "Motor: $fallas falla(s).\n";
exit($fallas === 0 ? 0 : 1);
