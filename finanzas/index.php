<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$busqueda = trim($_GET['q'] ?? '');
$limite   = min(500, max(50, (int) ($_GET['limite'] ?? 200)));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$tipo = in_array($_GET['tipo'] ?? '', ['ingreso', 'gasto'], true) ? (string) $_GET['tipo'] : '';
$mes = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = date('Y-m');
}
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));
$filtroLista = [
    'q' => $busqueda,
    'limite' => $limite,
    'orden' => 'fecha',
    'dir' => 'desc',
    'pagina' => $pagina,
    'rango' => ['fecha' => ['desde' => $desde, 'hasta' => $hasta]],
];
if ($tipo !== '') {
    $filtroLista['filtro'] = ['tipo' => $tipo];
}

$lista       = motorListar('finanzas_movimientos', $filtroLista);
$movimientos = $lista['filas'];
$total       = $lista['total'];
$paginas     = max(1, (int) ceil($total / $limite));
$hayAnterior = $pagina > 1;
$hayMas      = $pagina < $paginas;
$idsPacientesMovimiento = array_values(array_unique(array_filter(array_map(
    static fn (array $fila): int => (int) ($fila['paciente_id'] ?? 0),
    $movimientos
))));
$pacientesMovimiento = [];
if ($idsPacientesMovimiento) {
    $consultaPacientesMovimiento = db()->prepare(
        'SELECT id, folio, nombre, primer_apellido, segundo_apellido, eliminado_en
           FROM pacientes
          WHERE institucion_id = ? AND id IN (' . implode(',', array_fill(0, count($idsPacientesMovimiento), '?')) . ')'
    );
    $consultaPacientesMovimiento->execute([institucionId(), ...$idsPacientesMovimiento]);
    foreach ($consultaPacientesMovimiento->fetchAll(PDO::FETCH_ASSOC) as $pacienteMovimiento) {
        $nombrePacienteMovimiento = trim(implode(' ', array_filter([
            $pacienteMovimiento['nombre'],
            $pacienteMovimiento['primer_apellido'],
            $pacienteMovimiento['segundo_apellido'],
        ])));
        $etiquetaPaciente = trim($pacienteMovimiento['folio'] . ' · ' . $nombrePacienteMovimiento, ' ·');
        if ($pacienteMovimiento['eliminado_en'] !== null) {
            $etiquetaPaciente .= ' · dado de baja';
        }
        $pacientesMovimiento[(int) $pacienteMovimiento['id']] = $etiquetaPaciente;
    }
}

$institucion = (int) institucionId();
$suma = function (string $sql) use ($institucion, $desde, $hasta): float {
    $consulta = db()->prepare($sql);
    $consulta->execute([$institucion, $desde, $hasta]);
    return (float) $consulta->fetchColumn();
};
$ingresos   = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'ingreso' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?");
$gastos     = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'gasto' AND estado <> 'cancelado' AND fecha BETWEEN ? AND ?");
$pendientes = $suma("SELECT COALESCE(SUM(monto), 0) FROM finanzas_movimientos WHERE institucion_id = ? AND tipo = 'ingreso' AND estado = 'pendiente' AND fecha BETWEEN ? AND ?");
$balance    = $ingresos - $gastos;
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$dinero = fn (float $n): string => '$' . number_format($n, 2, '.', ',');

