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
require_once $raiz . '/app/servicios/ia/asistente.php';

$fallas = 0;
$ok  = function (string $texto) : void { echo "  ok    $texto\n"; };
$mal = function (string $texto) use (&$fallas) : void { $fallas++; echo "  FALLA $texto\n"; };

$probar = function (string $nombre, array $entrada, callable $verificar) use ($mal): void {
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
in_array('buscar_pacientes', $restringido, true) ? $mal('«buscar_pacientes» no debe exponer expedientes con acceso restringido') : $ok('«buscar_pacientes» protegida por acceso al expediente');
foreach (['datos', 'buscar_pacientes'] as $herramientaRestringida) {
    $r = ejecutarHerramientaIa($herramientaRestringida, ['consulta' => 'Ana'], 'restringido');
    empty($r['ok']) ? $ok("«{$herramientaRestringida}» denegada con acceso restringido") : $mal("«{$herramientaRestringida}» se ejecutó con acceso restringido");
}
foreach (['restringido', 'expediente'] as $accesoPrueba) {
    $definiciones = herramientasIa($accesoPrueba);
    $nombres = array_column($definiciones, 'nombre');
    count(array_unique($nombres)) === count($nombres)
        ? $ok("nombres únicos en el catálogo ($accesoPrueba)")
        : $mal("hay nombres duplicados en el catálogo ($accesoPrueba)");
    foreach ($definiciones as $definicion) {
        $nombre = (string) ($definicion['nombre'] ?? '');
        $esquema = $definicion['esquema'] ?? [];
        $propiedades = $esquema['properties'] ?? [];
        $requeridas = $esquema['required'] ?? [];
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $nombre)
            || ($esquema['type'] ?? null) !== 'object'
            || !is_array($propiedades)
            || !is_array($requeridas)
            || array_diff($requeridas, array_keys($propiedades))) {
            $mal("definición o esquema inválido: $nombre ($accesoPrueba)");
        }
    }
    $openai = herramientasOpenAI($definiciones);
    $anthropic = herramientasAnthropic($definiciones);
    count($openai) === count($definiciones)
        && count($anthropic) === count($definiciones)
        && array_column(array_column($openai, 'function'), 'name') === $nombres
        && array_column($anthropic, 'name') === $nombres
        ? $ok("esquemas completos para OpenAI-compatible y Anthropic ($accesoPrueba)")
        : $mal("conversión de esquemas inconsistente ($accesoPrueba)");
}
$llamadasParciales = [];
acumularLlamadaCompatibleOpenAI($llamadasParciales, [
    'index' => 0, 'id' => 'call_1', 'function' => ['name' => 'buscar_', 'arguments' => '{"consulta":"ace'],
]);
acumularLlamadaCompatibleOpenAI($llamadasParciales, [
    'index' => 1, 'id' => 'call_2', 'function' => ['name' => 'ver_agenda', 'arguments' => '{"desde":"2026-04-01"}'],
]);
acumularLlamadaCompatibleOpenAI($llamadasParciales, [
    'index' => 0, 'function' => ['name' => 'pacientes', 'arguments' => 'ites"}'],
]);
$llamadaReconstruida = $llamadasParciales[0] ?? [];
$argumentosReconstruidos = json_decode((string) ($llamadaReconstruida['argumentos'] ?? ''), true);
($llamadaReconstruida['id'] ?? '') === 'call_1'
    && ($llamadaReconstruida['nombre'] ?? '') === 'buscar_pacientes'
    && ($argumentosReconstruidos['consulta'] ?? '') === 'aceites'
    && count($llamadasParciales) === 2
    ? $ok('reconstrucción de tool_calls fragmentadas e intercaladas')
    : $mal('no se reconstruyeron correctamente los fragmentos de tool_calls');

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
    $cuentaPacientes = db()->prepare('SELECT COUNT(*) FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL');
    $cuentaPacientes->execute([institucionId()]);
    $esperado = (int) $cuentaPacientes->fetchColumn();
    empty($r['ok']) || $total !== $esperado || ($esperado > 0 && $grupos < 1)
        ? $mal("agrupar pacientes devolvió total=$total grupos=$grupos, se esperaban $esperado")
        : $ok("agrupar pacientes por sexo ($total registros, $grupos grupos)");
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
$pacientePrueba = db()->prepare(
    'SELECT id, nombre, primer_apellido, segundo_apellido FROM pacientes
      WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY id LIMIT 1'
);
$pacientePrueba->execute([institucionId()]);
$pacientePrueba = $pacientePrueba->fetch();
if ($pacientePrueba) {
    $nombrePrueba = trim($pacientePrueba['nombre'] . ' ' . $pacientePrueba['primer_apellido'] . ' ' . ($pacientePrueba['segundo_apellido'] ?? ''));
    $consultaNatural = 'Busca al paciente ' . $nombrePrueba . ' en expedientes, por favor';
    $probar('buscar_pacientes', ['consulta' => $consultaNatural], function (array $r) use ($ok, $mal, $pacientePrueba): void {
        $j = json_decode((string) $r['resultado'], true) ?: [];
        $encontroPaciente = (bool) array_filter($j['pacientes'] ?? [], fn ($p) => (int) ($p['id'] ?? 0) === (int) $pacientePrueba['id']);
        empty($r['ok']) || !$encontroPaciente
            ? $mal('buscar_pacientes no encontró un paciente real al pasar la frase «Busca al paciente … en expedientes»')
            : $ok('buscar_pacientes encuentra un paciente al pasar la frase completa');
    });
} else {
    $ok('prueba de frase natural omitida: no hay pacientes activos en esta institución');
}
$probar('buscar_medicamentos', ['consulta' => 'los aceites'], function (array $r) use ($ok, $mal): void {
    $j = json_decode((string) $r['resultado'], true) ?: [];
    $filas = $j['filas'] ?? [];
    $encontroAceite = (int) ($j['total'] ?? 0) > 0
        && (bool) array_filter($filas, fn ($fila) => stripos((string) ($fila['nombre'] ?? ''), 'aceite') !== false);
    empty($r['ok']) || !$encontroAceite
        ? $mal('buscar_medicamentos «los aceites» no devolvió un medicamento con «aceite» en el nombre')
        : $ok('buscar_medicamentos «los aceites» → catálogo');
});

echo str_repeat('-', 50) . "\n";
echo $fallas === 0 ? "Herramientas de la IA: todas las pruebas pasaron.\n" : "Herramientas de la IA: $fallas falla(s).\n";
exit($fallas === 0 ? 0 : 1);
