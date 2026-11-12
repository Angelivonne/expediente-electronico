<?php
/** Tablero personal: cada indicador se limita a citas, notas y movimientos asignados al usuario activo. */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/agenda.php';

exigirPermiso('ver_expediente');

$usuario = usuarioActual() ?? [];
$usuarioId = (int) ($usuario['id'] ?? 0);
$institucion = (int) institucionId();
$hoy = date('Y-m-d');
$inicioHoy = $hoy . ' 00:00:00';
$inicioManana = (new DateTimeImmutable($inicioHoy))->modify('+1 day')->format('Y-m-d H:i:s');
$inicioMes = date('Y-m-01');
$inicioMesSiguiente = (new DateTimeImmutable($inicioMes))->modify('+1 month')->format('Y-m-d');
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$diasSemana = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaLegible = ucfirst($diasSemana[(int) date('w')]) . ', ' . date('j') . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y');
$rolLegible = ROLES[$usuario['rol'] ?? '']['nombre'] ?? ucfirst((string) ($usuario['rol'] ?? 'Personal'));

$consultaCitasHoy = db()->prepare(
    "SELECT COUNT(*) FROM citas
      WHERE institucion_id = ? AND profesional_id = ? AND inicio >= ? AND inicio < ? AND estado <> 'cancelada'"
);
$consultaCitasHoy->execute([$institucion, $usuarioId, $inicioHoy, $inicioManana]);
$citasHoy = (int) $consultaCitasHoy->fetchColumn();

$consultaProximas = db()->prepare(
    "SELECT c.id, c.inicio, c.motivo, p.id AS paciente_id,
            CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente
       FROM citas c
       LEFT JOIN pacientes p ON p.id = c.paciente_id AND p.institucion_id = c.institucion_id
      WHERE c.institucion_id = ? AND c.profesional_id = ?
        AND c.estado IN ('programada', 'confirmada') AND c.inicio >= NOW()
      ORDER BY c.inicio LIMIT 6"
);
$consultaProximas->execute([$institucion, $usuarioId]);
$proximas = $consultaProximas->fetchAll(PDO::FETCH_ASSOC);

$actividadClinica = null;
$actividadMensual = [];
$maximoRegistrosMensuales = 1;
$registrosRecientes = [];
if (puede('historial')) {
    $consultaActividad = db()->prepare(
        'SELECT COUNT(*) AS notas, COUNT(DISTINCT h.paciente_id) AS pacientes
           FROM historial_clinico h
           JOIN pacientes p ON p.id = h.paciente_id
          WHERE p.institucion_id = ? AND h.autor_id = ?
            AND h.fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)'
    );
    $consultaActividad->execute([$institucion, $usuarioId]);
    $actividadClinica = $consultaActividad->fetch(PDO::FETCH_ASSOC) ?: ['notas' => 0, 'pacientes' => 0];

    $mesesCortos = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $mesActual = new DateTimeImmutable('first day of this month');
    for ($i = 5; $i >= 0; $i--) {
        $mes = $mesActual->modify("-$i months");
        $claveMes = $mes->format('Y-m');
        $actividadMensual[$claveMes] = [
            'etiqueta' => $mesesCortos[(int) $mes->format('n')] . ' ' . $mes->format('y'),
            'registros' => 0,
            'pacientes' => 0,
        ];
    }
    $inicioGrafica = array_key_first($actividadMensual) . '-01 00:00:00';
    $finGrafica = $mesActual->modify('+1 month')->format('Y-m-d H:i:s');
    $consultaActividadMensual = db()->prepare(
        "SELECT DATE_FORMAT(h.fecha, '%Y-%m') AS mes,
                COUNT(*) AS registros, COUNT(DISTINCT h.paciente_id) AS pacientes
           FROM historial_clinico h
           JOIN pacientes p ON p.id = h.paciente_id AND p.institucion_id = h.institucion_id
          WHERE h.institucion_id = ? AND h.autor_id = ? AND p.eliminado_en IS NULL
            AND h.fecha >= ? AND h.fecha < ?
          GROUP BY DATE_FORMAT(h.fecha, '%Y-%m')"
    );
    $consultaActividadMensual->execute([$institucion, $usuarioId, $inicioGrafica, $finGrafica]);
    foreach ($consultaActividadMensual->fetchAll(PDO::FETCH_ASSOC) as $mes) {
        $claveMes = (string) $mes['mes'];
        if (isset($actividadMensual[$claveMes])) {
            $actividadMensual[$claveMes]['registros'] = (int) $mes['registros'];
            $actividadMensual[$claveMes]['pacientes'] = (int) $mes['pacientes'];
        }
    }
    $maximoRegistrosMensuales = max(1, ...array_column($actividadMensual, 'registros'));

    $consultaRecientes = db()->prepare(
        'SELECT h.id, h.tipo, h.fecha, h.titulo, p.id AS paciente_id, p.folio,
                CONCAT_WS(\' \', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente
           FROM historial_clinico h
           JOIN pacientes p ON p.id = h.paciente_id
          WHERE p.institucion_id = ? AND h.autor_id = ?
          ORDER BY h.fecha DESC, h.id DESC LIMIT 6'
    );
    $consultaRecientes->execute([$institucion, $usuarioId]);
    $registrosRecientes = $consultaRecientes->fetchAll(PDO::FETCH_ASSOC);
}

