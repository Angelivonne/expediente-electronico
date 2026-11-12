<?php
/**
 * Agrega una entrada al historial clínico del paciente: notas médicas de la NOM-004 (consulta/evolución,
 * urgencias, interconsulta, referencia/traslado, egreso), receta, hospitalización, resultado de estudio
 * y carta de consentimiento informado.
 *
 * Recibe dos formularios:
 *   - el formulario corto del perfil (pacientes/ver.php): vuelve al historial y muestra el primer error;
 *   - el formulario completo (pacientes/nuevo_registro.php, origen=completo): ante un error regresa al
 *     formulario con lo escrito y el mensaje de cada campo; al guardar abre el documento (evento.php).
 *
 * Las entradas son inmutables (NOM-004 5.10): no existe ruta para editarlas ni borrarlas.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/registros.php';
require_once __DIR__ . '/../app/servicios/clinica/firmas_clinicas.php';

$id       = (int) ($_POST['paciente_id'] ?? 0);
$tipo     = is_string($_POST['tipo'] ?? null) ? $_POST['tipo'] : '';
$completo = ($_POST['origen'] ?? '') === 'completo';
$volver   = $completo
    ? '/pacientes/nuevo_registro.php?paciente=' . $id . (isset(TIPOS_REGISTRO[$tipo]) ? '&tipo=' . $tipo : '')
    : '/pacientes/ver.php?id=' . $id . '#historial';

if (!puede('historial')) {
    mensajeFlash('error', 'Tu rol no permite agregar entradas al historial clínico.');
    redirigir('/pacientes/ver.php?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValido()) {
    mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    redirigir($volver);
}
// El mismo formulario enviado dos veces (doble clic, reenvío tras un corte de red): no se duplica la entrada
if ($yaGuardado = envioYaProcesado()) {
    redirigir($yaGuardado);
}
$paciente = db()->prepare('SELECT id, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
$paciente->execute([$id, institucionId()]);
$paciente = $paciente->fetch();
if (!$paciente) {
    mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
    redirigir('/pacientes/');
}

/* ---------- Lectura y validación (app/servicios/clinica/registros.php) ---------- */
[
    'titulo' => $titulo, 'detalle' => $detalle, 'momento' => $momento, 'errores' => $errores,
] = validarRegistroClinico($tipo, $_POST, $paciente, $completo, (int) usuarioActual()['id']);

$textoTranscripcion = is_string($_POST['transcripcion_original'] ?? null) ? trim($_POST['transcripcion_original']) : '';
$motorTranscripcion = is_string($_POST['transcriptor_motor'] ?? null) ? $_POST['transcriptor_motor'] : '';
$confirmacionTranscripcion = is_string($_POST['confirmar_transcripcion'] ?? null) && $_POST['confirmar_transcripcion'] === '1';
$dictadoToken = is_string($_POST['dictado_contexto'] ?? null) ? $_POST['dictado_contexto'] : '';
$contextoSesion = $_SESSION['dictado_contextos'][$dictadoToken] ?? null;
$dictadoPendiente = $_SESSION['dictado_clinico_pendiente'][$dictadoToken] ?? null;
$dictadoVerificado = false;
if (!preg_match('/^[a-f0-9]{48}$/', $dictadoToken)
    || !is_array($contextoSesion)
    || (int) ($contextoSesion['creado_en'] ?? 0) < time() - 86400
    || (int) ($contextoSesion['paciente_id'] ?? 0) !== $id
    || ($contextoSesion['tipo'] ?? '') !== $tipo) {
    $dictadoPendiente = null;
} elseif (is_array($dictadoPendiente) && (int) ($dictadoPendiente['actualizado_en'] ?? 0) >= time() - 86400
    && (int) ($dictadoPendiente['paciente_id'] ?? 0) === $id && ($dictadoPendiente['tipo'] ?? '') === $tipo
    && ($dictadoPendiente['token'] ?? '') === $dictadoToken) {
    $dictadoVerificado = true;
    if ($textoTranscripcion === '' || !is_string($dictadoPendiente['texto_original'] ?? null)
        || !hash_equals($dictadoPendiente['texto_original'], $textoTranscripcion)) {
        $errores[] = 'La transcripción cambió o no llegó completa. Vuelve a dictar y revisar el texto antes de guardar.';
    }
    $motorTranscripcion = (string) $dictadoPendiente['motor'];
}
if ($textoTranscripcion !== '' && !$dictadoVerificado) {
    $errores[] = 'La sesión ya no conserva la fuente original del dictado. Vuelve a dictar la información antes de guardar.';
}
if (mb_strlen($textoTranscripcion) > 60000) {
    $errores[] = 'La transcripción supera el tamaño permitido; vuelve a dictar una parte más corta.';
} elseif ($textoTranscripcion !== '' && !$confirmacionTranscripcion) {
    $errores[] = 'Revisa la transcripción y confirma antes de guardar la nota.';
}
if ($textoTranscripcion !== '' && !in_array($motorTranscripcion, ['web_speech', 'stt_local'], true)) {
    $errores[] = 'No se pudo identificar el motor de transcripción. Vuelve a dictar la nota.';
}

