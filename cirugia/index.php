<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/cirugia.php';

exigirPermiso('ver_expediente');
$institucion = institucionId();
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirPermiso('gestionar_cirugia');
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró. Recarga la página e inténtalo de nuevo.';
    } else {
        try {
            $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
            if ($accion === 'programar') {
                $pacienteId = filter_var($_POST['paciente_id'] ?? null, FILTER_VALIDATE_INT);
                $cirujanoId = filter_var($_POST['cirujano_id'] ?? null, FILTER_VALIDATE_INT);
                $anestesiologoRaw = $_POST['anestesiologo_id'] ?? '';
                $anestesiologoId = $anestesiologoRaw === '' ? null : filter_var($anestesiologoRaw, FILTER_VALIDATE_INT);
                $duracion = filter_var($_POST['duracion_min'] ?? null, FILTER_VALIDATE_INT);
                $fechaLocal = is_string($_POST['programada_en'] ?? null) ? $_POST['programada_en'] : '';
                $fecha = str_replace('T', ' ', $fechaLocal);
                if ($fechaLocal !== '' && strlen($fecha) === 16) {
                    $fecha .= ':00';
                }
                if ($pacienteId === false || $cirujanoId === false || $duracion === false
                    || ($anestesiologoId !== null && $anestesiologoId === false)) {
                    throw new DomainException('Completa los datos de paciente y equipo quirúrgico.');
                }
                crearCirugiaProgramada(
                    (int) $pacienteId,
                    $fecha,
                    (int) $duracion,
                    is_string($_POST['procedimiento'] ?? null) ? $_POST['procedimiento'] : '',
                    is_string($_POST['quirofano'] ?? null) ? $_POST['quirofano'] : '',
                    (int) $cirujanoId,
                    $anestesiologoId === null ? null : (int) $anestesiologoId,
                    $institucion,
                    (int) usuarioActual()['id'],
                    (string) usuarioActual()['nombre']
                );
                mensajeFlash('exito', 'Cirugía programada y registrada en la agenda.');
            } elseif ($accion === 'estado') {
                $id = filter_var($_POST['cirugia_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    throw new DomainException('La cirugía seleccionada no es válida.');
                }
                cambiarEstadoCirugia(
                    (int) $id,
                    is_string($_POST['estado'] ?? null) ? $_POST['estado'] : '',
                    is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '',
                    $institucion,
                    (int) usuarioActual()['id'],
                    (string) usuarioActual()['nombre']
                );
                mensajeFlash('exito', 'Estado operativo actualizado; el cambio quedó en el historial.');
            } else {
                throw new DomainException('La acción solicitada no es válida.');
            }
            redirigir('/cirugia/');
        } catch (DomainException $e) {
            $errores[] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('[cirugias] ' . $e->getMessage());
            $errores[] = 'No se pudo guardar la cirugía. Revisa los datos e inténtalo de nuevo.';
        }
    }
}

$busqueda = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$pacientes = [];
if (mb_strlen($busqueda) >= 2 && puede('gestionar_cirugia')) {
    $buscar = db()->prepare(
        'SELECT id, folio, nombre, primer_apellido, segundo_apellido
           FROM pacientes
          WHERE institucion_id = ? AND eliminado_en IS NULL
            AND (nombre LIKE ? OR primer_apellido LIKE ? OR segundo_apellido LIKE ? OR folio LIKE ?)
          ORDER BY primer_apellido, nombre LIMIT 12'
    );
    $like = '%' . $busqueda . '%';
    $buscar->execute([$institucion, $like, $like, $like, $like]);
    $pacientes = $buscar->fetchAll(PDO::FETCH_ASSOC);
}
$pacienteSeleccionado = null;
$pacienteSeleccionadoId = filter_var($_POST['paciente_id'] ?? $_GET['paciente'] ?? null, FILTER_VALIDATE_INT);
if ($pacienteSeleccionadoId !== false && $pacienteSeleccionadoId > 0) {
    $consultaPaciente = db()->prepare(
        'SELECT id, folio, nombre, primer_apellido, segundo_apellido
           FROM pacientes
          WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL'
    );
    $consultaPaciente->execute([(int) $pacienteSeleccionadoId, $institucion]);
    $pacienteSeleccionado = $consultaPaciente->fetch(PDO::FETCH_ASSOC) ?: null;
}

