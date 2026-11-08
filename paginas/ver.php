<?php
/**
 * Página del espacio de trabajo: nota (editor por bloques), carpeta (lo que contiene) o
 * base de datos (tabla editable). El acceso se calcula en app/servicios/espacio/paginas.php.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../app/servicios/espacio/firmas.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

$usuarioId = (int) usuarioActual()['id'];
$pagina    = cargarPagina((int) ($_GET['id'] ?? 0), $usuarioId);
if (!$pagina) {
    mensajeFlash('error', 'La página ya no existe (pudo moverse a la papelera) o no tienes acceso.');
    redirigir('/');
}
$id = (int) $pagina['id'];
if ($pagina['seccion'] !== null) {   // las notas de sección se ven y editan en su sección
    require_once __DIR__ . '/../app/servicios/espacio/secciones.php';
    redirigir(rutaDeSeccion($pagina['seccion']));
}

$vista = db()->prepare(
    "SELECT 1 FROM bitacora WHERE tabla = 'paginas' AND registro_id = ? AND usuario_id = ? AND accion = 'acceso'
        AND campo = 'vista' AND creado_en > NOW() - INTERVAL 30 MINUTE LIMIT 1"
);
$vista->execute([$id, $usuarioId]);
if (!$vista->fetchColumn()) {
    registrarBitacora('paginas', $id, 'acceso', 'vista');
}

$favorita = db()->prepare('SELECT 1 FROM paginas_favoritas WHERE usuario_id = ? AND pagina_id = ?');
$favorita->execute([$usuarioId, $id]);
$pagina['favorita'] = (bool) $favorita->fetchColumn();
if ($pagina['eliminado_por']) {
    $quien = db()->prepare('SELECT nombre FROM usuarios WHERE id = ?');
    $quien->execute([$pagina['eliminado_por']]);
    $pagina['eliminado_por_nombre'] = $quien->fetchColumn() ?: null;
}

$editable  = nivelAlcanza($pagina, 'editar') && !$pagina['en_papelera'];
// Firma digital (NOM-024): una nota firmada queda en solo lectura hasta que se rompa el sello
$firmas = ['firmadas' => [], 'historial' => [], 'editable' => true, 'puede_firmar' => false, 'puede_revocar' => false];
if ($pagina['tipo'] === 'nota') {
    $firmas = estadoFirmas($pagina, $usuarioId);
    if (!$firmas['editable']) {
        $editable = false;
    }
}
$sinTitulo = TIPOS_PAGINA[$pagina['tipo']]['nueva'];
$titulo    = $pagina['titulo'] !== '' ? $pagina['titulo'] : $sinTitulo;

$migas = [];
foreach (rutaPagina($id, $usuarioId) as $p) {
    $migas[] = $p['id'] === $id
        ? ['texto' => $titulo]
        : ['texto' => $p['titulo'] !== '' ? $p['titulo'] : TIPOS_PAGINA[$p['tipo']]['nueva'], 'url' => '/paginas/ver.php?id=' . $p['id']];
}

$hijos = hijosDePagina($id, $usuarioId);

/*
 * Catálogo del sistema: su página no guarda renglones propios, muestra en solo lectura la tabla
 * que consulta el expediente (CIE-10, medicamentos). Se reconoce por la marca {"origen": …} o,
 * en páginas creadas antes, por el título.
 */
$catalogoCandidato = $pagina['tipo'] === 'base_datos' ? catalogoDePagina($pagina) : null;
$catalogoNoDisponible = $catalogoCandidato !== null && !catalogoConTabla($catalogoCandidato);
$catalogoSistema = $catalogoNoDisponible ? null : $catalogoCandidato;
$busquedaCatalogo = '';
$catalogoDatos    = null;
if ($catalogoSistema) {
    $busquedaCatalogo = trim((string) ($_GET['q'] ?? ''));
    $catalogoDatos    = filasDeCatalogo($catalogoSistema, $busquedaCatalogo, (int) ($_GET['p'] ?? 1));
}

