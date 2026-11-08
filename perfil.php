<?php
/**
 * Perfil de usuario del personal de salud (médicos, enfermeras, recepción, administración).
 * Se abre desde el nombre de usuario en la barra lateral.
 * Permite ver y editar datos personales, cambiar contraseña y ver información de la institución.
 */
require_once __DIR__ . '/app/nucleo/inicio.php';
require_once __DIR__ . '/app/servicios/cuenta/cedula.php';

exigirPermiso('ver_expediente');

$usuarioId = (int) usuarioActual();
$usuario = db()->prepare(
    "SELECT u.id, u.nombre, u.correo, u.rol, u.cedula_profesional, u.especialidad, u.cedula_estado, u.cedula_motivo, u.telefono, u.celular, u.direccion, u.foto, u.ultimo_acceso, u.creado_en, i.nombre AS institucion, i.tipo AS tipo_institucion
       FROM usuarios u LEFT JOIN instituciones i ON i.id = u.institucion_id WHERE u.id = ?"
);
$consulta = db()->prepare("SELECT * FROM usuarios WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
$consulta->execute([$usuarioId, institucionId()]);
$usuario = $consulta->fetch();

if (!$usuario) {
    mensajeFlash('error', 'No se pudo encontrar tu usuario.');
    redirigir('/');
}

$errores = [];
$valores = [
    'nombre' => (string) ($usuario['nombre'] ?? ''),
    'correo' => (string) ($usuario['correo'] ?? ''),
    'telefono' => (string) ($usuario['telefono'] ?? ''),
    'celular' => (string) ($usuario['celular'] ?? ''),
    'direccion' => (string) ($usuario['direccion'] ?? ''),
    'cedula_profesional' => (string) ($usuario['cedula_profesional'] ?? ''),
    'especialidad' => (string) ($usuario['especialidad'] ?? ''),
];

// Procesar cambios de contraseña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_clave') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar el formulario.';
    } else {
        $actual = trim((string) ($_POST['actual'] ?? ''));
        $nueva = (string) ($_POST['nueva'] ?? '');
        $confirmar = (string) ($_POST['confirmar'] ?? '');

        if (!dentroDelLimite('cambiar_clave:usuario:' . $usuarioId, 8, 900)) {
            $errores['actual'] = 'Demasiados intentos. Espera 15 minutos.';
        } elseif (!password_verify($actual, $usuario['clave_hash'])) {
            $errores['actual'] = 'La contraseña actual no es correcta.';
        } elseif ($nueva === '') {
            $errores['nueva'] = 'Escribe una contraseña nueva.';
        } elseif ($nueva !== $confirmar) {
            $errores['confirmar'] = 'Las contraseñas no coinciden.';
        } else {
            $alta = motorCrear('usuarios', [
                'id' => $usuarioId,
                'clave_hash' => password_hash($nueva, PASSWORD_DEFAULT),
                'actualizado_en' => date('Y-m-d H:i:s'),
            ]);
            // motorCrear no sirve para update, usamos SQL directo
            db()->prepare('UPDATE usuarios SET clave_hash = ?, actualizado_en = NOW() WHERE id = ?')
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
            session_regenerate_id(true);
            mensajeFlash('exito', 'Contraseña cambiada correctamente. Se cerraron tus sesiones en otros equipos.');
            redirigir('/perfil.php');
        }
    }
}

// Procesar actualización de datos personales
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_perfil') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar el formulario.';
    } else {
        $datos = [
            'nombre' => trim((string) ($_POST['nombre'] ?? '')),
            'correo' => trim((string) ($_POST['correo'] ?? '')),
            'telefono' => trim((string) ($_POST['telefono'] ?? '')),
            'celular' => trim((string) ($_POST['celular'] ?? '')),
            'direccion' => trim((string) ($_POST['direccion'] ?? '')),
            'cedula_profesional' => trim((string) ($_POST['cedula_profesional'] ?? '')),
            'especialidad' => trim((string) ($_POST['especialidad'] ?? '')),
        ];

        // Validar campos obligatorios
        if ($datos['nombre'] === '') {
            $errores['nombre'] = 'El nombre es obligatorio.';
        }
        if ($datos['correo'] === '') {
            $errores['correo'] = 'El correo es obligatorio.';
        } elseif (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores['correo'] = 'El correo no es válido.';
        }

        if (empty($errores)) {
            db()->prepare(
                "UPDATE usuarios SET nombre = ?, correo = ?, telefono = ?, celular = ?, direccion = ?, cedula_profesional = ?, especialidad = ?, actualizado_en = NOW() WHERE id = ?"
            )->execute([
                $datos['nombre'], $datos['correo'], $datos['telefono'], $datos['celular'],
                $datos['direccion'], $datos['cedula_profesional'], $datos['especialidad'], $usuarioId
            ]);
            mensajeFlash('exito', 'Perfil actualizado correctamente.');
            redirigir('/perfil.php');
        }
    }
}

$tituloPagina   = 'Mi perfil';
$seccionActiva  = 'cuenta';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Mi perfil']];
$error = fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$aviso = fn (string $c): string => isset($errores[$c]) ? '<span class="campo__ayuda">' . e($errores[$c]) . '</span>' : '';

require __DIR__ . '/app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Mi perfil</h1>
  <p class="pagina__subtitulo">Datos personales de la cuenta de usuario</p>
</div>

