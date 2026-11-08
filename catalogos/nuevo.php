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
    foreach ($campos as $campo) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
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

$tituloPagina   = 'Nuevo ' . $catalogo['titulo'];
$seccionActiva  = 'catalogos-' . $clave;
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'catálogos', 'url' => URL_BASE . '/catalogos/'], ['texto' => $catalogo['titulo'], 'url' => URL_BASE . '/catalogos/editar.php?tabla=' . urlencode($clave)], ['texto' => 'Nuevo registro']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Nuevo ' . $catalogo['titulo']) ?></h1>
  <p class="pagina__subtitulo">Registra una entrada en el catálogo de <?= e($catalogo['titulo']) ?></p>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if (!empty($errores['general'])): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--error" role="alert"><?= icono('info') ?><span><?= e($errores['general']) ?></span></div>
  </div>
<?php endif; ?>

<section class="finanzas__seccion-limpio">
  <div class="finanzas__titulo-fila-limpio">
    <h2 class="finanzas__titulo-limpio"><?= icono('documento') ?> Datos del registro</h2>
  </div>
  <form method="post" action="<?= e($_SERVER['REQUEST_URI'] . '?tabla=' . urlencode($clave)) ?>" class="nuevo-registro__form">
    <?= campoCsrf() ?>
    <input type="hidden" name="tabla" value="<?= e($clave) ?>">
    <input type="hidden" name="accion_nueva" value="1">

    <div class="nuevo-registro__campos">
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
        <label class="nuevo-registro__campo<?= $error !== '' ? ' tiene-error' : '' ?>">
          <span><?= e($texto) ?><?= $requerido ? ' *' : '' ?></span>
          <?php if ($tipo === 'casilla'): ?>
            <input type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
          <?php elseif ($tipo === 'opcion' && !empty($opciones)): ?>
            <select name="datos[<?= e($nombre) ?>]">
              <option value="">—</option>
              <?php foreach ($opciones as $claveOpcion => $textoOpcion): ?>
                <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($tipo === 'fecha'): ?>
            <input type="date" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
          <?php elseif ($tipo === 'numero'): ?>
            <input type="number" step="0.01" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
          <?php else: ?>
            <input type="text" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= !empty($def['max']) ? ' maxlength="' . (int) $def['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
          <?php endif; ?>
          <?php if ($error !== ''): ?><em class="nuevo-registro__error"><?= e($error) ?></em><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($errores['general'])): ?>
      <p class="nuevo-registro__error"><?= e((string) $errores['general']) ?></p>
    <?php endif; ?>

    <div class="nuevo-registro__acciones">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=<?= e(urlencode($clave)) ?>"><?= icono('atras') ?> Cancelar</a>
      <button type="submit" class="boton boton--primario"><?= icono('agregar') ?> Guardar registro</button>
    </div>
  </form>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>





