<?php
/**
 * Medicamentos — vista del motor único.
 *
 * Misma tabla, mismos grupos y misma búsqueda que antes; la diferencia es que ya no tiene consulta,
 * armado de tabla ni endpoint propios: lee con `motorListar()` y pinta con `vista_tabla.php`.
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
        $altaNueva = motorCrear('medicamentos', $entradaNueva);
        if (!empty($altaNueva['ok'])) {
            mensajeFlash('exito', 'Registro agregado.');
            redirigir('/medicamentos/');
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

$lista             = motorListar('medicamentos', ['q' => $busqueda, 'limite' => $limite, 'orden' => $ordenTabla, 'dir' => $dirTabla, 'filtro' => $filtroTabla]);
$gruposTabla      = $agruparTabla !== '' ? motorAgrupar('medicamentos', $agruparTabla, ['q' => $busqueda, 'filtro' => $filtroTabla]) : [];
$medicamentos      = $lista['filas'];
$totalMedicamentos = $lista['total'];
$hayMas            = count($medicamentos) < $totalMedicamentos;
$opcionesEditor    = opcionesEditorMotor('medicamentos');

$tituloPagina   = 'Medicamentos';
$seccionActiva  = 'medicamentos';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Expediente'], ['texto' => 'Medicamentos']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista('medicamentos'),
    'filas'     => $medicamentos,
    'atributos' => ['data-entidad' => 'medicamentos', 'data-catalogo' => 'medicamentos', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
    'fila_atributos' => function (array $fila, int $i) use ($nuevoId): array {
        $atributos = ['data-id' => $fila['id'], 'data-medicamento' => $fila['id'], 'style' => '--i: ' . min($i, 20)];
        if ((int) $fila['id'] === $nuevoId) {
            $atributos['class'] = 'nuevo';
        }
        return $atributos;
    },
    // El nombre combina nombre y clave de compendio para mostrarse, pero al editar se guarda solo el nombre
    'celdas' => [
        'nombre' => function (array $columna, array $fila): string {
            $nombre   = trim((string) ($fila['nombre'] ?? ''));
            $compendio = trim((string) ($fila['clave_compendio'] ?? ''));
            $texto    = $compendio !== '' ? ($nombre !== '' ? $nombre . ' · ' . $compendio : $compendio) : $nombre;
            return '<td data-campo="nombre" data-valor="' . e($nombre) . '" title="' . e($texto) . '">'
                . '<a href="' . URL_BASE . '/medicamentos/ver.php?id=' . (int) $fila['id'] . '">' . e($texto) . '</a></td>';
        },
    ],
    'acciones' => fn (array $fila): string => '<td class="tabla__acciones"><a class="boton-icono" href="' . URL_BASE
        . '/medicamentos/nuevo.php?id=' . (int) $fila['id'] . '" title="Editar">' . icono('editar') . '</a></td>',
    'grupos'   => ['Identificación' => 4, 'Presentación' => 2, 'Vía' => 1, 'Laboratorio' => 1, 'Estado' => 1],
];
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Medicamentos') ?></h1>
  <p class="pagina__subtitulo"><?= e('Catálogo de medicamentos de la institución · NOM-024-SSA3-2012') ?></p>
</div>

<section class="bd" data-bd>
  <div class="bd__cabecera">
    <h2 class="bd__titulo"><?= e('Registro de medicamentos') ?></h2>

    <div class="bd__herramientas">
      <?php $herramientas = ['clave' => 'medicamentos', 'base' => URL_BASE . '/medicamentos/', 'ocultos' => array_filter(['q' => $busqueda, 'limite' => $limite]), 'orden' => $ordenTabla, 'dir' => $dirTabla, 'campo' => $campoTabla, 'valor' => $valorTabla, 'agrupar' => $agruparTabla, 'solo' => 'menu', 'exportar' => URL_BASE . '/api/motor/exportar.php?' . http_build_query(array_filter(['entidad' => 'medicamentos', 'q' => $busqueda, 'campo' => $campoTabla, 'valor' => $valorTabla, 'orden' => $ordenTabla, 'dir' => $dirTabla]))]; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; ?>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar (nombre, clave, presentación…)"><?= icono('buscar') ?></button>
      <?php if ($busqueda !== ''): ?>
        <p class="pagina__descripcion">
          Resultados para «<?= e($busqueda) ?>» · <a href="<?= URL_BASE ?>/medicamentos/"><?= e('Ver todos') ?></a>
        </p>
      <?php endif; ?>
      <button type="button" class="boton boton--primario bd__nuevo" data-menu-mas aria-haspopup="menu" title="Registrar medicamento"><?= icono('agregar') ?> Nuevo</button>
    </div>
  </div>

  <?php if ($gruposTabla): $herramientas = ['clave' => 'medicamentos', 'grupos' => $gruposTabla, 'agrupar' => $agruparTabla, 'solo' => 'grupos']; require __DIR__ . '/../app/vistas/componentes/herramientas_tabla.php'; endif; ?>

  <?php $nuevo = ['titulo' => (entidadMotor('medicamentos')['titulo'] ?? ''), 'accion' => $_SERVER['REQUEST_URI'], 'campos' => (entidadMotor('medicamentos')['campos'] ?? []), 'errores' => $erroresNuevo, 'valores' => $valoresNuevo]; require __DIR__ . '/../app/vistas/componentes/formulario_nuevo.php'; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $medicamentos ? 'hidden' : '' ?>>
    <?= $busqueda !== '' || $medicamentos ? 'Ningún medicamento coincide con la búsqueda.' : 'Aún no hay medicamentos registrados.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($medicamentos) ?> de <?= $totalMedicamentos ?></a>
  <?php endif; ?>

  <a class="bd__nueva" href="<?= URL_BASE ?>/medicamentos/nuevo.php"><?= icono('agregar') ?> Registrar medicamento</a>
</section>

<div class="menu-mas" role="menu" data-menu-mas hidden>
  <div class="menu-mas__lista">
    <a class="menu-mas__item" href="<?= URL_BASE ?>/medicamentos/nuevo.php"><?= icono('agregar') ?> Nuevo medicamento</a>
  </div>
</div>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