if ($errores) {
    if ($completo) {
        // Se conserva lo escrito para devolverlo al formulario (sin el token)
        $limpiar = function ($valor) use (&$limpiar) {
            return is_array($valor) ? array_map($limpiar, array_slice($valor, 0, 40, true)) : mb_substr((string) $valor, 0, 5000);
        };
        $datosBorrador = $limpiar(array_diff_key($_POST, ['csrf' => 1, 'nota_bloques' => 1]));
        $datosBorrador['transcripcion_original'] = mb_substr($textoTranscripcion, 0, 60000);
        $datosBorrador['transcriptor_motor'] = in_array($motorTranscripcion, ['web_speech', 'stt_local'], true) ? $motorTranscripcion : '';
        $datosBorrador['confirmar_transcripcion'] = '';
        $_SESSION['borrador_registro'] = [
            'paciente' => $id,
            'tipo'     => $tipo,
            'datos'    => $datosBorrador
                + (is_array($_POST['nota_bloques'] ?? null) ? ['nota_bloques' => array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 60000) : '', array_slice($_POST['nota_bloques'], 0, 40, true))] : []),
            'errores'  => $errores,
        ];
    }
    mensajeFlash('error', count($errores) > 1 ? reset($errores) . ' (y ' . (count($errores) - 1) . ' campo' . (count($errores) > 2 ? 's' : '') . ' más por revisar)' : reset($errores));
    redirigir($volver);
}

/* ---------- Guardado ---------- */
require_once __DIR__ . '/../app/vistas/componentes/bloques_nota.php';

// Bloques del panel de nota en cada campo de texto: viajan con la entrada y conservan el formato.
foreach ((array) ($_POST['nota_bloques'] ?? []) as $cbCampo => $cbValor) {
    if (!is_string($cbCampo) || !preg_match('/^[a-z0-9_]{1,40}$/', $cbCampo) || !is_string($detalle[$cbCampo] ?? null)) {
        continue;
    }
    $cbLimpios = sanearBloquesNota($cbValor);
    if ($cbLimpios) {
        $detalle['_bloques'][$cbCampo] = json_encode($cbLimpios, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}

// Quien elabora la entrada (NOM-004 5.10: nombre completo y, en su caso, cédula)
$autor = db()->prepare('SELECT id, nombre, cedula_profesional FROM usuarios WHERE id = ?');
$autor->execute([usuarioActual()['id']]);
$autor = $autor->fetch();
$cedulaAutor = $autor['cedula_profesional'] ?? ($detalle['prescriptor']['cedula'] ?? null);
$consultaInstitucion = db()->prepare('SELECT nombre, domicilio, entidad, clues FROM instituciones WHERE id = ?');
$consultaInstitucion->execute([institucionId()]);
$institucionRegistro = $consultaInstitucion->fetch();
if ($institucionRegistro) {
    // La entrada es inmutable: conservar los datos del establecimiento que la emitió.
    $detalle['_institucion'] = [
        'nombre' => $institucionRegistro['nombre'],
        'domicilio' => $institucionRegistro['domicilio'],
        'entidad' => $institucionRegistro['entidad'],
        'clues' => $institucionRegistro['clues'],
    ];
}
if ($textoTranscripcion !== '') {
    $detalle['_transcripcion'] = [
        'version' => 1,
        'motor' => $motorTranscripcion,
        'texto_original' => $textoTranscripcion,
        'huella_texto_sha256' => hash('sha256', $textoTranscripcion),
        'huella_detalle_sha256' => huellaDatosTranscripcion($detalle),
        'confirmado_por' => (int) $autor['id'],
        'confirmado_nombre' => (string) $autor['nombre'],
        'confirmado_en_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare(
    'INSERT INTO historial_clinico (institucion_id, paciente_id, tipo, fecha, titulo, detalle, autor_id, autor_nombre, autor_cedula)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    institucionId(), $id, $tipo, $momento->format('Y-m-d H:i:s'), mb_substr($titulo, 0, 200),
    json_encode($detalle, JSON_UNESCAPED_UNICODE), $autor['id'], $autor['nombre'], $cedulaAutor,
]);
$nuevoId = (int) $pdo->lastInsertId();
registrarBitacora('pacientes', $id, 'crear', 'historial_' . $tipo, null, mb_substr($titulo, 0, 200));
if ($textoTranscripcion !== '') {
    registrarBitacora('historial_clinico', $nuevoId, 'crear', 'confirmacion_transcripcion', null, 'Transcripción revisada y confirmada por el autor de la nota');
}
$pdo->commit();
unset($_SESSION['dictado_clinico_pendiente'][$dictadoToken], $_SESSION['dictado_contextos'][$dictadoToken]);

// Aviso por umbral "signo vital fuera de rango" (push.php; solo encola, nunca interrumpe el registro).
// Va fuera de la transacción: si falla, no deshace el guardado de la entrada.
require_once __DIR__ . '/../app/servicios/cuenta/push.php';
evaluarUmbralesPush('signos', [$nuevoId]);
unset($_SESSION['borrador_registro']);

mensajeFlash('exito', 'Se agregó al historial: ' . nombreTipoRegistro($tipo, 'documento') . '.');
$destino = $completo ? '/pacientes/evento.php?id=' . $nuevoId : '/pacientes/ver.php?id=' . $id . '#historial';
recordarEnvio($destino);
redirigir($destino);
