<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$fecha = (string) ($_GET['fecha'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}

$erroresNuevo = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_nueva'] ?? '') === '1') {
    if (!csrfValido()) {
        $erroresNuevo = ['general' => 'La sesión expiró. Vuelve a enviar.'];
    } else {
        $datosCierre = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [];
        $fechaCierre = trim((string) ($datosCierre['fecha'] ?? ''));
        $fechaValidada = DateTime::createFromFormat('!Y-m-d', $fechaCierre);
        if (!$fechaValidada || $fechaValidada->format('Y-m-d') !== $fechaCierre) {
            $erroresNuevo = ['general' => 'Selecciona una fecha válida para el cierre.'];
        } else {
            $totalDeclarado = 0.0;
            foreach (['efectivo', 'tarjeta', 'transferencia', 'cheque', 'otro'] as $campoMoneda) {
                $valorMoneda = trim((string) ($datosCierre[$campoMoneda] ?? '0'));
                if ($valorMoneda === '') {
                    $valorMoneda = '0';
                }
                if (!is_numeric($valorMoneda) || (float) $valorMoneda < 0) {
                    $erroresNuevo = ['general' => 'Los importes del cierre deben ser números iguales o mayores a cero.'];
                    break;
                }
                $datosCierre[$campoMoneda] = (float) $valorMoneda;
                $totalDeclarado += (float) $valorMoneda;
            }
            $datosCierre['total'] = round($totalDeclarado, 2);

            if (!$erroresNuevo) {
                $consultaReal = db()->prepare(
                    "SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos
                      WHERE institucion_id = ? AND fecha = ? AND estado = 'pagado' AND tipo = 'ingreso'"
                );
                $consultaReal->execute([(int) institucionId(), $fechaCierre]);
                $totalReal = (float) $consultaReal->fetchColumn();
                $datosCierre['diferencia_total'] = round($totalReal - $datosCierre['total'], 2);
                $alta = motorCrear('finanzas_cierres', $datosCierre, [
                    '_diferencia_total_calculada' => $datosCierre['diferencia_total'],
                ]);
                if (!empty($alta['ok'])) {
                    $diferencia = (float) $datosCierre['diferencia_total'];
                    mensajeFlash('exito', $diferencia == 0.0
                        ? 'Cierre registrado y coincide con los movimientos del día.'
                        : 'Cierre registrado. Diferencia: $' . number_format($diferencia, 2, '.', ',') . '.');
                    redirigir('/finanzas/cierre.php?fecha=' . urlencode($fechaCierre));
                }
                $erroresNuevo = $alta['errores'] ?? ['general' => 'No se pudo guardar el cierre.'];
            }
        }
    }
}

$institucion = (int) institucionId();
$consulta = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN metodo = 'efectivo' THEN monto ELSE 0 END), 0) efectivo,
        COALESCE(SUM(CASE WHEN metodo = 'tarjeta' THEN monto ELSE 0 END), 0) tarjeta,
        COALESCE(SUM(CASE WHEN metodo = 'transferencia' THEN monto ELSE 0 END), 0) transferencia,
        COALESCE(SUM(CASE WHEN metodo = 'cheque' THEN monto ELSE 0 END), 0) cheque,
        COALESCE(SUM(CASE WHEN metodo = 'otro' THEN monto ELSE 0 END), 0) otro,
        COALESCE(SUM(monto), 0) total
       FROM finanzas_movimientos
      WHERE institucion_id = ? AND fecha = ? AND estado = 'pagado' AND tipo = 'ingreso'"
);
$consulta->execute([$institucion, $fecha]);
$totalesDia = $consulta->fetch(PDO::FETCH_ASSOC) ?: [];

$historial = motorListar('finanzas_cierres', ['limite' => 30, 'orden' => 'fecha', 'dir' => 'desc']);
$cierres   = $historial['filas'];

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');
$camposDef = (entidadMotor('finanzas_cierres')['campos'] ?? []);
$campos = array_filter(array_keys($camposDef), fn ($c) => !empty($camposDef[$c]['solo_lectura']) === false && $c !== 'id');