$personalConsulta = db()->prepare(
    "SELECT id, nombre, especialidad
       FROM usuarios
      WHERE institucion_id = ? AND activo = 1 AND rol = 'medico'
      ORDER BY nombre LIMIT 100"
);
$personalConsulta->execute([$institucion]);
$medicos = $personalConsulta->fetchAll(PDO::FETCH_ASSOC);

$consultaAgenda = db()->prepare(
    "SELECT c.id, c.paciente_id, c.programada_en, c.duracion_min, c.procedimiento, c.quirofano, c.estado,
            p.folio, p.nombre AS paciente_nombre, p.primer_apellido, p.segundo_apellido,
            q.nombre AS cirujano, a.nombre AS anestesiologo
       FROM cirugias_programadas c
       JOIN pacientes p ON p.id = c.paciente_id AND p.institucion_id = c.institucion_id
       JOIN usuarios q ON q.id = c.cirujano_id
       LEFT JOIN usuarios a ON a.id = c.anestesiologo_id
      WHERE c.institucion_id = ? AND p.eliminado_en IS NULL
        AND (c.estado NOT IN ('finalizada','cancelada') OR c.programada_en >= DATE_SUB(NOW(), INTERVAL 30 DAY))
      ORDER BY c.programada_en LIMIT 200"
);
$consultaAgenda->execute([$institucion]);
$agenda = $consultaAgenda->fetchAll(PDO::FETCH_ASSOC);

$tituloPagina = 'Agenda quirúrgica';
$seccionActiva = 'cirugia';
$claseContenido = 'pagina--ancha cirugia-pagina';
$migas = [['texto' => 'Agenda quirúrgica']];
$notificacion = $errores ? ['tipo' => 'error', 'texto' => implode(' ', $errores)] : tomarFlash();
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Agenda quirúrgica</h1>
  <p class="pagina__subtitulo">Seguimiento logístico y estados operativos. No sustituye la valoración preoperatoria, la lista de verificación quirúrgica ni las notas clínicas firmadas.</p>
</div>

<?php if (puede('gestionar_cirugia')): ?>
  <details class="cirugia__programar"<?= $pacienteSeleccionado ? ' open' : '' ?>>
    <summary class="boton boton--secundario">Programar cirugía</summary>
    <?php if (!$pacienteSeleccionado): ?>
      <form method="get" class="cirugia__buscar">
        <label for="cirugia-paciente">Busca al paciente por nombre o folio</label>
        <div><input id="cirugia-paciente" name="q" minlength="2" value="<?= e($busqueda) ?>" required><button class="boton boton--secundario">Buscar</button></div>
      </form>
      <?php if ($pacientes): ?>
        <ul class="cirugia__resultados">
          <?php foreach ($pacientes as $paciente): ?>
            <li><span><?= e(trim($paciente['nombre'] . ' ' . $paciente['primer_apellido'] . ' ' . ($paciente['segundo_apellido'] ?? ''))) ?> · <?= e($paciente['folio']) ?></span><a class="boton boton--secundario" href="?paciente=<?= (int) $paciente['id'] ?>">Seleccionar</a></li>
          <?php endforeach; ?>
        </ul>
      <?php elseif ($busqueda !== ''): ?><p>No se encontraron pacientes con esa búsqueda.</p><?php endif; ?>
    <?php else: ?>
      <p class="cirugia__paciente-seleccionado"><strong><?= e(trim($pacienteSeleccionado['nombre'] . ' ' . $pacienteSeleccionado['primer_apellido'] . ' ' . ($pacienteSeleccionado['segundo_apellido'] ?? ''))) ?></strong> · Folio <?= e($pacienteSeleccionado['folio']) ?> <a href="<?= URL_BASE ?>/cirugia/">Cambiar</a></p>
      <?php if (!$medicos): ?>
        <p>No hay personal médico activo en esta institución para asignar el procedimiento.</p>
      <?php else: ?>
        <form method="post" class="cirugia__formulario">
          <?= campoCsrf() ?><input type="hidden" name="accion" value="programar"><input type="hidden" name="paciente_id" value="<?= (int) $pacienteSeleccionado['id'] ?>">
          <label>Procedimiento<input name="procedimiento" maxlength="160" required value="<?= e(is_string($_POST['procedimiento'] ?? null) ? $_POST['procedimiento'] : '') ?>"></label>
          <label>Quirófano o sala<input name="quirofano" maxlength="80" required value="<?= e(is_string($_POST['quirofano'] ?? null) ? $_POST['quirofano'] : '') ?>"></label>
          <label>Fecha y hora<input type="datetime-local" name="programada_en" required value="<?= e(is_string($_POST['programada_en'] ?? null) ? $_POST['programada_en'] : '') ?>"></label>
          <label>Duración prevista
            <select name="duracion_min">
              <?php foreach ([30,45,60,90,120,180,240] as $minutos): ?><option value="<?= $minutos ?>"<?= (int) ($_POST['duracion_min'] ?? 60) === $minutos ? ' selected' : '' ?>><?= $minutos ?> minutos</option><?php endforeach; ?>
            </select>
          </label>
          <label>Cirujano<select name="cirujano_id" required><option value="">Selecciona</option><?php foreach ($medicos as $medico): ?><option value="<?= (int) $medico['id'] ?>"<?= (int) ($_POST['cirujano_id'] ?? 0) === (int) $medico['id'] ? ' selected' : '' ?>><?= e($medico['nombre']) ?><?= $medico['especialidad'] ? ' · ' . e($medico['especialidad']) : '' ?></option><?php endforeach; ?></select></label>
          <label>Anestesiólogo (opcional)<select name="anestesiologo_id"><option value="">Sin asignar</option><?php foreach ($medicos as $medico): ?><option value="<?= (int) $medico['id'] ?>"<?= (int) ($_POST['anestesiologo_id'] ?? 0) === (int) $medico['id'] ? ' selected' : '' ?>><?= e($medico['nombre']) ?><?= $medico['especialidad'] ? ' · ' . e($medico['especialidad']) : '' ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="boton boton--primario">Guardar programación</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </details>
