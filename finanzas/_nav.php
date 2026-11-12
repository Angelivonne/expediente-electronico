<?php
/** Navegación compacta de Finanzas. */
$rutaFinanzas = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$finActual = basename($rutaFinanzas);
$finActual = [
    '' => 'index.php',
    'finanzas' => 'index.php',
    'nuevo.php' => 'index.php',
    'presupuesto_nuevo.php' => 'presupuestos.php',
    'recurrente_nuevo.php' => 'recurrentes.php',
    'factura_nueva.php' => 'facturacion.php',
][$finActual] ?? $finActual;
$finEnlaces = [
    ['index.php', '/finanzas/', 'Movimientos', 'lista'],
    ['cuentas.php', '/finanzas/cuentas.php', 'Cuentas', 'tarjeta'],
    ['cierre.php', '/finanzas/cierre.php', 'Cierre', 'documento'],
    ['conciliacion.php', '/finanzas/conciliacion.php', 'Conciliar', 'flujo'],
    ['presupuestos.php', '/finanzas/presupuestos.php', 'Presupuestos', 'grafica'],
    ['recurrentes.php', '/finanzas/recurrentes.php', 'Recurrentes', 'reloj'],
    ['reportes.php', '/finanzas/reportes.php', 'Reportes', 'barras'],
    ['facturacion.php', '/finanzas/facturacion.php', 'Facturación', 'documento'],
    ['fiscales.php', '/finanzas/fiscales.php', 'Fiscal', 'escudo'],
];
if (function_exists('puede') && puede('configurar')) {
    array_splice($finEnlaces, 1, 0, [['servicios.php', '/finanzas/servicios.php', 'Servicios', 'clinico']]);
}
?>
<nav class="finanzas__nav" aria-label="Finanzas">
  <div class="finanzas__nav-enlaces">
    <?php foreach ($finEnlaces as [$archivo, $url, $texto, $iconoFinanzas]): ?>
      <a href="<?= URL_BASE . $url ?>" title="<?= e($texto) ?>" aria-label="<?= e($texto) ?>"<?= $finActual === $archivo ? ' aria-current="page"' : '' ?>><?= icono($iconoFinanzas) ?><span><?= e($texto) ?></span></a>
    <?php endforeach; ?>
  </div>
</nav>
