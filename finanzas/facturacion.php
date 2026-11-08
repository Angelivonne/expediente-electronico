<?php
/**
 * Facturación ” estilo de hoja de perfil.
 *
 * Control fiscal de las facturas de la clínica sobre el motor.
 * El timbrado lo realiza el PAC externo; aquí se registran folio, datos fiscales, total y estado.
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
        $alta = motorCrear('finanzas_facturas', is_array($_POST['datos'] ?? null) ? $_POST['datos'] : []);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Factura registrada.');
            redirigir('/finanzas/facturacion.php');
        }
        $erroresNuevo = $alta['errores'] ?? ['general' => 'No se pudo guardar la factura.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_cancelar_factura'] ?? '') === '1') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } elseif (!puede('configurar')) {
        mensajeFlash('error', 'Solo un administrador puede cancelar facturas.');
    } else {
        $facturaId = (int) ($_POST['factura_id'] ?? 0);
        $motivoCancelacion = trim((string) ($_POST['motivo'] ?? ''));
        $facturaCancelar = db()->prepare('SELECT id, folio, estado FROM finanzas_facturas WHERE id = ? AND institucion_id = ?');
        $facturaCancelar->execute([$facturaId, (int) institucionId()]);
        $facturaCancelar = $facturaCancelar->fetch(PDO::FETCH_ASSOC);
        if (!$facturaCancelar) {
            mensajeFlash('error', 'La factura no existe.');
        } elseif ($facturaCancelar['estado'] === 'cancelada') {
            mensajeFlash('error', 'La factura ya está cancelada.');
        } elseif ($motivoCancelacion === '') {
            mensajeFlash('error', 'Escribe el motivo de la cancelación.');
        } else {
            db()->prepare("UPDATE finanzas_facturas SET estado = 'cancelada', cancelada_en = NOW(), motivo_cancelacion = ? WHERE id = ?")
                ->execute([mb_substr($motivoCancelacion, 0, 255), $facturaId]);
            registrarBitacora('finanzas_facturas', $facturaId, 'cancelar', 'estado', (string) $facturaCancelar['estado'], 'cancelada');
            mensajeFlash('exito', 'Factura ' . $facturaCancelar['folio'] . ' cancelada.');
        }
    }
    redirigir('/finanzas/facturacion.php');
}

$listaFacturas = motorListar('finanzas_facturas', ['limite' => 200, 'orden' => 'fecha', 'dir' => 'desc']);
$facturas = $listaFacturas['filas'];
$totalFacturas = $listaFacturas['total'];

$institucion = (int) institucionId();
$desdeMes = date('Y-m-01');
$hastaMes = date('Y-m-t');
$consulta = db()->prepare(
    "SELECT estado, COUNT(*) cuantas, COALESCE(SUM(total), 0) monto
       FROM finanzas_facturas
      WHERE institucion_id = ? AND fecha BETWEEN ? AND ?
   GROUP BY estado"
);
$consulta->execute([$institucion, $desdeMes, $hastaMes]);
$resumen = [];
foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $resumen[$fila['estado']] = ['cuantas' => (int) $fila['cuantas'], 'monto' => (float) $fila['monto']];
}
$emitidas = $resumen['emitida'] ?? ['cuantas' => 0, 'monto' => 0.0];

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$exportar = URL_BASE . '/api/motor/exportar.php?' . http_build_query(array_filter(['entidad' => 'finanzas_facturas', 'orden' => 'fecha', 'dir' => 'desc']));

$tituloPagina   = 'Facturación';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Facturación']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista('finanzas_facturas'),
    'filas'     => $facturas,
    'atributos' => ['data-entidad' => 'finanzas_facturas', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
];
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Facturación') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas del mes -->
<dl class="finanzas__resumen">
  <div><dt>Facturas emitidas</dt><dd><?= (int) ($resumen['emitida']['cuantas'] ?? 0) ?></dd></div>
  <div><dt>Total facturado</dt><dd><?= e($dinero((float) ($resumen['emitida']['monto'] ?? 0))) ?></dd></div>
  <div><dt>Borradores</dt><dd><?= (int) ($resumen['borrador']['cuantas'] ?? 0) ?></dd></div>
  <div><dt>Canceladas</dt><dd><?= (int) ($resumen['cancelada']['cuantas'] ?? 0) ?></dd></div>
  <div><dt>Total registros</dt><dd><?= (int) $totalFacturas ?></dd></div>
  <div><dt>Mes</dt><dd><?= e(date('m/Y')) ?></dd></div>
</dl>

<div class="finanzas__cuerpo">

  <div class="finanzas__datos">

    <!-- Resumen del mes -->
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('grafica') ?> Resumen del mes</h2>
        
      </div>
      <div class="bd__herramientas"><a class="boton boton--sutil" href="<?= e($exportar) ?>">Exportar CSV</a></div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Estado</th><th>Facturas</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Emitidas</td><td><?= (int) ($resumen['emitida']['cuantas'] ?? 0) ?></td><td><?= e($dinero((float) ($resumen['emitida']['monto'] ?? 0))) ?></td></tr>
            <tr><td>Borradores</td><td><?= (int) ($resumen['borrador']['cuantas'] ?? 0) ?></td><td><?= e($dinero((float) ($resumen['borrador']['monto'] ?? 0))) ?></td></tr>
            <tr><td>Canceladas</td><td><?= (int) ($resumen['cancelada']['cuantas'] ?? 0) ?></td><td><?= e($dinero((float) ($resumen['cancelada']['monto'] ?? 0))) ?></td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Registrar factura -->
    <a class="bd__nueva" href="<?= URL_BASE ?>/finanzas/factura_nueva.php">Nueva factura</a>

  </div>

  <div class="finanzas__lado">

    <!-- Facturas registradas -->
    <section class="finanzas__seccion-limpio" data-bd>
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('documento') ?> Facturas registradas</h2>
        <span class="bd__contador"><?= (int) $totalFacturas ?></span>
      </div>
      <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>
      <p class="bd__vacio" data-bd-vacio <?= $facturas ? 'hidden' : '' ?>>Aún no hay facturas registradas.</p>
    </section>

    <!-- Movimientos ligados a factura -->
    <?php
    $consultaMov = db()->prepare(
        "SELECT m.id, m.fecha, m.concepto, m.monto, m.estado, f.folio
           FROM finanzas_movimientos m
           JOIN finanzas_facturas f ON f.id = m.factura_id
          WHERE m.institucion_id = ?
       ORDER BY m.fecha DESC, m.id DESC
          LIMIT 50"
    );
    $consultaMov->execute([$institucion]);
    $movimientosLigados = $consultaMov->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('enlace') ?> Movimientos ligados a factura</h2>
        <span class="finanzas__fuente-limpio"><?= count($movimientosLigados) ?> movimiento(s)</span>
      </div>
      <div class="finanzas__plano">
        <table class="bd-tabla">
          <thead><tr><th>Factura</th><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Estado</th></tr></thead>
          <tbody>
            <?php if (!$movimientosLigados): ?>
              <tr><td colspan="5">Sin movimientos ligados a facturas todavía.</td></tr>
            <?php endif; ?>
            <?php foreach ($movimientosLigados as $mov): ?>
              <tr>
                <td><?= e((string) $mov['folio']) ?></td>
                <td><?= e((string) $mov['fecha']) ?></td>
                <td><?= e((string) $mov['concepto']) ?></td>
                <td><?= e($dinero((float) $mov['monto'])) ?></td>
                <td><?= e((string) $mov['estado']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Cancelar factura (administrador) -->
    <?php if (puede('configurar')): ?>
    <?php
    $facturasCancelables = db()->prepare("SELECT id, folio, total, estado FROM finanzas_facturas WHERE institucion_id = ? AND estado <> 'cancelada' ORDER BY fecha DESC, id DESC LIMIT 200");
    $facturasCancelables->execute([(int) institucionId()]);
    $facturasCancelables = $facturasCancelables->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('papelera') ?> Cancelar factura</h2>
        <span class="finanzas__fuente-limpio">Solo administrador · motivo obligatorio</span>
      </div>
      <?php if (!$facturasCancelables): ?>
        <p class="finanzas__descripcion">No hay facturas por cancelar.</p>
      <?php else: ?>
        <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="nuevo-registro__form">
          <?= campoCsrf() ?>
          <input type="hidden" name="accion_cancelar_factura" value="1">
          <div class="nuevo-registro__campos">
            <label class="nuevo-registro__campo">
              <span>Factura *</span>
              <select name="factura_id" required>
                <option value="">”</option>
                <?php foreach ($facturasCancelables as $facturaCancelable): ?>
                  <option value="<?= (int) $facturaCancelable['id'] ?>"><?= e($facturaCancelable['folio'] . ' · $' . number_format((float) $facturaCancelable['total'], 2, '.', ',') . ' · ' . $facturaCancelable['estado']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="nuevo-registro__campo">
              <span>Motivo *</span>
              <input name="motivo" maxlength="255" required placeholder="Motivo de la cancelación">
            </label>
          </div>
          <div class="nuevo-registro__acciones">
            <button type="submit" class="boton boton--peligro">Cancelar factura</button>
          </div>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

  </div>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>








