<?php
/**
 * Guarda tiempo activo aproximado de la sesión actual.
 * El cliente solo puede registrar su propia sesión; no se guardan teclas ni contenido.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responderTiempo(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$usuario = usuarioActual();
$usuarioId = (int) $usuario['id'];
if ($usuario['rol'] === 'paciente') {
    responderTiempo(403, ['ok' => false, 'error' => 'El seguimiento de actividad no está disponible en el portal del paciente.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderTiempo(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('equipo_tiempo:usuario:' . $usuarioId, 120, 600)) {
    responderTiempo(429, ['ok' => false, 'error' => 'Demasiadas solicitudes.']);
}

$entrada = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($entrada) || !is_bool($entrada['activo'] ?? null)) {
    responderTiempo(400, ['ok' => false, 'error' => 'La señal de actividad no es válida.']);
}

$institucion = institucionId();
if ($institucion === null) {
    responderTiempo(403, ['ok' => false, 'error' => 'La cuenta no pertenece a una clínica activa.']);
}

$sesionHash = hash('sha256', session_id());
$fecha = date('Y-m-d');
$pdo = db();
try {
    $pdo->beginTransaction();
    $consulta = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, ultimo_latido, NOW()) AS intervalo
           FROM equipo_tiempo_activo
          WHERE usuario_id = ? AND fecha = ? AND sesion_hash = ?
          FOR UPDATE'
    );
    $consulta->execute([$usuarioId, $fecha, $sesionHash]);
    $intervalo = $consulta->fetchColumn();

    if ($intervalo === false) {
        $pdo->prepare(
            'INSERT INTO equipo_tiempo_activo (usuario_id, institucion_id, fecha, sesion_hash, inicio_en, ultimo_latido)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        )->execute([$usuarioId, $institucion, $fecha, $sesionHash]);
        $acumulado = 0;
    } else {
        $segundos = $entrada['activo'] && (int) $intervalo >= 30 && (int) $intervalo <= 90
            ? min((int) $intervalo, 75)
            : 0;
        $actualizar = $pdo->prepare(
            'UPDATE equipo_tiempo_activo
                SET institucion_id = ?, segundos_activos = segundos_activos + ?, ultimo_latido = NOW()
              WHERE usuario_id = ? AND fecha = ? AND sesion_hash = ?'
        );
        $actualizar->execute([$institucion, $segundos, $usuarioId, $fecha, $sesionHash]);
        $acumulado = $segundos;
    }

    $pdo->commit();
    responderTiempo(200, ['ok' => true, 'registrados' => $acumulado]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[equipo_tiempo] No se pudo guardar actividad: ' . $e->getMessage());
    responderTiempo(500, ['ok' => false, 'error' => 'No se pudo guardar el tiempo activo.']);
}
