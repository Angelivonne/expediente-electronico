<?php
/**
 * Retroalimentación privada y moderada entre integrantes de la clínica.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responderRetroalimentacion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$usuario = usuarioActual();
$usuarioId = (int) $usuario['id'];
$institucion = institucionId();
if ($institucion === null || !puede('ver_expediente')) {
    responderRetroalimentacion(403, ['ok' => false, 'error' => 'No tienes permiso para consultar la retroalimentación del equipo.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responderRetroalimentacion(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}

$entrada = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    responderRetroalimentacion(400, ['ok' => false, 'error' => 'La solicitud no es válida.']);
}
$personaId = filter_var($entrada['persona_id'] ?? null, FILTER_VALIDATE_INT);
if (!$personaId || $personaId < 1) {
    responderRetroalimentacion(422, ['ok' => false, 'error' => 'La persona seleccionada no es válida.']);
}
$pdo = db();
$persona = $pdo->prepare(
    "SELECT id, activo FROM usuarios
      WHERE id = ? AND institucion_id = ? AND rol <> 'paciente'
        AND correo NOT LIKE 'retirada+%@cuentas.invalid'"
);
$persona->execute([$personaId, $institucion]);
$destinatario = $persona->fetch();
if (!$destinatario) {
    responderRetroalimentacion(404, ['ok' => false, 'error' => 'La persona no pertenece a esta clínica.']);
}

$accion = (string) ($entrada['accion'] ?? 'listar');
$puedeModerar = puede('auditoria_equipo') && (int) $personaId !== $usuarioId;
if ($accion === 'listar') {
    if ($puedeModerar) {
        $consulta = $pdo->prepare(
            'SELECT f.id, f.autor_id, f.tipo, f.texto, f.estado, f.creado_en, a.nombre AS autor
               FROM retroalimentacion_equipo f
               JOIN usuarios a ON a.id = f.autor_id
              WHERE f.institucion_id = ? AND f.persona_id = ?
              ORDER BY FIELD(f.estado, "pendiente", "compartido", "cerrado"), f.creado_en DESC
              LIMIT 100'
        );
        $consulta->execute([$institucion, $personaId]);
    } else {
        $consulta = $pdo->prepare(
            "SELECT f.id, f.autor_id, f.tipo, f.texto, f.estado, f.creado_en, a.nombre AS autor
               FROM retroalimentacion_equipo f
               JOIN usuarios a ON a.id = f.autor_id
              WHERE f.institucion_id = ? AND f.persona_id = ?
                AND ((f.estado = 'compartido' AND f.persona_id = ?) OR f.autor_id = ?)
              ORDER BY f.creado_en DESC
              LIMIT 100"
        );
        $consulta->execute([$institucion, $personaId, $usuarioId, $usuarioId]);
    }

    $estados = ['pendiente' => 'Pendiente de revisión', 'compartido' => 'Compartido', 'cerrado' => 'Cerrado'];
    $items = array_map(static function (array $fila) use ($estados, $puedeModerar, $usuarioId): array {
        return [
            'id' => (int) $fila['id'],
            'tipo' => $fila['tipo'] === 'positivo' ? 'Reconocimiento' : 'Área de atención',
            'clase' => $fila['tipo'],
            'texto' => (string) $fila['texto'],
            'autor' => (string) $fila['autor'],
            'estado' => $estados[$fila['estado']] ?? 'Pendiente de revisión',
            'estado_clave' => (string) $fila['estado'],
            'fecha' => trim(fechaCorta(substr((string) $fila['creado_en'], 0, 10)) . ' ' . hora12((string) $fila['creado_en'])),
            'puede_moderar' => $puedeModerar && (int) $fila['autor_id'] !== $usuarioId,
        ];
    }, $consulta->fetchAll());
    responderRetroalimentacion(200, ['ok' => true, 'puede_moderar' => $puedeModerar, 'items' => $items]);
}

if ($accion === 'crear') {
    if ((int) $destinatario['id'] === $usuarioId) {
        responderRetroalimentacion(422, ['ok' => false, 'error' => 'No puedes enviarte retroalimentación a tu propio perfil.']);
    }
    if (!(bool) $destinatario['activo']) {
        responderRetroalimentacion(409, ['ok' => false, 'error' => 'No se puede enviar retroalimentación a una cuenta inactiva.']);
    }
    if (!dentroDelLimite('retroalimentacion_equipo:usuario:' . $usuarioId, 20, 600)) {
        responderRetroalimentacion(429, ['ok' => false, 'error' => 'Espera un momento antes de enviar más comentarios.']);
    }
    $tipo = (string) ($entrada['tipo'] ?? '');
    $texto = trim((string) ($entrada['texto'] ?? ''));
    if (!in_array($tipo, ['positivo', 'atencion'], true)) {
        responderRetroalimentacion(422, ['ok' => false, 'error' => 'El tipo de retroalimentación no es válido.']);
    }
    if ($texto === '' || mb_strlen($texto, 'UTF-8') > 1000) {
        responderRetroalimentacion(422, ['ok' => false, 'error' => 'Escribe un comentario de hasta 1000 caracteres.']);
    }

    $pdo->prepare(
        'INSERT INTO retroalimentacion_equipo (institucion_id, autor_id, persona_id, tipo, texto)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$institucion, $usuarioId, $personaId, $tipo, $texto]);
    registrarBitacora('usuarios', (int) $personaId, 'crear', 'retroalimentacion');
    responderRetroalimentacion(201, ['ok' => true]);
}

if (in_array($accion, ['compartir', 'cerrar'], true)) {
    if (!$puedeModerar) {
        responderRetroalimentacion(403, ['ok' => false, 'error' => 'Tu rol no puede revisar retroalimentación del equipo.']);
    }
    if (!dentroDelLimite('retroalimentacion_revision:usuario:' . $usuarioId, 120, 600)) {
        responderRetroalimentacion(429, ['ok' => false, 'error' => 'Demasiadas acciones de revisión.']);
    }
    $id = filter_var($entrada['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        responderRetroalimentacion(422, ['ok' => false, 'error' => 'El comentario seleccionado no es válido.']);
    }
    $estado = $accion === 'compartir' ? 'compartido' : 'cerrado';
    $compartido = $accion === 'compartir' ? 'NOW()' : 'compartido_en';
    $actualizar = $pdo->prepare(
        "UPDATE retroalimentacion_equipo
            SET estado = ?, revisado_por = ?, revisado_en = NOW(), compartido_en = {$compartido}
          WHERE id = ? AND institucion_id = ? AND persona_id = ? AND autor_id <> ? AND estado = 'pendiente'"
    );
    $actualizar->execute([$estado, $usuarioId, $id, $institucion, $personaId, $usuarioId]);
    if ($actualizar->rowCount() !== 1) {
        responderRetroalimentacion(409, ['ok' => false, 'error' => 'El comentario ya se revisó o no está disponible. Actualiza la lista.']);
    }
    registrarBitacora('usuarios', (int) $personaId, 'actualizar', 'retroalimentacion', 'pendiente', $estado);
    responderRetroalimentacion(200, ['ok' => true]);
}

responderRetroalimentacion(400, ['ok' => false, 'error' => 'La acción solicitada no es válida.']);