$mesActual = $nombresMes[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
$mesSeleccionado = DateTimeImmutable::createFromFormat('!Y-m-d', $desde) ?: new DateTimeImmutable($desde);
$inicioTendencia = $mesSeleccionado->modify('-5 months');
$finTendencia = $mesSeleccionado->modify('+1 month');
$mesesCortos = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$tendencia = [];
for ($i = 5; $i >= 0; $i--) {
    $fechaMes = $mesSeleccionado->modify("-$i months");
    $claveMes = $fechaMes->format('Y-m');
    $tendencia[$claveMes] = [
        'etiqueta' => $mesesCortos[(int) $fechaMes->format('n')] . ' ' . $fechaMes->format('y'),
        'ingresos' => 0.0,
        'gastos' => 0.0,
    ];
}
$consultaTendencia = db()->prepare(
    "SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes,
            COALESCE(SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END), 0) AS ingresos,
            COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END), 0) AS gastos
       FROM finanzas_movimientos
      WHERE institucion_id = ? AND estado = 'pagado' AND fecha >= ? AND fecha < ?
      GROUP BY DATE_FORMAT(fecha, '%Y-%m')"
);
$consultaTendencia->execute([$institucion, $inicioTendencia->format('Y-m-d'), $finTendencia->format('Y-m-d')]);
foreach ($consultaTendencia->fetchAll(PDO::FETCH_ASSOC) as $filaTendencia) {
    $claveMes = (string) $filaTendencia['mes'];
    if (isset($tendencia[$claveMes])) {
        $tendencia[$claveMes]['ingresos'] = (float) $filaTendencia['ingresos'];
        $tendencia[$claveMes]['gastos'] = (float) $filaTendencia['gastos'];
    }
}
$maximoTendencia = max(
    1.0,
    ...array_merge(array_column($tendencia, 'ingresos'), array_column($tendencia, 'gastos'))
);
$hayFiltros = $busqueda !== '' || $tipo !== '' || $mes !== date('Y-m');
$urlFiltro = static function (array $cambios) use ($mes, $tipo, $busqueda, $limite, $pagina): string {
    return '?' . http_build_query(array_filter(array_merge(
        ['mes' => $mes, 'tipo' => $tipo, 'q' => $busqueda, 'limite' => $limite, 'pagina' => $pagina],
        $cambios
    ), static fn ($valor): bool => $valor !== ''));
};
$parametrosExportacion = [
    'entidad' => 'finanzas_movimientos',
    'orden' => 'fecha',
    'dir' => 'desc',
    'q' => $busqueda,
    'desde' => $desde,
    'hasta' => $hasta,
];
if ($tipo !== '') {
    $parametrosExportacion['campo'] = 'tipo';
    $parametrosExportacion['valor'] = $tipo;
}
$urlExportacion = URL_BASE . '/api/motor/exportar.php?' . http_build_query($parametrosExportacion);

$tituloPagina   = 'Finanzas';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas']];

