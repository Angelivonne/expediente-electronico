<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/cirugia.php';

exigirPermiso('ver_expediente');
$institucion = institucionId();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$institucion || $id === false || $id < 1) {
    http_response_code(404);
    exit('Cirugía no encontrada.');
}

$consulta = db()->prepare(
    "SELECT c.id, c.programada_en, c.procedimiento, c.quirofano, c.estado,
            p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
       FROM cirugias_programadas c
       JOIN pacientes p ON p.id = c.paciente_id AND p.institucion_id = c.institucion_id
      WHERE c.id = ? AND c.institucion_id = ? AND p.eliminado_en IS NULL"
);
$consulta->execute([(int) $id, $institucion]);
$cirugia = $consulta->fetch(PDO::FETCH_ASSOC);
if (!$cirugia) {
    http_response_code(404);
    exit('Cirugía no encontrada.');
}

$consultaEventos = db()->prepare(
    'SELECT tipo, estado_anterior, estado_nuevo, fecha_anterior, fecha_nueva, motivo, usuario_nombre, creado_en
       FROM cirugias_eventos
      WHERE cirugia_id = ? AND institucion_id = ?
      ORDER BY id DESC'
);
$consultaEventos->execute([(int) $id, $institucion]);
$eventos = $consultaEventos->fetchAll(PDO::FETCH_ASSOC);
$nombrePaciente = trim($cirugia['nombre'] . ' ' . $cirugia['primer_apellido'] . ' ' . ($cirugia['segundo_apellido'] ?? ''));

$tituloPagina = 'Historial quirúrgico';
$seccionActiva = 'cirugia';
$claseContenido = 'pagina--ancha cirugia-pagina';
$migas = [['texto' => 'Agenda quirúrgica', 'url' => '/cirugia/'], ['texto' => 'Historial']];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <p class="pagina__meta">Folio <?= e($cirugia['folio']) ?> · <?= e(ESTADOS_CIRUGIA[$cirugia['estado']] ?? $cirugia['estado']) ?></p>
  <h1 class="pagina__titulo"><?= e($nombrePaciente) ?></h1>
  <p class="pagina__subtitulo"><?= e($cirugia['procedimiento']) ?> · <?= e($cirugia['quirofano']) ?> · <?= e(fechaCorta($cirugia['programada_en'])) ?></p>
</div>
<ol class="cirugia__historial">
  <?php foreach ($eventos as $evento): ?>
    <li>
      <time datetime="<?= e(date(DATE_ATOM, strtotime($evento['creado_en']))) ?>"><?= e(fechaCorta($evento['creado_en'])) ?> · <?= e(substr($evento['creado_en'], 11, 5)) ?></time>
      <strong><?= $evento['tipo'] === 'programacion' ? 'Programación creada' : e(ESTADOS_CIRUGIA[$evento['estado_anterior']] ?? $evento['estado_anterior']) . ' → ' . e(ESTADOS_CIRUGIA[$evento['estado_nuevo']] ?? $evento['estado_nuevo']) ?></strong>
      <span>Registró <?= e($evento['usuario_nombre']) ?></span>
      <?php if ($evento['motivo']): ?><p><?= e($evento['motivo']) ?></p><?php endif; ?>
    </li>
  <?php endforeach; ?>
</ol>
<p><a class="boton boton--secundario" href="<?= URL_BASE ?>/cirugia/">Volver a la agenda</a></p>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