$finanzasPreparadas = false;
$finanzasPersonales = ['cobrado_hoy' => 0.0, 'por_cobrar' => 0.0, 'neto_mes' => 0.0];
if (puede('finanzas')) {
    $existeTabla = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $existeTabla->execute(['finanzas_movimientos']);
    if ((int) $existeTabla->fetchColumn() > 0) {
        $existeColumna = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $existeColumna->execute(['finanzas_movimientos', 'profesional_id']);
        $finanzasPreparadas = (int) $existeColumna->fetchColumn() > 0;
    }

    if ($finanzasPreparadas) {
        $consultaFinanzas = db()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN fecha = ? AND tipo = 'ingreso' AND estado = 'pagado' THEN monto ELSE 0 END), 0) AS cobrado_hoy,
                COALESCE(SUM(CASE WHEN tipo = 'ingreso' AND estado = 'pendiente' THEN monto ELSE 0 END), 0) AS por_cobrar,
                COALESCE(SUM(CASE WHEN fecha >= ? AND fecha < ? AND estado = 'pagado'
                                  THEN CASE WHEN tipo = 'ingreso' THEN monto ELSE -monto END ELSE 0 END), 0) AS neto_mes
               FROM finanzas_movimientos
              WHERE institucion_id = ? AND profesional_id = ?"
        );
        $consultaFinanzas->execute([$hoy, $inicioMes, $inicioMesSiguiente, $institucion, $usuarioId]);
        $finanzasPersonales = $consultaFinanzas->fetch(PDO::FETCH_ASSOC) ?: $finanzasPersonales;
    }
}

$tiposRegistro = [
    'consulta' => 'Consulta', 'receta' => 'Receta', 'hospitalizacion' => 'Hospitalización',
    'estudio' => 'Estudio', 'urgencias' => 'Urgencias', 'interconsulta' => 'Interconsulta',
    'referencia' => 'Referencia', 'egreso' => 'Egreso', 'consentimiento' => 'Consentimiento',
    'incapacidad' => 'Incapacidad',
];
$dinero = static fn (float $monto): string => '$' . number_format($monto, 2, '.', ',');

$tituloPagina = 'Tablero personal';
$seccionActiva = 'tablero';
$clavePresencia = 'tablero:' . $usuarioId;
$mostrarBanner = true;   // La portada superior da vida al tablero (usa la imagen predeterminada o la configurada)
$claseContenido = 'pagina--expediente tablero-pagina';
$migas = [['texto' => 'Tablero']];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>