if ($pagina['tipo'] === 'base_datos' && !$catalogoSistema && !$catalogoNoDisponible) {
    $filas = db()->prepare('SELECT id, valores, notas FROM pagina_filas WHERE pagina_id = ? ORDER BY orden, id');
    $filas->execute([$id]);
    $datosBd = [
        'columnas' => columnasDe($pagina),
        'filas'    => array_map(fn ($f) => ['id' => (int) $f['id'], 'valores' => json_decode($f['valores'], true) ?: new stdClass(), 'notas' => (string) ($f['notas'] ?? '')], $filas->fetchAll()),
        'tipos'    => TIPOS_COLUMNA,
        'vistas'   => (json_decode((string) $pagina['contenido'], true)['vistas'] ?? null) ?: [['id' => 'v1', 'tipo' => 'tabla', 'nombre' => 'Tabla']],
        'plantillas' => array_map(fn ($p) => [$p[0], $p[1]], PLANTILLAS_BD),
        // Columnas de tipo Paciente: nombre y folio para mostrar y elegir (solo quien puede ver expedientes)
        'pacientes' => [],
        'herramientasDatos' => puede('importar_exportar'),
    ];
    if (puede('ver_expediente')) {
        $lista = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY nombre, primer_apellido LIMIT 3000');
        $lista->execute([institucionId()]);
        foreach ($lista->fetchAll() as $x) {
            $datosBd['pacientes'][(int) $x['id']] = [trim("{$x['nombre']} {$x['primer_apellido']} {$x['segundo_apellido']}"), $x['folio']];
        }
        $datosBd['pacientes'] = $datosBd['pacientes'] ?: new stdClass();
    }
}

$tituloPagina   = $titulo;
$paginaActual   = $pagina;
$clavePresencia = 'pagina:' . $id;
$mostrarBanner  = false;
$claseContenido = 'pagina--hoja' . ($pagina['tipo'] === 'base_datos' ? ' pagina--hoja-ancha' : '');
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>

