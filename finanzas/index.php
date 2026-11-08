<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$busqueda = trim($_GET['q'] ?? '');
$limite   = min(500, max(50, (int) ($_GET['limite'] ?? 200)));

$lista       = motorListar('finanzas_movimientos', ['q' => $busqueda, 'limite' => $limite, 'orden' => 'fecha', 'dir' => 'desc']);
$movimientos = $lista['filas'];
$total       = $lista['total'];
$hayMas      = count($movimientos) < $total;

$institucion = (int) institucionId();
$desde = date('Y-m-01');
$hasta = date('Y-m-t');
$suma = function (string $sql) use ($institucion, $desde, $hasta): float {
    $consulta = db()->prepare($sql);
    $consulta->execute([$institucion, $desde, $hasta]);
    return (float) $consulta->fetchColumn();
};
$ingresos   = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'ingreso' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?");
$gastos     = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'gasto' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?");
$pendientes = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'ingreso' AND estado = 'pendiente' AND fecha BETWEEN ? AND ?");
$balance    = $ingresos - $gastos;
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');

$mesActual = $nombresMes[(int) date('n')] . ' ' . date('Y');

$tituloPagina   = 'Finanzas';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas']];

$opcionesEditor = opcionesEditorMotor('finanzas_movimientos');

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista('finanzas_movimientos'),
    'filas'     => $movimientos,
    'atributos' => ['data-entidad' => 'finanzas_movimientos', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
];
?>
<div class="pagina__cabecera finanzas__cabecera">
  <h1 class="pagina__titulo">Finanzas</h1>
  <time class="finanzas__fecha" datetime="<?= date('Y-m') ?>"><?= e(ucfirst($mesActual)) ?></time>
</div>

<?php require __DIR__ . '/_nav.php'; ?>
<div class="finanzas__accion">
  <a class="boton boton--primario" href="<?= URL_BASE ?>/finanzas/nuevo.php"><?= icono('agregar') ?> Nuevo movimiento</a>
  
</div>

<dl class="finanzas__resumen" aria-label="Resumen del mes">
  <div><dt>Ingresos registrados</dt><dd><?= e($dinero($ingresos)) ?>
</dd></div>
  <div><dt>Gastos registrados</dt><dd><?= e($dinero($gastos)) ?>
</dd></div>
  <div><dt>Balance registrado</dt><dd><?= e($dinero($balance)) ?>
</dd></div>
  <div><dt>Por cobrar</dt><dd><?= e($dinero($pendientes)) ?>
</dd></div>
  <div><dt>Movimientos encontrados</dt><dd><?= (int) $total ?>
</dd></div>
</dl>
<section class="bd" data-bd>
  <div class="bd__cabecera">
    <h2 class="bd__titulo">Movimientos registrados</h2>
    <div class="bd__herramientas">
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar movimientos" aria-label="Buscar movimientos"><?= icono('buscar') ?></button>
    </div>
  </div>
<?php if ($busqueda !== ''): ?>
    <p class="pagina__descripcion" role="status">Resultados para «<?= e($busqueda) ?>» · <a href="<?= URL_BASE ?>/finanzas/">Ver todos los movimientos</a></p>
  <?php endif; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $movimientos ? 'hidden' : '' ?>>
    <?= $busqueda !== '' ? 'No hay movimientos que coincidan con esa búsqueda.' : 'Todavía no hay movimientos. Registra el primer ingreso o gasto para empezar.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($movimientos) ?> de <?= (int) $total ?></a>
  <?php endif; ?>
</section>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>