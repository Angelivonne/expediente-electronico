<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/flujo.php';

exigirPermiso('ver_expediente');
$flujoId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$institucion = institucionId();
if (!$institucion || $flujoId === false || $flujoId < 1) {
    http_response_code(404);
    exit('Episodio no encontrado.');
}

$cabecera = db()->prepare(
    "SELECT f.id, f.etapa, f.abierto_en, f.cerrado_en,
            p.id AS paciente_id, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
       FROM flujo_atencion f
       JOIN pacientes p ON p.id = f.paciente_id AND p.institucion_id = f.institucion_id
      WHERE f.id = ? AND f.institucion_id = ? AND p.eliminado_en IS NULL"
);
$cabecera->execute([(int) $flujoId, $institucion]);
$flujo = $cabecera->fetch(PDO::FETCH_ASSOC);
if (!$flujo) {
    http_response_code(404);
    exit('Episodio no encontrado.');
}

$eventos = db()->prepare(
    'SELECT etapa_anterior, etapa_nueva, motivo, usuario_nombre, creado_en
       FROM flujo_atencion_eventos
      WHERE flujo_id = ? AND institucion_id = ?
      ORDER BY id DESC'
);
$eventos->execute([(int) $flujoId, $institucion]);
$eventos = $eventos->fetchAll(PDO::FETCH_ASSOC);
$etiquetas = array_column(etapasFlujo(), 'nombre', 'clave');
$nombrePaciente = trim($flujo['nombre'] . ' ' . $flujo['primer_apellido'] . ' ' . ($flujo['segundo_apellido'] ?? ''));

$tituloPagina = 'Historial del flujo';
$seccionActiva = 'flujo';
$claseContenido = 'flujo-historial';
$migas = [['texto' => 'Flujo de atención', 'url' => '/flujo/'], ['texto' => 'Historial']];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <p class="pagina__meta">Folio <?= e($flujo['folio']) ?> · <?= $flujo['cerrado_en'] ? 'Episodio cerrado' : 'Episodio activo' ?></p>
  <h1 class="pagina__titulo"><?= e($nombrePaciente) ?></h1>
  <p class="pagina__subtitulo">Historial de etapas del flujo operativo. No sustituye las notas firmadas del expediente clínico.</p>
</div>
<ol class="flujo-historial__lista">
  <?php foreach ($eventos as $evento): ?>
    <li>
      <time datetime="<?= e(date(DATE_ATOM, strtotime($evento['creado_en']))) ?>"><?= e(fechaCorta($evento['creado_en'])) ?> · <?= e(substr($evento['creado_en'], 11, 5)) ?></time>
      <strong><?= e($etiquetas[$evento['etapa_anterior']] ?? 'Inicio') ?> → <?= e($etiquetas[$evento['etapa_nueva']] ?? $evento['etapa_nueva']) ?></strong>
      <span>Registró <?= e($evento['usuario_nombre']) ?></span>
      <?php if ($evento['motivo']): ?><p><?= e($evento['motivo']) ?></p><?php endif; ?>
    </li>
  <?php endforeach; ?>
</ol>
<p><a class="boton boton--secundario" href="<?= URL_BASE ?>/flujo/">Volver al flujo de atención</a></p>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
