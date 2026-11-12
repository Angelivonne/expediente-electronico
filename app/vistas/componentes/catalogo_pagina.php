<?php
/**
 * Visor de un catálogo del sistema dentro de su página del espacio de trabajo.
 *
 * La página no duplica los datos: muestra la tabla real que consulta el expediente
 * (cie10, medicamentos) en solo lectura, con búsqueda y paginación, y lleva a su editor.
 *
 * Espera: $catalogoSistema (catálogo), $catalogoDatos (filasDeCatalogo), $busquedaCatalogo (texto),
 *         $id (id de la página) y $pagina (fila de `paginas`).
 */
if (!isset($id, $catalogoSistema, $catalogoDatos, $busquedaCatalogo)
    || !is_int($id)
    || !is_array($catalogoSistema)
    || !is_array($catalogoDatos)
    || !is_string($busquedaCatalogo)) {
    throw new LogicException('Faltan los datos requeridos para mostrar el catálogo.');
}

$filas      = $catalogoDatos['filas'] ?? [];
$total      = (int) ($catalogoDatos['total'] ?? 0);
$numero     = (int) ($catalogoDatos['pagina'] ?? 1);
$paginas    = (int) ($catalogoDatos['paginas'] ?? 1);
$columnas   = $catalogoSistema['columnas'] ?? [];
$enlace     = fn (int $p, string $q = ''): string => URL_BASE . '/paginas/ver.php?' . http_build_query(array_filter([
    'id' => (int) $id,
    'q'  => $q !== '' ? $q : null,
    'p'  => $p > 1 ? $p : null,
]));
$editor     = (string) ($catalogoSistema['editor'] ?? '');
?>
<section class="catalogo catalogo--hoja" aria-label="Catálogo <?= e($catalogoSistema['titulo']) ?>">
  <div class="catalogo__caja">

    <div class="catalogo__origen">
      <p>
        <b>Catálogo del sistema.</b> Son los mismos <?= number_format($total, 0, '.', ',') ?> registros que usa el
        expediente al capturar una nota: aquí solo se consultan.
      </p>
      <?php if (puede('ver_expediente')): ?>
        <?php if ($editor !== ''): ?>
          <a class="boton boton--secundario" href="<?= URL_BASE . e($editor) ?>"><?= icono('editar') ?> Editar catálogo</a>
        <?php elseif (!empty($catalogoSistema['clave'])): ?>
          <a class="boton boton--secundario" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=<?= e($catalogoSistema['clave']) ?>"><?= icono('editar') ?> Editar catálogo</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <form method="get" class="catalogo__busqueda" role="search">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="search" name="q" value="<?= e($busquedaCatalogo) ?>"
             placeholder="Buscar en <?= e(mb_strtolower($catalogoSistema['titulo'])) ?>…"
             autocomplete="off" spellcheck="false" aria-label="Buscar en el catálogo">
      <button type="submit" class="boton boton--sutil"><?= icono('buscar') ?> Buscar</button>
      <?php if ($busquedaCatalogo !== ''): ?>
        <a class="catalogo__link-suave" href="<?= e($enlace(1)) ?>">Ver todos</a>
      <?php endif; ?>
    </form>

    <p class="catalogo__conteo">
      <?php if ($busquedaCatalogo !== ''): ?>
        <?= number_format($total, 0, '.', ',') ?> resultado<?= $total === 1 ? '' : 's' ?> con «<?= e($busquedaCatalogo) ?>»
      <?php else: ?>
        <?= number_format($total, 0, '.', ',') ?> registro<?= $total === 1 ? '' : 's' ?>
      <?php endif; ?>
      <?php if ($paginas > 1): ?>
        · página <?= $numero ?> de <?= $paginas ?>
      <?php endif; ?>
    </p>

    <?php if (!$filas): ?>
      <div class="catalogo__vacio">
        <?= icono('documento') ?>
        <div>
          <p><b><?= $busquedaCatalogo !== '' ? 'Ningún registro coincide con la búsqueda.' : 'Este catálogo todavía está vacío.' ?></b></p>
          <p>
            <?php if ($busquedaCatalogo !== ''): ?>
              Prueba con otra palabra o <a class="catalogo__link-suave" href="<?= e($enlace(1)) ?>">mira todos los registros</a>.
            <?php elseif ($editor !== ''): ?>
              Puedes cargarlos desde el <a class="catalogo__link-suave" href="<?= URL_BASE . e($editor) ?>">editor del catálogo</a>.
            <?php endif; ?>
          </p>
        </div>
      </div>
    <?php else: ?>
      <div class="catalogo__marco">
        <table class="catalogo__tabla">
          <thead>
            <tr>
              <?php foreach ($columnas as $col): ?>
                <th<?= !empty($col[3]) ? ' style="width:' . (int) $col[3] . 'px"' : '' ?>><?= e((string) ($col[2] ?? '')) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filas as $fila): ?>
              <tr>
                <?php foreach ($columnas as $i => $col): $alias = (string) ($col[1] ?? ''); $tipo = (string) ($col[4] ?? 'texto'); ?>
                  <td<?= $i === 1 ? ' class="catalogo__principal"' : '' ?>><?php
                    if ($tipo === 'casilla') {
                        echo !empty($fila[$alias]) ? 'Sí' : 'No';
                    } else {
                        echo e((string) ($fila[$alias] ?? ''));
                    }
                  ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($paginas > 1): ?>
        <nav class="catalogo__paginas" aria-label="Páginas del catálogo">
          <?php if ($numero > 1): ?>
            <a class="boton boton--sutil" href="<?= e($enlace($numero - 1, $busquedaCatalogo)) ?>" rel="prev">Anterior</a>
          <?php else: ?>
            <span class="boton boton--sutil" aria-disabled="true">Anterior</span>
          <?php endif; ?>
          <span class="catalogo__pagina-actual">Página <?= $numero ?> de <?= $paginas ?></span>
          <?php if ($numero < $paginas): ?>
            <a class="boton boton--sutil" href="<?= e($enlace($numero + 1, $busquedaCatalogo)) ?>" rel="next">Siguiente</a>
          <?php else: ?>
            <span class="boton boton--sutil" aria-disabled="true">Siguiente</span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</section>
