<?php
/**
 * Catálogos del sistema — sección completa, agrupada por módulos normativos.
 *
 * Una sola fuente de verdad: app/datos/catalogos_sistema.php (tabla real de cada catálogo).
 * Esta pantalla lista y enlaza al editor con el diseño consistente del expediente (estilo pacientes).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

exigirPermiso('ver_expediente');

$tituloPagina   = 'Catálogos';
$seccionActiva  = 'catalogos';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Catálogos']];

$catalogos = catalogosSistema();
$tablasCatalogo = array_values(array_unique(array_map(
    static function (array $catalogo): string {
        $tabla = (string) ($catalogo['tabla'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,64}$/i', $tabla)) {
            throw new LogicException('Definición de tabla de catálogo no válida.');
        }
        return $tabla;
    },
    $catalogos
)));
$tablasRevisar = array_merge($tablasCatalogo, ['catalogos_meta']);
$marcadoresTablas = implode(',', array_fill(0, count($tablasRevisar), '?'));
$consultaTablas = db()->prepare(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $marcadoresTablas . ')'
);
$consultaTablas->execute($tablasRevisar);
$tablasDisponibles = array_fill_keys($consultaTablas->fetchAll(PDO::FETCH_COLUMN), true);
$metaCatalogoDisponible = isset($tablasDisponibles['catalogos_meta']);
$metaCatalogoDb = [];
if ($metaCatalogoDisponible) {
    foreach (db()->query('SELECT clave, fuente, version, vigencia, responsable FROM catalogos_meta') as $metaFila) {
        $metaCatalogoDb[(string) $metaFila['clave']] = $metaFila;
    }
}
$institucionCatalogo = (int) institucionId();
$conteos = [];
foreach ($catalogos as $clave => $catalogo) {
    $tabla = (string) $catalogo['tabla'];
    if (!isset($tablasDisponibles[$tabla])) {
        $conteos[$clave] = null;
        continue;
    }
    $sqlConteo = 'SELECT COUNT(*) FROM `' . $tabla . '`';
    $tieneInstitucion = !empty($catalogo['institucion']) && (!function_exists('columnaExiste') || columnaExiste($tabla, 'institucion_id'));
    if ($tieneInstitucion) {
        $sqlConteo .= ' WHERE institucion_id = ?';
        $consultaConteo = db()->prepare($sqlConteo);
        $consultaConteo->execute([$institucionCatalogo]);
    } else {
        $consultaConteo = db()->query($sqlConteo);
    }
    $conteos[$clave] = (int) $consultaConteo->fetchColumn();
}
$secciones = seccionesCatalogo();
$seccionInicial = (string) array_key_first($secciones);

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Catálogos</h1>
</div>

<?php if (!$metaCatalogoDisponible): ?>
  <p class="pagina__descripcion" role="status">Metadatos editables no disponibles. Falta aplicar <code>database/migracion_catalogos_meta.sql</code>.</p>
<?php endif; ?>

<div class="catalogos-pagina" data-catalogos>
  <div class="catalogos__barra">
    <nav class="catalogos__tabs" role="tablist" aria-label="Secciones de catálogos">
      <?php
        $iconosSeccion = ['A' => 'usuarios', 'B' => 'clinico', 'C' => 'pildora', 'D' => 'casa', 'E' => 'tabla', 'F' => 'documento'];
        foreach ($secciones as $clave => $seccion):
      ?>
        <button type="button" class="catalogos__tab" role="tab"
          id="tab-catalogos-<?= e($clave) ?>"
          aria-controls="panel-catalogos-<?= e($clave) ?>"
          aria-selected="<?= $clave === $seccionInicial ? 'true' : 'false' ?>"
          tabindex="<?= $clave === $seccionInicial ? '0' : '-1' ?>"
          data-catalogos-tab="<?= e($clave) ?>">
          <?= icono($iconosSeccion[$clave] ?? 'tabla') ?>
          <span><?= e(preg_replace('/^[A-F]\.\s*/', '', $seccion['titulo'])) ?></span>
          <small data-catalogos-conteo><?= count($seccion['catalogos']) ?></small>
        </button>
      <?php endforeach; ?>
    </nav>
    <div class="catalogos__busqueda">
      <label class="sr-only" for="catalogos-filtro">Filtrar catálogos</label>
      <input id="catalogos-filtro" type="search" placeholder="Filtrar catálogos…" autocomplete="off" data-catalogos-filtro>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar en todo el expediente" aria-label="Buscar en todo el expediente"><?= icono('buscar') ?></button>
    </div>
  </div>
  <p class="catalogos__estado" role="status" aria-live="polite" data-catalogos-estado></p>