<?php endif; ?>

<section class="cirugia__agenda">
  <h2>Programación y seguimiento</h2>
  <?php if (!$agenda): ?><p>Aún no hay procedimientos programados.</p><?php endif; ?>
  <?php foreach ($agenda as $cirugia): ?>
    <article class="cirugia__tarjeta">
      <div class="cirugia__detalle">
        <span class="cirugia__estado"><?= e(ESTADOS_CIRUGIA[$cirugia['estado']]) ?></span>
        <h3><?= e($cirugia['procedimiento']) ?></h3>
        <p><a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $cirugia['paciente_id'] ?>"><?= e(trim($cirugia['paciente_nombre'] . ' ' . $cirugia['primer_apellido'] . ' ' . ($cirugia['segundo_apellido'] ?? ''))) ?></a> · Folio <?= e($cirugia['folio']) ?></p>
        <p><?= e(fechaCorta($cirugia['programada_en'])) ?> · <?= e(substr($cirugia['programada_en'], 11, 5)) ?> · <?= (int) $cirugia['duracion_min'] ?> min · <?= e($cirugia['quirofano']) ?></p>
        <p>Cirujano: <?= e($cirugia['cirujano']) ?><?= $cirugia['anestesiologo'] ? ' · Anestesiología: ' . e($cirugia['anestesiologo']) : '' ?></p>
        <a href="<?= URL_BASE ?>/cirugia/historial.php?id=<?= (int) $cirugia['id'] ?>">Ver historial operativo</a>
      </div>
      <?php $siguientes = TRANSICIONES_CIRUGIA[$cirugia['estado']] ?? []; ?>
      <?php if ($siguientes && puede('gestionar_cirugia')): ?>
        <form method="post" class="cirugia__cambio">
          <?= campoCsrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="cirugia_id" value="<?= (int) $cirugia['id'] ?>">
          <label>Siguiente estado<select name="estado"><?php foreach ($siguientes as $estado): ?><option value="<?= e($estado) ?>"><?= e(ESTADOS_CIRUGIA[$estado]) ?></option><?php endforeach; ?></select></label>
          <label>Motivo (opcional)<input name="motivo" maxlength="255"></label>
          <button class="boton boton--secundario">Actualizar estado</button>
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
