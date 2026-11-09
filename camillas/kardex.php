<?php
/**
 * Módulo de Kardex de Medicación y Cuidados de Enfermería Hospitalaria.
 * Control farmacoterapéutico por turnos (Matutino, Vespertino, Nocturno)
 * conforme a la NOM-004-SSA3-2012 y la NOM-019-SSA3-2013.
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
            mensajeFlash('exito', 'Medicamento prescrito y agregado al Kardex correctamente.');
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
            mensajeFlash('exito', 'Registro de dosis asentado en la Hoja de Enfermería.');
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
    WHERE c.institucion_id = ? AND c.estado = 'ocupada'
    ORDER BY c.area ASC, c.codigo ASC");
$stmtHosp->execute([$institucionId]);
$pacientesHospitalizados = $stmtHosp->fetchAll(PDO::FETCH_ASSOC);

// Si no se especificó paciente, tomar el primero hospitalizado por defecto
if (!$pacienteId && !empty($pacientesHospitalizados)) {
    $pacienteId = (int) $pacientesHospitalizados[0]['pac_id'];
}

$paciente = null;
$camillaActual = null;
$medicacionesActivas = [];
$medicacionesSuspendidas = [];
$administraciones = [];
$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');

if ($pacienteId) {
    // Datos del paciente
    $stmtP = $pdo->prepare("SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL");
    $stmtP->execute([$pacienteId, $institucionId]);
    $paciente = $stmtP->fetch(PDO::FETCH_ASSOC);

    if ($paciente) {
        // Camilla actual asignada
        $stmtCam = $pdo->prepare("SELECT * FROM camillas WHERE paciente_id = ? AND institucion_id = ? AND estado = 'ocupada' LIMIT 1");
        $stmtCam->execute([$pacienteId, $institucionId]);
        $camillaActual = $stmtCam->fetch(PDO::FETCH_ASSOC);

        // Medicaciones del paciente
        $stmtM = $pdo->prepare("SELECT * FROM kardex_medicaciones WHERE paciente_id = ? AND institucion_id = ? ORDER BY estado ASC, creado_en DESC");
        $stmtM->execute([$pacienteId, $institucionId]);
        $todasMed = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        foreach ($todasMed as $m) {
            if ($m['estado'] === 'activo') {
                $medicacionesActivas[] = $m;
            } else {
                $medicacionesSuspendidas[] = $m;
            }
        }

        // Administraciones para la fecha seleccionada
        $stmtA = $pdo->prepare("SELECT a.*, m.medicamento, m.dosis, m.via, m.horario
            FROM kardex_administraciones a
            INNER JOIN kardex_medicaciones m ON m.id = a.medicacion_id
            WHERE a.paciente_id = ? AND a.institucion_id = ? AND a.fecha = ?
            ORDER BY a.hora_programada ASC, a.creado_en ASC");
        $stmtA->execute([$pacienteId, $institucionId, $fechaFiltro]);
        $administraciones = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Agrupar administraciones por turno
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

$titulo = $paciente ? "Kardex de Medicación — {$nombreCompleto}" : 'Kardex de Medicación y Cuidados';
$seccionActiva = 'camillas';
require_once __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="hoja-paciente">

  <!-- Barra de navegación y herramientas -->
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 10px;">
      <a href="<?= URL_BASE ?>/camillas/" class="boton boton--sutil" style="display: inline-flex; align-items: center; gap: 6px;">
        ← <span>Censo de Camillas</span>
      </a>
      <span style="color: var(--texto-tenue);">/</span>
      <span style="font-weight: 600; font-size: 1.05rem; color: #fff;">Hoja de Kardex y Enfermería</span>
    </div>

    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
      <!-- Selector de Paciente Hospitalizado -->
      <form method="get" action="" style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
        <label for="select_paciente" style="font-size: 0.8125rem; color: var(--texto-suave);">Cama / Paciente:</label>
        <select id="select_paciente" name="paciente_id" onchange="this.form.submit()" style="background: #202020; color: #fff; border: 1px solid #333; padding: 6px 10px; border-radius: 6px; font-size: 0.875rem;">
          <?php if (empty($pacientesHospitalizados)): ?>
            <option value="">Sin pacientes en camillas</option>
          <?php else: ?>
            <?php foreach ($pacientesHospitalizados as $ph): ?>
              <option value="<?= (int)$ph['pac_id'] ?>" <?= $pacienteId === (int)$ph['pac_id'] ? 'selected' : '' ?>>
                [<?= e($ph['codigo']) ?>] <?= e("{$ph['nombre']} {$ph['primer_apellido']}") ?> (<?= e(ucfirst($ph['area'])) ?>)
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </form>

      <?php if ($paciente): ?>
        <button type="button" class="boton boton--sutil" onclick="window.print();" style="display: inline-flex; align-items: center; gap: 6px;">
          <?= icono('documento') ?> <span>Imprimir Kardex Oficial</span>
        </button>

        <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$paciente['id'] ?>" class="boton boton--secundario" style="display: inline-flex; align-items: center; gap: 6px;">
          <?= icono('usuarios') ?> <span>Ver Expediente</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$paciente): ?>
    <div style="text-align: center; padding: 60px 20px; background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; margin-top: 20px;">
      <p style="font-size: 1.1rem; color: var(--texto-suave); margin-bottom: 16px;">No hay ningún paciente seleccionado o no hay pacientes en camillas actualmente.</p>
      <a href="<?= URL_BASE ?>/camillas/" class="boton boton--primario">Ir al Censo para Asignar Pacientes</a>
    </div>
  <?php else: ?>

    <!-- Ficha del Paciente / Encabezado de Hospitalización -->
    <section class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; padding: 22px; margin-bottom: 24px;">
      <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; justify-content: space-between;">
        <div style="display: flex; gap: 16px; align-items: center;">
          <div style="width: 58px; height: 58px; border-radius: 50%; background: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; font-weight: 700; color: #fff;">
            <?= e(mb_strtoupper(mb_substr($paciente['nombre'], 0, 1) . mb_substr($paciente['primer_apellido'], 0, 1))) ?>
          </div>
          <div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
              <h1 style="font-size: 1.35rem; font-weight: 700; color: #fff; margin: 0;"><?= e($nombreCompleto) ?></h1>
              <?php if ($camillaActual): ?>
                <span class="badge" style="background: rgba(59, 130, 246, 0.2); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.8125rem;">
                  🛏️ <?= e($camillaActual['codigo']) ?> (<?= e(ucfirst($camillaActual['area'])) ?>)
                </span>
              <?php endif; ?>
            </div>
            <div style="font-size: 0.875rem; color: var(--texto-suave); margin-top: 5px; display: flex; gap: 14px; flex-wrap: wrap;">
              <span><strong>Folio:</strong> <?= e($paciente['folio']) ?></span>
              <span><strong>CURP:</strong> <?= e($paciente['curp'] ?: '—') ?></span>
              <span><strong>Edad:</strong> <?= $edadPaciente !== null ? "{$edadPaciente} años" : '—' ?> (<?= e($paciente['sexo'] === 'M' ? 'Femenino' : 'Masculino') ?>)</span>
              <span><strong>Gpo Sanguíneo:</strong> <?= e($paciente['grupo_sanguineo'] ?: 'No registrado') ?></span>
            </div>
          </div>
        </div>

        <!-- Botones de Acción Rápida -->
        <div style="display: flex; gap: 8px;">
          <button type="button" class="boton boton--primario" onclick="document.getElementById('modalNuevaMed').showModal();" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= icono('agregar') ?> <span>+ Prescribir Medicamento</span>
          </button>
          <button type="button" class="boton boton--secundario" onclick="document.getElementById('modalRegistrarAdmin').showModal();" style="display: inline-flex; align-items: center; gap: 6px;">
            <?= icono('verificado') ?> <span>Registrar Dosis</span>
          </button>
        </div>
      </div>

      <!-- Alerta de Alergias y Diagnóstico de Ingreso -->
      <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid #282828; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
        <div style="padding: 10px 14px; background: <?= !empty($paciente['alergias']) && mb_strtolower($paciente['alergias']) !== 'negadas' ? 'rgba(239, 68, 68, 0.12)' : 'rgba(255,255,255,0.03)' ?>; border: 1px solid <?= !empty($paciente['alergias']) && mb_strtolower($paciente['alergias']) !== 'negadas' ? 'rgba(239, 68, 68, 0.35)' : '#262626' ?>; border-radius: 8px;">
          <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: <?= !empty($paciente['alergias']) && mb_strtolower($paciente['alergias']) !== 'negadas' ? '#fca5a5' : 'var(--texto-tenue)' ?>; letter-spacing: 0.5px;">
            ⚠️ Alergias y Reacciones Adversas
          </div>
          <div style="font-size: 0.9rem; color: <?= !empty($paciente['alergias']) && mb_strtolower($paciente['alergias']) !== 'negadas' ? '#fecaca' : '#fff' ?>; font-weight: 600; margin-top: 3px;">
            <?= e($paciente['alergias'] ?: 'Negadas') ?>
          </div>
        </div>

        <div style="padding: 10px 14px; background: rgba(255,255,255,0.03); border: 1px solid #262626; border-radius: 8px;">
          <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--texto-tenue); letter-spacing: 0.5px;">
            Diagnóstico de Ingreso Hospitalario
          </div>
          <div style="font-size: 0.9rem; color: #fff; font-weight: 500; margin-top: 3px;">
            <?= e($camillaActual['diagnostico_ingreso'] ?? $paciente['motivo_consulta'] ?? 'No especificado') ?>
          </div>
        </div>
      </div>
    </section>

    <!-- SECCIÓN 1: Prescripciones Activas de Medicamentos (Kardex Farmacoterapéutico) -->
    <section class="ficha__seccion" style="margin-bottom: 30px;">
      <div class="ficha__titulo-fila" style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
        <div>
          <h2 class="ficha__titulo" style="font-size: 1.15rem; margin: 0;">Prescripción Farmacológica Activa (Kardex)</h2>
          <p style="font-size: 0.8125rem; color: var(--texto-tenue); margin: 3px 0 0 0;">Control oficial de medicamentos prescritos vigentes según la NOM-004-SSA3-2012.</p>
        </div>
        <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalNuevaMed').showModal();" style="font-size: 0.8125rem;">
          + Agregar Fármaco
        </button>
      </div>

      <?php if (empty($medicacionesActivas)): ?>
        <div style="padding: 24px; text-align: center; background: #181818; border: 1px dashed #333; border-radius: 10px; color: var(--texto-tenue);">
          No hay medicamentos prescritos activos en el Kardex para este paciente.
        </div>
      <?php else: ?>
        <div class="tabla-contenedor" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 10px; overflow: hidden;">
          <table class="tabla" style="width: 100%; margin: 0;">
            <thead>
              <tr style="background: #202020; border-bottom: 1px solid #2c2c2c;">
                <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Medicamento y Presentación</th>
                <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Dosis y Vía</th>
                <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Frecuencia y Horarios</th>
                <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Indicaciones / Dilución</th>
                <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Médico Prescribe</th>
                <th style="padding: 12px 16px; text-align: right; font-size: 0.8125rem;">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($medicacionesActivas as $med): ?>
                <tr style="border-bottom: 1px solid #232323;">
                  <td style="padding: 12px 16px;">
                    <div style="font-weight: 600; color: #fff; font-size: 0.9375rem;"><?= e($med['medicamento']) ?></div>
                    <div style="font-size: 0.75rem; color: var(--texto-tenue);">Inicio: <?= e(fechaCorta($med['fecha_inicio'])) ?></div>
                  </td>
                  <td style="padding: 12px 16px; font-size: 0.875rem;">
                    <span style="font-weight: 600; color: #93c5fd;"><?= e($med['dosis']) ?></span>
                    <div style="font-size: 0.75rem; color: var(--texto-suave);"><?= e($med['via']) ?></div>
                  </td>
                  <td style="padding: 12px 16px; font-size: 0.875rem;">
                    <div><?= e($med['frecuencia']) ?></div>
                    <?php if ($med['horario']): ?>
                      <div style="font-size: 0.75rem; color: #38bdf8; font-family: monospace; font-weight: 600;">⏰ <?= e($med['horario']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 16px; font-size: 0.8125rem; color: var(--texto-suave); max-width: 250px;">
                    <?= e($med['indicaciones'] ?: '—') ?>
                  </td>
                  <td style="padding: 12px 16px; font-size: 0.8125rem; color: var(--texto-suave);">
                    <?= e($med['medico_prescribe'] ?: 'Servicio Médico') ?>
                  </td>
                  <td style="padding: 12px 16px; text-align: right;">
                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                      <button type="button" class="boton boton--primario" style="font-size: 0.75rem; padding: 4px 8px;" onclick="prepararAdminDosis(<?= (int)$med['id'] ?>, '<?= e($med['medicamento']) ?>', '<?= e($med['dosis']) ?>', '<?= e($med['horario'] ?: '08:00') ?>');">
                        + Dosis
                      </button>
                      <form method="post" action="" style="display: inline;" onsubmit="return confirm('¿Confirmas suspender este medicamento del Kardex?');">
                        <?= campoCsrf() ?>
                        <input type="hidden" name="accion" value="suspender_medicacion">
                        <input type="hidden" name="medicacion_id" value="<?= (int)$med['id'] ?>">
                        <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
                        <button type="submit" class="boton boton--sutil" style="font-size: 0.75rem; padding: 4px 8px; color: #f87171;" title="Suspender medicamento">
                          Suspender
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- SECCIÓN 2: Hoja de Registro y Administración por Turno (Matutino, Vespertino, Nocturno) -->
    <section class="ficha__seccion">
      <div class="ficha__titulo-fila" style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
          <h2 class="ficha__titulo" style="font-size: 1.15rem; margin: 0;">Hoja de Administración de Fármacos por Turnos</h2>
          <p style="font-size: 0.8125rem; color: var(--texto-tenue); margin: 3px 0 0 0;">Registro de aplicación firmado por enfermería (NOM-019-SSA3-2013).</p>
        </div>

        <!-- Selector de Fecha de Administración -->
        <form method="get" action="" style="display: flex; align-items: center; gap: 8px; margin: 0;">
          <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
          <label for="fecha_kardex" style="font-size: 0.8125rem; color: var(--texto-suave);">Fecha:</label>
          <input type="date" id="fecha_kardex" name="fecha" value="<?= e($fechaFiltro) ?>" onchange="this.form.submit()" style="background: #202020; color: #fff; border: 1px solid #333; padding: 6px 10px; border-radius: 6px; font-size: 0.875rem;">
          <button type="button" class="boton boton--primario" onclick="document.getElementById('modalRegistrarAdmin').showModal();" style="font-size: 0.8125rem;">
            + Asentar Dosis
          </button>
        </form>
      </div>

      <!-- Turnos Hospitalarios en 3 Columnas Grid -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
        
        <!-- Turno Matutino (07:00 - 15:00) -->
        <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 10px; padding: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #282828; padding-bottom: 10px; margin-bottom: 12px;">
            <div style="font-weight: 700; color: #fde047; font-size: 0.95rem; display: flex; align-items: center; gap: 6px;">
              ☀️ Turno Matutino <span style="font-size: 0.75rem; color: var(--texto-tenue); font-weight: normal;">(07:00 - 15:00)</span>
            </div>
            <span class="badge" style="background: rgba(253, 224, 71, 0.15); color: #fde047; font-size: 0.75rem;">
              <?= count($adminPorTurno['matutino']) ?> dosis
            </span>
          </div>

          <?php if (empty($adminPorTurno['matutino'])): ?>
            <p style="font-size: 0.8125rem; color: var(--texto-tenue); text-align: center; margin: 20px 0;">Sin administraciones en este turno.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
              <?php foreach ($adminPorTurno['matutino'] as $aMat): ?>
                <div style="background: #202020; border: 1px solid #2b2b2b; border-left: 3px solid <?= $aMat['estado'] === 'aplicado' ? '#4ade80' : ($aMat['estado'] === 'pendiente' ? '#facc15' : '#f87171') ?>; border-radius: 6px; padding: 10px 12px;">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="font-weight: 600; font-size: 0.875rem; color: #fff;"><?= e($aMat['medicamento']) ?></div>
                    <span style="font-size: 0.75rem; font-family: monospace; font-weight: bold; color: #93c5fd;"><?= e($aMat['hora_programada']) ?></span>
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-suave); margin-top: 2px;">
                    Dosis: <strong><?= e($aMat['dosis']) ?></strong> (<?= e($aMat['via']) ?>)
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 4px; display: flex; justify-content: space-between;">
                    <span><?= e($aMat['enfermero_nombre'] ?: 'Enfermería') ?></span>
                    <span style="color: <?= $aMat['estado'] === 'aplicado' ? '#86efac' : ($aMat['estado'] === 'pendiente' ? '#fde047' : '#fca5a5') ?>; font-weight: 600;">
                      <?= ucfirst($aMat['estado']) ?>
                    </span>
                  </div>
                  <?php if (!empty($aMat['observaciones'])): ?>
                    <div style="font-size: 0.75rem; color: #94a3b8; font-style: italic; margin-top: 4px; border-top: 1px dashed #333; padding-top: 4px;">
                      "<?= e($aMat['observaciones']) ?>"
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Turno Vespertino (15:00 - 23:00) -->
        <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 10px; padding: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #282828; padding-bottom: 10px; margin-bottom: 12px;">
            <div style="font-weight: 700; color: #fb923c; font-size: 0.95rem; display: flex; align-items: center; gap: 6px;">
              🌇 Turno Vespertino <span style="font-size: 0.75rem; color: var(--texto-tenue); font-weight: normal;">(15:00 - 23:00)</span>
            </div>
            <span class="badge" style="background: rgba(251, 146, 60, 0.15); color: #fb923c; font-size: 0.75rem;">
              <?= count($adminPorTurno['vespertino']) ?> dosis
            </span>
          </div>

          <?php if (empty($adminPorTurno['vespertino'])): ?>
            <p style="font-size: 0.8125rem; color: var(--texto-tenue); text-align: center; margin: 20px 0;">Sin administraciones en este turno.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
              <?php foreach ($adminPorTurno['vespertino'] as $aVesp): ?>
                <div style="background: #202020; border: 1px solid #2b2b2b; border-left: 3px solid <?= $aVesp['estado'] === 'aplicado' ? '#4ade80' : ($aVesp['estado'] === 'pendiente' ? '#facc15' : '#f87171') ?>; border-radius: 6px; padding: 10px 12px;">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="font-weight: 600; font-size: 0.875rem; color: #fff;"><?= e($aVesp['medicamento']) ?></div>
                    <span style="font-size: 0.75rem; font-family: monospace; font-weight: bold; color: #93c5fd;"><?= e($aVesp['hora_programada']) ?></span>
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-suave); margin-top: 2px;">
                    Dosis: <strong><?= e($aVesp['dosis']) ?></strong> (<?= e($aVesp['via']) ?>)
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 4px; display: flex; justify-content: space-between;">
                    <span><?= e($aVesp['enfermero_nombre'] ?: 'Enfermería') ?></span>
                    <span style="color: <?= $aVesp['estado'] === 'aplicado' ? '#86efac' : ($aVesp['estado'] === 'pendiente' ? '#fde047' : '#fca5a5') ?>; font-weight: 600;">
                      <?= ucfirst($aVesp['estado']) ?>
                    </span>
                  </div>
                  <?php if (!empty($aVesp['observaciones'])): ?>
                    <div style="font-size: 0.75rem; color: #94a3b8; font-style: italic; margin-top: 4px; border-top: 1px dashed #333; padding-top: 4px;">
                      "<?= e($aVesp['observaciones']) ?>"
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Turno Nocturno (23:00 - 07:00) -->
        <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 10px; padding: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #282828; padding-bottom: 10px; margin-bottom: 12px;">
            <div style="font-weight: 700; color: #818cf8; font-size: 0.95rem; display: flex; align-items: center; gap: 6px;">
              🌙 Turno Nocturno <span style="font-size: 0.75rem; color: var(--texto-tenue); font-weight: normal;">(23:00 - 07:00)</span>
            </div>
            <span class="badge" style="background: rgba(129, 140, 248, 0.15); color: #818cf8; font-size: 0.75rem;">
              <?= count($adminPorTurno['nocturno']) ?> dosis
            </span>
          </div>

          <?php if (empty($adminPorTurno['nocturno'])): ?>
            <p style="font-size: 0.8125rem; color: var(--texto-tenue); text-align: center; margin: 20px 0;">Sin administraciones en este turno.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
              <?php foreach ($adminPorTurno['nocturno'] as $aNoc): ?>
                <div style="background: #202020; border: 1px solid #2b2b2b; border-left: 3px solid <?= $aNoc['estado'] === 'aplicado' ? '#4ade80' : ($aNoc['estado'] === 'pendiente' ? '#facc15' : '#f87171') ?>; border-radius: 6px; padding: 10px 12px;">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="font-weight: 600; font-size: 0.875rem; color: #fff;"><?= e($aNoc['medicamento']) ?></div>
                    <span style="font-size: 0.75rem; font-family: monospace; font-weight: bold; color: #93c5fd;"><?= e($aNoc['hora_programada']) ?></span>
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-suave); margin-top: 2px;">
                    Dosis: <strong><?= e($aNoc['dosis']) ?></strong> (<?= e($aNoc['via']) ?>)
                  </div>
                  <div style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 4px; display: flex; justify-content: space-between;">
                    <span><?= e($aNoc['enfermero_nombre'] ?: 'Enfermería') ?></span>
                    <span style="color: <?= $aNoc['estado'] === 'aplicado' ? '#86efac' : ($aNoc['estado'] === 'pendiente' ? '#fde047' : '#fca5a5') ?>; font-weight: 600;">
                      <?= ucfirst($aNoc['estado']) ?>
                    </span>
                  </div>
                  <?php if (!empty($aNoc['observaciones'])): ?>
                    <div style="font-size: 0.75rem; color: #94a3b8; font-style: italic; margin-top: 4px; border-top: 1px dashed #333; padding-top: 4px;">
                      "<?= e($aNoc['observaciones']) ?>"
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </section>

    <!-- Modal: Prescribir Nuevo Medicamento -->
    <dialog id="modalNuevaMed" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 12px; padding: 24px; max-width: 580px; width: 95%;">
      <form method="post" action="">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="agregar_medicacion">
        <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
        <input type="hidden" name="camilla_id" value="<?= (int)($camillaActual['id'] ?? 0) ?>">

        <h3 style="margin-top: 0; font-size: 1.2rem; color: #fff; margin-bottom: 16px;">+ Prescribir Medicación al Kardex</h3>

        <div style="display: flex; flex-direction: column; gap: 14px;">
          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Nombre del Medicamento y Presentación *</label>
            <input type="text" name="medicamento" required placeholder="Ej. Ceftriaxona 1g Fco. Ámpula" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Dosis *</label>
              <input type="text" name="dosis" required placeholder="Ej. 1 g, 50 mg, 1000 ml" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
            </div>
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Vía de Administración *</label>
              <select name="via" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
                <option value="Intravenosa (IV)">Intravenosa (IV)</option>
                <option value="Oral (VO)">Oral (VO)</option>
                <option value="Subcutánea (SC)">Subcutánea (SC)</option>
                <option value="Intramuscular (IM)">Intramuscular (IM)</option>
                <option value="Inhalatoria (Nebulización)">Inhalatoria (Nebulización)</option>
                <option value="Tópica">Tópica</option>
                <option value="Sublingual">Sublingual</option>
                <option value="Oftálmica">Oftálmica</option>
              </select>
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Frecuencia / Intervalo</label>
              <input type="text" name="frecuencia" required placeholder="Ej. Cada 8 horas, Cada 12 horas" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
            </div>
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Horario Programado</label>
              <input type="text" name="horario" placeholder="Ej. 08:00, 16:00, 24:00" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Indicaciones Especiales o Dilución</label>
            <textarea name="indicaciones" rows="2" placeholder="Ej. Diluir en 100 ml de Sol. Fisiológica e infundir en 30 minutos..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff; resize: vertical;"></textarea>
          </div>

          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Médico que Prescribe</label>
            <input type="text" name="medico_prescribe" value="<?= e($usuario['nombre']) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
          <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalNuevaMed').close();">Cancelar</button>
          <button type="submit" class="boton boton--primario">Guardar Prescripción</button>
        </div>
      </form>
    </dialog>

    <!-- Modal: Registrar Administración de Dosis -->
    <dialog id="modalRegistrarAdmin" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 12px; padding: 24px; max-width: 540px; width: 95%;">
      <form method="post" action="">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="registrar_administracion">
        <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">

        <h3 style="margin-top: 0; font-size: 1.2rem; color: #fff; margin-bottom: 16px;">Asentar Administración de Dosis</h3>

        <div style="display: flex; flex-direction: column; gap: 14px;">
          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Seleccionar Fármaco Prescrito *</label>
            <select id="admin_medicacion_id" name="medicacion_id" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
              <?php foreach ($medicacionesActivas as $mAct): ?>
                <option value="<?= (int)$mAct['id'] ?>">
                  <?= e($mAct['medicamento']) ?> — <?= e($mAct['dosis']) ?> (<?= e($mAct['via']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Turno *</label>
              <select name="turno" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
                <option value="matutino">Matutino (07:00 - 15:00)</option>
                <option value="vespertino">Vespertino (15:00 - 23:00)</option>
                <option value="nocturno">Nocturno (23:00 - 07:00)</option>
              </select>
            </div>
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Hora Programada</label>
              <input type="text" id="admin_hora_prog" name="hora_programada" value="<?= date('H:i') ?>" placeholder="08:00" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Estado de Aplicación</label>
              <select name="estado" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
                <option value="aplicado">✅ Aplicado / Infundido</option>
                <option value="pendiente">⏰ Pendiente de aplicar</option>
                <option value="omitido">⚠️ Omitido por indicación</option>
                <option value="rechazado">❌ Rechazado por paciente</option>
              </select>
            </div>
            <div>
              <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Fecha</label>
              <input type="date" name="fecha" value="<?= e($fechaFiltro) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Enfermero/a que Administra *</label>
            <input type="text" name="enfermero_nombre" required value="<?= e($usuario['nombre']) ?>" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
          </div>

          <div>
            <label style="font-size: 0.8125rem; color: var(--texto-suave); display: block; margin-bottom: 4px;">Notas Clínicas / Tolerancia</label>
            <input type="text" name="observaciones" placeholder="Ej. Sin eventualidades, tolera vía oral, vía periférica permeable..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 8px 12px; border-radius: 6px; color: #fff;">
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
          <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalRegistrarAdmin').close();">Cancelar</button>
          <button type="submit" class="boton boton--primario">Guardar en Hoja de Turno</button>
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

<!-- Estilos específicos para impresión formal de Kardex -->
<style>
@media print {
  /* Ocultar barra lateral, botones, formularios y encabezados del sistema */
  .barra-lateral, .encabezado, .pestanas, .boton, dialog, form, .no-imprimir {
    display: none !important;
  }
  body, .contenido, .hoja-paciente {
    background: #fff !important;
    color: #000 !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
  }
  .tarjeta, .ficha__seccion, .tabla-contenedor {
    background: #fff !important;
    border: 1px solid #000 !important;
    color: #000 !important;
    box-shadow: none !important;
  }
  table.tabla th {
    background: #e5e5e5 !important;
    color: #000 !important;
    border-bottom: 1px solid #000 !important;
  }
  table.tabla td {
    color: #000 !important;
    border-bottom: 1px solid #ccc !important;
  }
  h1, h2, h3, strong {
    color: #000 !important;
  }
  .badge {
    border: 1px solid #000 !important;
    color: #000 !important;
    background: transparent !important;
  }
}
</style>

<?php require_once __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
