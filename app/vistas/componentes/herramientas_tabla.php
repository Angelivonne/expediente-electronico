<?php
/**
 * Herramientas de una tabla: ordenar, filtrar y agrupar desde un desplegable discreto.
 *
 * Espera $herramientas:
 *   clave       entidad del motor
 *   base        ruta de la página (para el formulario)
 *   ocultos     [nombre => valor] que se conservan (q, limite, id, tabla…)
 *   orden, dir, campo, valor, agrupar   valores actuales
 *   grupos      resultado de motorAgrupar
 *   grupo_etiqueta  título del campo agrupado (opcional)
 *   solo        'menu' (solo el desplegable) o 'grupos' (solo el resumen agrupado)
 */
$colHerramientas = columnasVista($herramientas['clave'] ?? '');
$camposHerramientas = array_values(array_filter($colHerramientas, fn ($c) => !empty($c['campo'])));
$solo    = (string) ($herramientas['solo'] ?? 'menu');
$ocultos = (array) ($herramientas['ocultos'] ?? []);
$base    = (string) ($herramientas['base'] ?? '');
$orden   = (string) ($herramientas['orden'] ?? '');
$dir     = strtolower((string) ($herramientas['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$campo   = (string) ($herramientas['campo'] ?? '');
$valor   = (string) ($herramientas['valor'] ?? '');
$agrupar = (string) ($herramientas['agrupar'] ?? '');
$grupos  = (array) ($herramientas['grupos'] ?? []);
$etiquetaGrupo = (string) ($herramientas['grupo_etiqueta'] ?? '');
if ($etiquetaGrupo === '' && $agrupar !== '') {
    foreach ($camposHerramientas as $c) {
        if ($c['campo'] === $agrupar) {
            $etiquetaGrupo = $c['titulo'];
            break;
        }
    }
}
$hayHerramientas = $orden !== '' || ($campo !== '' && $valor !== '') || $agrupar !== '';

if ($solo === 'grupos') {
    if (!$grupos) {
        return;
    }
    ?>
    <div class="tabla-grupos" role="status">
      <p class="tabla-grupos__titulo">Agrupado por <?= e($etiquetaGrupo ?: $agrupar) ?></p>
      <ul>
        <?php foreach ($grupos as $g): ?>
          <li><span><?= e($g['valor'] === '' || $g['valor'] === null ? '(vacío)' : (is_bool($g['valor']) ? ($g['valor'] ? 'Sí' : 'No') : (string) $g['valor'])) ?></span><b><?= (int) $g['conteo'] ?></b></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
    return;
}
?>
<details class="tabla-herramientas"<?= $hayHerramientas ? ' data-activa="1"' : '' ?>>
  <summary class="boton-icono" title="Ordenar, filtrar y agrupar" aria-label="Herramientas de la tabla"><?= icono('lista') ?></summary>
  <form method="get" class="tabla-herramientas__panel" action="<?= e($base) ?>">
    <?php foreach ($ocultos as $nombre => $dato): ?>
      <input type="hidden" name="<?= e((string) $nombre) ?>" value="<?= e((string) $dato) ?>">
    <?php endforeach; ?>

    <p class="tabla-herramientas__titulo">Ordenar</p>
    <div class="tabla-herramientas__fila">
      <select name="orden" aria-label="Ordenar por">
        <option value="">Orden original</option>
        <?php foreach ($camposHerramientas as $c): ?>
          <option value="<?= e($c['campo']) ?>"<?= $orden === $c['campo'] ? ' selected' : '' ?>><?= e($c['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="dir" aria-label="Sentido">
        <option value="asc"<?= $dir === 'asc' ? ' selected' : '' ?>>Ascendente</option>
        <option value="desc"<?= $dir === 'desc' ? ' selected' : '' ?>>Descendente</option>
      </select>
    </div>

    <p class="tabla-herramientas__titulo">Filtrar</p>
    <div class="tabla-herramientas__fila">
      <select name="campo" aria-label="Campo a filtrar">
        <option value="">Sin filtro</option>
        <?php foreach ($camposHerramientas as $c): ?>
          <option value="<?= e($c['campo']) ?>"<?= $campo === $c['campo'] ? ' selected' : '' ?>><?= e($c['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
      <input name="valor" value="<?= e($valor) ?>" placeholder="Valor exacto" aria-label="Valor del filtro">
    </div>

    <p class="tabla-herramientas__titulo">Agrupar</p>
    <div class="tabla-herramientas__fila">
      <select name="agrupar" aria-label="Agrupar por">
        <option value="">Sin agrupar</option>
        <?php foreach ($camposHerramientas as $c): ?>
          <option value="<?= e($c['campo']) ?>"<?= $agrupar === $c['campo'] ? ' selected' : '' ?>><?= e($c['titulo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if (!empty($herramientas['exportar'])): ?>
      <p class="tabla-herramientas__exportar"><a href="<?= e($herramientas['exportar']) ?>">Descargar CSV</a></p>
    <?php endif; ?>

    <div class="tabla-herramientas__acciones">
      <button type="submit" class="boton boton--primario">Aplicar</button>
      <?php if ($hayHerramientas): ?><a class="tabla-herramientas__limpiar" href="<?= e($base) ?>">Limpiar</a><?php endif; ?>
    </div>
  </form>
</details>
