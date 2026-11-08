<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$entidad = entidadMotor('finanzas_presupuestos');
$camposDef = $entidad['campos'] ?? [];
$campos = array_filter(array_keys($camposDef), fn ($c) => !empty($camposDef[$c]['solo_lectura']) === false && $c !== 'id');

$datos   = array_fill_keys($campos, '');
$errores = [];

// Valores por defecto
$datos['anio'] = (string) date('Y');
$datos['mes']  = (string) date('n');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datosEnviados = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [];
    foreach ($campos as $campo) {
        $valor = $datosEnviados[$campo] ?? '';
        $datos[$campo] = is_scalar($valor) ? trim((string) $valor) : '';
    }

    if ($datos['tipo'] === '') {
        $errores['tipo'] = 'Selecciona el tipo.';
    }
    if ($datos['anio'] === '' || !is_numeric($datos['anio'])) {
        $errores['anio'] = 'Escribe un año válido.';
    }
    if ($datos['mes'] === '' || !is_numeric($datos['mes'])) {
        $errores['mes'] = 'Selecciona el mes.';
    }
    if ($datos['monto'] === '' || !is_numeric($datos['monto'])) {
        $errores['monto'] = 'Escribe un monto válido.';
    }

    if (empty($errores) && !csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar.';
    }

    if (empty($errores)) {
        $entrada = [];
        foreach ($campos as $campo) {
            $v = $datos[$campo];
            if ($v !== '') {
                $entrada[$campo] = $v;
            }
        }
        if (isset($entrada['monto'])) {
            $entrada['monto'] = (float) $entrada['monto'];
        }
        if (isset($entrada['anio'])) {
            $entrada['anio'] = (int) $entrada['anio'];
        }
        if (isset($entrada['mes'])) {
            $entrada['mes'] = (int) $entrada['mes'];
        }
        $alta = motorCrear('finanzas_presupuestos', $entrada);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Presupuesto registrado.');
            redirigir('/finanzas/presupuestos.php');
        }
        $errores = array_merge($errores, $alta['errores'] ?? []);
    }
}

$tituloPagina   = 'Nuevo presupuesto';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Presupuestos', 'url' => URL_BASE . '/finanzas/presupuestos.php'], ['texto' => 'Nuevo presupuesto']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Nuevo presupuesto</h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if (!empty($errores['general'])): ?>
  <p class="pagina__descripcion pagina__descripcion--error"><?= e($errores['general']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" id="form-presupuesto">
  <?= campoCsrf() ?>
  <input type="hidden" name="accion_nueva" value="1">

  <section class="seccion">
    <h2 class="seccion__titulo"><?= icono('documento') ?> Datos del presupuesto</h2>
    <div class="seccion__cuerpo">
      <div class="rejilla rejilla--5">
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
          <div class="campo<?= $error !== '' ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="<?= e($nombre) ?>"><?= e($texto) ?><?= $requerido ? ' *' : '' ?></label>
            <div class="campo__control">
              <?php if ($tipo === 'casilla'): ?>
                <input id="<?= e($nombre) ?>" type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
              <?php elseif ($tipo === 'opcion' && !empty($opciones)): ?>
                <select id="<?= e($nombre) ?>" name="datos[<?= e($nombre) ?>]">
                  <option value="">—</option>
                  <?php foreach ($opciones as $claveOpcion => $textoOpcion): ?>
                    <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($tipo === 'fecha'): ?>
                <input id="<?= e($nombre) ?>" type="date" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
              <?php elseif ($tipo === 'numero'): ?>
                <input id="<?= e($nombre) ?>" type="number" step="0.01" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
              <?php else: ?>
                <input id="<?= e($nombre) ?>" type="text" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= !empty($def['max']) ? ' maxlength="' . (int) $def['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
              <?php endif; ?>
            </div>
            <?php if ($error !== ''): ?><span class="campo__ayuda"><?= e($error) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <div class="seccion__acciones">
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/finanzas/presupuestos.php">Cancelar</a>
    <button type="submit" class="boton boton--primario">Guardar presupuesto</button>
  </div>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>