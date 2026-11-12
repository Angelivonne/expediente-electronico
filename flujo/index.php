<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/flujo.php';

exigirPermiso('ver_expediente');
$institucion = institucionId();
if (!$institucion) {
    http_response_code(403);
    exit('No hay una institución activa.');
}

$etapas = etapasFlujo();
$etiquetas = array_column($etapas, 'nombre', 'clave');
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirPermiso('flujo_clinico');
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró. Recarga el tablero e inténtalo de nuevo.';
    } else {
        $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
        try {
            if ($accion === 'iniciar') {
                $pacienteId = filter_var($_POST['paciente_id'] ?? null, FILTER_VALIDATE_INT);
                if ($pacienteId === false || $pacienteId < 1) {
                    throw new DomainException('Selecciona un paciente válido.');
                }
                iniciarFlujoPaciente((int) $pacienteId, $institucion, (int) usuarioActual()['id']);
                mensajeFlash('exito', 'El paciente se agregó al flujo de atención.');
            } elseif ($accion === 'mover') {
                $flujoId = filter_var($_POST['flujo_id'] ?? null, FILTER_VALIDATE_INT);
                if ($flujoId === false || $flujoId < 1) {
                    throw new DomainException('El episodio seleccionado no es válido.');
                }
                $etapa = is_string($_POST['etapa'] ?? null) ? $_POST['etapa'] : '';
                $motivo = is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '';
                moverFlujoPaciente((int) $flujoId, $etapa, $motivo, $institucion, (int) usuarioActual()['id']);
                mensajeFlash('exito', 'Movimiento registrado en el historial del flujo.');
            } else {
                throw new DomainException('La acción solicitada no es válida.');
            }
            redirigir('/flujo/');
        } catch (DomainException | InvalidArgumentException $e) {
            $errores[] = $e->getMessage();
        } catch (PDOException $e) {
            error_log('[flujo_atencion] ' . $e->getMessage());
            $errores[] = 'No se pudo guardar el movimiento. Actualiza el tablero e inténtalo de nuevo.';
        }
    }
}

$busqueda = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$pacientesParaAgregar = [];
if (mb_strlen($busqueda) >= 2 && puede('flujo_clinico')) {
    $buscar = db()->prepare(
        "SELECT p.id, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
           FROM pacientes p
          WHERE p.institucion_id = ? AND p.eliminado_en IS NULL
            AND (p.nombre LIKE ? OR p.primer_apellido LIKE ? OR p.segundo_apellido LIKE ? OR p.folio LIKE ?)
            AND NOT EXISTS (
              SELECT 1 FROM flujo_atencion f
               WHERE f.paciente_id = p.id AND f.institucion_id = p.institucion_id AND f.cerrado_en IS NULL
            )
          ORDER BY p.primer_apellido, p.nombre LIMIT 12"
    );
    $patron = '%' . $busqueda . '%';
    $buscar->execute([$institucion, $patron, $patron, $patron, $patron]);
    $pacientesParaAgregar = $buscar->fetchAll(PDO::FETCH_ASSOC);
}

