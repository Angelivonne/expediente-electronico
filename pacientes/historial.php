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

if ($errores) {
    if ($completo) {
        // Se conserva lo escrito para devolverlo al formulario (sin el token)
        $limpiar = function ($valor) use (&$limpiar) {
            return is_array($valor) ? array_map($limpiar, array_slice($valor, 0, 40, true)) : mb_substr((string) $valor, 0, 5000);
        };
        $_SESSION['borrador_registro'] = [
            'paciente' => $id,
            'tipo'     => $tipo,
            'datos'    => $limpiar(array_diff_key($_POST, ['csrf' => 1, 'cuerpo' => 1, 'nota_bloques' => 1]))
                + (is_string($_POST['cuerpo'] ?? null) ? ['cuerpo' => mb_substr($_POST['cuerpo'], 0, 60000)] : [])
                + (is_array($_POST['nota_bloques'] ?? null) ? ['nota_bloques' => array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 60000) : '', array_slice($_POST['nota_bloques'], 0, 40, true))] : []),
            'errores'  => $errores,
        ];
    }
    mensajeFlash('error', count($errores) > 1 ? reset($errores) . ' (y ' . (count($errores) - 1) . ' campo' . (count($errores) > 2 ? 's' : '') . ' más por revisar)' : reset($errores));
    redirigir($volver);
}

/* ---------- Guardado ---------- */
// Cuerpo libre escrito con el motor de bloques de la nota (solo texto, ya validado).
require_once __DIR__ . '/../app/vistas/componentes/bloques_nota.php';
$cuerpo = sanearBloquesNota($_POST['cuerpo'] ?? []);
$cuerpoEscrito = array_filter($cuerpo, fn ($b) => !in_array($b['t'], ['h1', 'h2', 'h3', 'separador'], true));
if ($cuerpoEscrito) {
    $detalle['cuerpo'] = $cuerpo;
}

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
$pdo->commit();

// Aviso por umbral "signo vital fuera de rango" (push.php; solo encola, nunca interrumpe el registro).
// Va fuera de la transacción: si falla, no deshace el guardado de la entrada.
require_once __DIR__ . '/../app/servicios/cuenta/push.php';
evaluarUmbralesPush('signos', [$nuevoId]);
unset($_SESSION['borrador_registro']);

mensajeFlash('exito', 'Se agregó al historial: ' . nombreTipoRegistro($tipo, 'documento') . '.');
$destino = $completo ? '/pacientes/evento.php?id=' . $nuevoId : '/pacientes/ver.php?id=' . $id . '#historial';
recordarEnvio($destino);
redirigir($destino);