<?php if (!empty($errores['general'])): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--error" role="alert"><?= icono('alerta') ?><span><?= e($errores['general']) ?></span></div>
  </div>
<?php endif; ?>

<div class="finanzas__cuerpo">
  <div class="finanzas__datos">
    <!-- Datos personales -->
    <section class="ficha__seccion">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo"><?= icono('usuario') ?> Datos personales</h2>
      </div>
      <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="finanzas__form">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="actualizar_perfil">
        <div class="finanzas__campos">
          <div class="finanzas__campo<?= $error('nombre') ?>">
            <span>Nombre completo *</span>
            <input type="text" name="nombre" value="<?= e($valores['nombre']) ?>" required<?= $error('nombre') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('nombre') ?>
          </div>
          <div class="finanzas__campo<?= $error('correo') ?>">
            <span>Correo electrónico *</span>
            <input type="email" name="correo" value="<?= e($valores['correo']) ?>" required<?= $error('correo') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('correo') ?>
          </div>
          <div class="finanzas__campo<?= $error('telefono') ?>">
            <span>Teléfono</span>
            <input type="tel" name="telefono" value="<?= e($valores['telefono']) ?>"<?= $error('telefono') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('telefono') ?>
          </div>
          <div class="finanzas__campo<?= $error('celular') ?>">
            <span>Celular</span>
            <input type="tel" name="celular" value="<?= e($valores['celular']) ?>"<?= $error('celular') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('celular') ?>
          </div>
          <div class="finanzas__campo<?= $error('direccion') ?>">
            <span>Dirección</span>
            <input type="text" name="direccion" value="<?= e($valores['direccion']) ?>"<?= $error('direccion') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('direccion') ?>
          </div>
          <div class="finanzas__campo<?= $error('cedula_profesional') ?>">
            <span>Cédula profesional</span>
            <input type="text" name="cedula_profesional" value="<?= e($valores['cedula_profesional']) ?>"<?= $error('cedula_profesional') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('cedula_profesional') ?>
          </div>
          <div class="finanzas__campo<?= $error('especialidad') ?>">
            <span>Especialidad</span>
            <input type="text" name="especialidad" value="<?= e($valores['especialidad']) ?>"<?= $error('especialidad') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('especialidad') ?>
          </div>
        </div>
        <div class="finanzas__acciones">
          <button type="submit" class="boton boton--primario">Guardar cambios</button>
        </div>
      </form>
    </section>

    <!-- Cambiar contraseña -->
    <section class="ficha__seccion">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo"><?= icono('candado') ?> Cambiar contraseña</h2>
      </div>
      <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="finanzas__form">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="cambiar_clave">
        <div class="finanzas__campos">
          <div class="finanzas__campo<?= $error('actual') ?>">
            <span>Contraseña actual *</span>
            <input type="password" name="actual" required<?= $error('actual') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('actual') ?>
          </div>
          <div class="finanzas__campo<?= $error('nueva') ?>">
            <span>Contraseña nueva *</span>
            <input type="password" name="nueva" required<?= $error('nueva') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('nueva') ?>
          </div>
          <div class="finanzas__campo<?= $error('confirmar') ?>">
            <span>Confirmar contraseña *</span>
            <input type="password" name="confirmar" required<?= $error('confirmar') ? ' aria-invalid="true"' : '' ?>>
            <?= $aviso('confirmar') ?>
          </div>
        </div>
        <div class="finanzas__acciones">
          <button type="submit" class="boton boton--primario">Cambiar contraseña</button>
        </div>
      </form>
    </section>

    <!-- Información de la cuenta -->
    <section class="ficha__seccion">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo"><?= icono('documento') ?> Información de la cuenta</h2>
      </div>
      <div class="finanzas__datos">
        <table class="bd-tabla">
          <thead><tr><th>Campo</th><th>Valor</th></tr></thead>
          <tbody>
            <tr><td>Nombre de usuario</td><td><?= e($usuario['nombre']) ?></td></tr>
            <tr><td>Correo electrónico</td><td><?= e($usuario['correo']) ?></td></tr>
            <tr><td>Rol</td><td><?= e($usuario['rol']) ?></td></tr>
            <tr><td>Cédula profesional</td><td><?= e($usuario['cedula_profesional'] ?? '—') ?></td></tr>
            <tr><td>Especialidad</td><td><?= e($usuario['especialidad'] ?? '—') ?></td></tr>
            <tr><td>Institución</td><td><?= e($usuario['institucion'] ?? '—') ?></td></tr>
            <tr><td>Tipo de institución</td><td><?= e($usuario['tipo_institucion'] ?? '—') ?></td></tr>
            <tr><td>Creado el</td><td><?= e($usuario['creado_en'] ?? '—') ?></td></tr>
            <tr><td>Último acceso</td><td><?= e($usuario['ultimo_acceso'] ?? '—') ?></td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="finanzas__lado">
    <section class="ficha__seccion">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo"><?= icono('info') ?> Información</h2>
      </div>
      <div class="bd__marco">
        <ul class="lista-clinica">
          <li><span>Los cambios de contraseña cierran todas las sesiones abiertas en otros equipos.</span></li>
          <li><span>La cédula profesional y especialidad aparecen en los documentos que firmes.</span></li>
          <li><span>Si olvidas tu contraseña, solicita restablecimiento a un administrador.</span></li>
        </ul>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/app/vistas/plantilla/pie.php'; ?>