<div class="pagina__cabecera tablero__cabecera">
  <p class="pagina__meta"><?= e($fechaLegible) ?></p>
  <div class="tablero__identidad">
    <?= avatar($usuarioId, (string) ($usuario['nombre'] ?? ''), $usuario['foto'] ?? null, 'tablero__avatar') ?>
    <div class="hoja-paciente__identidad">
      <p class="hoja-paciente__linea"><span class="tablero__rol"><?= e($rolLegible) ?></span></p>
      <h1 class="hoja-paciente__nombre"><?= e($saludo) ?>, <?= e((string) ($usuario['nombre'] ?? '')) ?></h1>
    </div>
  </div>
</div>

<dl class="hoja-paciente__resumen tablero__resumen" aria-label="Resumen personal">
  <div><dt>Citas hoy</dt><dd><?= $citasHoy ?></dd></div>
  <?php if ($actividadClinica !== null): ?>
    <div><dt>Notas · 6 meses</dt><dd><?= (int) $actividadClinica['notas'] ?></dd></div>
    <div><dt>Pacientes atendidos · 6 meses</dt><dd><?= (int) $actividadClinica['pacientes'] ?></dd></div>
  <?php endif; ?>
  <?php if ($finanzasPreparadas): ?>
    <div><dt>Cobrado hoy</dt><dd><?= e($dinero((float) $finanzasPersonales['cobrado_hoy'])) ?></dd></div>
    <div><dt>Por cobrar</dt><dd><?= e($dinero((float) $finanzasPersonales['por_cobrar'])) ?></dd></div>
    <div><dt>Neto personal · mes</dt><dd><?= e($dinero((float) $finanzasPersonales['neto_mes'])) ?></dd></div>
  <?php endif; ?>
</dl>

