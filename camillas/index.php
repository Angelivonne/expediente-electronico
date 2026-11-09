<?php
/**
 * Módulo de Censo de Camas, Camillas y Hospitalización.
 * Control operativo de camas censables, urgencias y hospitalización
 * conforme a la NOM-004-SSA3-2012 y NOM-016-SSA3-2012.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId() ?: 1;
$usuario = usuarioActual() ?: ['id' => 1, 'nombre' => 'Personal'];

// Procesar acciones de gestión de camillas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Intenta de nuevo.');
        redirigir('/camillas/');
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'asignar') {
        $camillaId = (int) ($_POST['camilla_id'] ?? 0);
        $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
        $diag = mb_substr(trim((string) ($_POST['diagnostico_ingreso'] ?? '')), 0, 255);
        $notas = trim((string) ($_POST['notas'] ?? ''));

        if ($camillaId && $pacienteId) {
            $stmt = $pdo->prepare("UPDATE camillas SET estado = 'ocupada', paciente_id = ?, ingreso_en = NOW(), diagnostico_ingreso = ?, notas = ? WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$pacienteId, $diag, $notas, $camillaId, $institucionId]);

            $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'ingreso', ?, ?, NOW())");
            $stmtMov->execute([$institucionId, $camillaId, $pacienteId, $diag ?: 'Ingreso a camilla', $usuario['id']]);

            registrarBitacora('camillas', $camillaId, 'actualizar', 'paciente_id', null, (string)$pacienteId);
            mensajeFlash('exito', 'Paciente asignado correctamente a la cama/camilla.');
        } else {
            mensajeFlash('error', 'Selecciona el paciente y la camilla.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'liberar') {
        $camillaId = (int) ($_POST['camilla_id'] ?? 0);
        $pasarALimpieza = !empty($_POST['limpieza']);

        if ($camillaId) {
            $cam = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ?");
            $cam->execute([$camillaId, $institucionId]);
            $cData = $cam->fetch();

            if ($cData && $cData['paciente_id']) {
                $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'alta', 'Alta hospitalaria / Liberación de camilla', ?, NOW())");
                $stmtMov->execute([$institucionId, $camillaId, (int)$cData['paciente_id'], $usuario['id']]);
            }

            $nuevoEstado = $pasarALimpieza ? 'limpieza' : 'disponible';
            $stmt = $pdo->prepare("UPDATE camillas SET estado = ?, paciente_id = NULL, ingreso_en = NULL, diagnostico_ingreso = NULL, notas = NULL WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$nuevoEstado, $camillaId, $institucionId]);

            mensajeFlash('exito', $pasarALimpieza ? 'Cama liberada y puesta en desinfección.' : 'Cama liberada y disponible.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'marcar_disponible') {
        $camillaId = (int) ($_POST['camilla_id'] ?? 0);
        if ($camillaId) {
            $stmt = $pdo->prepare("UPDATE camillas SET estado = 'disponible', paciente_id = NULL WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$camillaId, $institucionId]);
            mensajeFlash('exito', 'Camilla desinfectada y lista para ocupación.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'trasladar') {
        $camillaOrigenId = (int) ($_POST['camilla_origen_id'] ?? 0);
        $camillaDestinoId = (int) ($_POST['camilla_destino_id'] ?? 0);
        $motivo = mb_substr(trim((string) ($_POST['motivo_traslado'] ?? '')), 0, 255);

        if ($camillaOrigenId && $camillaDestinoId && $camillaOrigenId !== $camillaDestinoId) {
            $qOrig = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ?");
            $qOrig->execute([$camillaOrigenId, $institucionId]);
            $origen = $qOrig->fetch();

            $qDest = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ? AND estado = 'disponible'");
            $qDest->execute([$camillaDestinoId, $institucionId]);
            $destino = $qDest->fetch();

            if ($origen && $destino && $origen['paciente_id']) {
                $pacienteId = (int)$origen['paciente_id'];
                $diag = $origen['diagnostico_ingreso'];

                // Asignar destino
                $stmtDest = $pdo->prepare("UPDATE camillas SET estado = 'ocupada', paciente_id = ?, ingreso_en = ?, diagnostico_ingreso = ?, notas = ? WHERE id = ? AND institucion_id = ?");
                $stmtDest->execute([$pacienteId, $origen['ingreso_en'], $diag, $origen['notas'], $camillaDestinoId, $institucionId]);

                // Liberar origen a limpieza
                $stmtOrig = $pdo->prepare("UPDATE camillas SET estado = 'limpieza', paciente_id = NULL, ingreso_en = NULL, diagnostico_ingreso = NULL, notas = NULL WHERE id = ? AND institucion_id = ?");
                $stmtOrig->execute([$camillaOrigenId, $institucionId]);

                // Movimiento
                $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, camilla_origen_id, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'traslado', ?, ?, ?, NOW())");
                $stmtMov->execute([$institucionId, $camillaDestinoId, $pacienteId, $camillaOrigenId, $motivo ?: 'Traslado interno de cama', $usuario['id']]);

                mensajeFlash('exito', 'Paciente trasladado exitosamente.');
            } else {
                mensajeFlash('error', 'La cama de destino no está disponible o el origen es inválido.');
            }
        }
        redirigir('/camillas/');
    }
}

// Filtro de área
$areaFiltro = $_GET['area'] ?? 'todas';
$areasValidas = ['urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'];

$sql = "SELECT c.*, 
               p.nombre as pac_nombre, p.primer_apellido as pac_ap1, p.segundo_apellido as pac_ap2, 
               p.folio as pac_folio, p.fecha_nacimiento as pac_nac, p.sexo as pac_sexo, p.alergias as pac_alergias
        FROM camillas c
        LEFT JOIN pacientes p ON p.id = c.paciente_id AND p.eliminado_en IS NULL
        WHERE c.institucion_id = ?";
$params = [$institucionId];

if (in_array($areaFiltro, $areasValidas, true)) {
    $sql .= " AND c.area = ?";
    $params[] = $areaFiltro;
}
$sql .= " ORDER BY FIELD(c.area, 'urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'), c.codigo ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todasCamillas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Conteos globales de estado
$stmtCount = $pdo->prepare("SELECT 
    COUNT(*) as total,
    SUM(estado = 'ocupada') as ocupadas,
    SUM(estado = 'disponible') as disponibles,
    SUM(estado = 'limpieza') as limpieza,
    SUM(area = 'urgencias') as urgencias,
    SUM(area = 'hospitalizacion') as hosp,
    SUM(area = 'terapia_intensiva') as uti,
    SUM(area = 'cirugia') as cirugia
    FROM camillas WHERE institucion_id = ?");
$stmtCount->execute([$institucionId]);
$conteos = $stmtCount->fetch(PDO::FETCH_ASSOC);

$totalCamas   = (int)($conteos['total'] ?? 0);
$ocupadas     = (int)($conteos['ocupadas'] ?? 0);
$disponibles  = (int)($conteos['disponibles'] ?? 0);
$limpieza     = (int)($conteos['limpieza'] ?? 0);
$totalUrg     = (int)($conteos['urgencias'] ?? 0);
$totalHosp    = (int)($conteos['hosp'] ?? 0);
$totalUti     = (int)($conteos['uti'] ?? 0);

// Lista de pacientes activos para modal de asignación
$stmtPacientes = $pdo->prepare("SELECT id, folio, nombre, primer_apellido, segundo_apellido, curp 
    FROM pacientes 
    WHERE institucion_id = ? AND eliminado_en IS NULL 
    AND id NOT IN (SELECT DISTINCT paciente_id FROM camillas WHERE institucion_id = ? AND paciente_id IS NOT NULL AND estado = 'ocupada')
    ORDER BY nombre ASC, primer_apellido ASC LIMIT 100");
$stmtPacientes->execute([$institucionId, $institucionId]);
$pacientesDisponibles = $stmtPacientes->fetchAll(PDO::FETCH_ASSOC);

// Camillas disponibles para traslados
$camillasLibres = array_filter($todasCamillas, fn($c) => $c['estado'] === 'disponible');

$tituloPagina = 'Censo de Camas y Camillas';
$seccionActiva = 'camillas';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente">

  <div class="pagina__cabecera">
    <h1 class="pagina__titulo">Censo de Camas y Camillas</h1>
    <p class="pagina__subtitulo">Censo hospitalario en tiempo real · Camas censables, urgencias y hospitalización · NOM-004-SSA3-2012</p>
  </div>

  <!-- Barra de Resumen Métrico (Diseño oficial hoja-paciente__resumen) -->
  <dl class="hoja-paciente__resumen">
    <div><dt>Capacidad instalada</dt><dd><?= $totalCamas ?></dd></div>
    <div><dt>Ocupadas</dt><dd><?= $ocupadas ?></dd></div>
    <div><dt>Disponibles</dt><dd><?= $disponibles ?></dd></div>
    <div><dt>En desinfección</dt><dd><?= $limpieza ?></dd></div>
    <div><dt>Urgencias</dt><dd><?= $totalUrg ?></dd></div>
    <div><dt>Hospitalización</dt><dd><?= $totalHosp ?></dd></div>
    <div><dt>Terapia intensiva</dt><dd><?= $totalUti ?></dd></div>
  </dl>

  <!-- Sección de Base de Datos Nativa (bd / bd-tabla) -->
  <section class="bd" data-bd style="margin-top: 28px;">
    <div class="bd__cabecera">
      <h2 class="bd__titulo">Unidades Hospitalarias y Estancias Activas</h2>

      <div class="bd__herramientas">
        <!-- Filtros por área en botones de texto limpios -->
        <a href="?area=todas" class="boton<?= $areaFiltro === 'todas' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Todas (<?= $totalCamas ?>)</a>
        <a href="?area=urgencias" class="boton<?= $areaFiltro === 'urgencias' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Urgencias</a>
        <a href="?area=hospitalizacion" class="boton<?= $areaFiltro === 'hospitalizacion' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Piso 1</a>
        <a href="?area=cirugia" class="boton<?= $areaFiltro === 'cirugia' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Cirugía</a>
        <a href="?area=terapia_intensiva" class="boton<?= $areaFiltro === 'terapia_intensiva' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">UTI</a>

        <a href="<?= URL_BASE ?>/camillas/kardex.php" class="boton boton--secundario" style="margin-left: 6px;">
          <?= icono('documento') ?> Kardex
        </a>
        <button type="button" class="boton boton--primario bd__nuevo" onclick="document.getElementById('modal-asignar-camilla').showModal();">
          <?= icono('agregar') ?> Asignar paciente
        </button>
      </div>
    </div>

    <div class="bd__marco">
      <table class="bd-tabla">
        <thead>
          <tr>
            <th scope="col"><button type="button"><?= icono('clinico') ?> <span>Cama / Camilla</span></button></th>
            <th scope="col"><button type="button"><?= icono('carpeta') ?> <span>Área / Servicio</span></button></th>
            <th scope="col"><button type="button"><?= icono('etiqueta') ?> <span>Tipo</span></button></th>
            <th scope="col"><button type="button"><?= icono('reloj') ?> <span>Estado</span></button></th>
            <th scope="col"><button type="button"><?= icono('usuarios') ?> <span>Paciente asignado</span></button></th>
            <th scope="col"><button type="button"><?= icono('documento') ?> <span>Diagnóstico / Motivo</span></button></th>
            <th scope="col"><button type="button"><?= icono('calendario') ?> <span>Ingreso</span></button></th>
            <th scope="col" class="num"><button type="button"><?= icono('reloj') ?> <span>Estancia</span></button></th>
            <th scope="col" style="text-align: right;"><button type="button"><span>Acciones</span></button></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($todasCamillas as $c): 
            $nombrePac = $c['pac_nombre'] ? trim("{$c['pac_nombre']} {$c['pac_ap1']} {$c['pac_ap2']}") : '';
            $diasEstancia = 0;
            if ($c['ingreso_en']) {
                $diasEstancia = max(1, (new DateTime($c['ingreso_en']))->diff(new DateTime())->days);
            }
            $esOcupada = $c['estado'] === 'ocupada';
            $esLimpieza = $c['estado'] === 'limpieza';
            $tipoTexto = match($c['tipo']) {
                'cama_censable' => 'Cama censable',
                'cama_no_censable' => 'No censable',
                'camilla' => 'Camilla',
                'cuna' => 'Cuna',
                'incubadora' => 'Incubadora',
                default => ucfirst((string)$c['tipo'])
            };
          ?>
            <tr>
              <td class="bd-tabla__celda-titulo">
                <span class="bd-tabla__titulo">
                  <?= icono('clinico') ?>
                  <span><?= e($c['codigo']) ?></span>
                </span>
              </td>
              <td><?= e($c['piso'] ?: ucfirst($c['area'])) ?></td>
              <td><?= e($tipoTexto) ?></td>
              <td>
                <?php if ($esOcupada): ?>
                  <span style="color: #60a5fa; font-weight: 600;">Ocupada</span>
                <?php elseif ($esLimpieza): ?>
                  <span style="color: #facc15; font-weight: 600;">En limpieza</span>
                <?php else: ?>
                  <span style="color: #4ade80; font-weight: 600;">Disponible</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($esOcupada && $c['paciente_id']): ?>
                  <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$c['paciente_id'] ?>" style="color: inherit; text-decoration: underline;">
                    <?= e($nombrePac) ?>
                  </a>
                  <span style="color: var(--texto-tenue); font-size: var(--fs-xs); display: block;">Folio: <?= e($c['pac_folio']) ?></span>
                <?php else: ?>
                  <span style="color: var(--texto-tenue);">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?= e($c['diagnostico_ingreso'] ?: '—') ?>
              </td>
              <td>
                <?= $c['ingreso_en'] ? e(fechaCorta($c['ingreso_en'])) : '—' ?>
              </td>
              <td class="num">
                <?= $esOcupada ? "{$diasEstancia} d" : '—' ?>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <?php if ($esOcupada): ?>
                  <a href="<?= URL_BASE ?>/camillas/kardex.php?paciente_id=<?= (int)$c['paciente_id'] ?>" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs);">
                    Kardex
                  </a>
                  <button type="button" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs);" onclick="abrirModalTraslado(<?= (int)$c['id'] ?>, '<?= e($c['codigo']) ?>', '<?= e($nombrePac) ?>');">
                    Trasladar
                  </button>
                  <form method="post" action="" style="display: inline;" onsubmit="return confirm('¿Confirmas el alta y liberación de la cama?');">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="liberar">
                    <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="limpieza" value="1">
                    <button type="submit" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs); color: #f87171;">
                      Alta
                    </button>
                  </form>
                <?php elseif ($esLimpieza): ?>
                  <form method="post" action="" style="display: inline;">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="marcar_disponible">
                    <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs); color: #4ade80;">
                      Marcar lista
                    </button>
                  </form>
                <?php else: ?>
                  <button type="button" class="boton boton--sutil" style="padding: 2px 7px; font-size: var(--fs-xs);" onclick="abrirModalAsignarDirecto(<?= (int)$c['id'] ?>, '<?= e($c['codigo']) ?>');">
                    Asignar
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<!-- Modal: Asignar Paciente a Cama -->
<dialog id="modal-asignar-camilla" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 500px; width: 95%;">
  <form method="post" action="">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="asignar">

    <div class="ficha__titulo-fila" style="margin-bottom: 16px;">
      <h3 class="ficha__titulo" style="font-size: 1.1rem; margin: 0;">Asignar Paciente a Cama / Camilla</h3>
    </div>

    <div style="display: flex; flex-direction: column; gap: 12px;">
      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Cama / Camilla Destino *</label>
        <select id="modal_camilla_id" name="camilla_id" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          <option value="">Selecciona una cama...</option>
          <?php foreach ($todasCamillas as $tc): ?>
            <?php if ($tc['estado'] === 'disponible'): ?>
              <option value="<?= (int)$tc['id'] ?>"><?= e($tc['codigo']) ?> — <?= e($tc['piso'] ?: ucfirst($tc['area'])) ?> (<?= e(ucfirst($tc['tipo'])) ?>)</option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Paciente a Ingresar *</label>
        <select name="paciente_id" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          <option value="">Selecciona un paciente del expediente...</option>
          <?php foreach ($pacientesDisponibles as $pd): ?>
            <option value="<?= (int)$pd['id'] ?>"><?= e("{$pd['nombre']} {$pd['primer_apellido']} {$pd['segundo_apellido']}") ?> [<?= e($pd['folio']) ?>]</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Diagnóstico de Admisión / Motivo</label>
        <input type="text" name="diagnostico_ingreso" placeholder="Ej. Insuficiencia respiratoria aguda, Post-quirúrgico..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
      </div>

      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Notas Clínicas de Ingreso</label>
        <textarea name="notas" rows="2" placeholder="Indicaciones iniciales..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff; resize: vertical;"></textarea>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
      <button type="button" class="boton boton--sutil" onclick="document.getElementById('modal-asignar-camilla').close();">Cancelar</button>
      <button type="submit" class="boton boton--primario">Confirmar Ingreso</button>
    </div>
  </form>
</dialog>

<!-- Modal: Traslado Interno de Cama -->
<dialog id="modal-trasladar" class="modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 480px; width: 95%;">
  <form method="post" action="">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="trasladar">
    <input type="hidden" id="traslado_origen_id" name="camilla_origen_id" value="">

    <div class="ficha__titulo-fila" style="margin-bottom: 16px;">
      <h3 class="ficha__titulo" style="font-size: 1.1rem; margin: 0;">Traslado Interno de Paciente</h3>
    </div>

    <p style="font-size: var(--fs-sm); color: var(--texto-suave); margin-bottom: 14px;">
      Paciente: <strong id="traslado_paciente_nombre" style="color: #fff;"></strong><br>
      Cama actual: <strong id="traslado_origen_codigo" style="color: #93c5fd;"></strong>
    </p>

    <div style="display: flex; flex-direction: column; gap: 12px;">
      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Cama de Destino Disponible *</label>
        <select name="camilla_destino_id" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          <option value="">Selecciona la cama destino...</option>
          <?php foreach ($camillasLibres as $cl): ?>
            <option value="<?= (int)$cl['id'] ?>"><?= e($cl['codigo']) ?> — <?= e($cl['piso'] ?: ucfirst($cl['area'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Motivo Clínico del Traslado</label>
        <input type="text" name="motivo_traslado" placeholder="Ej. Pasa a hospitalización general, Ingreso a UTI..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
      <button type="button" class="boton boton--sutil" onclick="document.getElementById('modal-trasladar').close();">Cancelar</button>
      <button type="submit" class="boton boton--primario">Ejecutar Traslado</button>
    </div>
  </form>
</dialog>

<script>
function abrirModalAsignarDirecto(camillaId, camillaCodigo) {
  const sel = document.getElementById('modal_camilla_id');
  if (sel) sel.value = camillaId;
  document.getElementById('modal-asignar-camilla').showModal();
}

function abrirModalTraslado(camillaId, camillaCodigo, pacienteNombre) {
  document.getElementById('traslado_origen_id').value = camillaId;
  document.getElementById('traslado_origen_codigo').textContent = camillaCodigo;
  document.getElementById('traslado_paciente_nombre').textContent = pacienteNombre;
  document.getElementById('modal-trasladar').showModal();
}
</script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