<?php foreach ($secciones as $clave => $seccion): ?>
  <?php $llaves = $seccion['catalogos']; ?>
  <section class="catalogos__seccion" id="panel-catalogos-<?= e($clave) ?>"
    role="tabpanel" aria-labelledby="tab-catalogos-<?= e($clave) ?>"
    data-catalogos-panel="<?= e($clave) ?>"<?= $clave !== $seccionInicial ? ' hidden' : '' ?>>
    <header class="catalogos__seccion-cabecera">
      <div>
        <h2><?= e(preg_replace('/^[A-F]\.\s*/', '', $seccion['titulo'])) ?></h2>
        <p><?= e($seccion['descripcion']) ?></p>
      </div>
      <span><?= count($llaves) ?> catálogos</span>
      <?php if ($clave === 'A'): ?>
        <a class="boton boton--sutil catalogos__ver-entidades" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=catalogo_entidades">
          <?= icono('ubicacion') ?><span>Ver entidades federativas</span>
        </a>
      <?php endif; ?>
    </header>
    <div class="bd-tabla-contenedor catalogos__tabla-contenedor">
      <table class="bd-tabla">
        <thead>
          <tr>
            <th>Catálogo</th>
            <th class="num">Registros</th>
            <th>Fuente / Norma</th>
            <th class="catalogos__acciones-columna">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($llaves as $llave): ?>
            <?php
              $catalogo = $catalogos[$llave] ?? null;
              if (!$catalogo) continue;
              $total = $conteos[$llave] ?? null;
              $urlTabla = !empty($catalogo['editor'])
                ? URL_BASE . '/' . trim($catalogo['editor'], '/') . '/'
                : URL_BASE . '/catalogos/editar_tabla.php?tabla=' . urlencode($llave);
              $textoBusqueda = implode(' ', array_filter([
                $seccion['titulo'] ?? '',
                $catalogo['titulo'] ?? '',
                $catalogo['descripcion'] ?? '',
                $catalogo['fuente'] ?? '',
                $catalogo['version'] ?? '',
                $catalogo['norma'] ?? '',
                $metaCatalogoDb[$llave]['fuente'] ?? '',
                $metaCatalogoDb[$llave]['version'] ?? '',
              ]));
              if (!empty($metaCatalogoDb[$llave]['norma'])) {
                  $textoBusqueda .= ' ' . (string) $metaCatalogoDb[$llave]['norma'];
              }
              $urlNuevo = !empty($catalogo['editor'])
                ? URL_BASE . '/' . trim($catalogo['editor'], '/') . '/nuevo.php'
                : URL_BASE . '/catalogos/nuevo.php?tabla=' . urlencode($llave);
            ?>
            <tr data-catalogos-fila data-busqueda="<?= e($textoBusqueda) ?>">
              <td class="bd-tabla__celda-titulo">
                <a class="catalogos__enlace" href="<?= e($urlTabla) ?>" title="Abrir tabla independiente de <?= e($catalogo['titulo']) ?>">
                  <?= icono('tabla') ?>
                  <span class="catalogos__enlace-nombre"><?= e($catalogo['titulo']) ?></span>
                  <span class="catalogos__enlace-accion">Ver registros <?= icono('flecha_derecha') ?></span>
                </a>
              </td>
              <td class="num font-mono"><?= $total === null ? '<span class="datos-tenue">—</span>' : number_format((int) $total) ?></td>
              <?php
                $metaFila = $metaCatalogoDb[$llave] ?? [];
                $fuenteMostrar = $metaFila['fuente'] ?? ($catalogo['fuente'] ?? '—');
                $versionMostrar = $metaFila['version'] ?? ($catalogo['version'] ?? '');
              ?>
              <td>
                <span style="font-weight:550;color:var(--texto-fuerte);"><?= e($fuenteMostrar) ?></span>
                <?= $versionMostrar !== '' && $versionMostrar !== '—' && $versionMostrar !== $fuenteMostrar ? ' · ' . e($versionMostrar) : '' ?>
                <br><small class="datos-tenue"><?= e($catalogo['norma'] ?? '') ?><?= !empty($metaFila['vigencia']) ? ' · hasta ' . e((string) $metaFila['vigencia']) : '' ?><?= !empty($metaFila['responsable']) ? ' · ' . e((string) $metaFila['responsable']) : '' ?></small>
              </td>
              <td class="catalogos__acciones-celda">
                <details class="catalogos__acciones-menu">
                  <summary class="boton-icono" title="Acciones de <?= e($catalogo['titulo']) ?>" aria-label="Acciones de <?= e($catalogo['titulo']) ?>" aria-haspopup="menu"><?= icono('mas') ?></summary>
                  <div class="catalogos__acciones-lista" role="menu" aria-label="Acciones de <?= e($catalogo['titulo']) ?>">
                    <a href="<?= e($urlNuevo) ?>" role="menuitem"><?= icono('agregar') ?><span>Nuevo registro</span></a>
                    <a href="<?= e($urlTabla) ?>" role="menuitem"><?= icono('tabla') ?><span>Abrir catálogo</span></a>
                    <a href="<?= URL_BASE ?>/catalogos/exportar.php?tabla=<?= e(urlencode($llave)) ?>" role="menuitem"><?= icono('descargar') ?><span>Exportar</span></a>
                    <?php if (puede('configurar')): ?>
                      <a href="<?= URL_BASE ?>/catalogos/importar.php?tabla=<?= e(urlencode($llave)) ?>" role="menuitem"><?= icono('subir') ?><span>Importar</span></a>
                      <?php if ($metaCatalogoDisponible): ?>
                        <a href="<?= URL_BASE ?>/catalogos/meta.php?tabla=<?= e(urlencode($llave)) ?>" role="menuitem"><?= icono('ajustes') ?><span>Metadatos</span></a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