<?php if ($actividadClinica !== null): ?>
  <section class="ficha__seccion tablero__grafica-panel" aria-labelledby="tablero-grafica-titulo">
    <div class="ficha__titulo-fila">
      <div>
        <h2 class="ficha__titulo" id="tablero-grafica-titulo">Actividad clínica</h2>
        <p>Registros y pacientes únicos por mes · solo tu actividad</p>
      </div>
      <span class="ficha__fuente">Últimos 6 meses</span>
    </div>
    <div class="tablero__grafica" aria-hidden="true">
      <?php foreach ($actividadMensual as $mes): ?>
        <?php
          $altura = min(100, (int) round(($mes['registros'] / $maximoRegistrosMensuales) * 100));
          $alturaPacientes = min(100, (int) round(($mes['pacientes'] / $maximoRegistrosMensuales) * 100));
        ?>
        <div class="tablero__grafica-mes">
          <div class="tablero__grafica-barras" title="<?= (int) $mes['registros'] ?> registros, <?= (int) $mes['pacientes'] ?> pacientes">
            <span class="tablero__grafica-barra tablero__grafica-barra--registros" style="--altura: <?= $altura ?>%"></span>
            <span class="tablero__grafica-barra tablero__grafica-barra--pacientes" style="--altura: <?= $alturaPacientes ?>%"></span>
          </div>
          <span><?= e($mes['etiqueta']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tablero__grafica-leyenda" aria-hidden="true">
      <span><i class="tablero__grafica-muestra tablero__grafica-muestra--registros"></i>Registros clínicos</span>
      <span><i class="tablero__grafica-muestra tablero__grafica-muestra--pacientes"></i>Pacientes únicos</span>
    </div>
    <table class="sr-only">
      <caption>Actividad clínica del usuario por mes durante los últimos seis meses</caption>
      <thead><tr><th>Mes</th><th>Registros clínicos</th><th>Pacientes únicos</th></tr></thead>
      <tbody>
        <?php foreach ($actividadMensual as $mes): ?>
          <tr><th scope="row"><?= e($mes['etiqueta']) ?></th><td><?= (int) $mes['registros'] ?></td><td><?= (int) $mes['pacientes'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<div class="hoja-paciente__cuerpo tablero__cuerpo">
  <div class="hoja-paciente__datos tablero__principal">
    <section class="ficha__seccion tablero__seccion" aria-labelledby="tablero-citas">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo" id="tablero-citas">Próximas citas</h2>
        <?php if (puede('agenda')): ?><a class="tablero__enlace" href="<?= URL_BASE ?>/agenda/">Agenda</a><?php endif; ?>
      </div>
      <?php if ($proximas): ?>
        <ul class="tablero__lista">
          <?php foreach ($proximas as $cita): ?>
            <li>
              <time datetime="<?= e(str_replace(' ', 'T', (string) $cita['inicio'])) ?>"><?= e(date('d/m · H:i', strtotime((string) $cita['inicio']))) ?></time>
              <span>
                <strong><?= e(trim((string) ($cita['paciente'] ?: $cita['motivo'] ?: 'Cita'))) ?></strong>
                <small><?= e((string) ($cita['motivo'] ?: 'Consulta')) ?></small>
              </span>
              <?php if (puede('agenda')): ?><a href="<?= URL_BASE ?>/agenda/?fecha=<?= e(substr((string) $cita['inicio'], 0, 10)) ?>&amp;cita=<?= (int) $cita['id'] ?>" aria-label="Abrir cita">Ver</a><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="tablero__vacio">Sin citas próximas.</p>
      <?php endif; ?>
    </section>

    <?php if ($actividadClinica !== null): ?>
      <section class="ficha__seccion tablero__seccion" aria-labelledby="tablero-actividad">
        <div class="ficha__titulo-fila">
          <h2 class="ficha__titulo" id="tablero-actividad">Actividad clínica reciente</h2>
        </div>
        <?php if ($registrosRecientes): ?>
          <ul class="tablero__lista tablero__lista--actividad">
            <?php foreach ($registrosRecientes as $registro): ?>
              <li>
                <time datetime="<?= e((string) $registro['fecha']) ?>"><?= e(date('d/m/Y', strtotime((string) $registro['fecha']))) ?></time>
                <span>
                  <strong><?= e((string) ($registro['titulo'] ?: ($tiposRegistro[$registro['tipo']] ?? $registro['tipo']))) ?></strong>
                  <small><?= e($tiposRegistro[$registro['tipo']] ?? (string) $registro['tipo']) ?> · <?= e((string) ($registro['paciente'] ?: $registro['folio'])) ?></small>
                </span>
                <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $registro['paciente_id'] ?>">Expediente</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="tablero__vacio">Sin actividad reciente.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>

  <aside class="hoja-paciente__lado tablero__lado">
    <section class="ficha__seccion tablero__seccion" aria-labelledby="tablero-accesos">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo" id="tablero-accesos">Accesos</h2>
      </div>
      <nav class="tablero__accesos" aria-label="Accesos">
        <?php if (puede('agenda')): ?><a class="tablero__acceso" href="<?= URL_BASE ?>/agenda/"><?= icono('calendario') ?><span>Agenda</span></a><?php endif; ?>
        <a class="tablero__acceso" href="<?= URL_BASE ?>/pacientes/"><?= icono('usuarios') ?><span>Pacientes</span></a>
        <?php if (puede('editar_paciente')): ?><a class="tablero__acceso" href="<?= URL_BASE ?>/pacientes/registro.php"><?= icono('agregar') ?><span>Nuevo paciente</span></a><?php endif; ?>
        <?php if (puede('finanzas')): ?><a class="tablero__acceso" href="<?= URL_BASE ?>/finanzas/nuevo.php"><?= icono('tarjeta') ?><span>Nuevo movimiento</span></a><?php endif; ?>
        <?php if (puede('importar_exportar')): ?><a class="tablero__acceso" href="<?= URL_BASE ?>/datos/"><?= icono('tabla') ?><span>Importar o exportar</span></a><?php endif; ?>
        <a class="tablero__acceso" href="<?= URL_BASE ?>/cuenta/"><?= icono('usuario') ?><span>Mi cuenta</span></a>
      </nav>
    </section>
  </aside>
</div>


<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
