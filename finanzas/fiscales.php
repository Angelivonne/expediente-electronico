<?php
/**
 * Resumen fiscal del mes ” estilo de hoja de perfil.
 *
 * Cálculo de pantalla sobre las mismas tablas del motor; no inventa comprobantes.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$fisPeriodo = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $fisPeriodo)) {
    $fisPeriodo = date('Y-m');
}
$fisDesde = $fisPeriodo . '-01';
$fisHasta = date('Y-m-t', strtotime($fisDesde));
$fisAnterior  = (new DateTimeImmutable($fisDesde))->modify('-1 month')->format('Y-m');
$fisSiguiente = (new DateTimeImmutable($fisDesde))->modify('+1 month')->format('Y-m');

$institucion = (int) institucionId();
$unaSuma = function (string $sql, array $parametros): float {
    $stmt = db()->prepare($sql);
    $stmt->execute($parametros);
    return (float) $stmt->fetchColumn();
};

$facturado = $unaSuma(
    "SELECT COALESCE(SUM(total), 0) FROM finanzas_facturas
      WHERE institucion_id = ? AND estado = 'emitida' AND fecha BETWEEN ? AND ?",
    [$institucion, $fisDesde, $fisHasta]
);
$facturasEmitidas = (int) $unaSuma(
    "SELECT COUNT(*) FROM finanzas_facturas
      WHERE institucion_id = ? AND estado = 'emitida' AND fecha BETWEEN ? AND ?",
    [$institucion, $fisDesde, $fisHasta]
);
$ivaTrasladado = round($facturado - ($facturado / 1.16), 2);
$baseGravable  = round($facturado - $ivaTrasladado, 2);

$gastosMes = $unaSuma(
    "SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos
      WHERE institucion_id = ? AND tipo = 'gasto' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?",
    [$institucion, $fisDesde, $fisHasta]
);
$ingresosCobrados = $unaSuma(
    "SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos
      WHERE institucion_id = ? AND tipo = 'ingreso' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?",
    [$institucion, $fisDesde, $fisHasta]
);

$utilidad = $baseGravable - $gastosMes;
$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fisTitulo = $nombresMes[(int) substr($fisPeriodo, 5, 2)] . ' ' . substr($fisPeriodo, 0, 4);

$tituloPagina   = 'Resumen fiscal';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Fiscal']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Resumen fiscal estimado') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas fiscales -->
<dl class="finanzas__resumen">
  <div><dt>Facturas emitidas</dt><dd><?= (int) $facturasEmitidas ?></dd></div>
  <div><dt>Total facturado (IVA inc.)</dt><dd><?= e($dinero($facturado)) ?></dd></div>
  <div><dt>Base gravable estimada</dt><dd><?= e($dinero($baseGravable)) ?></dd></div>
  <div><dt>IVA trasladado (16%)</dt><dd><?= e($dinero($ivaTrasladado)) ?></dd></div>
  <div><dt>Ingresos cobrados</dt><dd><?= e($dinero($ingresosCobrados)) ?></dd></div>
  <div><dt>Gastos del mes</dt><dd><?= e($dinero($gastosMes)) ?></dd></div>
  <div><dt>Utilidad estimada</dt><dd><?= e($dinero($utilidad)) ?></dd></div>
</dl>

<div class="finanzas__cuerpo finanzas__cuerpo--simple">

  <div class="finanzas__datos">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('grafica') ?> Resumen del periodo</h2>
        <div class="bd__herramientas" style="display:flex;gap:8px;align-items:center;">
          <a class="boton boton--sutil" href="?mes=<?= e($fisAnterior) ?>">‹ <?= e($nombresMes[(int) substr($fisAnterior, 5, 2)]) ?></a>
          <a class="boton boton--sutil" href="?mes=<?= e(date('Y-m')) ?>">Este mes</a>
          <a class="boton boton--sutil" href="?mes=<?= e($fisSiguiente) ?>"><?= e($nombresMes[(int) substr($fisSiguiente, 5, 2)]) ?> ›</a>
        </div>
      </div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Facturas emitidas</td><td><?= (int) $facturasEmitidas ?></td></tr>
            <tr><td>Total facturado (IVA incluido)</td><td><?= e($dinero($facturado)) ?></td></tr>
            <tr><td>Base gravable estimada</td><td><?= e($dinero($baseGravable)) ?></td></tr>
            <tr><td>IVA trasladado estimado (16%)</td><td><?= e($dinero($ivaTrasladado)) ?></td></tr>
            <tr><td>Ingresos cobrados (movimientos)</td><td><?= e($dinero($ingresosCobrados)) ?></td></tr>
            <tr><td>Gastos del mes (posible deducción)</td><td><?= e($dinero($gastosMes)) ?></td></tr>
            <tr><td><b>Utilidad estimada (base ˆ’ gastos)</b></td><td><b><?= e($dinero($utilidad)) ?></b></td></tr>
          </tbody>
        </table>
      </div>
      <p class="finanzas__nota">IVA acreditable sujeto al CFDI; esta estimación no sustituye la contabilidad oficial.</p>
    </section>
  </div>

</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>