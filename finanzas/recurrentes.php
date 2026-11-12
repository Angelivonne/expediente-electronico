<?php
/**
 * Ingresos y gastos recurrentes ” estilo de hoja de perfil.
 *
 * Se registran una vez y se generan como movimientos del mes.
 * Mismo motor: entidad finanzas_recurrentes y movimientos en finanzas_movimientos.
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
        $alta = motorCrear('finanzas_recurrentes', is_array($_POST['datos'] ?? null) ? $_POST['datos'] : []);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Recurrente registrado.');
            redirigir('/finanzas/recurrentes.php');
        }
        $erroresNuevo = $alta['errores'] ?? ['general' => 'No se pudo guardar el recurrente.'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_generar'] ?? '') === '1') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } else {
        $generados = 0;
        $omitidos  = 0;
        $anioGen   = (int) date('Y');
        $mesGen    = (int) date('n');
        $diasMes   = (int) date('t');
        foreach (motorListar('finanzas_recurrentes', ['limite' => 200])['filas'] as $rec) {
            if (empty($rec['activo'])) {
                continue;
            }
            $dia   = min(max(1, (int) $rec['dia_del_mes']), $diasMes);
            $fecha = sprintf('%04d-%02d-%02d', $anioGen, $mesGen, $dia);
            $ref   = 'REC-' . (int) $rec['id'] . '-' . date('Y-m');
            $existe = motorListar('finanzas_movimientos', ['filtro' => ['referencia' => $ref], 'limite' => 1])['total'];
            if ($existe) {
                $omitidos++;
                continue;
            }
            $altaMov = motorCrear('finanzas_movimientos', [
                'tipo'        => $rec['tipo'],
                'fecha'       => $fecha,
                'concepto'    => $rec['concepto'],
                'descripcion' => 'Generado de recurrente',
                'monto'       => $rec['monto'],
                'metodo'      => $rec['metodo'],
                'estado'      => 'pagado',
                'referencia'  => $ref,
            ]);
            if (!empty($altaMov['ok'])) {
                $generados++;
            }
        }
        mensajeFlash('exito', 'Movimientos generados: ' . $generados . ' · ya existentes: ' . $omitidos . '.');
    }
    redirigir('/finanzas/recurrentes.php');
}

$recurrentes = motorListar('finanzas_recurrentes', ['limite' => 200])['filas'];
$movimientosMes = db()->prepare(
    "SELECT COUNT(*) FROM finanzas_movimientos
      WHERE institucion_id = ? AND DATE_FORMAT(fecha, '%Y-%m') = ? AND referencia LIKE 'REC-%'"
);
$movimientosMes->execute([(int) institucionId(), date('Y-m')]);
$generadosMes = (int) $movimientosMes->fetchColumn();

$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');

$totalRecurrentes = array_sum(array_map(fn ($r) => (float) $r['monto'], $recurrentes));
$activos = array_filter($recurrentes, fn ($r) => !empty($r['activo']));
$inactivos = array_filter($recurrentes, fn ($r) => empty($r['activo']));

$tituloPagina   = 'Ingresos y gastos recurrentes';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Recurrentes']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => columnasVista('finanzas_recurrentes'),
    'filas'     => $recurrentes,
    'atributos' => ['data-entidad' => 'finanzas_recurrentes', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
];
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Ingresos y gastos recurrentes') ?></h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<!-- Métricas -->
<dl class="finanzas__resumen">
  <div><dt>Total recurrentes</dt><dd><?= e($dinero($totalRecurrentes)) ?></dd></div>
  <div><dt>Activos</dt><dd><?= count($activos) ?> concepto(s)</dd></div>
  <div><dt>Inactivos</dt><dd><?= count($inactivos) ?> concepto(s)</dd></div>
  <div><dt>Generados este mes</dt><dd><?= $generadosMes ?> movimiento(s)</dd></div>
  <div><dt>Mes actual</dt><dd><?= e(date('m/Y')) ?></dd></div>
  <div><dt>Total registros</dt><dd><?= count($recurrentes) ?></dd></div>
</dl>

<div class="finanzas__cuerpo">

  <div class="finanzas__datos">
    <section class="finanzas__seccion-limpio">
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('agregar') ?> Generar movimientos del mes</h2>
        <span class="finanzas__fuente-limpio"><?= e(date('m/Y')) ?> · generados este mes: <?= $generadosMes ?></span>
      </div>
      <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="nuevo-registro__form">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion_generar" value="1">
        <div class="nuevo-registro__acciones">
          <button type="submit" class="boton boton--primario"><?= icono('agregar') ?> Generar del mes</button>
        </div>
      </form>
      <p class="finanzas__descripcion">Cada recurrente activo crea un movimiento en su día del mes, con referencia única para no duplicarlo si lo vuelves a generar.</p>
    </section>
  </div>

  <div class="finanzas__lado">
    <section class="finanzas__seccion-limpio" data-bd>
      <div class="finanzas__titulo-fila-limpio">
        <h2 class="finanzas__titulo-limpio"><?= icono('documento') ?> Recurrentes registrados</h2>
        <span class="bd__contador"><?= count($recurrentes) ?></span>
      </div>
      <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>
      <p class="bd__vacio" data-bd-vacio <?= $recurrentes ? 'hidden' : '' ?>>Aún no hay recurrentes registrados.</p>
    </section>

    <!-- Registrar recurrente -->
    <a class="bd__nueva" href="<?= URL_BASE ?>/finanzas/recurrente_nuevo.php">Nuevo recurrente</a>
  </div>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>