<?php
/**
 * Presencia: cada pestaña actualiza periódicamente la sección/página y si está editando.
 * El cursor opcional se comparte temporalmente solo con personas de la misma página.
 * POST JSON {clave, editando, cursor?} → presencia; GET ?equipo=1 → conexión reciente del equipo.
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
    if (!puede('ver_expediente')) {
        responder(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para ver la presencia del equipo.']);
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
            AND u.correo NOT LIKE 'retirada+%@cuentas.invalid'
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
if (!dentroDelLimite('presencia:usuario:' . $usuarioId, 1500, 600)) {
    responder(429, ['ok' => false]);
}

$entrada = json_decode(file_get_contents('php://input'), true) ?: [];
$clave   = (string) ($entrada['clave'] ?? '');
if (!preg_match('/^[a-z_]{1,20}:[a-z0-9_-]{1,36}$/', $clave)) {
    responder(400, ['ok' => false]);
}
$actualizaCursor = array_key_exists('cursor', $entrada);
$puedeCompartirCursor = puede('ver_expediente');
$cursorX = null;
$cursorY = null;
if ($actualizaCursor && $entrada['cursor'] !== null) {
    if (!$puedeCompartirCursor) {
        responder(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para compartir cursores del equipo.']);
    }
    $cursor = $entrada['cursor'];
    if (!is_array($cursor)
        || !is_numeric($cursor['x'] ?? null) || !is_numeric($cursor['y'] ?? null)
        || !is_finite((float) $cursor['x']) || !is_finite((float) $cursor['y'])
        || (float) $cursor['x'] < 0 || (float) $cursor['x'] > 1
        || (float) $cursor['y'] < 0 || (float) $cursor['y'] > 1) {
        responder(400, ['ok' => false, 'error' => 'La posición del cursor no es válida.']);
    }
    $cursorX = (float) $cursor['x'];
    $cursorY = (float) $cursor['y'];
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
if ($actualizaCursor) {
    $pdo->prepare(
        'INSERT INTO presencia (usuario_id, clave, editando, visto_en, cursor_x, cursor_y, cursor_en)
         VALUES (?, ?, ?, NOW(), ?, ?, IF(? IS NULL, NULL, NOW()))
         ON DUPLICATE KEY UPDATE editando = VALUES(editando), visto_en = NOW(),
             cursor_x = VALUES(cursor_x), cursor_y = VALUES(cursor_y), cursor_en = VALUES(cursor_en)'
    )->execute([$usuarioId, $clave, !empty($entrada['editando']) ? 1 : 0, $cursorX, $cursorY, $cursorX]);
} else {
    $pdo->prepare(
        'INSERT INTO presencia (usuario_id, clave, editando, visto_en) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE editando = VALUES(editando), visto_en = NOW()'
    )->execute([$usuarioId, $clave, !empty($entrada['editando']) ? 1 : 0]);
}
$pdo->prepare(
    'UPDATE presencia SET cursor_x = NULL, cursor_y = NULL, cursor_en = NULL
      WHERE clave = ? AND cursor_en < NOW() - INTERVAL 12 SECOND'
)->execute([$clave]);

// Limpieza ocasional de registros viejos
if (random_int(1, 50) === 1) {
    $pdo->exec('DELETE FROM presencia WHERE visto_en < NOW() - INTERVAL 1 DAY');
}

$personas = $pdo->prepare(
    'SELECT u.id, u.nombre, u.foto, p.editando,
            CASE WHEN p.cursor_en >= NOW() - INTERVAL 12 SECOND THEN p.cursor_x END AS cursor_x,
            CASE WHEN p.cursor_en >= NOW() - INTERVAL 12 SECOND THEN p.cursor_y END AS cursor_y
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
    'cursor'   => !$puedeCompartirCursor || $p['cursor_x'] === null || $p['cursor_y'] === null
        ? null
        : ['x' => (float) $p['cursor_x'], 'y' => (float) $p['cursor_y']],
], $personas->fetchAll())]);
