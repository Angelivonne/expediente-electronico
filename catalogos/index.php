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

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Catálogos del Sistema</h1>
  <p class="pagina__subtitulo">Catálogos clínicos y normativos maestros (NOM-004, NOM-024 y compendios oficiales)</p>
</div>

<?php if (!$metaCatalogoDisponible): ?>
  <p class="pagina__descripcion" role="status">Metadatos editables no disponibles. Falta aplicar <code>database/migracion_catalogos_meta.sql</code>.</p>
<?php endif; ?>

<?php foreach ($secciones as $clave => $seccion): ?>
  <?php $llaves = $seccion['catalogos']; ?>
  <section class="bd" aria-labelledby="cat-<?= e($clave) ?>" style="margin-bottom:28px;">
    <div class="bd__cabecera">
      <div style="display:flex;align-items:center;gap:10px;">
        <h2 class="bd__titulo" id="cat-<?= e($clave) ?>"><?= e($seccion['titulo']) ?></h2>
        <span class="bd__contador"><?= count($llaves) ?></span>
      </div>
      <div class="bd__herramientas">
        <span class="datos-tenue" style="font-size:var(--fs-xs);">Herramientas de datos</span>
      </div>
    </div>
    <p class="pagina__descripcion" style="margin-bottom:12px;"><?= e($seccion['descripcion']) ?></p>

    <div class="bd-tabla-contenedor">
      <table class="bd-tabla">
        <thead>
          <tr>
            <th style="min-width:210px;">Catálogo</th>
            <th>Descripción</th>
            <th class="num" style="width:110px;">Registros</th>
            <th>Fuente / Norma</th>
            <th style="width:170px;text-align:right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($llaves as $llave): ?>
            <?php $catalogo = $catalogos[$llave] ?? null; if (!$catalogo) continue; ?>
            <?php $total = $conteos[$llave] ?? null; ?>
            <tr>
              <td class="bd-tabla__celda-titulo">
                <?php if ($total !== null): ?>
                  <a class="bd-tabla__titulo" href="<?= URL_BASE ?>/catalogos/editar_tabla.php?tabla=<?= e(urlencode($llave)) ?>">
                    <?= icono('tabla') ?> <span><?= e($catalogo['titulo']) ?></span>
                  </a>
                <?php else: ?>
                  <span class="bd-tabla__titulo" style="opacity:.6;"><?= icono('tabla') ?> <span><?= e($catalogo['titulo']) ?></span></span>
                <?php endif; ?>
              </td>
              <td style="color:var(--texto);"><?= e($catalogo['descripcion'] ?? ('Catálogo del sistema · tabla ' . $catalogo['tabla'])) ?></td>
              <td class="num font-mono"><?= $total === null ? '<span class="datos-tenue">No instalada</span>' : number_format((int) $total) ?></td>
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
              <td style="text-align:right;">
                <?php if ($total !== null): ?>
                  <div style="display:inline-flex;align-items:center;gap:6px;justify-content:flex-end;">
                    <a class="boton-icono" href="<?= URL_BASE ?>/api/motor/exportar.php?<?= e(http_build_query(['entidad' => $llave])) ?>" title="Exportar catálogo" aria-label="Exportar"><?= icono('descargar') ?></a>
                    <?php if (puede('configurar')): ?>
                      <a class="boton-icono" href="<?= URL_BASE ?>/catalogos/importar.php?tabla=<?= e(urlencode($llave)) ?>" title="Importar datos" aria-label="Importar"><?= icono('subir') ?></a>
                      <?php if ($metaCatalogoDisponible): ?>
                        <a class="boton-icono" href="<?= URL_BASE ?>/catalogos/meta.php?tabla=<?= e(urlencode($llave)) ?>" title="Metadatos" aria-label="Metadatos"><?= icono('ajustes') ?></a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
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
