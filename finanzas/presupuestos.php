<?php
/**
 * Presupuestos mensuales ” estilo de hoja de perfil.
 *
 * Presupuesto contra real, sobre el motor (finanzas_presupuestos).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$erroresNuevo = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_nueva'] ?? '') === '1') {
    if (!csrfValido()) {
        $erroresNuevo = ['general' => 'La sesión expiró. Vuelve a enviar el formulario.'];
    } else {
        $alta = motorCrear('finanzas_presupuestos', is_array($_POST['datos'] ?? null) ? $_POST['datos'] : []);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Presupuesto registrado.');
            redirigir('/finanzas/presupuestos.php');
        }
        $erroresNuevo = $alta['errores'] ?? ['general' => 'No se pudo guardar el presupuesto.'];
    }
}

$presPeriodo = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $presPeriodo)) {
    $presPeriodo = date('Y-m');
}
$presAnio = (int) substr($presPeriodo, 0, 4);
$presMes  = (int) substr($presPeriodo, 5, 2);
$presDesde = $presPeriodo . '-01';
$presHasta = date('Y-m-t', strtotime($presDesde));
$presAnterior  = (new DateTimeImmutable($presDesde))->modify('-1 month')->format('Y-m');
$presSiguiente = (new DateTimeImmutable($presDesde))->modify('+1 month')->format('Y-m');

$presupuestos = array_values(array_filter(
    motorListar('finanzas_presupuestos', ['limite' => 500])['filas'],
    fn ($p) => (int) $p['anio'] === $presAnio && (int) $p['mes'] === $presMes
));

$institucion = (int) institucionId();
$consulta = db()->prepare(
    "SELECT tipo, concepto, COALESCE(SUM(monto), 0) total
       FROM finanzas_movimientos
      WHERE institucion_id = ? AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?
   GROUP BY tipo, concepto"
);
$consulta->execute([$institucion, $presDesde, $presHasta]);
$realesPor = [];
$realesTipo = ['ingreso' => 0.0, 'gasto' => 0.0];
foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $realesPor[$fila['tipo'] . '|' . $fila['concepto']] = (float) $fila['total'];
    $realesTipo[$fila['tipo']] = ($realesTipo[$fila['tipo']] ?? 0) + (float) $fila['total'];
}

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$presTitulo = $nombresMes[$presMes] . ' ' . $presAnio;

$tituloPagina   = 'Presupuestos';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Presupuestos']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$valores = ['anio' => (string) $presAnio, 'mes' => (string) $presMes];
$presupuestoIngreso = array_sum(array_map(fn ($p) => $p['tipo'] === 'ingreso' ? (float) $p['monto'] : 0, $presupuestos));
$presupuestoGasto   = array_sum(array_map(fn ($p) => $p['tipo'] === 'gasto' ? (float) $p['monto'] : 0, $presupuestos));
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Presupuestos') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas del mes -->
<dl class="finanzas__resumen">
  <div><dt>Presupuesto ingresos</dt><dd><?= e($dinero($presupuestoIngreso)) ?></dd></div>
  <div><dt>Presupuesto gastos</dt><dd><?= e($dinero($presupuestoGasto)) ?></dd></div>
  <div><dt>Real ingresos</dt><dd><?= e($dinero($realesTipo['ingreso'] ?? 0)) ?></dd></div>
  <div><dt>Real gastos</dt><dd><?= e($dinero($realesTipo['gasto'] ?? 0)) ?></dd></div>
  <div><dt>Diferencia ingresos</dt><dd><?= e($dinero($presupuestoIngreso - ($realesTipo['ingreso'] ?? 0))) ?></dd></div>
  <div><dt>Diferencia gastos</dt><dd><?= e($dinero($presupuestoGasto - ($realesTipo['gasto'] ?? 0))) ?></dd></div>
</dl>

<?php
$presupuestosIngreso = array_filter($presupuestos, fn ($p) => $p['tipo'] === 'ingreso');
$presupuestosGasto   = array_filter($presupuestos, fn ($p) => $p['tipo'] === 'gasto');
?>
<div class="finanzas__cuerpo">

  <div class="finanzas__datos">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('grafica') ?> Presupuestos de ingresos</h2>
        <span class="finanzas__fuente-limpio"><?= count($presupuestosIngreso) ?> concepto(s)</span>
      </div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Concepto</th><th>Presupuesto</th><th>Real</th><th>Diferencia</th></tr></thead>
          <tbody>
            <?php if (!$presupuestosIngreso): ?>
              <tr><td colspan="4">Sin presupuestos de ingresos para este mes.</td></tr>
            <?php endif; ?>
            <?php foreach ($presupuestosIngreso as $p): ?>
              <?php $real = $realesPor[$p['tipo'] . '|' . $p['concepto']] ?? ($realesTipo[$p['tipo']] ?? 0); $monto = (float) $p['monto']; ?>
              <tr>
                <td><?= e($p['concepto'] !== '' ? $p['concepto'] : 'Total del tipo') ?></td>
                <td><?= e($dinero($monto)) ?></td>
                <td><?= e($dinero($real)) ?></td>
                <td><?= e($dinero($monto - $real)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="finanzas__lado">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('corazon') ?> Presupuestos de gastos</h2>
        <span class="finanzas__fuente-limpio"><?= count($presupuestosGasto) ?> concepto(s)</span>
      </div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Concepto</th><th>Presupuesto</th><th>Real</th><th>Diferencia</th></tr></thead>
          <tbody>
            <?php if (!$presupuestosGasto): ?>
              <tr><td colspan="4">Sin presupuestos de gastos para este mes.</td></tr>
            <?php endif; ?>
            <?php foreach ($presupuestosGasto as $p): ?>
              <?php $real = $realesPor[$p['tipo'] . '|' . $p['concepto']] ?? ($realesTipo[$p['tipo']] ?? 0); $monto = (float) $p['monto']; ?>
              <tr>
                <td><?= e($p['concepto'] !== '' ? $p['concepto'] : 'Total del tipo') ?></td>
                <td><?= e($dinero($monto)) ?></td>
                <td><?= e($dinero($real)) ?></td>
                <td><?= e($dinero($monto - $real)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Registrar presupuesto -->
    <a class="bd__nueva" href="<?= URL_BASE ?>/finanzas/presupuesto_nuevo.php">Nuevo presupuesto</a>
  </div>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>