$consultaFlujo = db()->prepare(
    "SELECT f.id, f.paciente_id, f.etapa, f.abierto_en, f.actualizado_en,
            p.folio, p.nombre, p.primer_apellido, p.segundo_apellido,
            c.codigo AS cama_codigo, c.area AS cama_area, c.piso AS cama_piso,
            u.usuario_nombre AS ultimo_nombre, u.etapa_anterior, u.etapa_nueva,
            u.motivo AS ultimo_motivo, u.creado_en AS ultimo_movimiento
       FROM flujo_atencion f
       JOIN pacientes p ON p.id = f.paciente_id AND p.institucion_id = f.institucion_id
       LEFT JOIN (
         SELECT cm.institucion_id, cm.paciente_id, MAX(cm.id) AS camilla_id
           FROM camillas cm WHERE cm.estado = 'ocupada'
          GROUP BY cm.institucion_id, cm.paciente_id
       ) ocupada ON ocupada.institucion_id = f.institucion_id AND ocupada.paciente_id = f.paciente_id
       LEFT JOIN camillas c ON c.id = ocupada.camilla_id
       LEFT JOIN (
         SELECT ev.flujo_id, ev.usuario_nombre, ev.etapa_anterior, ev.etapa_nueva, ev.motivo, ev.creado_en
           FROM flujo_atencion_eventos ev
           JOIN (SELECT flujo_id, MAX(id) AS id FROM flujo_atencion_eventos GROUP BY flujo_id) reciente
             ON reciente.id = ev.id
       ) u ON u.flujo_id = f.id
      WHERE f.institucion_id = ? AND f.cerrado_en IS NULL AND p.eliminado_en IS NULL
      ORDER BY f.actualizado_en ASC LIMIT 500"
);
$consultaFlujo->execute([$institucion]);
$activos = $consultaFlujo->fetchAll(PDO::FETCH_ASSOC);
$consultaCerrados = db()->prepare(
    "SELECT f.id, f.cerrado_en, p.id AS paciente_id, p.folio,
            p.nombre, p.primer_apellido, p.segundo_apellido, ev.etapa_nueva
       FROM flujo_atencion f
       JOIN pacientes p ON p.id = f.paciente_id AND p.institucion_id = f.institucion_id
       LEFT JOIN (
         SELECT e.flujo_id, e.etapa_nueva
           FROM flujo_atencion_eventos e
           JOIN (SELECT flujo_id, MAX(id) AS id FROM flujo_atencion_eventos GROUP BY flujo_id) reciente
             ON reciente.id = e.id
       ) ev ON ev.flujo_id = f.id
      WHERE f.institucion_id = ? AND f.cerrado_en IS NOT NULL AND p.eliminado_en IS NULL
      ORDER BY f.cerrado_en DESC LIMIT 20"
);
$consultaCerrados->execute([$institucion]);
$cerrados = $consultaCerrados->fetchAll(PDO::FETCH_ASSOC);
$porEtapa = array_fill_keys(array_keys($etiquetas), []);
foreach ($activos as $fila) {
    if (isset($porEtapa[$fila['etapa']])) {
        $porEtapa[$fila['etapa']][] = $fila;
    }
}

$tituloPagina = 'Flujo de atención';
$seccionActiva = 'flujo';
$claseContenido = 'pagina--ancha flujo-pagina';
$migas = [['texto' => 'Flujo de atención']];
$notificacion = $errores
    ? ['tipo' => 'error', 'texto' => implode(' ', $errores)]
    : tomarFlash();
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera flujo__cabecera">
  <div>
    <h1 class="pagina__titulo">Flujo de atención</h1>
    <p class="pagina__subtitulo">Seguimiento operativo por etapas. Cada cambio conserva autor, hora y motivo en el historial.</p>
  </div>
  <span class="flujo__contador"><?= count($activos) ?> episodios activos</span>
</div>

<?php if (puede('flujo_clinico')): ?>
  <details class="flujo__agregar">
    <summary class="boton boton--secundario">Agregar paciente al flujo</summary>
    <form method="get" class="flujo__buscar">
      <label for="flujo-buscar">Busca por nombre o folio</label>
      <div><input id="flujo-buscar" name="q" value="<?= e($busqueda) ?>" minlength="2" required>
        <button class="boton boton--secundario" type="submit">Buscar</button></div>
    </form>
    <?php if ($busqueda !== '' && mb_strlen($busqueda) < 2): ?>
      <p>Escribe al menos dos caracteres.</p>
    <?php elseif ($pacientesParaAgregar): ?>
      <ul class="flujo__resultados">
        <?php foreach ($pacientesParaAgregar as $paciente): ?>
          <li>
            <span><strong><?= e(trim($paciente['nombre'] . ' ' . $paciente['primer_apellido'] . ' ' . ($paciente['segundo_apellido'] ?? ''))) ?></strong><small>Folio <?= e($paciente['folio']) ?></small></span>
            <form method="post">
              <?= campoCsrf() ?><input type="hidden" name="accion" value="iniciar"><input type="hidden" name="paciente_id" value="<?= (int) $paciente['id'] ?>">
              <button class="boton boton--primario" type="submit">Iniciar seguimiento</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($busqueda !== '' && mb_strlen($busqueda) >= 2): ?>
      <p>No hay pacientes disponibles para agregar con esa búsqueda.</p>
    <?php endif; ?>
  </details>
