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
    ['index.php', '/finanzas/', 'Movimientos'],
    ['cuentas.php', '/finanzas/cuentas.php', 'Cuentas'],
    ['cierre.php', '/finanzas/cierre.php', 'Cierre'],
    ['conciliacion.php', '/finanzas/conciliacion.php', 'Conciliación'],
    ['presupuestos.php', '/finanzas/presupuestos.php', 'Presupuestos'],
    ['recurrentes.php', '/finanzas/recurrentes.php', 'Recurrentes'],
    ['reportes.php', '/finanzas/reportes.php', 'Reportes'],
    ['facturacion.php', '/finanzas/facturacion.php', 'Facturación'],
    ['fiscales.php', '/finanzas/fiscales.php', 'Fiscal'],
];
if (function_exists('puede') && puede('configurar')) {
    array_splice($finEnlaces, 1, 0, [['servicios.php', '/finanzas/servicios.php', 'Servicios']]);
}
?>
<nav class="finanzas__nav" aria-label="Finanzas">
  <div class="finanzas__nav-enlaces">
    <?php foreach ($finEnlaces as [$archivo, $url, $texto]): ?>
      <a href="<?= URL_BASE . $url ?>"<?= $finActual === $archivo ? ' aria-current="page"' : '' ?>><?= e($texto) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