<?php if ($pagina['portada']): ?>
  <div class="portada" style="--portada: url(<?= e(URL_BASE . '/' . $pagina['portada']) ?>);<?= $pagina['portada_alto'] ? ' --alto: ' . (int) $pagina['portada_alto'] . 'px;' : '' ?>" role="img" aria-label="Portada">
    <?php if ($editable): ?>
      <div class="portada__acciones">
        <button type="button" data-portada-cambiar data-destino="pagina" data-id="<?= $id ?>" data-tiene="1"><?= icono('imagen') ?> Cambiar portada</button>
        <?php if ($pagina['portada_alto']): ?>
          <button type="button" data-portada-alto-original data-destino="pagina" data-id="<?= $id ?>">Tamaño predeterminado</button>
        <?php endif; ?>
        <button type="button" data-portada-quitar data-destino="pagina" data-id="<?= $id ?>">Quitar</button>
      </div>
      <div class="portada__tirador" data-portada-tirador data-destino="pagina" data-id="<?= $id ?>" role="separator" aria-orientation="horizontal" aria-label="Arrastra para cambiar el alto de la portada" tabindex="0"></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<article class="hoja<?= $pagina['portada'] ? ' hoja--con-portada' : '' ?>" data-hoja data-editable="<?= $editable ? '1' : '0' ?>" data-version="<?= (int) $pagina['version'] ?>">
  <?php if ($editable && !$pagina['portada']): ?>
    <div class="hoja__opciones-titulo">
      <button type="button" data-portada-cambiar data-destino="pagina" data-id="<?= $id ?>"><?= icono('imagen') ?> Agregar portada</button>
    </div>
  <?php endif; ?>
  <h1 class="hoja__titulo" data-hoja-titulo data-placeholder="<?= e($sinTitulo) ?>"<?= $editable ? ' contenteditable="plaintext-only" spellcheck="true"' : '' ?>><?= e($pagina['titulo']) ?></h1>
  <?php if ($pagina['tipo'] === 'base_datos' && !$catalogoSistema && !$catalogoNoDisponible && puede('importar_exportar')): ?>
    <div class="bd__herramientas hoja__herramientas">
      <button type="button" class="boton-icono" data-herramientas-datos-pagina data-pagina="<?= $id ?>" aria-haspopup="menu" title="Herramientas de datos" aria-label="Herramientas de datos"><?= icono('tabla') ?></button>
    </div>
  <?php endif; ?>

  <?php if ($pagina['tipo'] === 'nota'): ?>
    <script type="application/json" id="editor-datos"><?= $json(json_decode((string) $pagina['contenido'], true) ?: []) ?></script>
    <div class="editor" data-editor-bloques aria-label="Contenido de la página"></div>

  <?php elseif ($catalogoSistema): ?>
    <?php require __DIR__ . '/../app/vistas/componentes/catalogo_pagina.php'; ?>

  <?php elseif ($catalogoNoDisponible): ?>
    <p class="pagina__descripcion" role="status">La tabla <code><?= e($catalogoCandidato['tabla']) ?></code> no está instalada. Revisa las migraciones del catálogo.</p>

  <?php elseif ($pagina['tipo'] === 'base_datos'): ?>
    <script type="application/json" id="bdp-datos"><?= $json($datosBd) ?></script>
    <div class="bdp" data-bdp></div>
  <?php endif; ?>

  <?php if ($pagina['tipo'] === 'nota' && $firmas['firmadas']): ?>
    <!-- Sello de firma digital (NOM-024): identidad + cédula + hora + huella SHA-256 del contenido firmado -->
    <section class="sello-firma" data-sello-firma aria-label="Firma digital">
      <?php foreach ($firmas['firmadas'] as $f): ?>
        <div class="sello-firma__bloque<?= $f['integra'] ? '' : ' sello-firma__bloque--alterada' ?>">
          <span class="sello-firma__icono" aria-hidden="true"><?= icono($f['integra'] ? 'escudo_ok' : 'alerta') ?></span>
          <div class="sello-firma__datos">
            <span class="sello-firma__estado"><?= $f['integra'] ? 'Firmada digitalmente' : 'Contenido alterado después de firmar' ?></span>
            <span class="sello-firma__quien"><?= e($f['nombre']) ?> · Cédula profesional <?= e($f['cedula']) ?><?= $f['especialidad'] ? ' · ' . e($f['especialidad']) : '' ?></span>
            <span class="sello-firma__tiempo">Sello de tiempo: <?= e($f['fecha_corta']) ?></span>
            <span class="sello-firma__huella" title="Huella SHA-256 del contenido firmado">SHA-256: <?= e(substr($f['hash'], 0, 16)) ?>…</span>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($firmas['historial']): ?>
        <details class="sello-firma__historial">
          <summary>Firmas anteriores revocadas (<?= count($firmas['historial']) ?>)</summary>
          <ul>
            <?php foreach ($firmas['historial'] as $h): ?>
              <li><?= e($h['nombre']) ?> · Cédula <?= e($h['cedula']) ?> · firmada <?= e($h['firmada']) ?> · revocada <?= e($h['revocada']) ?> por <?= e($h['revoco'] ?? 'alguien') ?> · motivo: <?= e($h['motivo']) ?></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($hijos || $pagina['tipo'] === 'carpeta'): ?>
    <!-- Lo que contiene esta página (carpetas, notas y bases de datos) -->
    <ul class="hoja__hijos" data-hoja-hijos>
      <?php foreach ($hijos as $h): ?>
        <li>
          <a href="<?= URL_BASE ?>/paginas/ver.php?id=<?= $h['id'] ?>">
            <?= iconoRelleno(TIPOS_PAGINA[$h['tipo']]['icono']) ?>
            <span class="hoja__hijo-titulo"><?= e($h['titulo'] !== '' ? $h['titulo'] : TIPOS_PAGINA[$h['tipo']]['nueva']) ?></span>
            <span class="hoja__hijo-fecha"><?= e(hace($h['actualizado_en'])) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($editable && $hijos): ?>
      <button type="button" class="hoja__agregar" data-pagina-menu-nueva data-padre="<?= $id ?>"><?= icono('agregar') ?> Agregar página</button>
    <?php endif; ?>
  <?php endif; ?>
</article>

<?php if ($pagina['tipo'] === 'nota'): ?>
<script type="application/json" id="plantillas-nota"><?= json_encode((function () {
    $base = [];
    foreach (PLANTILLAS_NOTA as $clave => $p) {
        $base[] = ['clave' => $clave, 'titulo' => $p['titulo'], 'norma' => $p['norma']];
    }
    $propias = [];
    $q = db()->prepare('SELECT id, titulo FROM plantillas WHERE institucion_id = ? AND activo = 1 ORDER BY creado_en DESC LIMIT 20');
    $q->execute([institucionId()]);
    foreach ($q as $r) {
        $propias[] = ['clave' => 'custom:' . (int) $r['id'], 'titulo' => $r['titulo'], 'norma' => 'Plantilla del equipo'];
    }
    return ['base' => $base, 'propias' => $propias];
})(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
