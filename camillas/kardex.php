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
$leerTexto = static fn (string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
$leerId = static function (string $campo): int {
    $valor = $_POST[$campo] ?? null;
    if (!is_string($valor) && !is_int($valor)) {
        return 0;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? $id : 0;
};
$transaccion = function (callable $operacion, string $error) use ($pdo): bool {
    try {
        $pdo->beginTransaction();
        $operacion();
        $pdo->commit();
        return true;
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mensajeFlash('error', $e->getMessage());
        return false;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[kardex] ' . $e->getMessage());
        mensajeFlash('error', $error);
        return false;
    }
};

// Procesar formularios POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
        redirigir($_SERVER['REQUEST_URI']);
    }

    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';

    // 1. Agregar nueva indicación / medicación al Kardex
    if ($accion === 'agregar_medicacion') {
        exigirPermiso('historial');
        $pacienteId = $leerId('paciente_id');
        $camillaId = $leerId('camilla_id') ?: null;
        $medicamento = mb_substr(trim($leerTexto('medicamento')), 0, 150);
        $medicamentoCatalogoId = $leerId('medicamento_id');
        $dosis = mb_substr(trim($leerTexto('dosis')), 0, 80);
        $via = mb_substr(trim($leerTexto('via')), 0, 50);
        $frecuencia = mb_substr(trim($leerTexto('frecuencia')), 0, 80);
        $horario = mb_substr(trim($leerTexto('horario')), 0, 100);
        $fechaSolicitada = $_POST['fecha_inicio'] ?? null;
        $fechaInicio = is_string($fechaSolicitada) ? (trim($fechaSolicitada) ?: date('Y-m-d')) : '';
        $indicaciones = trim($leerTexto('indicaciones'));
        $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        if (!$pacienteId || $medicamento === '' || $dosis === '' || $via === '' || $frecuencia === ''
            || !$fechaValida || $fechaValida->format('Y-m-d') !== $fechaInicio) {
            mensajeFlash('error', 'Completa paciente, medicamento, dosis, vía, frecuencia y una fecha válida.');
        } else {
            $guardado = $transaccion(function () use ($pdo, $pacienteId, $camillaId, $medicamento, $medicamentoCatalogoId, $dosis, $via, $frecuencia, $horario, $fechaInicio, $indicaciones, $institucionId, $usuario): void {
                $paciente = $pdo->prepare('SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
                $paciente->execute([$pacienteId, $institucionId]);
                if (!$paciente->fetchColumn()) {
                    throw new DomainException('El paciente no existe en esta institución o fue dado de baja.');
                }
                if ($medicamentoCatalogoId) {
                    $catalogo = $pdo->prepare('SELECT nombre, concentracion FROM medicamentos WHERE id = ? AND institucion_id = ? AND activo = 1 FOR UPDATE');
                    $catalogo->execute([$medicamentoCatalogoId, $institucionId]);
                    $medicamentoSeleccionado = $catalogo->fetch(PDO::FETCH_ASSOC);
                    if (!$medicamentoSeleccionado) {
                        throw new DomainException('El medicamento seleccionado ya no está activo en el catálogo.');
                    }
                    $medicamento = mb_substr(trim((string) $medicamentoSeleccionado['nombre'] . ' ' . (string) ($medicamentoSeleccionado['concentracion'] ?? '')), 0, 150);
                }
                $cama = $pdo->prepare("SELECT id FROM camillas WHERE paciente_id = ? AND institucion_id = ? AND estado = 'ocupada' LIMIT 1 FOR UPDATE");
                $cama->execute([$pacienteId, $institucionId]);
                $camillaActualId = (int) ($cama->fetchColumn() ?: 0);
                if (!$camillaActualId || ($camillaId && $camillaId !== $camillaActualId)) {
                    throw new DomainException('El paciente ya no ocupa esta cama. Actualiza el censo antes de registrar la indicación.');
                }
                $stmt = $pdo->prepare("INSERT INTO kardex_medicaciones
                    (institucion_id, paciente_id, camilla_id, medicamento, dosis, via, frecuencia, horario, fecha_inicio, indicaciones, estado, medico_prescribe)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?)");
                $stmt->execute([$institucionId, $pacienteId, $camillaActualId, $medicamento, $dosis, $via, $frecuencia, $horario, $fechaInicio, $indicaciones, mb_substr((string) $usuario['nombre'], 0, 120)]);
                registrarBitacora('kardex_medicaciones', (int) $pdo->lastInsertId(), 'crear', 'medicamento', null, $medicamento);
            }, 'No se pudo guardar la indicación. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Indicación agregada al Kardex por el usuario autenticado.');
            }
        }
        redirigir("/camillas/kardex.php?paciente_id={$pacienteId}");
    }

    // 2. Registrar administración de una dosis
    if ($accion === 'registrar_administracion') {
        exigirPermiso('kardex');
        $medicacionId = $leerId('medicacion_id');
        $pacienteId = $leerId('paciente_id');
        $turno = $leerTexto('turno');
        $fechaSolicitada = $_POST['fecha'] ?? null;
        $fecha = is_string($fechaSolicitada) ? (trim($fechaSolicitada) ?: date('Y-m-d')) : '';
        $horaProg = trim($leerTexto('hora_programada'));
        $estado = $leerTexto('estado');
        $obs = mb_substr(trim($leerTexto('observaciones')), 0, 255);
        $fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

        if (!$medicacionId || !$pacienteId || !in_array($turno, ['matutino', 'vespertino', 'nocturno'], true)
            || !in_array($estado, ['aplicado', 'pendiente', 'omitido', 'rechazado'], true)
            || !$fechaValida || $fechaValida->format('Y-m-d') !== $fecha
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaProg)) {
            mensajeFlash('error', 'Revisa medicamento, turno, fecha, hora programada y resultado de la dosis.');
        } else {
            $guardado = $transaccion(function () use ($pdo, $medicacionId, $pacienteId, $turno, $fecha, $horaProg, $estado, $obs, $institucionId, $usuario): void {
                $paciente = $pdo->prepare('SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
                $paciente->execute([$pacienteId, $institucionId]);
                if (!$paciente->fetchColumn()) {
                    throw new DomainException('El paciente no existe en esta institución o fue dado de baja.');
                }
                $cama = $pdo->prepare("SELECT id FROM camillas WHERE paciente_id = ? AND institucion_id = ? AND estado = 'ocupada' LIMIT 1 FOR UPDATE");
                $cama->execute([$pacienteId, $institucionId]);
                if (!$cama->fetchColumn()) {
                    throw new DomainException('El paciente ya no está hospitalizado en una cama ocupada.');
                }
                $medicacion = $pdo->prepare("SELECT id FROM kardex_medicaciones WHERE id = ? AND paciente_id = ? AND institucion_id = ? AND estado = 'activo' FOR UPDATE");
                $medicacion->execute([$medicacionId, $pacienteId, $institucionId]);
                if (!$medicacion->fetchColumn()) {
                    throw new DomainException('La indicación no pertenece a este paciente o ya no está activa.');
                }
                $duplicada = $pdo->prepare('SELECT id FROM kardex_administraciones WHERE medicacion_id = ? AND paciente_id = ? AND institucion_id = ? AND fecha = ? AND hora_programada = ? LIMIT 1 FOR UPDATE');
                $duplicada->execute([$medicacionId, $pacienteId, $institucionId, $fecha, $horaProg]);
                if ($duplicada->fetchColumn()) {
                    throw new DomainException('Ya existe un registro de esta dosis para esa fecha y hora.');
                }
                $horaAplicacion = $estado === 'aplicado' ? date('Y-m-d H:i:s') : null;
                $stmt = $pdo->prepare("INSERT INTO kardex_administraciones
                    (institucion_id, medicacion_id, paciente_id, turno, fecha, hora_programada, hora_aplicacion, estado, enfermero_nombre, observaciones)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$institucionId, $medicacionId, $pacienteId, $turno, $fecha, $horaProg, $horaAplicacion, $estado, mb_substr((string) $usuario['nombre'], 0, 120), $obs]);
                registrarBitacora('kardex_administraciones', (int) $pdo->lastInsertId(), 'crear', 'estado', null, $estado);
            }, 'No se pudo registrar la dosis. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Dosis registrada en la Hoja de Enfermería.');
            }
        }
        redirigir("/camillas/kardex.php?paciente_id={$pacienteId}&fecha={$fecha}");
    }

    // 3. Suspender medicación
    if ($accion === 'suspender_medicacion') {
        exigirPermiso('historial');
        $medId = $leerId('medicacion_id');
        $pacienteId = $leerId('paciente_id');
        $guardado = $transaccion(function () use ($pdo, $medId, $pacienteId, $institucionId): void {
            $stmt = $pdo->prepare("UPDATE kardex_medicaciones SET estado = 'suspendido' WHERE id = ? AND paciente_id = ? AND institucion_id = ? AND estado = 'activo'");
            $stmt->execute([$medId, $pacienteId, $institucionId]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('La indicación no pertenece a este paciente o ya no está activa.');
            }
            registrarBitacora('kardex_medicaciones', $medId, 'actualizar', 'estado', 'activo', 'suspendido');
        }, 'No se pudo suspender la indicación. Intenta de nuevo.');
        if ($guardado) {
            mensajeFlash('exito', 'Indicación marcada como suspendida.');
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
$fechaSolicitada = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : '';
$fechaValida = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaSolicitada);
$fechaFiltro = $fechaValida && $fechaValida->format('Y-m-d') === $fechaSolicitada ? $fechaSolicitada : date('Y-m-d');

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

$edadPaciente = null;
if ($paciente && !empty($paciente['fecha_nacimiento'])) {
    $edadPaciente = (new DateTime($paciente['fecha_nacimiento']))->diff(new DateTime('today'))->y;
}

$nombreCompleto = $paciente ? trim("{$paciente['nombre']} {$paciente['primer_apellido']} {$paciente['segundo_apellido']}") : '';
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

    <header class="pagina__cabecera">
      <h1 class="pagina__titulo">Kardex de medicación</h1>
      <p class="pagina__subtitulo">
        <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $paciente['id'] ?>"><?= e($nombreCompleto) ?></a>
        · Folio <?= e($paciente['folio']) ?>
        · <?= $edadPaciente !== null ? "{$edadPaciente} años" : 'Edad no registrada' ?>
        · <?= e(SEXOS[$paciente['sexo']] ?? 'Sexo no registrado') ?>
        · Cama <?= e($camillaActual['codigo'] ?? 'no asignada') ?>
      </p>
      <p class="pagina__descripcion">
        <?php if (!$sinAlergias): ?><strong><?= icono('alerta') ?> Alergias:</strong> <?= e($paciente['alergias']) ?> · <?php endif; ?>
        <strong>Diagnóstico de ingreso:</strong>
        <?= e($camillaActual['diagnostico_ingreso'] ?? $paciente['motivo_consulta'] ?? 'No documentado') ?>
        <a class="boton boton--sutil" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $paciente['id'] ?>" style="margin-left: 10px;">
          <?= icono('usuarios') ?> Abrir expediente general
        </a>
      </p>
    </header>

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
                  <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int) $paciente['id'] ?>">
                    <span class="lista-clinica__icono"><?= icono('pildora') ?></span>
                    <span class="lista-clinica__texto">
                      <b><?= e($med['medicamento']) ?></b> · <?= e($med['dosis']) ?> · <?= e($med['via']) ?>
                      <small>Frecuencia: <?= e($med['frecuencia']) ?> · Horario: <?= e($med['horario'] ?: 'Según indicación') ?> · Inicio: <?= e(fechaCorta($med['fecha_inicio'])) ?><?= $med['indicaciones'] ? ' · ' . e($med['indicaciones']) : '' ?> · Prescribe: <?= e($med['medico_prescribe'] ?: 'Servicio Médico') ?></small>
                    </span>
                  </a>
                  <div style="display: flex; gap: 4px; align-items: center; margin-left: auto;">
                    <?php if (puede('kardex')): ?><button type="button" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs);" onclick="prepararAdminDosis(<?= (int) $med['id'] ?>, <?= e(json_encode($med['horario'] ?: '08:00', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>);">
                      <?= icono('agregar') ?> Dosis
                    </button><?php endif; ?>
                    <?php if (puede('historial')): ?><form method="post" action="" style="display: inline;" onsubmit="return confirm('¿Suspender este medicamento del Kardex?');">
                      <?= campoCsrf() ?>
                      <input type="hidden" name="accion" value="suspender_medicacion">
                      <input type="hidden" name="medicacion_id" value="<?= (int)$med['id'] ?>">
                      <input type="hidden" name="paciente_id" value="<?= (int)$paciente['id'] ?>">
                      <button type="submit" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs); color: #f87171;">
                        Suspender
                      </button>
                    </form><?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if (puede('historial') && $camillaActual): ?><button type="button" class="hoja-paciente__agendar" onclick="document.getElementById('modalNuevaMed').showModal();" style="margin-top: 10px;">
            <?= icono('agregar') ?> Prescribir medicamento al Kardex
          </button><?php endif; ?>
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

          <?php if (puede('kardex') && $camillaActual && !empty($medicacionesActivas)): ?><button type="button" class="hoja-paciente__agendar" onclick="document.getElementById('modalRegistrarAdmin').showModal();" style="margin-top: 10px;">
            <?= icono('agregar') ?> Asentar aplicación de dosis
          </button><?php elseif (puede('kardex') && $camillaActual): ?>
            <p class="ficha__vacio">Agrega primero una indicación activa para poder registrar dosis.</p>
          <?php endif; ?>
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
            <a href="<?= URL_BASE ?>/camillas/" class="boton boton--sutil" style="width: 100%; justify-content: flex-start;">
              <?= icono('clinico') ?> <span>Censo de camillas</span>
            </a>
          </div>
        </section>

      </aside>

    </div>

    <!-- Modal: Prescribir Medicación -->
    <?php if (puede('historial') && $camillaActual): ?><dialog id="modalNuevaMed" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 520px; width: 95%;">
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
            <div data-kardex-medicamento>
              <input type="hidden" name="medicamento_id" data-medicamento-id>
              <input type="text" name="medicamento" required placeholder="Buscar medicamento en el catálogo" data-catalogo="medicamentos" role="combobox" aria-expanded="false" aria-autocomplete="list" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Dosis *</label>
              <input type="text" name="dosis" required placeholder="Ej. 1 g, 50 mg" style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Vía *</label>
              <input type="text" name="via" required placeholder="Se completa desde el catálogo" style="width: 100%; background: #222; color: #fff; border: 1px solid #333; padding: 6px 8px; border-radius: 6px;">
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

          <p style="margin: 0; color: var(--texto-suave); font-size: var(--fs-xs);">El usuario autenticado quedará registrado como prescriptor.</p>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
          <button type="button" class="boton boton--sutil" onclick="document.getElementById('modalNuevaMed').close();">Cancelar</button>
          <button type="submit" class="boton boton--primario">Guardar Prescripción</button>
        </div>
      </form>
    </dialog>
    <?php endif; ?>

    <!-- Modal: Asentar Dosis -->
    <?php if (puede('kardex') && !empty($medicacionesActivas)): ?><dialog id="modalRegistrarAdmin" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 500px; width: 95%;">
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
              <option value="" selected disabled>Selecciona una indicación activa</option>
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
              <input type="time" id="admin_hora_prog" name="hora_programada" value="<?= date('H:i') ?>" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            </div>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
              <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 3px;">Estado</label>
              <select name="estado" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
                <option value="" selected disabled>Selecciona el resultado</option>
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

          <p style="margin: 0; color: var(--texto-suave); font-size: var(--fs-xs);">El usuario autenticado quedará registrado como responsable de la administración.</p>

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

  <?php endif; ?>

</div>

<script>
function prepararAdminDosis(medId, medHorario) {
  const sel = document.getElementById('admin_medicacion_id');
  if (sel) sel.value = medId;
  const hp = document.getElementById('admin_hora_prog');
  if (hp) {
    const hora = medHorario.split(',').map(valor => valor.trim()).find(valor => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(valor));
    if (hora) hp.value = hora;
  }
  document.getElementById('modalRegistrarAdmin').showModal();
}
</script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
