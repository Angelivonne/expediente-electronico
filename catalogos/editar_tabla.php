<?php
/**
 * Editor de un catálogo del sistema: la misma tabla del motor en modo captura.
 *
 * Se llega desde la página del catálogo («Editar catálogo»). Usa el motor único para leer y el
 * endpoint único para guardar, así que los catálogos se comportan igual que pacientes o medicamentos.
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


// Alta rápida: crea el registro con el mismo motor y las mismas reglas
$erroresNuevo = [];
$valoresNuevo = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_nueva'] ?? '') === '1') {
    if (!csrfValido()) {
        $erroresNuevo = ['general' => 'La sesión expiró. Vuelve a enviar el formulario.'];
    } else {
        $entradaNueva = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [];
        $valoresNuevo = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $entradaNueva);
        $altaNueva = motorCrear($clave, $entradaNueva);
        if (!empty($altaNueva['ok'])) {
            mensajeFlash('exito', 'Registro agregado.');
            redirigir('/catalogos/editar_tabla.php?tabla=' . urlencode($clave));
        }
        $erroresNuevo = $altaNueva['errores'] ?? ['general' => 'No se pudo guardar.'];
    }
}
$busqueda = trim($_GET['q'] ?? '');
$limite   = min(5000, max(200, (int) ($_GET['limite'] ?? 200)));
// Herramientas de la tabla: ordenar, filtrar y agrupar
$ordenTabla   = trim((string) ($_GET['orden'] ?? ''));
$dirTabla     = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$campoTabla   = trim((string) ($_GET['campo'] ?? ''));
$valorTabla   = trim((string) ($_GET['valor'] ?? ''));
$agruparTabla = trim((string) ($_GET['agrupar'] ?? ''));
$filtroTabla  = ($campoTabla !== '' && $valorTabla !== '') ? [$campoTabla => $valorTabla] : [];

$lista      = motorListar($clave, ['q' => $busqueda, 'limite' => $limite, 'orden' => $ordenTabla, 'dir' => $dirTabla, 'filtro' => $filtroTabla]);
$gruposTabla = $agruparTabla !== '' ? motorAgrupar($clave, $agruparTabla, ['q' => $busqueda, 'filtro' => $filtroTabla]) : [];
$filas      = $lista['filas'];
$total      = $lista['total'];
$hayMas     = count($filas) < $total;
$opcionesEditor = opcionesEditorMotor($clave);

$tituloPagina  = 'Editar · ' . $catalogo['titulo'];
$seccionActiva = 'catalogos-' . $clave;
$claseContenido = 'pagina--ancha';
$migas = [['texto' => 'Catálogos', 'url' => '/catalogos/'], ['texto' => $catalogo['titulo']]];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista($clave),
    'filas'     => $filas,
    'atributos' => ['data-entidad' => $clave, 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
    'fila_atributos' => fn (array $fila) => ['data-id' => $fila['id']],
];
?>
<div class="pagina__cabecera">
  <span class="pagina__meta"><?= iconoRelleno('tabla') ?> Catálogo del sistema</span>
  <h1 class="pagina__titulo"><?= e($catalogo['titulo']) ?></h1>
  <p class="pagina__subtitulo">Edita los valores directamente en la tabla. Los cambios quedan en la bitácora.</p>
</div>

<section class="bd" data-bd>
  <div class="bd__cabecera">
    <div style="display:flex;align-items:center;gap:10px;">
      <h2 class="bd__titulo"><?= e($catalogo['titulo']) ?></h2>
      <span class="bd__contador" data-bd-contador><?= (int) $total ?></span>
    </div>
    <div class="bd__herramientas">
      <?php $herramientas = ['clave' => $clave, 'base' => URL_BASE . '/catalogos/editar_tabla.php', 'ocultos' => array_filter(['tabla' => $clave, 'q' => $busqueda, 'limite' => $limite]), 'orden' => $ordenTabla, 'dir' => $dirTabla, 'campo' => $campoTabla, 'valor' => $valorTabla, 'agrupar' => $agruparTabla, 'solo' => 'menu', 'exportar' => URL_BASE . '/catalogos/exportar.php?' . http_build_query(array_filter(['tabla' => $clave, 'q' => $busqueda, 'campo' => $campoTabla, 'valor' => $valorTabla, 'orden' => $ordenTabla, 'dir' => $dirTabla]))]; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; ?>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar en catálogo"><?= icono('buscar') ?></button>
      <a class="boton boton--primario bd__nuevo" href="<?= URL_BASE ?>/catalogos/nuevo.php?tabla=<?= e(urlencode($clave)) ?>" title="Nuevo registro"><?= icono('agregar') ?> Nuevo</a>
    </div>
  </div>

  <?php if ($busqueda !== ''): ?>
    <p class="pagina__descripcion">Resultados para «<?= e($busqueda) ?>» · <a href="?tabla=<?= e(urlencode($clave)) ?>">Ver todos</a></p>
  <?php endif; ?>

  <?php if ($gruposTabla): $herramientas = ['clave' => $clave, 'grupos' => $gruposTabla, 'agrupar' => $agruparTabla, 'solo' => 'grupos']; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; endif; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $filas ? 'hidden' : '' ?>>
    <?= $busqueda !== '' || $filas ? 'Ningún registro coincide con la búsqueda.' : 'Este catálogo todavía está vacío.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['tabla' => $clave, 'q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($filas) ?> de <?= $total ?></a>
  <?php endif; ?>
</section>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>