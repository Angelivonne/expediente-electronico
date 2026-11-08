<?php
/**
 * Motor único — API de consulta para herramientas (incluida la IA).
 *
 * POST JSON {accion, entidad, ...} con el token CSRF en X-CSRF-Token.
 *
 *   describir   {entidad}                         campos, tipos y obligatorios
 *   listar      {entidad, q?, filtro?, limite?, pagina?}   filas + total
 *   obtener     {entidad, id}
 *   crear       {entidad, datos}
 *   actualizar  {entidad, id, datos}
 *   eliminar    {entidad, id}
 *
 * Una sola superficie para todo el sistema: cualquier herramienta (o la IA) opera con estas seis
 * acciones sobre las 37 entidades, y los permisos y la bitácora se aplican en el motor.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/motor/almacen.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}

$entrada = json_decode((string) file_get_contents('php://input'), true) ?: [];
$accion  = (string) ($entrada['accion'] ?? '');
$clave   = (string) ($entrada['entidad'] ?? '');
$entidad = entidadMotor($clave);
if (!$entidad) {
    responder(400, ['ok' => false, 'error' => 'Esa entidad no existe.']);
}
exigirPermiso($entidad['permiso'] ?? 'ver_expediente');

switch ($accion) {
    case 'describir':
        responder(200, ['ok' => true] + (motorDescribir($clave) ?? []));

    case 'listar':
        $filtro = is_array($entrada['filtro'] ?? null) ? $entrada['filtro'] : [];
        $lista  = motorListar($clave, [
            'q'      => mb_substr(trim((string) ($entrada['q'] ?? '')), 0, 120),
            'filtro' => $filtro,
            'limite' => max(1, min(200, (int) ($entrada['limite'] ?? 50))),
            'pagina' => max(1, (int) ($entrada['pagina'] ?? 1)),
        ]);
        responder(200, ['ok' => true, 'entidad' => $clave] + $lista);

    case 'obtener':
        $fila = motorObtener($clave, (int) ($entrada['id'] ?? 0));
        $fila === null
            ? responder(404, ['ok' => false, 'error' => 'El registro no existe.'])
            : responder(200, ['ok' => true, 'registro' => $fila]);

    case 'agrupar':
        $campo = (string) ($entrada['campo'] ?? '');
        if ($campo === '') {
            responder(422, ['ok' => false, 'error' => 'Indica el campo para agrupar.']);
        }
        $grupos = motorAgrupar($clave, $campo, [
            'q'      => mb_substr(trim((string) ($entrada['q'] ?? '')), 0, 120),
            'filtro' => is_array($entrada['filtro'] ?? null) ? $entrada['filtro'] : [],
        ], max(1, min(100, (int) ($entrada['limite'] ?? 25))));
        responder(200, ['ok' => true, 'entidad' => $clave, 'campo' => $campo, 'grupos' => $grupos,
                        'total' => array_sum(array_column($grupos, 'conteo'))]);

    case 'crear':
        if (!is_array($entrada['datos'] ?? null)) {
            responder(422, ['ok' => false, 'error' => 'Faltan los datos del registro.']);
        }
        $resultado = motorCrear($clave, $entrada['datos']);
        empty($resultado['ok'])
            ? responder(422, ['ok' => false, 'errores' => $resultado['errores'] ?? []])
            : responder(201, ['ok' => true, 'id' => (int) $resultado['id'], 'folio' => $resultado['folio'] ?? null]);

    case 'actualizar':
        $id = (int) ($entrada['id'] ?? 0);
        if ($id <= 0 || !is_array($entrada['datos'] ?? null)) {
            responder(422, ['ok' => false, 'error' => 'Indica el registro y los datos a cambiar.']);
        }
        $resultado = motorActualizar($clave, $id, $entrada['datos']);
        empty($resultado['ok'])
            ? responder(422, ['ok' => false, 'errores' => $resultado['errores'] ?? []])
            : responder(200, ['ok' => true, 'id' => $id, 'cambios' => (int) ($resultado['cambios'] ?? 0)]);

    case 'eliminar':
        $resultado = motorEliminar($clave, (int) ($entrada['id'] ?? 0));
        empty($resultado['ok'])
            ? responder(422, ['ok' => false, 'errores' => $resultado['errores'] ?? []])
            : responder(200, ['ok' => true]);
}

responder(400, ['ok' => false, 'error' => 'Acción no válida.']);
