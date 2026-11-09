<?php
/**
 * Módulo de Kardex de Medicación y Cuidados de Enfermería Hospitalaria.
 * Control farmacoterapéutico por turnos (Matutino, Vespertino, Nocturno)
 * conforme a la NOM-004-SSA3-2012 y NOM-019-SSA3-2013.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId() ?: 1;
$usuario = usuarioActual() ?: ['id' => 1, 'nombre' => 'Personal de Enfermería'];

// Procesar formularios POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
        redirigir($_SERVER['REQUEST_URI']);
    }

    $accion = $_POST['accion'] ?? '';

    // 1. Agregar nueva indicación / medicación al Kardex
    if ($accion === 'agregar_medicacion') {
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        $camillaId = (int) ($_POST['camilla_id'] ?? 0) ?: null;
        $medicamento = mb_substr(trim((string) ($_POST['medicamento'] ?? '')), 0, 150);
        $dosis = mb_substr(trim((string) ($_POST['dosis'] ?? '')), 0, 80);
        $via = mb_substr(trim((string) ($_POST['via'] ?? '')), 0, 50);
        $frecuencia = mb_substr(trim((string) ($_POST['frecuencia'] ?? '')), 0, 80);
        $horario = mb_substr(trim((string) ($_POST['horario'] ?? '')), 0, 100);
        $fechaInicio = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d');
        $indicaciones = trim((string) ($_POST['indicaciones'] ?? ''));
        $medicoPrescribe = mb_substr(trim((string) ($_POST['medico_prescribe'] ?? $usuario['nombre'])), 0, 120);

        if ($pacienteId && $medicamento && $dosis && $via) {
            $stmt = $pdo->prepare("INSERT INTO kardex_medicaciones 
                (institucion_id, paciente_id, camilla_id, medicamento, dosis, via, frecuencia, horario, fecha_inicio, indicaciones, estado, medico_prescribe)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
            $stmt->execute([$institucionId, $pacienteId, $camillaId, $medicamento, $dosis, $via, $frecuencia, $horario, $fechaInicio, $indicaciones, $medicoPrescribe]);

            registrarBitacora('kardex_medicaciones', (int)$pdo->lastInsertId(), 'crear', 'medicamento', null, $medicamento);
            mensajeFlash('exito', 'Medicamento prescrito y agregado al Kardex.');
        } else {
            mensajeFlash('error', 'Faltan campos obligatorios para registrar la medicación.');
        }
        redirigir("/camillas/kardex.php?paciente_id={$pacienteId}");
    }

    // 2. Registrar administración de una dosis
    if ($accion === 'registrar_administracion') {
        $medicacionId = (int) ($_POST['medicacion_id'] ?? 0);
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        $turno = in_array($_POST['turno'] ?? '', ['matutino', 'vespertino', 'nocturno'], true) ? $_POST['turno'] : 'matutino';
        $fecha = !empty($_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d');
        $horaProg = mb_substr(trim((string) ($_POST['hora_programada'] ?? date('H:i'))), 0, 10);
        $estado = in_array($_POST['estado'] ?? '', ['aplicado', 'pendiente', 'omitido', 'rechazado'], true) ? $_POST['estado'] : 'aplicado';
        $enfermero = mb_substr(trim((string) ($_POST['enfermero_nombre'] ?? $usuario['nombre'])), 0, 120);
        $obs = trim((string) ($_POST['observaciones'] ?? ''));

        if ($medicacionId && $pacienteId) {
            $horaApl = ($estado === 'aplicado') ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("INSERT INTO kardex_administraciones 
                (institucion_id, medicacion_id, paciente_id, turno, fecha, hora_programada, hora_aplicacion, estado, enfermero_nombre, observaciones)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$institucionId, $medicacionId, $pacienteId, $turno, $fecha, $horaProg, $horaApl, $estado, $enfermero, $obs]);

            registrarBitacora('kardex_administraciones', (int)$pdo->lastInsertId(), 'crear', 'estado', null, $estado);
            mensajeFlash('exito', 'Dosis registrada en la Hoja de Enfermería.');
        }
        redirigir("/camillas/kardex.php?paciente_id={$pacienteId}&fecha={$fecha}");
    }

    // 3. Suspender medicación
    if ($accion === 'suspender_medicacion') {
        $medId = (int) ($_POST['medicacion_id'] ?? 0);
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        if ($medId) {
            $stmt = $pdo->prepare("UPDATE kardex_medicaciones SET estado = 'suspendido' WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$medId, $institucionId]);
            registrarBitacora('kardex_medicaciones', $medId, 'actualizar', 'estado', 'activo', 'suspendido');
            mensajeFlash('exito', 'Medicamento marcado como SUSPENDIDO.');
        }
        redirigir("/camillas/kardex.php?paciente_id={$pacienteId}");
    }
}

// Determinar paciente seleccionado
$pacienteId = (int) ($_GET['paciente_id'] ?? 0);
$camillaId = (int) ($_GET['camilla_id'] ?? 0);

if (!$pacienteId && $camillaId) {
    $stmtC = $pdo->prepare("SELECT paciente_id FROM camillas WHERE id = ? AND institucion_id = ?");
    $stmtC->execute([$camillaId, $institucionId]);
    $pacienteId = (int) ($stmtC->fetchColumn() ?: 0);
}

// Lista de pacientes hospitalizados en camillas para el selector rápido
$stmtHosp = $pdo->prepare("SELECT c.id as camilla_id, c.codigo, c.area, p.id as pac_id, p.nombre, p.primer_apellido, p.segundo_apellido, p.folio
    FROM camillas c 
    INNER JOIN pacientes p ON p.id = c.paciente_id 
    WHERE c.institucion_id = ? AND c.estado = 'ocupada' AND p.eliminado_en IS NULL
    ORDER BY c.area ASC, c.codigo ASC");
$stmtHosp->execute([$institucionId]);
$pacientesHospitalizados = $stmtHosp->fetchAll(PDO::FETCH_ASSOC);

if (!$pacienteId && !empty($pacientesHospitalizados)) {
    $pacienteId = (int) $pacientesHospitalizados[0]['pac_id'];
}

$paciente = null;
$camillaActual = null;
$medicacionesActivas = [];
$administraciones = [];
$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');

if ($pacienteId) {
    $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
    $stmtP->execute([$pacienteId, $institucionId]);
    $paciente = $stmtP->fetch(PDO::FETCH_ASSOC);

    if ($paciente) {
        $stmtCam = $pdo->prepare("SELECT * FROM camillas WHERE paciente_id = ? AND institucion_id = ? AND estado = 'ocupada' LIMIT 1");
        $stmtCam->execute([$pacienteId, $institucionId]);
        $camillaActual = $stmtCam->fetch(PDO::FETCH_ASSOC);

        $stmtM = $pdo->prepare("SELECT * FROM kardex_medicaciones WHERE paciente_id = ? AND institucion_id = ? AND estado = 'activo' ORDER BY creado_en ASC");
        $stmtM->execute([$pacienteId, $institucionId]);
        $medicacionesActivas = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $stmtA = $pdo->prepare("SELECT a.*, m.medicamento, m.dosis, m.via, m.horario
            FROM kardex_administraciones a
            INNER JOIN kardex_medicaciones m ON m.id = a.medicacion_id
            WHERE a.paciente_id = ? AND a.institucion_id = ? AND a.fecha = ?
            ORDER BY a.hora_programada ASC, a.creado_en ASC");
        $stmtA->execute([$pacienteId, $institucionId, $fechaFiltro]);
        $administraciones = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }
}

$adminPorTurno = [
    'matutino' => [],
    'vespertino' => [],
    'nocturno' => []
];
foreach ($administraciones as $adm) {
    $t = $adm['turno'] ?? 'matutino';
    if (isset($adminPorTurno[$t])) {
        $adminPorTurno[$t][] = $adm;
    }
}

$edadPaciente = null;
if ($paciente && !empty($paciente['fecha_nacimiento'])) {
    $edadPaciente = (new DateTime($paciente['fecha_nacimiento']))->diff(new DateTime('today'))->y;
}

$nombreCompleto = $paciente ? trim("{$paciente['nombre']} {$paciente['primer_apellido']} {$paciente['segundo_apellido']}") : '';
$iniciales = $paciente ? mb_strtoupper(mb_substr($paciente['nombre'], 0, 1) . mb_substr($paciente['primer_apellido'], 0, 1)) : '';
$sinAlergias = empty($paciente['alergias']) || mb_strtolower($paciente['alergias']) === 'negadas';

$tituloPagina = $paciente ? "Kardex — {$nombreCompleto}" : 'Kardex de Medicación';
$seccionActiva = 'camillas';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente">

  <?php if (!$paciente): ?>
    <div class="pagina__cabecera">
      <h1 class="pagina__titulo">Kardex de Medicación</h1>
      <p class="pagina__subtitulo">No hay ningún paciente seleccionado.</p>
    </div>
    <p class="bd__vacio"><a href="<?= URL_BASE ?>/camillas/">Ir al Censo de Camas</a> para asignar pacientes.</p>
  <?php else: ?>

    <!-- Encabezado Idéntico a pacientes/ver.php (hoja-paciente) -->
    <header class="hoja-paciente" id="perfil">
      <div class="hoja-paciente__foto">
        <span class="hoja-paciente__iniciales" aria-hidden="true"><?= e($iniciales) ?></span>
      </div>
      <div class="hoja-paciente__identidad">
        <h1 class="hoja-paciente__nombre"><?= e($nombreCompleto) ?></h1>
        <p class="hoja-paciente__linea">
          <span><?= e($paciente['folio']) ?></span>
          <span><?= $edadPaciente !== null ? "{$edadPaciente} años" : '—' ?></span>
          <span><?= e($paciente['sexo'] === 'M' ? 'Femenino' : 'Masculino') ?></span>
          <span>Nació el <?= e(fechaCorta($paciente['fecha_nacimiento'])) ?></span>
        </p>
        <ul class="hoja-paciente__alertas">
          <?php if (!$sinAlergias): ?>
            <li class="hoja-paciente__alerta hoja-paciente__alerta--alergia">
              <?= icono('alerta') ?><span><b>Alergias</b> <?= e($paciente['alergias']) ?></span>
            </li>
          <?php endif; ?>
          <?php if ($camillaActual): ?>
            <li class="hoja-paciente__alerta hoja-paciente__alerta--hospital">
              <?= icono('clinico') ?><span><b>Cama:</b> <?= e($camillaActual['codigo']) ?> (<?= e(ucfirst($camillaActual['area'])) ?>)</span>
            </li>
          <?php endif; ?>
          <li class="hoja-paciente__alerta">
            <?= icono('documento') ?><span><b>Diagnóstico de ingreso:</b> <?= e($camillaActual['diagnostico_ingreso'] ?? $paciente['motivo_consulta'] ?? 'No documentado') ?></span>
          </li>
        </ul>
      </div>
    </header>

    <!-- Resumen Métrico (Idéntico a pacientes/ver.php hoja-paciente__resumen) -->
    <dl class="hoja-paciente__resumen">
      <div><dt>Grupo sanguíneo</dt><dd><?= e($paciente['grupo_sanguineo'] ?: '—') ?></dd></div>
      <div><dt>Cama asignada</dt><dd><?= e($camillaActual['codigo'] ?? 'Sin cama') ?></dd></div>
      <div><dt>Ingreso</dt><dd><?= $camillaActual['ingreso_en'] ? e(fechaCorta($camillaActual['ingreso_en'])) : '—' ?></dd></div>
      <div><dt>Fármacos activos</dt><dd><?= count($medicacionesActivas) ?></dd></div>
      <div><dt>Dosis matutino</dt><dd><?= count($adminPorTurno['matutino']) ?></dd></div>
      <div><dt>Dosis vespertino</dt><dd><?= count($adminPorTurno['vespertino']) ?></dd></div>
      <div><dt>Dosis nocturno</dt><dd><?= count($adminPorTurno['nocturno']) ?></dd></div>
    </dl>

    <!-- Cuerpo en dos columnas (hoja-paciente__cuerpo) -->
    <div class="hoja-paciente__cuerpo">

      <!-- Columna Principal: Prescripciones y Administraciones -->
      <div class="hoja-paciente__datos">

        <!-- Sección: Fármacos Prescritos Activos (formato lista-clinica) -->
        <section class="ficha__seccion">
          <div class="ficha__titulo-fila">
            <h2 class="ficha__titulo">Medicamentos prescritos (Kardex activo)</h2>
            <span class="ficha__fuente">NOM-004-SSA3-2012</span>
          </div>

          <?php if (empty($medicacionesActivas)): ?>
            <p class="ficha__vacio">Sin medicamentos prescritos activos en el Kardex.</p>
          <?php else: ?>
            <ul class="lista-clinica">
              <?php foreach ($medicacionesActivas as $med): ?>
                <li>
                  <a href="javascript:void(0)" onclick="prepararAdminDosis(<?= (int)$med['id'] ?>, '<?= e($med['medicamento']) ?>', '<?= e($med['dosis']) ?>', '<?= e($med['horario'] ?: '08:00') ?>');">
                    <span class="lista-clinica__icono"><?= icono('pildora') ?></span>
                    <span class="lista-clinica__texto">
                      <b><?= e($med['medicamento']) ?></b> · <?= e($med['dosis']) ?> · <?= e($med['via']) ?>
                      <small>Frecuencia: <?= e($med['frecuencia']) ?> · Horario: <?= e($med['horario'] ?: 'Según indicación') ?> · Inicio: <?= e(fechaCorta($med['fecha_inicio'])) ?><?= $med['indicaciones'] ? ' · ' . e($med['indicaciones']) : '' ?> · Prescribe: <?= e($med['medico_prescribe'] ?: 'Servicio Médico') ?></small>
                    </span>
                  </a>
                  <div style="display: flex; gap: 4px; align-items: center; margin-left: auto;">
                    <button type="button" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs);" onclick="prepararAdminDosis(<?= (int)$med['id'] ?>, '<?= e($med['medicamento']) ?>', '<?= e($med['dosis']) ?>', '<?= e($med['horario'] ?: '08:00') ?>');">
                      <?= icono('agregar') ?> Dosis
                    </button>
                    <form method="post" action="" style="display: inline;" onsubmit="return confirm('¿Suspender este medicamento del Kardex?');">
                      <?= campoCsrf() ?>
                      <input type="hidden" name="accion" value="suspender_medicacion">
                      <input type="hidden" name="medicacion_id" value="<?= (int)$med['id'] ?>">
                      <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
                      <button type="submit" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs); color: #f87171;">
                        Suspender
                      </button>
                    </form>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <button type="button" class="hoja-paciente__agendar" onclick="document.getElementById('modalNuevaMed').showModal();" style="margin-top: 10px;">
            <?= icono('agregar') ?> Prescribir medicamento al Kardex
          </button>
        </section>

        <!-- Sección: Tabla Nativa de Aplicación de Dosis (bd-tabla) -->
        <section class="ficha__seccion" style="margin-top: 24px;">
          <div class="ficha__titulo-fila">
            <h2 class="ficha__titulo">Hoja de enfermería y administración de dosis</h2>
            <span class="ficha__fuente">Fecha: <?= e(fechaCorta($fechaFiltro)) ?> · NOM-019-SSA3-2013</span>
          </div>

          <?php if (empty($administraciones)): ?>
            <p class="ficha__vacio">No hay dosis registradas para la fecha seleccionada (<?= e(fechaCorta($fechaFiltro)) ?>).</p>
          <?php else: ?>
            <div class="bd__marco" style="margin-top: 8px;">
              <table class="bd-tabla">
                <thead>
                  <tr>
                    <th scope="col"><button type="button"><?= icono('reloj') ?> <span>Turno</span></button></th>
                    <th scope="col"><button type="button"><?= icono('reloj') ?> <span>Hora prog.</span></button></th>
                    <th scope="col"><button type="button"><?= icono('pildora') ?> <span>Medicamento</span></button></th>
                    <th scope="col"><button type="button"><?= icono('etiqueta') ?> <span>Dosis / Vía</span></button></th>
                    <th scope="col"><button type="button"><?= icono('verificado') ?> <span>Estado</span></button></th>
                    <th scope="col"><button type="button"><?= icono('usuarios') ?> <span>Enfermero/a</span></button></th>
                    <th scope="col"><button type="button"><?= icono('documento') ?> <span>Notas</span></button></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($administraciones as $adm): 
                    $colorEstado = match($adm['estado']) {
                        'aplicado' => '#4ade80',
                        'pendiente' => '#facc15',
                        default => '#f87171'
                    };
                  ?>
                    <tr>
                      <td style="text-transform: capitalize;"><?= e($adm['turno']) ?></td>
                      <td class="num" style="font-weight: 600;"><?= e($adm['hora_programada']) ?></td>
                      <td class="bd-tabla__celda-titulo">
                        <span class="bd-tabla__titulo">
                          <span><?= e($adm['medicamento']) ?></span>
                        </span>
                      </td>
                      <td><?= e($adm['dosis']) ?> (<?= e($adm['via']) ?>)</td>
                      <td>
                        <span style="color: <?= $colorEstado ?>; font-weight: 600;"><?= ucfirst($adm['estado']) ?></span>
                      </td>
                      <td><?= e($adm['enfermero_nombre'] ?: '—') ?></td>
                      <td><?= e($adm['observaciones'] ?: '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <button type="button" class="hoja-paciente__agendar" onclick="document.getElementById('modalRegistrarAdmin').showModal();" style="margin-top: 10px;">
            <?= icono('agregar') ?> Asentar aplicación de dosis
          </button>
        </section>

      </div>

      <!-- Columna Lateral Derecha (hoja-paciente__lado) -->
      <aside class="hoja-paciente__lado">

        <section class="ficha__seccion">
          <div class="ficha__titulo-fila">
            <h2 class="ficha__titulo">Control de paciente</h2>
          </div>

          <form method="get" action="" style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: var(--fs-xs); color: var(--texto-suave);">Cambiar paciente hospitalizado:</label>
            <select name="paciente_id" onchange="this.form.submit()" style="background: #202020; color: #fff; border: 1px solid #333; padding: 6px 8px; border-radius: 6px; font-size: var(--fs-xs);">
              <?php foreach ($pacientesHospitalizados as $ph): ?>
                <option value="<?= (int)$ph['pac_id'] ?>" <?= $pacienteId === (int)$ph['pac_id'] ? 'selected' : '' ?>>
                  [<?= e($ph['codigo']) ?>] <?= e("{$ph['nombre']} {$ph['primer_apellido']}") ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label style="font-size: var(--fs-xs); color: var(--texto-suave); margin-top: 6px;">Fecha de administración:</label>
            <input type="date" name="fecha" value="<?= e($fechaFiltro) ?>" onchange="this.form.submit()" style="background: #202020; color: #fff; border: 1px solid #333; padding: 6px 8px; border-radius: 6px; font-size: var(--fs-xs);">
          </form>
        </section>

        <section class="ficha__seccion">
          <div class="ficha__titulo-fila">
            <h2 class="ficha__titulo">Acciones del Kardex</h2>
          </div>

          <div style="display: flex; flex-direction: column; gap: 6px;">
            <button type="button" class="boton boton--secundario" onclick="window.print();" style="width: 100%; justify-content: flex-start;">
              <?= icono('documento') ?> <span>Imprimir Kardex oficial</span>
            </button>
            <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$paciente['id'] ?>" class="boton boton--sutil" style="width: 100%; justify-content: flex-start;">
              <?= icono('usuarios') ?> <span>Ver expediente completo</span>
            </a>
            <a href="<?= URL_BASE ?>/camillas/" class="boton boton--sutil" style="width: 100%; justify-content: flex-start;">
              <?= icono('clinico') ?> <span>Censo de camillas</span>
            </a>
          </div>
        </section>

      </aside>

    </div>

    <!-- Modal: Prescribir Medicación -->
    <dialog id="modalNuevaMed" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 520px; width: 95%;">
      <form method="post" action="">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="agregar_medicacion">
        <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
        <input type="hidden" name="camilla_id" value="<?= (int)($camillaActual['id'] ?? 0) ?>">

        <div class="ficha__titulo-fila" style="margin-bottom: 14px;">
          <h3 class="ficha__titulo" style="font-size: 1.1rem; margin: 0;">Prescribir Medicación al Kardex</h3>
        </div>

        <div style="display: flex; flex-direction: column; gap: 11px;">
          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Medicamento y Presentación *</label>
            <input type="text" name="medicamento" required placeholder="Ej. Ceftriaxona 1g Fco. Ámpula" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Dosis *</label>
              <input type="text" name="dosis" required placeholder="Ej. 1 g, 50 mg" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Vía *</label>
              <select name="via" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
                <option value="Intravenosa (IV)">Intravenosa (IV)</option>
                <option value="Oral (VO)">Oral (VO)</option>
                <option value="Subcutánea (SC)">Subcutánea (SC)</option>
                <option value="Intramuscular (IM)">Intramuscular (IM)</option>
                <option value="Inhalatoria">Inhalatoria</option>
                <option value="Tópica">Tópica</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Frecuencia</label>
              <input type="text" name="frecuencia" required placeholder="Ej. Cada 8 horas, Cada 12 horas" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Horario</label>
              <input type="text" name="horario" placeholder="08:00, 20:00" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Indicaciones / Dilución</label>
            <input type="text" name="indicaciones" placeholder="Ej. Diluir en 100ml Sol. Fisiológica..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          </div>

          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Médico Prescribe</label>
            <input type="text" name="medico_prescribe" value="<?= e($usuario['nombre']) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
          <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalNuevaMed').close();">Cancelar</button>
          <button type="submit" class="boton boton--primario">Guardar Prescripción</button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Asentar Dosis -->
    <dialog id="modalRegistrarAdmin" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 500px; width: 95%;">
      <form method="post" action="">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="registrar_administracion">
        <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">

        <div class="ficha__titulo-fila" style="margin-bottom: 14px;">
          <h3 class="ficha__titulo" style="font-size: 1.1rem; margin: 0;">Asentar Administración de Dosis</h3>
        </div>

        <div style="display: flex; flex-direction: column; gap: 11px;">
          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Medicamento Prescrito *</label>
            <select id="admin_medicacion_id" name="medicacion_id" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
              <?php foreach ($medicacionesActivas as $mAct): ?>
                <option value="<?= (int)$mAct['id'] ?>">
                  <?= e($mAct['medicamento']) ?> — <?= e($mAct['dosis']) ?> (<?= e($mAct['via']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Turno *</label>
              <select name="turno" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
                <option value="matutino">Matutino (07:00 - 15:00)</option>
                <option value="vespertino">Vespertino (15:00 - 23:00)</option>
                <option value="nocturno">Nocturno (23:00 - 07:00)</option>
              </select>
            </div>
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Hora Programada</label>
              <input type="text" id="admin_hora_prog" name="hora_programada" value="<?= date('H:i') ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Estado</label>
              <select name="estado" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
                <option value="aplicado">Aplicado</option>
                <option value="pendiente">Pendiente</option>
                <option value="omitido">Omitido</option>
                <option value="rechazado">Rechazado</option>
              </select>
            </div>
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Fecha</label>
              <input type="date" name="fecha" value="<?= e($fechaFiltro) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Enfermero/a Responsable *</label>
            <input type="text" name="enfermero_nombre" required value="<?= e($usuario['nombre']) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          </div>

          <div>
            <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Observaciones / Notas de Turno</label>
            <input type="text" name="observaciones" placeholder="Sin eventualidades, vía permeable..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
          <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalRegistrarAdmin').close();">Cancelar</button>
          <button type="submit" class="boton boton--primario">Guardar en Hoja</button>
        </div>
      </form>
    </dialog>

  <?php endif; ?>

</div>

<script>
function prepararAdminDosis(medId, medNombre, medDosis, medHorario) {
  const sel = document.getElementById('admin_medicacion_id');
  if (sel) sel.value = medId;
  const hp = document.getElementById('admin_hora_prog');
  if (hp) {
    const hrs = medHorario.split(',');
    hp.value = hrs[0].trim();
  }
  document.getElementById('modalRegistrarAdmin').showModal();
}
</script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
