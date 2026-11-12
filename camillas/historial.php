<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

$institucionId = institucionId() ?: 1;
$busquedaSolicitada = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
$busqueda = mb_substr(trim($busquedaSolicitada), 0, 100);
$tipoFiltro = is_string($_GET['tipo'] ?? null) ? $_GET['tipo'] : '';
$tiposMovimiento = [
    'ingreso' => 'Ingreso',
    'alta' => 'Alta',
    'traslado' => 'Traslado',
];
$tipoFiltroValido = $tipoFiltro === '' || isset($tiposMovimiento[$tipoFiltro]);
$errorFiltro = null;
if (!$tipoFiltroValido) {
    http_response_code(400);
    $errorFiltro = 'El tipo de movimiento seleccionado no es válido.';
    $tipoFiltro = '';
}

$validarFecha = static function (string $campo) use (&$errorFiltro): ?string {
    $valor = $_GET[$campo] ?? '';
    if (!is_string($valor) || $valor === '') {
        return null;
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    $errores = DateTimeImmutable::getLastErrors();
    if (!$fecha || ($errores !== false && ($errores['warning_count'] || $errores['error_count']))
        || $fecha->format('Y-m-d') !== $valor) {
        http_response_code(400);
        $errorFiltro = 'Indica las fechas del historial con un formato válido.';
        return null;
    }

    return $valor;
};
$fechaDesde = $validarFecha('desde');
$fechaHasta = $validarFecha('hasta');
if ($fechaDesde !== null && $fechaHasta !== null && $fechaDesde > $fechaHasta) {
    http_response_code(400);
    $errorFiltro = 'La fecha inicial no puede ser posterior a la fecha final.';
    $fechaDesde = $fechaHasta = null;
}

$filtros = array_filter(
    ['q' => $busqueda, 'tipo' => $tipoFiltro, 'desde' => $fechaDesde, 'hasta' => $fechaHasta],
    static fn ($valor): bool => $valor !== null && $valor !== ''
);

$joins = " FROM camillas_movimientos m
           LEFT JOIN camillas c ON c.id = m.camilla_id AND c.institucion_id = m.institucion_id
           LEFT JOIN camillas origen ON origen.id = m.camilla_origen_id AND origen.institucion_id = m.institucion_id
           LEFT JOIN pacientes p ON p.id = m.paciente_id AND p.institucion_id = m.institucion_id
           LEFT JOIN usuarios u ON u.id = m.usuario_id";
$where = ['m.institucion_id = ?'];
$parametros = [$institucionId];
if ($busqueda !== '') {
    $where[] = "(c.codigo LIKE ? OR origen.codigo LIKE ? OR m.tipo_movimiento LIKE ? OR m.motivo LIKE ?
                 OR p.folio LIKE ? OR p.nombre LIKE ? OR p.primer_apellido LIKE ? OR p.segundo_apellido LIKE ? OR u.nombre LIKE ?)";
    $termino = '%' . $busqueda . '%';
    array_push($parametros, $termino, $termino, $termino, $termino, $termino, $termino, $termino, $termino, $termino);
}
if ($tipoFiltro !== '') {
    $where[] = 'm.tipo_movimiento = ?';
    $parametros[] = $tipoFiltro;
}
if ($fechaDesde !== null) {
    $where[] = 'm.fecha >= ?';
    $parametros[] = $fechaDesde . ' 00:00:00';
}
if ($fechaHasta !== null) {
    $where[] = 'm.fecha < DATE_ADD(?, INTERVAL 1 DAY)';
    $parametros[] = $fechaHasta;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$columnasCsv = "SELECT m.fecha, m.tipo_movimiento, c.codigo AS destino, origen.codigo AS origen,
                       m.paciente_id, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido,
                       m.motivo, u.nombre AS usuario";

if (($_GET['exportar'] ?? '') === 'csv') {
    exigirPermiso('importar_exportar');
    $exportar = db()->prepare($columnasCsv . $joins . $whereSql . ' ORDER BY m.fecha DESC, m.id DESC');
    $exportar->execute($parametros);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="historial-camillas-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $salida = fopen('php://output', 'wb');
    if ($salida === false) {
        throw new RuntimeException('No se pudo preparar la descarga del historial.');
    }
    fwrite($salida, "\xEF\xBB\xBF");
    fputcsv($salida, ['Fecha y hora', 'Movimiento', 'Origen', 'Destino', 'Folio', 'Paciente', 'Motivo', 'Registró'], ';', '"', '\\');
    while ($movimiento = $exportar->fetch(PDO::FETCH_ASSOC)) {
        $nombrePaciente = trim(implode(' ', array_filter([
            $movimiento['nombre'],
            $movimiento['primer_apellido'],
            $movimiento['segundo_apellido'],
        ], static fn ($parte): bool => $parte !== null && $parte !== '')));
        $valores = [
            $movimiento['fecha'],
            $tiposMovimiento[$movimiento['tipo_movimiento']] ?? $movimiento['tipo_movimiento'],
            $movimiento['origen'] ?? '',
            $movimiento['destino'] ?? '',
            $movimiento['folio'] ?? '',
            $nombrePaciente,
            $movimiento['motivo'] ?? '',
            $movimiento['usuario'] ?? '',
        ];
        $valores = array_map(static function ($valor): string {
            $texto = (string) ($valor ?? '');
            return preg_match('/^[=+\-@]/u', $texto) ? "'" . $texto : $texto;
        }, $valores);
        fputcsv($salida, $valores, ';', '"', '\\');
    }
    fclose($salida);
    exit;
}

$paginaSolicitada = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$pagina = max(1, $paginaSolicitada === false ? 1 : $paginaSolicitada);
$porPagina = 50;
$joinsConteo = $busqueda === '' ? ' FROM camillas_movimientos m' : $joins;
$totalConsulta = db()->prepare('SELECT COUNT(*)' . $joinsConteo . $whereSql);
$totalConsulta->execute($parametros);
$total = (int) $totalConsulta->fetchColumn();
$paginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($pagina, $paginas);
$desdeFila = ($pagina - 1) * $porPagina;
$consulta = db()->prepare($columnasCsv . $joins . $whereSql
    . " ORDER BY m.fecha DESC, m.id DESC LIMIT {$porPagina} OFFSET {$desdeFila}");
$consulta->execute($parametros);
$movimientos = $consulta->fetchAll(PDO::FETCH_ASSOC);
$urlExportar = URL_BASE . '/camillas/historial.php?' . http_build_query($filtros + ['exportar' => 'csv']);

$tituloPagina = 'Historial de camas y camillas';
$seccionActiva = 'camillas';
$claseContenido = 'pagina--ancha';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente pagina--ancha camillas-historial">
  <div class="pagina__cabecera">
    <h1 class="pagina__titulo">Historial de camas y camillas</h1>
    <p class="pagina__subtitulo">Ingresos, altas y traslados registrados en el censo</p>
  </div>

  <section class="bd" data-bd>
    <div class="bd__cabecera camillas-historial__cabecera">
      <div class="camillas-historial__titulo">
        <h2 class="bd__titulo">Movimientos</h2>
        <span class="bd__contador"><?= $total ?> registros</span>
        <span class="camillas-historial__solo-lectura" title="Los movimientos se conservan como fueron registrados">Solo consulta</span>
      </div>
      <form method="get" action="<?= URL_BASE ?>/camillas/historial.php" class="camillas-historial__filtros" role="search">
        <label class="sr-only" for="historial-busqueda">Buscar en el historial</label>
        <span class="camillas-historial__buscador">
          <?= icono('buscar') ?>
          <input id="historial-busqueda" type="search" name="q" value="<?= e($busqueda) ?>" placeholder="Paciente, folio o camilla" autocomplete="off">
        </span>
        <label class="sr-only" for="historial-tipo">Tipo de movimiento</label>
        <select id="historial-tipo" name="tipo">
          <option value="">Todos los movimientos</option>
          <?php foreach ($tiposMovimiento as $clave => $etiqueta): ?>
            <option value="<?= e($clave) ?>"<?= $tipoFiltro === $clave ? ' selected' : '' ?>><?= e($etiqueta) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="sr-only" for="historial-desde">Desde</label>
        <input id="historial-desde" type="date" name="desde" value="<?= e((string) $fechaDesde) ?>" aria-label="Fecha inicial">
        <label class="sr-only" for="historial-hasta">Hasta</label>
        <input id="historial-hasta" type="date" name="hasta" value="<?= e((string) $fechaHasta) ?>" aria-label="Fecha final">
        <button type="submit" class="boton-icono" title="Aplicar filtros" aria-label="Aplicar filtros"><?= icono('adelante') ?></button>
        <?php if ($filtros): ?>
          <a href="<?= URL_BASE ?>/camillas/historial.php" class="boton-icono" title="Limpiar filtros" aria-label="Limpiar filtros"><?= icono('cerrar') ?></a>
        <?php endif; ?>
      </form>
      <div class="camillas-historial__acciones">
        <?php if (puede('importar_exportar')): ?>
          <a href="<?= e($urlExportar) ?>" class="boton-icono" title="Exportar resultados filtrados a CSV" aria-label="Exportar resultados filtrados a CSV"><?= icono('tabla') ?></a>
        <?php endif; ?>
        <a href="<?= URL_BASE ?>/camillas/" class="boton boton--sutil"><?= icono('flecha_derecha') ?> Censo</a>
      </div>
    </div>

    <?php if ($errorFiltro !== null): ?>
      <p class="camillas-historial__error" role="alert"><?= e($errorFiltro) ?></p>
    <?php endif; ?>

    <div class="bd__marco camillas-historial__marco" role="region" aria-label="Historial de movimientos; desplaza horizontalmente para ver todas las columnas" tabindex="0">
      <table class="bd-tabla camillas-historial__tabla">
        <caption class="sr-only">Movimientos de camas y camillas. Historial de solo consulta.</caption>
        <thead>
          <tr>
            <th scope="col"><?= icono('calendario') ?> <span>Fecha y hora</span></th>
            <th scope="col"><?= icono('flujo') ?> <span>Movimiento</span></th>
            <th scope="col"><?= icono('clinico') ?> <span>Origen / destino</span></th>
            <th scope="col"><?= icono('usuarios') ?> <span>Paciente</span></th>
            <th scope="col"><?= icono('documento') ?> <span>Motivo</span></th>
            <th scope="col"><?= icono('usuario') ?> <span>Registró</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movimientos as $movimiento): ?>
            <?php
              $nombrePaciente = trim(implode(' ', array_filter([
                  $movimiento['nombre'], $movimiento['primer_apellido'], $movimiento['segundo_apellido'],
              ], static fn ($parte): bool => $parte !== null && $parte !== '')));
              $tipoMovimiento = $tiposMovimiento[$movimiento['tipo_movimiento']] ?? ucfirst((string) $movimiento['tipo_movimiento']);
            ?>
            <tr>
              <td><time datetime="<?= e(date('c', strtotime($movimiento['fecha']))) ?>"><?= e(date('d/m/Y H:i', strtotime($movimiento['fecha']))) ?></time></td>
              <td class="bd-tabla__celda-titulo"><span class="bd-tabla__titulo"><?= e($tipoMovimiento) ?></span></td>
              <td>
                <?php if ($movimiento['tipo_movimiento'] === 'traslado'): ?>
                  <span class="camillas-historial__traslado"><?= e($movimiento['origen'] ?: '—') ?> <span aria-hidden="true">→</span> <?= e($movimiento['destino'] ?: '—') ?></span>
                <?php else: ?>
                  <?= e($movimiento['destino'] ?: '—') ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($movimiento['paciente_id']): ?>
                  <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $movimiento['paciente_id'] ?>"><?= e($nombrePaciente ?: 'Paciente') ?></a>
                  <?php if ($movimiento['folio']): ?><small><?= e($movimiento['folio']) ?></small><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= e($movimiento['motivo'] ?: '—') ?></td>
              <td><?= e($movimiento['usuario'] ?: '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="bd__vacio" <?= $movimientos ? 'hidden' : '' ?>>
      <?= $errorFiltro !== null ? 'Corrige los filtros para consultar el historial.' : ($filtros ? 'Ningún movimiento coincide con estos filtros.' : 'Aún no hay movimientos de camas registrados.') ?>
    </p>
    <?php if ($paginas > 1): ?>
      <nav class="bd__mas camillas-historial__paginacion" aria-label="Páginas del historial">
        <?php if ($pagina > 1): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina - 1])) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= $pagina ?> de <?= $paginas ?></span>
        <?php if ($pagina < $paginas): ?><a href="?<?= e(http_build_query($filtros + ['pagina' => $pagina + 1])) ?>">Siguiente</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