$datos = array_fill_keys($campos, '');
$datos['fecha'] = $fecha;
$datos['efectivo'] = (string) ($totalesDia['efectivo'] ?? 0);
$datos['tarjeta'] = (string) ($totalesDia['tarjeta'] ?? 0);
$datos['transferencia'] = (string) ($totalesDia['transferencia'] ?? 0);
$datos['cheque'] = (string) ($totalesDia['cheque'] ?? 0);
$datos['otro'] = (string) ($totalesDia['otro'] ?? 0);
$datos['total'] = (string) ($totalesDia['total'] ?? 0);

$tituloPagina   = 'Cierre de caja';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Cierre de caja']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Cierre de caja</h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if (!empty($erroresNuevo['general'])): ?>
  <p class="pagina__descripcion pagina__descripcion--error"><?= e($erroresNuevo['general']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" id="form-cierre">
  <?= campoCsrf() ?>
  <input type="hidden" name="accion_nueva" value="1">

  <section class="seccion">
    <h2 class="seccion__titulo"><?= icono('agregar') ?> Registrar cierre</h2>
    <div class="seccion__cuerpo">
      <div class="rejilla rejilla--5">
        <?php foreach ($campos as $nombre): ?>
          <?php
            $def = $camposDef[$nombre] ?? [];
            $texto = (string) ($def['texto'] ?? $nombre);
            $tipo = (string) ($def['tipo'] ?? 'texto');
            $valor = (string) ($datos[$nombre] ?? '');
            $requerido = !empty($def['req']);
            $error = (string) ($erroresNuevo[$nombre] ?? '');
            $opciones = $def['opciones'] ?? [];
          ?>
          <div class="campo<?= $error !== '' ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="<?= e($nombre) ?>"><?= e($texto) ?><?= $requerido ? ' *' : '' ?></label>
            <div class="campo__control">
              <?php if ($tipo === 'casilla'): ?>
                <input id="<?= e($nombre) ?>" type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
              <?php elseif ($tipo === 'opcion' && !empty($opciones)): ?>
                <select id="<?= e($nombre) ?>" name="datos[<?= e($nombre) ?>]">
                  <option value="">—</option>
                  <?php foreach ($opciones as $claveOpcion => $textoOpcion): ?>
                    <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($tipo === 'fecha'): ?>
                <input id="<?= e($nombre) ?>" type="date" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
              <?php elseif ($tipo === 'numero'): ?>
                <input id="<?= e($nombre) ?>" type="number" step="0.01" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
              <?php else: ?>
                <input id="<?= e($nombre) ?>" type="text" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= !empty($def['max']) ? ' maxlength="' . (int) $def['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
              <?php endif; ?>
            </div>
            <?php if ($error !== ''): ?><span class="campo__ayuda"><?= e($error) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <div class="seccion__acciones">
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/finanzas/cierre.php?fecha=<?= e($fecha) ?>">Cancelar</a>
    <button type="submit" class="boton boton--primario">Guardar cierre</button>
  </div>
</form>

<?php if ($cierres): ?>
  <section class="seccion">
    <h2 class="seccion__titulo"><?= icono('tabla') ?> Ultimo cierre</h2>
    <div class="seccion__cuerpo">
      <table class="bd-tabla">
        <thead><tr><th>Concepto</th><th>Monto</th><th>Diferencia</th></tr></thead>
        <tbody>
          <tr>
            <td>Cierre registrado</td>
            <td><?= e($dinero((float) ($cierres[0]['total'] ?? 0))) ?></td>
            <td><?= e($dinero((float) ($cierres[0]['diferencia_total'] ?? 0))) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($cierres): ?>
  <section class="seccion">
    <h2 class="seccion__titulo"><?= icono('tabla') ?> Cierres registrados</h2>
    <div class="seccion__cuerpo">
      <table class="bd-tabla">
        <thead><tr><th>Efectivo</th><th>Tarjeta</th><th>Transferencia</th><th>Cheque</th><th>Otro</th><th>Total</th><th>Notas</th></tr></thead>
        <tbody>
          <?php foreach ($cierres as $c): ?>
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

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>