<?php endif; ?>

<section class="flujo__tablero" aria-label="Pacientes por etapa">
  <?php foreach ($etapas as $etapa): ?>
    <section class="flujo__columna" aria-labelledby="flujo-etapa-<?= e($etapa['clave']) ?>">
      <header class="flujo__columna-cabecera">
        <h2 id="flujo-etapa-<?= e($etapa['clave']) ?>"><?= e($etapa['nombre']) ?></h2>
        <span><?= count($porEtapa[$etapa['clave']]) ?></span>
      </header>
      <?php foreach ($porEtapa[$etapa['clave']] as $fila): ?>
        <article class="flujo__tarjeta">
          <h3><a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $fila['paciente_id'] ?>"><?= e(trim($fila['nombre'] . ' ' . $fila['primer_apellido'] . ' ' . ($fila['segundo_apellido'] ?? ''))) ?></a></h3>
          <p class="flujo__folio">Folio <?= e($fila['folio']) ?></p>
          <?php if ($fila['cama_codigo']): ?>
            <p class="flujo__ubicacion">Ubicación: <?= e($fila['cama_area']) ?> · <?= e($fila['cama_codigo']) ?><?= $fila['cama_piso'] ? ' · ' . e($fila['cama_piso']) : '' ?></p>
          <?php endif; ?>
          <p class="flujo__tiempo">En seguimiento desde <?= e(fechaCorta($fila['abierto_en'])) ?> · actualizado <?= e(fechaCorta($fila['actualizado_en'])) ?></p>
          <?php if ($fila['ultimo_nombre']): ?>
            <details class="flujo__ultimo">
              <summary>Último movimiento</summary>
              <p><?= e($etiquetas[$fila['etapa_anterior']] ?? 'Inicio') ?> → <?= e($etiquetas[$fila['etapa_nueva']] ?? $fila['etapa_nueva']) ?></p>
              <p><?= e($fila['ultimo_nombre']) ?> · <?= e(fechaCorta($fila['ultimo_movimiento'])) ?><?= $fila['ultimo_motivo'] ? ' · ' . e($fila['ultimo_motivo']) : '' ?></p>
            </details>
          <?php endif; ?>
          <a class="flujo__historial-enlace" href="<?= URL_BASE ?>/flujo/historial.php?id=<?= (int) $fila['id'] ?>">Ver historial completo</a>
          <?php if (puede('flujo_clinico')): ?>
            <form method="post" class="flujo__mover">
              <?= campoCsrf() ?><input type="hidden" name="accion" value="mover"><input type="hidden" name="flujo_id" value="<?= (int) $fila['id'] ?>">
              <label>Pasar a
                <select name="etapa" required>
                  <?php foreach ($etapas as $destino): if ($destino['clave'] === $fila['etapa']) { continue; } ?>
                    <option value="<?= e($destino['clave']) ?>"><?= e($destino['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Motivo (opcional)<input name="motivo" maxlength="255" placeholder="Nota breve del movimiento"></label>
              <button class="boton boton--secundario" type="submit">Registrar movimiento</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$porEtapa[$etapa['clave']]): ?><p class="flujo__vacio">Sin pacientes</p><?php endif; ?>
    </section>
  <?php endforeach; ?>
</section>

<?php if ($cerrados): ?>
  <section class="flujo__cerrados">
    <h2>Episodios recientes con egreso</h2>
    <ul>
      <?php foreach ($cerrados as $cerrado): ?>
        <li>
          <a href="<?= URL_BASE ?>/flujo/historial.php?id=<?= (int) $cerrado['id'] ?>"><?= e(trim($cerrado['nombre'] . ' ' . $cerrado['primer_apellido'] . ' ' . ($cerrado['segundo_apellido'] ?? ''))) ?></a>
          <span>Folio <?= e($cerrado['folio']) ?> · <?= e(fechaCorta($cerrado['cerrado_en'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
