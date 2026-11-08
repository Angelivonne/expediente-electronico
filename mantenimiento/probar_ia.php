<?php
/**
 * Prueba de las herramientas del asistente (tool calling) sin llamar al modelo.
 *
 * Ejecuta la misma ruta que usa la IA al pedir una herramienta, con una sesión de administrador,
 * y verifica que las respuestas sean las esperadas. Sirve para detectar regresiones del motor o de
 * los permisos sin gastar tokens.
 *
 *   php mantenimiento/probar_ia.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
$_SESSION = ['usuario' => ['id' => 1, 'nombre' => 'Administrador', 'rol' => 'administrador', 'institucion_id' => 1]];

require_once $raiz . '/config/config.php';
require_once $raiz . '/config/db.php';
require_once $raiz . '/app/nucleo/funciones.php';
require_once $raiz . '/app/nucleo/seguridad.php';
require_once $raiz . '/app/nucleo/institucion.php';
require_once $raiz . '/app/datos/catalogos.php';
require_once $raiz . '/app/servicios/ia/herramientas.php';

$fallas = 0;
$ok  = function (string $texto) : void { echo "  ok    $texto\n"; };
$mal = function (string $texto) use (&$fallas) : void { $fallas++; echo "  FALLA $texto\n"; };

$probar = function (string $nombre, array $entrada, callable $verificar) use ($ok, $mal): void {
    $r = ejecutarHerramientaIa($nombre, $entrada, 'expediente');
    try {
        $verificar($r);
    } catch (Throwable $e) {
        $mal($nombre . ': ' . $e->getMessage());
    }
};

echo "== catálogo de herramientas ==\n";
$expediente = array_column(herramientasIa('expediente'), 'nombre');
$restringido = array_column(herramientasIa('restringido'), 'nombre');
in_array('datos', $expediente, true) ? $ok('«datos» disponible con acceso al expediente') : $mal('falta «datos» con acceso al expediente');
in_array('datos', $restringido, true) ? $mal('«datos» no debe estar con acceso restringido') : $ok('«datos» bloqueada con acceso restringido');
in_array('buscar_pacientes', $expediente, true) ? $ok('herramientas clínicas presentes') : $mal('faltan herramientas clínicas');

echo "== herramienta datos ==\n";
$probar('datos', ['accion' => 'describir', 'entidad' => 'pacientes'], function (array $r) use ($ok, $mal): void {
    $j = json_decode((string) $r['resultado'], true) ?: [];
    empty($r['ok']) ? $mal('describir pacientes falló') : $ok('describir pacientes (' . count($j['campos'] ?? []) . ' campos)');
    count($j['campos'] ?? []) < 10 ? $mal('describir devolvió muy pocos campos') : null;
});

$probar('datos', ['accion' => 'listar', 'entidad' => 'catalogo_especialidades', 'q' => 'cardio'], function (array $r) use ($ok, $mal): void {
    $j = json_decode((string) $r['resultado'], true) ?: [];
    $primera = $j['filas'][0]['nombre'] ?? '';
    empty($r['ok']) || $primera !== 'Cardiología' ? $mal('buscar «cardio» no devolvió Cardiología') : $ok('buscar «cardio» → Cardiología');
});

$probar('datos', ['accion' => 'agrupar', 'entidad' => 'pacientes', 'campo' => 'sexo'], function (array $r) use ($ok, $mal): void {
    $j = json_decode((string) $r['resultado'], true) ?: [];
    $total = (int) ($j['total'] ?? 0);
    $grupos = count($j['grupos'] ?? []);
    empty($r['ok']) || $total !== 9 || $grupos < 1 ? $mal("agrupar pacientes devolvió total=$total grupos=$grupos") : $ok("agrupar pacientes por sexo ($total registros, $grupos grupos)");
});

$probar('datos', ['accion' => 'obtener', 'entidad' => 'catalogo_municipios', 'id' => 6], function (array $r) use ($ok, $mal): void {
    $j = json_decode((string) $r['resultado'], true) ?: [];
    ($j['registro']['entidad'] ?? '') === 'Baja California' ? $ok('obtener municipio 6 → Baja California') : $mal('obtener municipio 6 no trajo su entidad');
});

$probar('datos', ['accion' => 'crear', 'entidad' => 'historial', 'datos' => ['titulo' => 'x']], function (array $r) use ($ok, $mal): void {
    empty($r['ok']) ? $ok('historial rechaza altas (solo lectura)') : $mal('el historial aceptó un alta');
});

$probar('datos', ['accion' => 'listar', 'entidad' => 'loquesea'], function (array $r) use ($ok, $mal): void {
    empty($r['ok']) ? $ok('entidad inexistente rechazada') : $mal('aceptó una entidad inexistente');
});

echo "== herramientas clínicas ==\n";
$probar('buscar_pacientes', ['consulta' => 'Ana'], function (array $r) use ($ok, $mal): void {
    empty($r['ok']) ? $mal('buscar_pacientes falló') : $ok('buscar_pacientes «Ana»');
});

echo str_repeat('-', 50) . "\n";
echo $fallas === 0 ? "Herramientas de la IA: todas las pruebas pasaron.\n" : "Herramientas de la IA: $fallas falla(s).\n";
exit($fallas === 0 ? 0 : 1);