$opcionesEditor = opcionesEditorMotor('finanzas_movimientos');
$columnasFinanzas = columnasVista('finanzas_movimientos');

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tabla = [
    'columnas'  => $columnasFinanzas,
    'filas'     => $movimientos,
    'atributos' => ['data-entidad' => 'finanzas_movimientos', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
    'fila_atributos' => static fn (array $fila, int $i): array => ['data-id' => (int) $fila['id']],
    'celdas' => [
        'paciente_id' => static function (array $columna, array $fila, int $i) use ($pacientesMovimiento): string {
            $idPaciente = (int) ($fila['paciente_id'] ?? 0);
            $textoPaciente = $idPaciente > 0
                ? ($pacientesMovimiento[$idPaciente] ?? 'Paciente no disponible')
                : 'Sin paciente asociado';
            return '<td data-campo="paciente_id" data-valor="' . e($idPaciente > 0 ? (string) $idPaciente : '')
                . '" tabindex="0" role="button" title="Cambiar paciente asociado" aria-label="Paciente asociado: '
                . e($textoPaciente) . '. Activar para cambiar.">' . e($textoPaciente) . '</td>';
        },
    ],
];
?>
<div class="pagina__cabecera finanzas__cabecera">
  <h1 class="pagina__titulo">Finanzas</h1>
  <div class="finanzas__cabecera-acciones">
    <time class="finanzas__fecha" datetime="<?= e($mes) ?>"><?= e(ucfirst($mesActual)) ?></time>
    <a class="finanzas__accion-enlace" href="<?= URL_BASE ?>/finanzas/nuevo.php?tipo=ingreso"><?= icono('agregar') ?><span>Registrar ingreso</span></a>
    <a class="finanzas__accion-enlace" href="<?= URL_BASE ?>/finanzas/nuevo.php?tipo=gasto"><?= icono('agregar') ?><span>Registrar gasto</span></a>
  </div>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<dl class="hoja-paciente__resumen tablero__resumen finanzas__resumen" aria-label="Resumen de <?= e($mesActual) ?>">
  <div><dt>Ingresos</dt><dd><?= e($dinero($ingresos)) ?></dd></div>
  <div><dt>Gastos</dt><dd><?= e($dinero($gastos)) ?></dd></div>
  <div><dt>Balance</dt><dd><?= e($dinero($balance)) ?></dd></div>
  <div><dt>Por cobrar</dt><dd><?= e($dinero($pendientes)) ?></dd></div>
</dl>

<div class="hoja-paciente__cuerpo tablero__cuerpo">
<main class="hoja-paciente__datos tablero__principal finanzas__datos">
<section class="bd finanzas__movimientos" data-bd>
  <div class="bd__cabecera">
    <div class="finanzas__tabla-titulo">
      <h2 class="bd__titulo">Movimientos</h2>
      <span class="bd__contador"><?= (int) $total ?></span>
    </div>
  </div>
  <div class="finanzas__barra" aria-label="Filtros y acciones de movimientos">
    <nav class="finanzas__tipos" aria-label="Filtrar por tipo">
      <a href="<?= e($urlFiltro(['tipo' => '', 'pagina' => 1])) ?>"<?= $tipo === '' ? ' aria-current="page"' : '' ?>><?= icono('lista') ?><span>Todos</span></a>
      <a href="<?= e($urlFiltro(['tipo' => 'ingreso', 'pagina' => 1])) ?>"<?= $tipo === 'ingreso' ? ' aria-current="page"' : '' ?>><?= icono('flecha_abajo') ?><span>Ingresos</span></a>
      <a href="<?= e($urlFiltro(['tipo' => 'gasto', 'pagina' => 1])) ?>"<?= $tipo === 'gasto' ? ' aria-current="page"' : '' ?>><?= icono('flecha_arriba') ?><span>Gastos</span></a>
    </nav>
    <form class="finanzas__filtros" method="get" action="<?= URL_BASE ?>/finanzas/" role="search">
      <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
      <input type="hidden" name="pagina" value="1">
      <label class="sr-only" for="finanzas-mes">Mes</label>
      <input id="finanzas-mes" type="month" name="mes" value="<?= e($mes) ?>" aria-label="Filtrar por mes">
      <label class="sr-only" for="finanzas-busqueda">Buscar movimientos</label>
      <input id="finanzas-busqueda" type="search" name="q" value="<?= e($busqueda) ?>" placeholder="Buscar" aria-label="Buscar movimientos">
      <button class="boton-icono" type="submit" title="Aplicar filtros" aria-label="Aplicar filtros"><?= icono('buscar') ?></button>
      <?php if ($hayFiltros): ?>
        <a class="boton-icono" href="<?= URL_BASE ?>/finanzas/" title="Limpiar filtros" aria-label="Limpiar filtros"><?= icono('cerrar') ?></a>
      <?php endif; ?>
    </form>
    <div class="finanzas__acciones-rapidas">
      <a class="boton-icono" href="<?= e($urlExportacion) ?>" title="Exportar movimientos filtrados" aria-label="Exportar movimientos filtrados"><?= icono('tabla') ?></a>
    </div>
  </div>
  <?php if ($hayFiltros): ?>
    <p class="finanzas__estado-filtro" role="status">
      <?= $tipo !== '' ? ($tipo === 'ingreso' ? 'Ingresos' : 'Gastos') . ' · ' : '' ?><?= $busqueda !== '' ? 'Coincidencias para «' . e($busqueda) . '» · ' : '' ?><?= e(ucfirst($mesActual)) ?>
    </p>
  <?php endif; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $movimientos ? 'hidden' : '' ?>>
    <?= $hayFiltros ? 'No hay movimientos con estos filtros.' : 'Todavía no hay movimientos. Registra el primer ingreso o gasto para empezar.' ?>
  </p>

  <?php if ($total > 0): ?>
    <div class="finanzas__paginacion" aria-label="Paginación de movimientos">
      <?php if ($hayAnterior): ?><a href="<?= e($urlFiltro(['pagina' => $pagina - 1])) ?>"><?= icono('atras') ?><span>Anterior</span></a><?php endif; ?>
      <span>Página <?= $pagina ?> de <?= $paginas ?> · <?= (int) $total ?> movimientos</span>
      <?php if ($hayMas): ?><a href="<?= e($urlFiltro(['pagina' => $pagina + 1])) ?>"><span>Siguiente</span><?= icono('adelante') ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
</main>
<aside class="hoja-paciente__lado tablero__lado">
  <details class="ficha__seccion tablero__seccion finanzas__accesos" open>
    <summary class="finanzas__accesos-resumen">
      <h2 class="ficha__titulo" id="finanzas-accesos">Accesos</h2>
      <?= icono('flecha_abajo') ?>
    </summary>
    <nav class="tablero__accesos" aria-label="Accesos financieros">
      <a class="tablero__acceso" href="<?= URL_BASE ?>/finanzas/cuentas.php"><?= icono('tarjeta') ?><span>Cuentas por cobrar y pagar</span></a>
      <a class="tablero__acceso" href="<?= URL_BASE ?>/finanzas/cierre.php"><?= icono('documento') ?><span>Cierre de caja</span></a>
      <a class="tablero__acceso" href="<?= URL_BASE ?>/finanzas/conciliacion.php"><?= icono('flujo') ?><span>Conciliación</span></a>
      <a class="tablero__acceso" href="<?= URL_BASE ?>/finanzas/reportes.php"><?= icono('barras') ?><span>Reportes</span></a>
    </nav>
  </details>
  <section class="ficha__seccion tablero__grafica-panel finanzas__grafica-panel" aria-labelledby="finanzas-grafica-titulo">
    <div class="ficha__titulo-fila">
      <div>
        <h2 class="ficha__titulo" id="finanzas-grafica-titulo">Flujo financiero</h2>
        <p>Ingresos y gastos pagados · seis meses</p>
      </div>
      <span class="ficha__fuente">Datos reales</span>
    </div>
    <div class="tablero__grafica" aria-hidden="true">
      <?php foreach ($tendencia as $datoMes): ?>
        <?php
          $alturaIngreso = min(100, (int) round(($datoMes['ingresos'] / $maximoTendencia) * 100));
          $alturaGasto = min(100, (int) round(($datoMes['gastos'] / $maximoTendencia) * 100));
        ?>
        <div class="tablero__grafica-mes">
          <div class="tablero__grafica-barras" title="<?= e($datoMes['etiqueta']) ?> · ingresos <?= e($dinero($datoMes['ingresos'])) ?> · gastos <?= e($dinero($datoMes['gastos'])) ?>">
            <span class="tablero__grafica-barra tablero__grafica-barra--registros" style="--altura: <?= $alturaIngreso ?>%"></span>
            <span class="tablero__grafica-barra tablero__grafica-barra--pacientes" style="--altura: <?= $alturaGasto ?>%"></span>
          </div>
          <span><?= e($datoMes['etiqueta']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tablero__grafica-leyenda" aria-hidden="true">
      <span><i class="tablero__grafica-muestra tablero__grafica-muestra--registros"></i>Ingresos</span>
      <span><i class="tablero__grafica-muestra tablero__grafica-muestra--pacientes"></i>Gastos</span>
    </div>
    <table class="sr-only">
      <caption>Ingresos y gastos pagados por mes durante los seis meses hasta <?= e($mesActual) ?></caption>
      <thead><tr><th>Mes</th><th>Ingresos pagados</th><th>Gastos pagados</th></tr></thead>
      <tbody>
        <?php foreach ($tendencia as $datoMes): ?>
          <tr><th scope="row"><?= e($datoMes['etiqueta']) ?></th><td><?= e($dinero($datoMes['ingresos'])) ?></td><td><?= e($dinero($datoMes['gastos'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</aside>
</div>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>