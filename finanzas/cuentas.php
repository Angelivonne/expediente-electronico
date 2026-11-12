<?php
/**
 * Cuentas por cobrar y por pagar ” estilo de hoja de perfil.
 *
 * Movimientos pendientes del motor, con vencimiento.
 * No crea tablas nuevas: usa la fecha de vencimiento de finanzas_movimientos.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$pendientes = motorListar('finanzas_movimientos', [
    'filtro'  => ['estado' => 'pendiente'],
    'limite'  => 500,
    'orden'   => 'vence_en',
    'dir'      => 'asc',
])['filas'];

$porCobrar = array_filter($pendientes, fn ($m) => $m['tipo'] === 'ingreso');
$porPagar  = array_filter($pendientes, fn ($m) => $m['tipo'] === 'gasto');
$suma = fn (array $filas): float => array_sum(array_map(fn ($m) => (float) $m['monto'], $filas));
$totalCobrar = $suma($porCobrar);
$totalPagar  = $suma($porPagar);

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$hoy = strtotime(date('Y-m-d'));
$situacion = function (array $m) use ($hoy): string {
    if (empty($m['vence_en'])) {
        return 'Sin fecha de vencimiento';
    }
    $dias = (int) floor(($hoy - strtotime($m['vence_en'])) / 86400);
    if ($dias > 0) {
        return 'Vencida hace ' . $dias . ' día' . ($dias === 1 ? '' : 's');
    }
    if ($dias === 0) {
        return 'Vence hoy';
    }
    return 'Vence en ' . abs($dias) . ' día' . (abs($dias) === 1 ? '' : 's');
};

$tituloPagina   = 'Cuentas por cobrar y por pagar';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Cuentas']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$pintarTabla = function (array $filas) use ($situacion, $dinero): void {
    ?>
    <table class="bd-tabla">
      <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th>Situación</th><th>Monto</th></tr></thead>
      <tbody>
        <?php if (!$filas): ?><tr><td colspan="5">Sin cuentas pendientes.</td></tr><?php endif; ?>
        <?php foreach ($filas as $m): ?>
          <tr>
            <td><?= e((string) $m['fecha']) ?></td>
            <td><?= e((string) $m['concepto']) ?></td>
            <td><?= e((string) ($m['vence_en'] ?? '”')) ?></td>
            <td><?= e($situacion($m)) ?></td>
            <td><?= e($dinero((float) $m['monto'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Cuentas por cobrar y por pagar') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas -->
<dl class="finanzas__resumen">
  <div><dt>Por cobrar</dt><dd><?= e($dinero($totalCobrar)) ?></dd></div>
  <div><dt>Por pagar</dt><dd><?= e($dinero($totalPagar)) ?></dd></div>
  <div><dt>Balance neto</dt><dd><?= e($dinero($totalCobrar - $totalPagar)) ?></dd></div>
  <div><dt>Pendientes (cobrar)</dt><dd><?= count($porCobrar) ?> movimiento(s)</dd></div>
  <div><dt>Pendientes (pagar)</dt><dd><?= count($porPagar) ?> movimiento(s)</dd></div>
  <div><dt>Total pendientes</dt><dd><?= count($pendientes) ?> movimiento(s)</dd></div>
</dl>

<!-- Cuerpo: por cobrar a la izquierda, por pagar a la derecha -->
<div class="finanzas__cuerpo">

  <div class="finanzas__datos">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('usuarios') ?> Por cobrar (pacientes)</h2>
        <span class="finanzas__fuente-limpio"><?= e($dinero($totalCobrar)) ?> · <?= count($porCobrar) ?> movimiento(s)</span>
      </div>
      <div class="finanzas__plano"><?php $pintarTabla($porCobrar); ?></div>
    </section>
  </div>

  <div class="finanzas__lado">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('corazon') ?> Por pagar (proveedores)</h2>
        <span class="finanzas__fuente-limpio"><?= e($dinero($totalPagar)) ?> · <?= count($porPagar) ?> movimiento(s)</span>
      </div>
      <div class="finanzas__plano"><?php $pintarTabla($porPagar); ?></div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>