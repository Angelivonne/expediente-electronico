<?php
/**
 * Reportes de finanzas ” estilo de hoja de perfil.
 *
 * Mismo motor, misma tabla, solo lectura y agregados de pantalla.
 * Filtros por mes y tipo; exportación con el endpoint del motor (CSV).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$mes = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));
$anterior = (new DateTimeImmutable($desde))->modify('-1 month')->format('Y-m');
$siguiente = (new DateTimeImmutable($desde))->modify('+1 month')->format('Y-m');

$tipo = (string) ($_GET['tipo'] ?? '');
if (!in_array($tipo, ['ingreso', 'gasto'], true)) {
    $tipo = '';
}

$institucion = (int) institucionId();
$condiciones = ["institucion_id = ?", "estado <> 'cancelado'", "fecha BETWEEN ? AND ?"];
$parametros  = [$institucion, $desde, $hasta];
if ($tipo !== '') {
    $condiciones[] = 'tipo = ?';
    $parametros[] = $tipo;
}
$where = implode(' AND ', $condiciones);

$consulta = function (string $sql) use ($where, $parametros): array {
    $stmt = db()->prepare(str_replace('{donde}', 'WHERE ' . $where, $sql));
    $stmt->execute($parametros);
    return $stmt->fetchAll();
};

$porConcepto  = $consulta('SELECT tipo, concepto, COUNT(*) registros, SUM(monto) total FROM finanzas_movimientos {donde} GROUP BY tipo, concepto ORDER BY total DESC');
$porMetodo    = $consulta('SELECT metodo, COUNT(*) registros, SUM(monto) total FROM finanzas_movimientos {donde} GROUP BY metodo ORDER BY total DESC');
$porEstablecimiento = $consulta("SELECT COALESCE(e.nombre, 'Sin establecimiento') establecimiento, COUNT(*) registros, SUM(m.monto) total
                                   FROM finanzas_movimientos m LEFT JOIN catalogo_establecimientos e ON e.id = m.establecimiento_id
                                  WHERE m.institucion_id = ? AND m.estado <> 'cancelado' AND m.fecha BETWEEN ? AND ?
                               GROUP BY e.nombre ORDER BY total DESC");
$porProfesional = $consulta("SELECT COALESCE(u.nombre, 'Sin profesional') profesional, COUNT(*) registros, SUM(m.monto) total
                               FROM finanzas_movimientos m LEFT JOIN usuarios u ON u.id = m.profesional_id
                              WHERE m.institucion_id = ? AND m.estado <> 'cancelado' AND m.fecha BETWEEN ? AND ?
                           GROUP BY u.nombre ORDER BY total DESC");
$totales     = $consulta("SELECT COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) ingresos, COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END), 0) gastos FROM finanzas_movimientos {donde}");

$ingresos = (float) ($totales[0]['ingresos'] ?? 0);
$gastos   = (float) ($totales[0]['gastos'] ?? 0);
$balance  = $ingresos - $gastos;
$dinero   = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$tituloMes  = $nombresMes[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
$enlace = fn (array $cambios): string => '?' . http_build_query(array_filter(array_merge(['mes' => $mes, 'tipo' => $tipo], $cambios)));
$exportar = URL_BASE . '/api/motor/exportar.php?' . http_build_query(array_filter(['entidad' => 'finanzas_movimientos', 'orden' => 'fecha', 'dir' => 'desc']));

$tituloPagina   = 'Reportes de finanzas';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Reportes']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Reportes de finanzas') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas del periodo -->
<dl class="finanzas__resumen">
  <div><dt>Ingresos</dt><dd><?= e($dinero($ingresos)) ?></dd></div>
  <div><dt>Gastos</dt><dd><?= e($dinero($gastos)) ?></dd></div>
  <div><dt>Balance</dt><dd><?= e($dinero($balance)) ?></dd></div>
  <div><dt>Registros</dt><dd><?= count($porConcepto) ?> concepto(s)</dd></div>
  <div><dt>Periodo</dt><dd><?= e($tituloMes) ?></dd></div>
  <div><dt>Tipo</dt><dd><?= $tipo ? (e($tipo === 'ingreso' ? 'Solo ingresos' : 'Solo gastos')) : 'Todos' ?></dd></div>
</dl>

<!-- Totales del mes -->
<section class="finanzas__seccion-limpio">
  <div class="finanzas__titulo-fila-limpio">
    <h2 class="finanzas__titulo-limpio"><?= icono('grafica') ?> Totales del periodo</h2>
    <div class="bd__herramientas" style="display:flex;gap:8px;align-items:center;">
      <a class="boton boton--sutil" href="<?= e($enlace(['mes' => $anterior])) ?>">‹ <?= e($nombresMes[(int) substr($anterior, 5, 2)]) ?></a>
      <a class="boton boton--sutil" href="<?= e($enlace(['mes' => date('Y-m')])) ?>">Este mes</a>
      <a class="boton boton--sutil" href="<?= e($enlace(['mes' => $siguiente])) ?>"><?= e($nombresMes[(int) substr($siguiente, 5, 2)]) ?> ›</a>
      <a class="boton boton--sutil" href="<?= e($exportar) ?>" title="Descargar CSV con el mismo motor">Exportar CSV</a>
    </div>
  </div>
  <div class="bd__herramientas" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
    <a class="boton boton--sutil" href="<?= e($enlace(['tipo' => ''])) ?>"<?= $tipo === '' ? ' aria-current="true"' : '' ?>>Todos</a>
    <a class="boton boton--sutil" href="<?= e($enlace(['tipo' => 'ingreso'])) ?>"<?= $tipo === 'ingreso' ? ' aria-current="true"' : '' ?>>Solo ingresos</a>
    <a class="boton boton--sutil" href="<?= e($enlace(['tipo' => 'gasto'])) ?>"<?= $tipo === 'gasto' ? ' aria-current="true"' : '' ?>>Solo gastos</a>
  </div>
  <div class="finanzas__plano">
    <table class="bd-tabla">
      <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
      <tbody>
        <tr><td>Ingresos</td><td><?= e($dinero($ingresos)) ?></td></tr>
        <tr><td>Gastos</td><td><?= e($dinero($gastos)) ?></td></tr>
        <tr><td><b>Balance</b></td><td><b><?= e($dinero($balance)) ?></b></td></tr>
      </tbody>
    </table>
  </div>
</section>

<?php
$desgloses = [
    'concepto'      => ['titulo' => 'Por concepto',   'filas' => $porConcepto,   'cols' => ['tipo' => 'Tipo', 'concepto' => 'Concepto', 'registros' => 'Registros', 'total' => 'Total'], 'vacio' => 'Sin movimientos en el periodo.'],
    'profesional'   => ['titulo' => 'Por profesional', 'filas' => $porProfesional, 'cols' => ['profesional' => 'Profesional', 'registros' => 'Registros', 'total' => 'Total'], 'vacio' => 'Sin movimientos en el periodo.'],
    'establecimiento' => ['titulo' => 'Por establecimiento', 'filas' => $porEstablecimiento, 'cols' => ['establecimiento' => 'Establecimiento', 'registros' => 'Registros', 'total' => 'Total'], 'vacio' => 'Sin movimientos en el periodo.'],
    'metodo'        => ['titulo' => 'Por método de pago', 'filas' => $porMetodo,   'cols' => ['metodo' => 'Método', 'registros' => 'Registros', 'total' => 'Total'], 'vacio' => 'Sin movimientos en el periodo.'],
];
$metodosMap = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'otro' => 'Otro'];

foreach ($desgloses as $clave => $d):
?>
<section class="finanzas__seccion-limpio">
  <div class="finanzas__titulo-fila-limpio">
    <h2 class="finanzas__titulo-limpio"><?= e($d['titulo']) ?></h2>
    <span class="bd__contador"><?= count($d['filas']) ?></span>
  </div>
  <div class="finanzas__plano">
    <table class="bd-tabla">
      <thead><tr><?php foreach ($d['cols'] as $colTitulo): ?><th><?= e($colTitulo) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php if (!$d['filas']): ?>
          <tr><td colspan="<?= count($d['cols']) ?>"><?= e($d['vacio']) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($d['filas'] as $fila): ?>
          <tr>
            <?php foreach ($d['cols'] as $campo => $colTitulo): ?>
              <?php $valor = $fila[$campo] ?? ''; ?>
              <?php if ($campo === 'tipo'): ?>
                <td><?= e($valor === 'ingreso' ? 'Ingreso' : 'Gasto') ?></td>
              <?php elseif ($campo === 'metodo'): ?>
                <td><?= e($metodosMap[$valor] ?? $valor) ?></td>
              <?php elseif ($campo === 'total' || $campo === 'monto'): ?>
                <td><?= e($dinero((float) $valor)) ?></td>
              <?php elseif ($campo === 'registros'): ?>
                <td><?= (int) $valor ?></td>
              <?php else: ?>
                <td><?= e((string) $valor) ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endforeach; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>








