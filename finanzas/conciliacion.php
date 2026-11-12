<?php
/**
 * Conciliación del cierre ” estilo de hoja de perfil.
 *
 * Compara el cierre registrado contra los movimientos reales del día.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$concFecha = (string) ($_GET['fecha'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $concFecha)) {
    $concFecha = date('Y-m-d');
}

$cierresDia = motorListar('finanzas_cierres', ['filtro' => ['fecha' => $concFecha], 'limite' => 50])['filas'];
$metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'otro' => 'Otro'];

$cierrePorMetodo = array_fill_keys(array_keys($metodos), 0.0);
foreach ($cierresDia as $c) {
    foreach ($metodos as $claveMetodo => $textoMetodo) {
        $cierrePorMetodo[$claveMetodo] += (float) ($c[$claveMetodo] ?? 0);
    }
}

$institucion = (int) institucionId();
$consulta = db()->prepare(
    "SELECT metodo, COALESCE(SUM(monto), 0) total
       FROM finanzas_movimientos
      WHERE institucion_id = ? AND fecha = ? AND estado = 'pagado'
   GROUP BY metodo"
);
$consulta->execute([$institucion, $concFecha]);
$realPorMetodo = array_fill_keys(array_keys($metodos), 0.0);
foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $realPorMetodo[$fila['metodo']] = (float) $fila['total'];
}

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$totalCierre = array_sum($cierrePorMetodo);
$totalReal = array_sum($realPorMetodo);
$hayCierre = (bool) $cierresDia;
$diferenciaTotal = $totalReal - $totalCierre;

$tituloPagina   = 'Conciliación del cierre';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Conciliación']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Conciliación del cierre') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas del día comparado -->
<dl class="finanzas__resumen">
  <div><dt>Cierre registrado</dt><dd><?= $hayCierre ? e($dinero($totalCierre)) : '”' ?></dd></div>
  <div><dt>Movimientos reales</dt><dd><?= e($dinero($totalReal)) ?></dd></div>
  <div><dt>Diferencia total</dt><dd><?= $hayCierre ? e($dinero($diferenciaTotal)) : '”' ?></dd></div>
  <div><dt>Estado</dt><dd><?= $hayCierre ? (e(abs($diferenciaTotal) < 0.01 ? 'Cuadra' : 'No cuadra')) : 'Sin cierre' ?></dd></div>
  <div><dt>Fecha</dt><dd><?= e($concFecha) ?></dd></div>
  <div><dt>Métodos</dt><dd><?= count($metodos) ?></dd></div>
</dl>

<div class="finanzas__cuerpo">

  <div class="finanzas__datos">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('grafica') ?> Día comparado</h2>
        <div class="bd__herramientas" style="display:flex;gap:8px;align-items:center;">
          <a class="boton boton--sutil" href="?fecha=<?= e(date('Y-m-d', strtotime($concFecha . ' -1 day'))) ?>">‹ Día anterior</a>
          <a class="boton boton--sutil" href="?fecha=<?= e(date('Y-m-d')) ?>">Hoy</a>
          <a class="boton boton--sutil" href="?fecha=<?= e(date('Y-m-d', strtotime($concFecha . ' +1 day'))) ?>">Día siguiente ›</a>
          <a class="boton boton--sutil" href="<?= URL_BASE ?>/finanzas/cierre.php?fecha=<?= e($concFecha) ?>">Ir al cierre</a>
        </div>
      </div>
      <?php if (!$hayCierre): ?>
        <p class="finanzas__descripcion">No hay cierre registrado para este día; solo se muestran los movimientos reales.</p>
      <?php endif; ?>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Método</th><th>Cierre registrado</th><th>Real del día</th><th>Diferencia</th></th></thead>
          <tbody>
            <?php foreach ($metodos as $claveMetodo => $textoMetodo): ?>
              <?php $diferencia = $realPorMetodo[$claveMetodo] - $cierrePorMetodo[$claveMetodo]; ?>
              <tr>
                <td><?= e($textoMetodo) ?></td>
                <td><?= $hayCierre ? e($dinero($cierrePorMetodo[$claveMetodo])) : '”' ?></td>
                <td><?= e($dinero($realPorMetodo[$claveMetodo])) ?></td>
                <td><?= $hayCierre ? e($dinero($diferencia)) : '”' ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td><b>Total</b></td>
              <td><b><?= $hayCierre ? e($dinero($totalCierre)) : '”' ?></b></td>
              <td><b><?= e($dinero($totalReal)) ?></b></td>
              <td><b><?= $hayCierre ? e($dinero($diferenciaTotal)) : '”' ?></b></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="finanzas__lado">
    <?php if ($hayCierre): ?>
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('documento') ?> Cierres registrados ese día</h2>
        <span class="finanzas__fuente-limpio"><?= count($cierresDia) ?> cierre(s)</span>
      </div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Efectivo</th><th>Tarjeta</th><th>Transferencia</th><th>Cheque</th><th>Otro</th><th>Total</th><th>Notas</th></tr></thead>
          <tbody>
            <?php foreach ($cierresDia as $c): ?>
              <tr>
                <td><?= e($dinero((float) $c['efectivo'])) ?></td>
                <td><?= e($dinero((float) $c['tarjeta'])) ?></td>
                <td><?= e($dinero((float) $c['transferencia'])) ?></td>
                <td><?= e($dinero((float) $c['cheque'])) ?></td>
                <td><?= e($dinero((float) $c['otro'])) ?></td>
                <td><?= e($dinero((float) $c['total'])) ?></td>
                <td><?= e((string) ($c['notas'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>