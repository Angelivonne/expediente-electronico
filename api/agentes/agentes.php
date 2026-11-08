<?php
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/ia/asistente.php';
require_once __DIR__ . '/../../app/servicios/ia/agentes.php';
header('Content-Type: application/json; charset=UTF-8');
function resp(int $c, array $d): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if (!puede('asistente')) resp(403, ['ok'=>false,'error'=>'Sin permiso']);
$uid = (int)usuarioActual()['id'];
$modo = $_SERVER['REQUEST_METHOD'];
if ($modo === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $a = agentGet($id,$uid);
        if (!$a) resp(404,['ok'=>false,'error'=>'Agente no encontrado']);
        $a['historial'] = agentHistory($id, 50);
        $a['figura'] = agentSvg($a['shape'], (int)$a['hue']);
        unset($a['api_clave']);
        resp(200, ['ok'=>true,'agente'=>$a]);
    }
    $lista = agentsList($uid);
    foreach ($lista as &$r) {
        $r['figura'] = agentSvg($r['shape'], (int)$r['hue'], '', 48);
        unset($r['api_clave']);
    }
    resp(200, ['ok'=>true,'agentes'=>$lista, 'formas'=>AGENT_SHAPES, 'servicios'=>SERVICIOS_IA]);
}
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) resp(403,['ok'=>false,'error'=>'CSRF']);
$d = json_decode(file_get_contents('php://input'), true) ?: [];
$accion = $d['accion'] ?? '';
if ($accion === 'crear') {
    $id = agentCreate($d, $uid, institucionId());
    registrarBitacora('agentes', $id, 'crear', 'agente', null, ($d['nombre'] ?? 'Agente'));
    resp(200, ['ok'=>true,'id'=>$id]);
}
if ($accion === 'estado') {
    $id = (int)($d['id'] ?? 0);
    $estado = (string)($d['estado'] ?? '');
    if (!in_array($estado,['creado','iniciado','pausado','eliminado'],true)) resp(422,['ok'=>false,'error'=>'Estado no válido']);
    if (!agentSetState($id,$uid,$estado)) resp(404,['ok'=>false,'error'=>'Agente no encontrado']);
    registrarBitacora('agentes', $id, 'actualizar', 'estado', null, $estado);
    resp(200, ['ok'=>true]);
}
if ($accion === 'conversar') {
    $id = (int)($d['id'] ?? 0);
    $a = agentGet($id,$uid);
    if (!$a) resp(404,['ok'=>false,'error'=>'Agente no encontrado']);
    $texto = mb_substr(trim((string)($d['texto'] ?? '')),0,12000);
    if ($texto === '') resp(422,['ok'=>false,'error'=>'Escribe algo']);
    agentMessage($id,$uid,institucionId(),'user',$texto);
    $historial = agentHistory($id, 20);
    $mensajes = [];
    foreach ($historial as $h) {
        $rol = $h['rol'] === 'user' ? 'user' : 'assistant';
        $mensajes[] = ['role'=>$rol,'content'=>$h['contenido']];
    }
    if (!$mensajes || end($mensajes)['content'] !== $texto) $mensajes[] = ['role'=>'user','content'=>$texto];
    if (ob_get_level() > 0) ob_end_flush();
    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();
    ob_implicit_flush(true);
    set_time_limit(600);
    $cfg = agentConfig($a);
    if (!asistenteConfigurado($cfg)) {
        echo json_encode(['t'=>'error','d'=>'Este agente todavía no tiene modelo configurado.'],JSON_UNESCAPED_UNICODE)."\n";
        echo json_encode(['t'=>'fin'],JSON_UNESCAPED_UNICODE)."\n"; exit;
    }
    $sistema = agentSystemPrompt($a);
    $respuesta = '';
    $emitir = function(string $t, string $dato) use (&$respuesta) {
        if ($t === 'texto') $respuesta .= $dato;
        echo json_encode(['t'=>$t,'d'=>$dato],JSON_UNESCAPED_UNICODE)."\n";
        flush();
    };
    $esfuerzo = in_array($a['esfuerzo'] ?? '',['low','medium','high'],true) ? $a['esfuerzo'] : 'medium';
    $herramientas = $a['herramientas'] ?? [];
    $listaHerramientas = [];
    if ($herramientas) {
        $todas = herramientasIa('restringido');
        foreach ($todas as $h) if (in_array($h['nombre'],$herramientas,true)) $listaHerramientas[] = $h;
    }
    $ejecutar = null;
    if ($listaHerramientas) {
        $llamadas = 0;
        $ejecutar = function(string $nombre, array $entrada) use (&$llamadas) : array {
            if (++$llamadas > 20) return ['ok'=>false,'resultado'=>'{"error":"limite"}'];
            $r = ejecutarHerramientaIa($nombre,$entrada,'restringido');
            echo json_encode(['t'=>'herramienta','d'=>$r['resumen'] ?? $nombre,'ok'=>$r['ok'] ?? false],JSON_UNESCAPED_UNICODE)."\n"; flush();
            return $r;
        };
    }
    transmitirAsistente($cfg,$sistema,$mensajes,$esfuerzo,$emitir,$listaHerramientas,$ejecutar);
    if ($respuesta !== '') agentMessage($id,$uid,institucionId(),'assistant',$respuesta);
    echo json_encode(['t'=>'fin'],JSON_UNESCAPED_UNICODE)."\n";
    exit;
}
resp(400,['ok'=>false,'error'=>'Acción no válida']);
