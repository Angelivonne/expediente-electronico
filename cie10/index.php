<?php
/**
 * Diagnósticos CIE-10 — vista del motor único.
 *
 * Antes esta pantalla tenía su propia consulta, su propio armado de tabla y su propio endpoint de
 * edición. Ahora usa el motor: `motorListar()` para leer y `vista_tabla.php` para pintar. El aspecto,
 * los filtros y el comportamiento son los mismos; la diferencia es que ya no hay una copia más.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('ver_expediente');


// Alta rápida: crea el registro con el mismo motor y las mismas reglas
$erroresNuevo = [];
$valoresNuevo = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_nueva'] ?? '') === '1') {
    if (!csrfValido()) {
        $erroresNuevo = ['general' => 'La sesión expiró. Vuelve a enviar el formulario.'];
    } else {
        $entradaNueva = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [];
        $valoresNuevo = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $entradaNueva);
        $altaNueva = motorCrear('cie10', $entradaNueva);
        if (!empty($altaNueva['ok'])) {
            mensajeFlash('exito', 'Registro agregado.');
            redirigir('/cie10/');
        }
        $erroresNuevo = $altaNueva['errores'] ?? ['general' => 'No se pudo guardar.'];
    }
}
$busqueda = trim($_GET['q'] ?? '');
$nuevoId  = (int) ($_GET['nuevo'] ?? 0);
$limite   = min(5000, max(200, (int) ($_GET['limite'] ?? 200)));
// Herramientas de la tabla: ordenar, filtrar y agrupar
$ordenTabla   = trim((string) ($_GET['orden'] ?? ''));
$dirTabla     = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$campoTabla   = trim((string) ($_GET['campo'] ?? ''));
$valorTabla   = trim((string) ($_GET['valor'] ?? ''));
$agruparTabla = trim((string) ($_GET['agrupar'] ?? ''));
$filtroTabla  = ($campoTabla !== '' && $valorTabla !== '') ? [$campoTabla => $valorTabla] : [];

$lista             = motorListar('cie10', ['q' => $busqueda, 'limite' => $limite, 'orden' => $ordenTabla, 'dir' => $dirTabla, 'filtro' => $filtroTabla]);
$gruposTabla      = $agruparTabla !== '' ? motorAgrupar('cie10', $agruparTabla, ['q' => $busqueda, 'filtro' => $filtroTabla]) : [];
$diagnosticos      = $lista['filas'];
$totalDiagnosticos = $lista['total'];
$hayMas            = count($diagnosticos) < $totalDiagnosticos;
$opcionesEditor    = opcionesEditorMotor('cie10');

$tituloPagina   = 'CIE-10';
$seccionActiva  = 'cie10';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Expediente'], ['texto' => 'CIE-10']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista('cie10'),
    'filas'     => $diagnosticos,
    'atributos' => ['data-entidad' => 'cie10', 'data-catalogo' => 'cie10', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
    'fila_atributos' => function (array $fila, int $i) use ($nuevoId): array {
        $atributos = ['data-id' => $fila['id'], 'data-diagnostico' => $fila['id'], 'style' => '--i: ' . min($i, 20)];
        if ((int) $fila['id'] === $nuevoId) {
            $atributos['class'] = 'nuevo';
        }
        return $atributos;
    },
    'acciones' => fn (array $fila): string => '<td class="tabla__acciones"><a class="boton-icono" href="' . URL_BASE
        . '/cie10/nuevo.php?id=' . (int) $fila['id'] . '" title="Editar">' . icono('editar') . '</a></td>',
    'grupos'   => ['Identificación' => 2, 'Clasificación' => 1, 'Estado' => 1],
];
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('CIE-10') ?></h1>
  <p class="pagina__subtitulo"><?= e('Catálogo de diagnósticos con clave oficial · NOM-024-SSA3-2012') ?></p>
</div>

<section class="bd" data-bd>
  <div class="bd__cabecera">
    <h2 class="bd__titulo"><?= e('Registro de diagnósticos CIE-10') ?></h2>

    <div class="bd__herramientas">
      <?php $herramientas = ['clave' => 'cie10', 'base' => URL_BASE . '/cie10/', 'ocultos' => array_filter(['q' => $busqueda, 'limite' => $limite]), 'orden' => $ordenTabla, 'dir' => $dirTabla, 'campo' => $campoTabla, 'valor' => $valorTabla, 'agrupar' => $agruparTabla, 'solo' => 'menu', 'exportar' => URL_BASE . '/api/motor/exportar.php?' . http_build_query(array_filter(['entidad' => 'cie10', 'q' => $busqueda, 'campo' => $campoTabla, 'valor' => $valorTabla, 'orden' => $ordenTabla, 'dir' => $dirTabla]))]; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; ?>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar (código, descripción, capítulo…)"><?= icono('buscar') ?></button>
      <?php if ($busqueda !== ''): ?>
        <p class="pagina__descripcion">
          Resultados para «<?= e($busqueda) ?>» · <a href="<?= URL_BASE ?>/cie10/"><?= e('Ver todos') ?></a>
        </p>
      <?php endif; ?>
      <button type="button" class="boton boton--primario bd__nuevo" data-menu-mas aria-haspopup="menu" title="Registrar diagnóstico"><?= icono('agregar') ?> Nuevo</button>
    </div>
  </div>

  <?php if ($gruposTabla): $herramientas = ['clave' => 'cie10', 'grupos' => $gruposTabla, 'agrupar' => $agruparTabla, 'solo' => 'grupos']; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; endif; ?>

  <?php $nuevo = ['titulo' => (entidadMotor('cie10')['titulo'] ?? ''), 'accion' => $_SERVER['REQUEST_URI'], 'campos' => (entidadMotor('cie10')['campos'] ?? []), 'errores' => $erroresNuevo, 'valores' => $valoresNuevo]; require __DIR__ . '/../app/vistas/componentes/formulario_nuevo.php'; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $diagnosticos ? 'hidden' : '' ?>>
    <?= $busqueda !== '' || $diagnosticos ? 'Ningún diagnóstico coincide con la búsqueda.' : 'Aún no hay diagnósticos registrados.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($diagnosticos) ?> de <?= $totalDiagnosticos ?></a>
  <?php endif; ?>

  <a class="bd__nueva" href="<?= URL_BASE ?>/cie10/nuevo.php"><?= icono('agregar') ?> Registrar diagnóstico</a>
</section>

<div class="menu-mas" role="menu" data-menu-mas hidden>
  <div class="menu-mas__lista">
    <a class="menu-mas__item" href="<?= URL_BASE ?>/cie10/nuevo.php"><?= icono('agregar') ?> Nuevo diagnóstico</a>
  </div>
</div>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
