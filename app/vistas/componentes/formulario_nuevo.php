<?php
/**
 * Formulario de alta de una entidad del motor, plegado dentro de la tabla.
 *
 * Espera $nuevo = ['titulo' => …, 'accion' => URL de la página, 'campos' => campos de la entidad,
 *                  'errores' => errores por campo, 'valores' => lo que se alcanzó a capturar].
 * Los campos de solo lectura no se incluyen y los obligatorios se marcan con asterisco.
 */
$camposNuevo = [];
foreach ((array) ($nuevo['campos'] ?? []) as $nombre => $definicion) {
    if (!empty($definicion['solo_lectura']) || $nombre === 'id') {
        continue;
    }
    $camposNuevo[$nombre] = $definicion;
}
$valoresNuevo = (array) ($nuevo['valores'] ?? []);
$erroresNuevo = (array) ($nuevo['errores'] ?? []);
$tituloForm = (string) ($nuevo['titulo'] ?? 'Nuevo registro');
$accionForm = (string) ($nuevo['accion'] ?? '');
$estaAbierto = !empty($erroresNuevo);
?>
<details class="nuevo-registro"<?= $estaAbierto ? ' open' : '' ?>>
  <summary class="nuevo-registro__resumen">
    <span class="nuevo-registro__resumen-content">
      <span class="nuevo-registro__texto"><?= icono('agregar') ?> Nuevo <?= e($tituloForm) ?></span>
      <span class="nuevo-registro__chevron" aria-hidden="true"><?= $estaAbierto ? '▲' : '▼' ?></span>
    </span>
  </summary>
  <form method="post" action="<?= e($accionForm) ?>" class="nuevo-registro__form">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion_nueva" value="1">

    <div class="nuevo-registro__campos">
      <?php foreach ($camposNuevo as $nombre => $definicion): ?>
        <?php
          $texto = (string) ($definicion['texto'] ?? $nombre);
          $tipo = (string) ($definicion['tipo'] ?? 'texto');
          $valor = (string) ($valoresNuevo[$nombre] ?? '');
          $requerido = !empty($definicion['req']);
          $error = (string) ($erroresNuevo[$nombre] ?? '');
        ?>
        <label class="nuevo-registro__campo<?= $error !== '' ? ' tiene-error' : '' ?>">
          <span><?= e($texto) ?><?= $requerido ? ' *' : '' ?></span>
          <?php if ($tipo === 'casilla'): ?>
            <input type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
          <?php elseif ($tipo === 'opcion' && !empty($definicion['opciones'])): ?>
            <select name="datos[<?= e($nombre) ?>]">
              <option value="">—</option>
              <?php foreach ((array) $definicion['opciones'] as $claveOpcion => $textoOpcion): ?>
                <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="<?= $tipo === 'fecha' ? 'date' : ($tipo === 'numero' ? 'number' : 'text') ?>"
                   name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"
                   <?= !empty($definicion['max']) ? 'maxlength="' . (int) $definicion['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
          <?php endif; ?>
          <?php if ($error !== ''): ?><em class="nuevo-registro__error"><?= e($error) ?></em><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($erroresNuevo['general'])): ?>
      <p class="nuevo-registro__error"><?= e((string) $erroresNuevo['general']) ?></p>
    <?php endif; ?>

    <div class="nuevo-registro__acciones">
      <button type="submit" class="boton boton--primario">Guardar registro</button>
    </div>
  </form>
</details>
