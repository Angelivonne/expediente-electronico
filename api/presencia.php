<?php
/**
 * Presencia: cada pestaña avisa cada 15 s en qué sección o página está y si está editando.
 * POST JSON {clave, editando} → presencia de sección; GET ?equipo=1 → conexión reciente del equipo.
 * POST devuelve {personas: [{id, nombre, editando, tu}]} de los últimos 40 s,
 * primero quien edita y después por actividad más reciente.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['equipo'] ?? '') === '1') {
    header('Cache-Control: no-store');
    if ((usuarioActual()['rol'] ?? '') === 'paciente') {
        responder(403, ['ok' => false, 'error' => 'No disponible para el portal del paciente.']);
    }
    if (!dentroDelLimite('presencia_equipo:usuario:' . $usuarioId, 180, 600)) {
        responder(429, ['ok' => false]);
    }
    $consulta = db()->prepare(
        "SELECT u.id, u.nombre, u.foto, u.rol, u.activo,
                COALESCE(p.ultima_vez, u.ultimo_acceso) AS ultima_vez,
                (u.activo = 1 AND p.ultima_vez >= NOW() - INTERVAL 60 SECOND) AS en_linea
           FROM usuarios u
           LEFT JOIN (SELECT usuario_id, MAX(visto_en) AS ultima_vez FROM presencia GROUP BY usuario_id) p ON p.usuario_id = u.id
          WHERE u.institucion_id = ? AND u.rol <> 'paciente'
          ORDER BY en_linea DESC, ultima_vez DESC, u.nombre
          LIMIT 500"
    );
    $consulta->execute([institucionId()]);
    responder(200, ['ok' => true, 'personas' => array_map(fn ($p) => [
        'id' => (int) $p['id'],
        'nombre' => $p['nombre'],
        'foto' => $p['foto'] ? URL_BASE . '/' . $p['foto'] : null,
        'rol' => $p['rol'],
        'activo' => (bool) $p['activo'],
        'en_linea' => (bool) $p['en_linea'],
        'ultima_vez' => $p['ultima_vez'],
    ], $consulta->fetchAll())]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró.']);
}
if (!dentroDelLimite('presencia:usuario:' . $usuarioId, 400, 600)) {
    responder(429, ['ok' => false]);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$clave   = (string) ($entrada['clave'] ?? '');
if (!preg_match('/^[a-z_]{1,20}:[a-z0-9_-]{1,36}$/', $clave)) {
    responder(400, ['ok' => false]);
}
// En una página del espacio solo cuenta quien tiene acceso a ella
if (str_starts_with($clave, 'pagina:') && !cargarPagina((int) substr($clave, 7), $usuarioId)) {
    responder(404, ['ok' => false]);
}

$pdo = db();
if (!empty($entrada['salir'])) {
    $pdo->prepare('DELETE FROM presencia WHERE usuario_id = ? AND clave = ?')->execute([$usuarioId, $clave]);
    responder(200, ['ok' => true]);
}
$pdo->prepare(
    'INSERT INTO presencia (usuario_id, clave, editando, visto_en) VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE editando = VALUES(editando), visto_en = NOW()'
)->execute([$usuarioId, $clave, !empty($entrada['editando']) ? 1 : 0]);

// Limpieza ocasional de registros viejos
if (random_int(1, 50) === 1) {
    $pdo->exec('DELETE FROM presencia WHERE visto_en < NOW() - INTERVAL 1 DAY');
}

$personas = $pdo->prepare(
    'SELECT u.id, u.nombre, u.foto, p.editando
       FROM presencia p JOIN usuarios u ON u.id = p.usuario_id AND u.activo = 1 AND u.institucion_id = ?
      WHERE p.clave = ? AND p.visto_en >= NOW() - INTERVAL 40 SECOND
      ORDER BY p.editando DESC, p.visto_en DESC
      LIMIT 60'
);
$personas->execute([institucionId(), $clave]);
responder(200, ['ok' => true, 'personas' => array_map(fn ($p) => [
    'id'       => (int) $p['id'],
    'nombre'   => $p['nombre'],
    'foto'     => $p['foto'] ? URL_BASE . '/' . $p['foto'] : null,
    'editando' => (bool) $p['editando'],
    'tu'       => (int) $p['id'] === $usuarioId,
], $personas->fetchAll())]);
