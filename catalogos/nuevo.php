<?php
/**
 * Registro de nuevo valor en un catálogo — página separada.
 *
 * Abre un formulario completo para registrar una entrada en cualquier catálogo del sistema.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

exigirPermiso('ver_expediente');

$clave = (string) ($_GET['tabla'] ?? '');
$catalogo = catalogoSistema($clave);
if (!$catalogo || empty($catalogo['tabla'])) {
    mensajeFlash('error', 'Ese catálogo no existe.');
    redirigir('/catalogos/');
}

$entidad = entidadMotor($clave);
$camposDef = $entidad['campos'] ?? [];
$campos = array_filter(array_keys($camposDef), fn ($c) => !empty($camposDef[$c]['solo_lectura']) === false && $c !== 'id');

$datos   = array_fill_keys($campos, '');
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entradaPost = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : $_POST;
    foreach ($campos as $campo) {
        $datos[$campo] = trim((string) ($entradaPost[$campo] ?? ''));
    }

    // Validación básica: campos obligatorios
    foreach ($camposDef as $nombre => $definicion) {
        if (!empty($definicion['req']) && ($datos[$nombre] ?? '') === '') {
            $errores[$nombre] = 'Campo obligatorio.';
        }
    }

    if (empty($errores) && !csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar el formulario.';
    }

    if (empty($errores)) {
        $entrada = [];
        foreach ($campos as $campo) {
            $v = $datos[$campo];
            if ($v !== '') {
                $entrada[$campo] = $v;
            }
        }
        // Normalizar tipos
        foreach ($entrada as $campo => $valor) {
            $tipo = $camposDef[$campo]['tipo'] ?? 'texto';
            if ($tipo === 'numero') {
                $entrada[$campo] = (float) $valor;
            }
        }
        $alta = motorCrear($clave, $entrada);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Registro agregado al catálogo.');
            redirigir('/catalogos/editar_tabla.php?tabla=' . urlencode($clave));
        }
        $errores = array_merge($errores, $alta['errores'] ?? []);
    }
}

$tituloPagina   = 'Nuevo · ' . $catalogo['titulo'];
$seccionActiva  = 'catalogos-' . $clave;
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Catálogos', 'url' => URL_BASE . '/catalogos/'], ['texto' => $catalogo['titulo'], 'url' => URL_BASE . '/catalogos/editar_tabla.php?tabla=' . urlencode($clave)], ['texto' => 'Nuevo registro']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <span class="pagina__meta"><?= iconoRelleno('tabla') ?> Catálogo del sistema</span>
  <h1 class="pagina__titulo">Nuevo registro: <?= e($catalogo['titulo']) ?></h1>
  <p class="pagina__subtitulo">Registra una nueva entrada en el catálogo maestro.</p>
</div>

<?php if (!empty($errores['general'])): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--error" role="alert"><?= icono('info') ?><span><?= e($errores['general']) ?></span></div>
  </div>
<?php endif; ?>

<section class="bd" style="max-width:800px;margin-bottom:32px;">
  <div class="bd__cabecera">
    <h2 class="bd__titulo"><?= icono('documento') ?> Datos del registro</h2>
    <div class="bd__herramientas">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=<?= e(urlencode($clave)) ?>"><?= icono('atras') ?> Volver al catálogo</a>
    </div>
  </div>

  <form method="post" action="" style="padding:24px 20px;">
    <?= campoCsrf() ?>
    <input type="hidden" name="tabla" value="<?= e($clave) ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:20px;">
      <?php foreach ($campos as $nombre): ?>
        <?php
          $def = $camposDef[$nombre] ?? [];
          $texto = (string) ($def['texto'] ?? $nombre);
          $tipo = (string) ($def['tipo'] ?? 'texto');
          $valor = (string) ($datos[$nombre] ?? '');
          $requerido = !empty($def['req']);
          $error = (string) ($errores[$nombre] ?? '');
          $opciones = $def['opciones'] ?? [];
        ?>
        <label class="formulario__campo<?= $error !== '' ? ' tiene-error' : '' ?>" style="display:flex;flex-direction:column;gap:6px;">
          <span style="font-weight:550;font-size:var(--fs-sm);color:var(--texto-fuerte);"><?= e($texto) ?><?= $requerido ? ' *' : '' ?></span>
          <?php if ($tipo === 'casilla'): ?>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
              <span>Activo / Sí</span>
            </label>
          <?php elseif ($tipo === 'opcion' && !empty($opciones)): ?>
            <select class="control" name="datos[<?= e($nombre) ?>]">
              <option value="">—</option>
              <?php foreach ($opciones as $claveOpcion => $textoOpcion): ?>
                <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($tipo === 'fecha'): ?>
            <input class="control" type="date" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
          <?php elseif ($tipo === 'numero'): ?>
            <input class="control" type="number" step="0.01" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
          <?php else: ?>
            <input class="control" type="text" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= !empty($def['max']) ? ' maxlength="' . (int) $def['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
          <?php endif; ?>
          <?php if ($error !== ''): ?><em class="error-campo" style="color:var(--color-peligro, #e53935);font-size:var(--fs-xs);"><?= e($error) ?></em><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:24px;padding-top:16px;border-top:1px solid var(--borde-suave, #e0e0e0);">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=<?= e(urlencode($clave)) ?>"><?= icono('atras') ?> Cancelar</a>
      <button type="submit" class="boton boton--primario"><?= icono('agregar') ?> Guardar registro</button>
    </div>
  </form>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
