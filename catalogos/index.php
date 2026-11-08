<?php
/**
 * catálogos del sistema — sección completa, agrupada por módulos normativos.
 *
 * Una sola fuente de verdad: app/datos/catalogos_sistema.php (tabla real de cada catálogo).
 * Esta pantalla solo lista y enlaza al editor existente; no tiene consultas ni estilos propios.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

exigirPermiso('ver_expediente');

$tituloPagina   = 'catálogos';
$seccionActiva  = 'catalogos';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'catálogos']];

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
    if (!empty($catalogo['institucion'])) {
        $sqlConteo .= ' WHERE institucion_id = ?';
        $consultaConteo = db()->prepare($sqlConteo);
        $consultaConteo->execute([$institucionCatalogo]);
    } else {
        $consultaConteo = db()->query($sqlConteo);
    }
    $conteos[$clave] = (int) $consultaConteo->fetchColumn();
}
$secciones = seccionesCatalogo();


require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<header class="pagina__cabecera">
  <h1 class="pagina__titulo">Catálogos</h1>
</header>

<?php if (!$metaCatalogoDisponible): ?>
  <p class="pagina__descripcion" role="status">Metadatos editables no disponibles. Falta aplicar <code>database/migracion_catalogos_meta.sql</code>.</p>
<?php endif; ?>

<?php foreach ($secciones as $clave => $seccion): ?>
  <?php $llaves = $seccion['catalogos']; ?>
  <section class="bd" aria-labelledby="cat-<?= e($clave) ?>">
    <div class="bd__cabecera">
      <h2 class="bd__titulo" id="cat-<?= e($clave) ?>"><?= e($seccion['titulo']) ?></h2>
      <span class="bd__contador"><?= count($llaves) ?></span>
    </div>
    <p class="pagina__descripcion"><?= e($seccion['descripcion']) ?></p>
    <div class="finanzas__plano">
      <table class="bd-tabla">
        <thead>
          <tr>
            <th>catálogo</th>
            <th>Descripción</th>
            <th>Registros</th>
            <th>Fuente</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($llaves as $llave): ?>
            <?php $catalogo = $catalogos[$llave] ?? null; if (!$catalogo) continue; ?>
            <?php $total = $conteos[$llave] ?? null; ?>
            <tr>
              <td><?php if ($total !== null): ?><a href="<?= URL_BASE ?>/catalogos/editar.php?tabla=<?= e(urlencode($llave)) ?>"><?= e($catalogo['titulo']) ?></a><?php else: ?><?= e($catalogo['titulo']) ?><?php endif; ?></td>
              <td><?= e($catalogo['descripcion'] ?? ('catálogo del sistema · tabla ' . $catalogo['tabla'])) ?></td>
              <td><?= $total === null ? '<span class="datos-tenue">Tabla no instalada</span>' : (int) $total ?></td>
              <?php
                $metaFila = $metaCatalogoDb[$llave] ?? [];
                $fuenteMostrar = $metaFila['fuente'] ?? ($catalogo['fuente'] ?? '—');
                $versionMostrar = $metaFila['version'] ?? ($catalogo['version'] ?? '');
              ?>
              <td><?= e($fuenteMostrar) ?><?= $versionMostrar !== '' && $versionMostrar !== '—' && $versionMostrar !== $fuenteMostrar ? ' · ' . e($versionMostrar) : '' ?><br><small class="datos-tenue"><?= e($catalogo['norma'] ?? '') ?><?= !empty($metaFila['vigencia']) ? ' · vigente hasta ' . e((string) $metaFila['vigencia']) : '' ?><?= !empty($metaFila['responsable']) ? ' · ' . e((string) $metaFila['responsable']) : '' ?></small></td>
              <td>
                <?php if ($total !== null): ?>
                  <a href="<?= URL_BASE ?>/api/motor/exportar.php?<?= e(http_build_query(['entidad' => $llave])) ?>">Exportar</a>
                  <?php if (puede('configurar')): ?> · <a href="<?= URL_BASE ?>/catalogos/importar.php?tabla=<?= e(urlencode($llave)) ?>">Importar</a><?php endif; ?>
                  <?php if (puede('configurar') && $metaCatalogoDisponible): ?> · <a href="<?= URL_BASE ?>/catalogos/meta.php?tabla=<?= e(urlencode($llave)) ?>">Metadatos</a><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>






