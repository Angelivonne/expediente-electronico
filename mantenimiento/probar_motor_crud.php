<?php
/**
 * Prueba de altas, cambios y bajas del motor único (crea un registro temporal de medicamentos
 * y lo borra al final; no toca expedientes).
 *
 *   php mantenimiento/probar_motor_crud.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require_once $raiz . '/config/config.php';
require_once $raiz . '/config/db.php';
require_once $raiz . '/app/nucleo/funciones.php';
require_once $raiz . '/app/datos/catalogos.php';
require_once $raiz . '/app/servicios/motor/almacen.php';

$institucion = (int) db()->query('SELECT id FROM instituciones ORDER BY id LIMIT 1')->fetchColumn();
$opciones = ['institucion_id' => $institucion];
$fallas = 0;
$ok = function (string $texto): void { echo "  ok   $texto\n"; };
$mal = function (string $texto) use (&$fallas): void { $fallas++; echo "  FALLA $texto\n"; };

$clave = 'PRUEBA-MOTOR-' . strtoupper(bin2hex(random_bytes(3)));
$id = 0;

try {
    echo "== alta ==\n";
    $alta = motorCrear('medicamentos', [
        'clave' => $clave, 'nombre' => 'Medicamento de prueba', 'presentacion' => 'Caja',
        'concentracion' => '1 g', 'via' => 'Oral', 'laboratorio' => 'Prueba', 'activo' => '1',
    ], $opciones);
    if (empty($alta['ok'])) {
        $mal('no se pudo crear: ' . json_encode($alta['errores'] ?? [], JSON_UNESCAPED_UNICODE));
        throw new RuntimeException('sin alta');
    }
    $id = (int) $alta['id'];
    $ok("creado #$id");

    $encontrado = motorListar('medicamentos', $opciones + ['q' => $clave, 'limite' => 5]);
    if ($encontrado['total'] < 1) {
        $mal('no aparece en la búsqueda');
    } else {
        $ok('aparece en la búsqueda (' . $encontrado['total'] . ')');
    }

    echo "== validación ==\n";
    $malAlta = motorCrear('medicamentos', ['clave' => $clave . '-X', 'nombre' => ''], $opciones);
    if (!empty($malAlta['ok'])) {
        $mal('aceptó un alta sin nombre');
        db()->prepare('DELETE FROM medicamentos WHERE id = ?')->execute([(int) $malAlta['id']]);
    } else {
        $ok('rechaza el alta sin nombre (' . ($malAlta['errores']['nombre'] ?? '') . ')');
    }

    echo "== cambio ==\n";
    $cambio = motorActualizar('medicamentos', $id, ['nombre' => 'Medicamento de prueba 2'], $opciones);
    if (empty($cambio['ok']) || $cambio['cambios'] < 1) {
        $mal('no se pudo actualizar');
    } else {
        $ok("actualizado ({$cambio['cambios']} cambio)");
    }
    $fila = motorObtener('medicamentos', $id, $opciones);
    if (($fila['nombre'] ?? '') !== 'Medicamento de prueba 2') {
        $mal('el nombre no quedó guardado');
    } else {
        $ok('el valor quedó en la base');
    }

    echo "== baja ==\n";
    $baja = motorEliminar('medicamentos', $id, $opciones);
    if (!empty($baja['ok'])) {
        $mal('permitió dar de baja un catálogo sin columna de baja');
    } else {
        $ok('rechaza la baja donde no hay columna de baja');
    }
} finally {
    if ($id > 0) {
        db()->prepare('DELETE FROM medicamentos WHERE id = ?')->execute([$id]);
        echo "  limpieza: registro temporal eliminado\n";
    }
}

echo str_repeat('-', 50) . "\n";
echo $fallas === 0 ? "Altas, cambios y bajas: todas las pruebas pasaron.\n" : "Fallos: $fallas\n";
exit($fallas === 0 ? 0 : 1);
