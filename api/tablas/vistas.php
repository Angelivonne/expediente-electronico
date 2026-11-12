<?php
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/motor/entidades.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responderVistas(int $estado, array $datos): never
{
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$usuarioId = (int) usuarioActual()['id'];
$entidad = is_string($_GET['entidad'] ?? null) ? $_GET['entidad'] : '';
if ($metodo !== 'GET') {
    $entrada = json_decode((string) file_get_contents('php://input'), true);
    $entrada = is_array($entrada) ? $entrada : [];
    if ($entidad === '' && is_string($entrada['entidad'] ?? null)) {
        $entidad = $entrada['entidad'];
    }
}
if (!entidadMotor($entidad)) {
    responderVistas(400, ['ok' => false, 'error' => 'Entidad de tabla no válida.']);
}
exigirPermiso('ver_expediente');

try {
    if ($metodo === 'GET') {
        $consulta = db()->prepare(
            'SELECT id, nombre, estado FROM vistas_tabla_usuario
              WHERE usuario_id = ? AND entidad = ? ORDER BY nombre'
        );
        $consulta->execute([$usuarioId, $entidad]);
        $vistas = [];
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $vista) {
            $vistas[] = [
                'id' => (int) $vista['id'],
                'nombre' => $vista['nombre'],
                'estado' => json_decode((string) $vista['estado'], true, 16, JSON_THROW_ON_ERROR),
            ];
        }
        responderVistas(200, ['ok' => true, 'vistas' => $vistas]);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        responderVistas(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
    }

    if ($metodo === 'POST') {
        $nombre = trim(is_string($entrada['nombre'] ?? null) ? $entrada['nombre'] : '');
        if ($nombre === '' || mb_strlen($nombre) > 80) {
            responderVistas(422, ['ok' => false, 'error' => 'El nombre debe tener entre 1 y 80 caracteres.']);
        }
        $estado = is_array($entrada['estado'] ?? null) ? $entrada['estado'] : [];
        $consultaEstado = [];
        foreach (['orden', 'dir', 'campo', 'valor', 'agrupar'] as $clave) {
            $valor = $estado['consulta'][$clave] ?? '';
            if (!is_string($valor) || mb_strlen($valor) > 120) {
                responderVistas(422, ['ok' => false, 'error' => 'Los filtros de la vista no son válidos.']);
            }
            $consultaEstado[$clave] = $valor;
        }
        $ocultas = $estado['ocultas'] ?? [];
        if (!is_array($ocultas) || count($ocultas) > 80) {
            responderVistas(422, ['ok' => false, 'error' => 'La selección de columnas no es válida.']);
        }
        foreach ($ocultas as $columna) {
            if (!is_string($columna) || !preg_match('/^[a-zA-Z0-9_]{1,60}$/', $columna)) {
                responderVistas(422, ['ok' => false, 'error' => 'La selección de columnas no es válida.']);
            }
        }
        $estadoSeguro = ['consulta' => $consultaEstado, 'ocultas' => array_values(array_unique($ocultas))];
        db()->prepare(
            'INSERT INTO vistas_tabla_usuario (usuario_id, entidad, nombre, estado)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), actualizado_en = CURRENT_TIMESTAMP'
        )->execute([
            $usuarioId, $entidad, $nombre,
            json_encode($estadoSeguro, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        responderVistas(200, ['ok' => true]);
    }

    if ($metodo === 'DELETE') {
        $id = filter_var($entrada['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            responderVistas(422, ['ok' => false, 'error' => 'Selecciona una vista válida.']);
        }
        $borrar = db()->prepare('DELETE FROM vistas_tabla_usuario WHERE id = ? AND usuario_id = ? AND entidad = ?');
        $borrar->execute([(int) $id, $usuarioId, $entidad]);
        responderVistas(200, ['ok' => true, 'eliminada' => $borrar->rowCount() === 1]);
    }

    header('Allow: GET, POST, DELETE');
    responderVistas(405, ['ok' => false, 'error' => 'Método no permitido.']);
} catch (JsonException | PDOException $e) {
    error_log('[vistas_tabla] ' . $e->getMessage());
    responderVistas(500, ['ok' => false, 'error' => 'No se pudieron cargar o guardar las vistas.']);
}
