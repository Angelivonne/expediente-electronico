<?php
/**
 * Módulo de Censo de Camas, Camillas y Hospitalización.
 * Control en tiempo real de ocupación hospitalaria, áreas clínicas, asignación de pacientes,
 * traslados internos y liberación de camas según la NOM-004-SSA3-2012 y NOM-016-SSA3-2012.
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

            // Registrar en bitácora
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

            mensajeFlash('exito', $pasarALimpieza ? 'Cama liberada y puesta en proceso de desinfección/limpieza.' : 'Cama liberada y disponible de inmediato.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'marcar_disponible') {
        $camillaId = (int) ($_POST['camilla_id'] ?? 0);
        if ($camillaId) {
            $stmt = $pdo->prepare("UPDATE camillas SET estado = 'disponible', paciente_id = NULL WHERE id = ? AND institucion_id = ?");
            $stmt->execute([$camillaId, $institucionId]);
            mensajeFlash('exito', 'Camilla desinfectada y marcada como DISPONIBLE.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'trasladar') {
        $camillaOrigenId = (int) ($_POST['camilla_origen_id'] ?? 0);
        $camillaDestinoId = (int) ($_POST['camilla_destino_id'] ?? 0);
        $motivo = mb_substr(trim((string) ($_POST['motivo_traslado'] ?? '')), 0, 255);

        if ($camillaOrigenId && $camillaDestinoId) {
            $orig = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ?");
            $orig->execute([$camillaOrigenId, $institucionId]);
            $dOrig = $orig->fetch();

            if ($dOrig && $dOrig['paciente_id']) {
                $pId = (int) $dOrig['paciente_id'];
                $ing = $dOrig['ingreso_en'];
                $diag = $dOrig['diagnostico_ingreso'];

                // Ocupar destino
                $pdo->prepare("UPDATE camillas SET estado = 'ocupada', paciente_id = ?, ingreso_en = ?, diagnostico_ingreso = ? WHERE id = ? AND institucion_id = ?")
                    ->execute([$pId, $ing, $diag, $camillaDestinoId, $institucionId]);

                // Liberar origen a limpieza
                $pdo->prepare("UPDATE camillas SET estado = 'limpieza', paciente_id = NULL, ingreso_en = NULL, diagnostico_ingreso = NULL, notas = NULL WHERE id = ? AND institucion_id = ?")
                    ->execute([$camillaOrigenId, $institucionId]);

                // Movimiento
                $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, camilla_anterior_id, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'traslado', ?, ?, ?, NOW())")
                    ->execute([$institucionId, $camillaDestinoId, $pId, $camillaOrigenId, $motivo ?: 'Traslado interno de servicio', $usuario['id']]);

                mensajeFlash('exito', 'Paciente trasladado exitosamente. La camilla previa quedó en limpieza.');
            }
        }
        redirigir('/camillas/');
    }
}

// Filtro de área
$areaFiltro = $_GET['area'] ?? 'todas';
$condArea = '';
$paramsArea = [$institucionId];
if (in_array($areaFiltro, ['urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'], true)) {
    $condArea = ' AND c.area = ?';
    $paramsArea[] = $areaFiltro;
}

// Consulta de camillas con datos del paciente si está ocupada
$sqlCamillas = "SELECT c.*, 
       p.nombre AS pac_nombre, p.primer_apellido AS pac_ap1, p.segundo_apellido AS pac_ap2,
       p.sexo AS pac_sexo, p.fecha_nacimiento AS pac_nacimiento, p.folio AS pac_folio, p.foto AS pac_foto
FROM camillas c
LEFT JOIN pacientes p ON p.id = c.paciente_id AND p.eliminado_en IS NULL
WHERE c.institucion_id = ? $condArea
ORDER BY c.area DESC, c.codigo ASC";

$stmt = $pdo->prepare($sqlCamillas);
$stmt->execute($paramsArea);
$todasCamillas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales para métricas
$stmtMetricas = $pdo->prepare("SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN estado = 'ocupada' THEN 1 ELSE 0 END) AS ocupadas,
    SUM(CASE WHEN estado = 'disponible' THEN 1 ELSE 0 END) AS disponibles,
    SUM(CASE WHEN estado = 'limpieza' THEN 1 ELSE 0 END) AS limpieza
FROM camillas WHERE institucion_id = ?");
$stmtMetricas->execute([$institucionId]);
$metricas = $stmtMetricas->fetch(PDO::FETCH_ASSOC);

$totalCamas = (int) ($metricas['total'] ?? 0);
$ocupadas = (int) ($metricas['ocupadas'] ?? 0);
$disponibles = (int) ($metricas['disponibles'] ?? 0);
$limpieza = (int) ($metricas['limpieza'] ?? 0);
$porcentajeOcupacion = $totalCamas > 0 ? round(($ocupadas / $totalCamas) * 100, 1) : 0;

// Lista de pacientes para asignar (los que no están hospitalizados actualmente)
$pacientesParaAsignar = $pdo->query("SELECT p.id, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido, p.sexo, p.padecimientos_cronicos, p.motivo_consulta 
FROM pacientes p 
WHERE p.eliminado_en IS NULL 
  AND p.id NOT IN (SELECT paciente_id FROM camillas WHERE paciente_id IS NOT NULL)
ORDER BY p.primer_apellido, p.nombre LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// Camillas disponibles para traslados
$camillasDisponiblesParaTraslado = array_filter($todasCamillas, fn($c) => $c['estado'] === 'disponible');

$tituloPagina = 'Censo de Camas y Camillas';
$seccionActiva = 'camillas';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente">

  <!-- Cabecera de Sección (Mismo diseño institucional del sistema) -->
  <header class="ficha__cabecera" style="margin-bottom: 24px;">
    <div class="ficha__titulo-fila" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
      <div>
        <h1 class="ficha__titulo" style="font-size: 1.75rem; margin: 0; display: flex; align-items: center; gap: 10px;">
          <?= icono('clinico') ?>
          <span>Censo de Camas, Camillas y Hospitalización</span>
        </h1>
        <p class="ficha__subtitulo" style="color: var(--texto-suave); margin-top: 4px; font-size: 0.95rem;">
          Control operativo en tiempo real de estancias, camas censables, urgencias y cuidados intensivos (NOM-004 / NOM-016-SSA3-2012).
        </p>
      </div>

      <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="<?= URL_BASE ?>/camillas/kardex.php" class="boton boton--secundario" style="display: inline-flex; align-items: center; gap: 6px;">
          <?= icono('documento') ?>
          <span>Kardex de Medicación</span>
        </a>
        <button type="button" class="boton boton--primario" onclick="document.getElementById('modal-asignar-camilla').showModal();" style="display: inline-flex; align-items: center; gap: 6px;">
          <?= icono('agregar') ?>
          <span>Asignar Paciente a Cama</span>
        </button>
      </div>
    </div>
  </header>

  <!-- Métricas Rápidas de Ocupación Hospitalaria -->
  <section class="tarjetas-resumen" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 28px;">
    
    <div class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; padding: 18px 20px;">
      <div style="color: var(--texto-tenue); font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.04em;">Capacidad Total Instalada</div>
      <div style="font-size: 1.85rem; font-weight: 700; color: #ffffff; margin-top: 6px;"><?= $totalCamas ?> <span style="font-size: 0.95rem; font-weight: 400; color: var(--texto-suave);">camas/camillas</span></div>
      <div style="font-size: 0.8125rem; color: var(--texto-suave); margin-top: 4px;">Urgencias, Piso y Terapia</div>
    </div>

    <div class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; padding: 18px 20px;">
      <div style="color: #60a5fa; font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.04em;">Camas Ocupadas</div>
      <div style="font-size: 1.85rem; font-weight: 700; color: #60a5fa; margin-top: 6px;"><?= $ocupadas ?> <span style="font-size: 0.95rem; font-weight: 400; color: var(--texto-suave);">(<?= $porcentajeOcupacion ?>%)</span></div>
      <div style="font-size: 0.8125rem; color: var(--texto-suave); margin-top: 4px;">Pacientes en estancia activa</div>
    </div>

    <div class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; padding: 18px 20px;">
      <div style="color: #4ade80; font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.04em;">Camas Disponibles</div>
      <div style="font-size: 1.85rem; font-weight: 700; color: #4ade80; margin-top: 6px;"><?= $disponibles ?> <span style="font-size: 0.95rem; font-weight: 400; color: var(--texto-suave);">libres</span></div>
      <div style="font-size: 0.8125rem; color: var(--texto-suave); margin-top: 4px;">Listas para ingreso inmediato</div>
    </div>

    <div class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 12px; padding: 18px 20px;">
      <div style="color: #facc15; font-size: 0.8125rem; text-transform: uppercase; letter-spacing: 0.04em;">En Desinfección / Limpieza</div>
      <div style="font-size: 1.85rem; font-weight: 700; color: #facc15; margin-top: 6px;"><?= $limpieza ?> <span style="font-size: 0.95rem; font-weight: 400; color: var(--texto-suave);">camas</span></div>
      <div style="font-size: 0.8125rem; color: var(--texto-suave); margin-top: 4px;">Protocolo post-alta</div>
    </div>

  </section>

  <!-- Filtro por Área Clínica (Pestañas nativas de la aplicación) -->
  <div style="display: flex; gap: 8px; margin-bottom: 22px; border-bottom: 1px solid #2a2a2a; padding-bottom: 12px; overflow-x: auto;">
    <a href="?area=todas" class="boton<?= $areaFiltro === 'todas' ? ' boton--primario' : ' boton--secundario' ?>" style="border-radius: 20px; font-size: 0.875rem;">
      Todas las áreas (<?= $totalCamas ?>)
    </a>
    <a href="?area=urgencias" class="boton<?= $areaFiltro === 'urgencias' ? ' boton--primario' : ' boton--secundario' ?>" style="border-radius: 20px; font-size: 0.875rem;">
      <?= icono('alerta') ?> Urgencias y Choque
    </a>
    <a href="?area=hospitalizacion" class="boton<?= $areaFiltro === 'hospitalizacion' ? ' boton--primario' : ' boton--secundario' ?>" style="border-radius: 20px; font-size: 0.875rem;">
      <?= icono('casa') ?> Piso 1 · Medicina Interna
    </a>
    <a href="?area=cirugia" class="boton<?= $areaFiltro === 'cirugia' ? ' boton--primario' : ' boton--secundario' ?>" style="border-radius: 20px; font-size: 0.875rem;">
      <?= icono('documento') ?> Piso 2 · Cirugía y Recuperación
    </a>
    <a href="?area=terapia_intensiva" class="boton<?= $areaFiltro === 'terapia_intensiva' ? ' boton--primario' : ' boton--secundario' ?>" style="border-radius: 20px; font-size: 0.875rem;">
      <?= icono('corazon') ?> Terapia Intensiva (UTI)
    </a>
  </div>

  <!-- Rejilla Visual de Camillas / Camas (Diseño idéntico a las tarjetas del perfil de paciente) -->
  <section class="ficha__seccion" style="margin-bottom: 36px;">
    <div class="ficha__titulo-fila" style="margin-bottom: 16px;">
      <h2 class="ficha__titulo" style="font-size: 1.15rem;">Censo Visual de Unidades Hospitalarias</h2>
      <span class="ficha__fuente"><?= count($todasCamillas) ?> unidades activas</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 18px;">
      <?php foreach ($todasCamillas as $cam): 
        $esOcupada = ($cam['estado'] === 'ocupada' && !empty($cam['paciente_id']));
        $esLimpieza = ($cam['estado'] === 'limpieza');
        $esDisponible = ($cam['estado'] === 'disponible');

        $colorBorde = $esOcupada ? '#3b82f6' : ($esLimpieza ? '#ca8a04' : '#22c55e');
        $badgeColor = $esOcupada ? 'rgba(59, 130, 246, 0.16); color: #93c5fd;' : ($esLimpieza ? 'rgba(234, 179, 8, 0.16); color: #fde047;' : 'rgba(34, 197, 94, 0.16); color: #86efac;');
        $etiquetaEstado = $esOcupada ? 'Ocupada' : ($esLimpieza ? 'En Limpieza' : 'Disponible');

        $diasEstancia = 0;
        if ($esOcupada && !empty($cam['ingreso_en'])) {
            $fIng = new DateTime($cam['ingreso_en']);
            $fHoy = new DateTime();
            $diasEstancia = max(1, $fIng->diff($fHoy)->days);
        }
      ?>
        <div class="tarjeta" style="background: #181818; border: 1px solid #2a2a2a; border-top: 4px solid <?= $colorBorde ?>; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between; transition: transform 120ms ease, border-color 120ms ease;">
          
          <div>
            <!-- Encabezado de la Cama -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
              <div>
                <span style="font-size: 1.15rem; font-weight: 700; color: #ffffff;"><?= e($cam['codigo']) ?></span>
                <span style="display: block; font-size: 0.75rem; color: var(--texto-suave); margin-top: 2px;">
                  <?= e($cam['piso'] ?: ucfirst($cam['area'])) ?> · <?= e(str_replace('_', ' ', $cam['tipo'])) ?>
                </span>
              </div>
              <span style="padding: 4px 10px; border-radius: 14px; font-size: 0.75rem; font-weight: 600; background: <?= $badgeColor ?>">
                <?= $etiquetaEstado ?>
              </span>
            </div>

            <!-- Cuerpo: Paciente o Estado Libre -->
            <?php if ($esOcupada): 
              $nombrePac = trim("{$cam['pac_nombre']} {$cam['pac_ap1']} {$cam['pac_ap2']}");
              $edadPac = $cam['pac_nacimiento'] ? (date('Y') - date('Y', strtotime($cam['pac_nacimiento']))) : 0;
            ?>
              <div style="padding: 12px; background: #202020; border-radius: 8px; border: 1px solid #2e2e2e; margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                  <span class="avatar avatar--sm" style="width: 32px; height: 32px; border-radius: 50%; background: #2f3e46; display: grid; place-items: center; color: #fff; font-size: 0.8125rem; font-weight: 600;">
                    <?= strtoupper(substr($cam['pac_nombre'] ?? 'P', 0, 1) . substr($cam['pac_ap1'] ?? 'P', 0, 1)) ?>
                  </span>
                  <div style="overflow: hidden;">
                    <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$cam['paciente_id'] ?>" style="font-size: 0.9375rem; font-weight: 600; color: #ffffff; text-decoration: none; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="Abrir expediente de <?= e($nombrePac) ?>">
                      <?= e($nombrePac) ?>
                    </a>
                    <span style="font-size: 0.75rem; color: var(--texto-suave);">
                      <?= e($cam['pac_folio'] ?: 'EXP') ?> · <?= $edadPac ?> años · <?= $cam['pac_sexo'] === 'H' ? 'Hombre' : 'Mujer' ?>
                    </span>
                  </div>
                </div>

                <div style="font-size: 0.8125rem; color: #d4d4d4; margin-top: 6px;">
                  <strong style="color: var(--texto-suave);">Dx:</strong> <?= e($cam['diagnostico_ingreso'] ?: 'Monitoreo hospitalario') ?>
                </div>

                <div style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 6px; display: flex; justify-content: space-between;">
                  <span>Ingreso: <?= e(fechaCorta($cam['ingreso_en'])) ?></span>
                  <span style="color: #93c5fd; font-weight: 600;">Estancia: <?= $diasEstancia ?> <?= $diasEstancia === 1 ? 'día' : 'días' ?></span>
                </div>
              </div>
            <?php elseif ($esLimpieza): ?>
              <div style="padding: 16px; background: rgba(234, 179, 8, 0.05); border: 1px dashed rgba(234, 179, 8, 0.3); border-radius: 8px; margin-bottom: 14px; text-align: center;">
                <p style="font-size: 0.875rem; color: #fde047; margin: 0;">Unidad en sanitización y recambio de ropa de cama.</p>
                <span style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 4px; display: block;">No asignar hasta completar desinfección.</span>
              </div>
            <?php else: ?>
              <div style="padding: 16px; background: rgba(34, 197, 94, 0.05); border: 1px dashed rgba(34, 197, 94, 0.25); border-radius: 8px; margin-bottom: 14px; text-align: center;">
                <p style="font-size: 0.875rem; color: #86efac; margin: 0;">Unidad higienizada y lista para ocupación.</p>
                <span style="font-size: 0.75rem; color: var(--texto-tenue); margin-top: 4px; display: block;">Disponible para urgencias o ingreso programado.</span>
              </div>
            <?php endif; ?>
          </div>

          <!-- Acciones de la Camilla -->
          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: auto;">
            <?php if ($esOcupada): ?>
              <form method="post" action="" style="display: inline;" onsubmit="return confirm('¿Confirmas el alta hospitalaria y liberación de esta cama?');">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion" value="liberar">
                <input type="hidden" name="camilla_id" value="<?= (int)$cam['id'] ?>">
                <input type="hidden" name="limpieza" value="1">
                <button type="submit" class="boton boton--sutil" style="font-size: 0.8125rem; color: #f87171; border-color: rgba(248, 113, 113, 0.3);">
                  Liberar / Alta
                </button>
              </form>

              <button type="button" class="boton boton--secundario" style="font-size: 0.8125rem;" onclick="abrirModalTraslado(<?= (int)$cam['id'] ?>, '<?= e($cam['codigo']) ?>', '<?= e($nombrePac) ?>');">
                Trasladar
              </button>

              <a href="<?= URL_BASE ?>/camillas/kardex.php?camilla_id=<?= (int)$cam['id'] ?>" class="boton boton--secundario" style="font-size: 0.8125rem;" title="Ver hoja de medicación y enfermería">
                💊 Kardex
              </a>

              <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$cam['paciente_id'] ?>" class="boton boton--sutil" style="font-size: 0.8125rem; margin-left: auto;">
                Expediente →
              </a>
            <?php elseif ($esLimpieza): ?>
              <form method="post" action="" style="width: 100%;">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion" value="marcar_disponible">
                <input type="hidden" name="camilla_id" value="<?= (int)$cam['id'] ?>">
                <button type="submit" class="boton boton--secundario" style="width: 100%; font-size: 0.8125rem; color: #4ade80;">
                  <?= icono('verificado') ?> Marcar como Desinfectada y Libre
                </button>
              </form>
            <?php else: ?>
              <button type="button" class="boton boton--primario" style="width: 100%; font-size: 0.8125rem;" onclick="abrirModalAsignarDirecto(<?= (int)$cam['id'] ?>, '<?= e($cam['codigo']) ?>');">
                + Asignar Paciente
              </button>
            <?php endif; ?>
          </div>

        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Tabla Detallada del Censo Hospitalario (Vista Formal) -->
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila" style="margin-bottom: 14px;">
      <h2 class="ficha__titulo" style="font-size: 1.15rem;">Libro de Censo y Estancias Activas</h2>
      <button type="button" class="boton boton--sutil" onclick="window.print();" style="display: inline-flex; align-items: center; gap: 6px;">
        <?= icono('documento') ?> <span>Imprimir Censo Diario</span>
      </button>
    </div>

    <div class="tabla-contenedor" style="background: #181818; border: 1px solid #2a2a2a; border-radius: 10px; overflow: hidden;">
      <table class="tabla" style="width: 100%; margin: 0;">
        <thead>
          <tr style="background: #202020; border-bottom: 1px solid #2c2c2c;">
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Cama / Camilla</th>
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Servicio / Área</th>
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Estado</th>
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Paciente Asignado</th>
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Diagnóstico de Admisión</th>
            <th style="padding: 12px 16px; text-align: left; font-size: 0.8125rem;">Fecha Ingreso</th>
            <th style="padding: 12px 16px; text-align: right; font-size: 0.8125rem;">Estancia</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($todasCamillas as $c): 
            $nombreC = $c['pac_nombre'] ? trim("{$c['pac_nombre']} {$c['pac_ap1']} {$c['pac_ap2']}") : '—';
            $dias = 0;
            if ($c['ingreso_en']) {
                $dias = max(1, (new DateTime($c['ingreso_en']))->diff(new DateTime())->days);
            }
          ?>
            <tr style="border-bottom: 1px solid #232323;">
              <td style="padding: 12px 16px; font-weight: 600; color: #fff;"><?= e($c['codigo']) ?></td>
              <td style="padding: 12px 16px; color: var(--texto-suave); font-size: 0.875rem;"><?= e($c['piso'] ?: ucfirst($c['area'])) ?></td>
              <td style="padding: 12px 16px;">
                <span class="badge" style="padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; <?= $c['estado'] === 'ocupada' ? 'background:rgba(59,130,246,0.18); color:#93c5fd;' : ($c['estado'] === 'limpieza' ? 'background:rgba(234,179,8,0.18); color:#fde047;' : 'background:rgba(34,197,94,0.18); color:#86efac;') ?>">
                  <?= ucfirst($c['estado']) ?>
                </span>
              </td>
              <td style="padding: 12px 16px; font-size: 0.875rem;">
                <?php if ($c['paciente_id']): ?>
                  <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$c['paciente_id'] ?>" style="color: #60a5fa; text-decoration: none; font-weight: 500;">
                    <?= e($nombreC) ?>
                  </a>
                <?php else: ?>
                  <span style="color: var(--texto-tenue);">Sin paciente</span>
                <?php endif; ?>
              </td>
              <td style="padding: 12px 16px; font-size: 0.8125rem; color: var(--texto-suave);"><?= e($c['diagnostico_ingreso'] ?: '—') ?></td>
              <td style="padding: 12px 16px; font-size: 0.8125rem; color: var(--texto-tenue);"><?= $c['ingreso_en'] ? e(fechaCorta($c['ingreso_en'])) : '—' ?></td>
              <td style="padding: 12px 16px; text-align: right; font-size: 0.875rem; color: #fff;"><?= $c['ingreso_en'] ? ($dias . ' d') : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<!-- Modal 1: Asignar Paciente a Cama -->
<dialog id="modal-asignar-camilla" style="border: 1px solid #333; border-radius: 12px; background: #1c1c1c; color: #fff; max-width: 520px; width: 92%; padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.8);">
  <form method="post" action="">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="asignar">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
      <h3 style="margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
        <?= icono('clinico') ?> <span>Asignar Paciente a Cama / Camilla</span>
      </h3>
      <button type="button" class="boton-icono" onclick="document.getElementById('modal-asignar-camilla').close();" title="Cerrar">✕</button>
    </div>

    <div style="margin-bottom: 14px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Seleccionar Cama / Camilla:</label>
      <select name="camilla_id" id="select-camilla-destino" required style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.9375rem;">
        <option value="">-- Elige una cama disponible --</option>
        <?php foreach ($todasCamillas as $cm): if ($cm['estado'] === 'disponible'): ?>
          <option value="<?= (int)$cm['id'] ?>"><?= e($cm['codigo']) ?> · <?= e($cm['piso'] ?: ucfirst($cm['area'])) ?></option>
        <?php endif; endforeach; ?>
      </select>
    </div>

    <div style="margin-bottom: 14px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Paciente para Ingreso:</label>
      <select name="paciente_id" required style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.9375rem;">
        <option value="">-- Selecciona un paciente --</option>
        <?php foreach ($pacientesParaAsignar as $pItem): 
          $nomP = trim("{$pItem['nombre']} {$pItem['primer_apellido']} {$pItem['segundo_apellido']}");
        ?>
          <option value="<?= (int)$pItem['id'] ?>"><?= e($nomP) ?> (<?= e($pItem['folio']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="margin-bottom: 14px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Diagnóstico de Admisión / Motivo:</label>
      <input type="text" name="diagnostico_ingreso" placeholder="Ej. Observación por crisis hipertensiva, abdomen agudo..." style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.875rem;">
    </div>

    <div style="margin-bottom: 20px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Indicaciones o notas iniciales:</label>
      <textarea name="notas" rows="2" placeholder="Indicaciones para enfermería, dieta, soluciones IV..." style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.875rem;"></textarea>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px;">
      <button type="button" class="boton boton--secundario" onclick="document.getElementById('modal-asignar-camilla').close();">Cancelar</button>
      <button type="submit" class="boton boton--primario">Confirmar Asignación</button>
    </div>
  </form>
</dialog>

<!-- Modal 2: Trasladar Paciente -->
<dialog id="modal-trasladar" style="border: 1px solid #333; border-radius: 12px; background: #1c1c1c; color: #fff; max-width: 480px; width: 92%; padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,0.8);">
  <form method="post" action="">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="trasladar">
    <input type="hidden" name="camilla_origen_id" id="traslado-origen-id" value="">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
      <h3 style="margin: 0; font-size: 1.25rem;">Traslado Interno de Camilla</h3>
      <button type="button" class="boton-icono" onclick="document.getElementById('modal-trasladar').close();">✕</button>
    </div>

    <p style="font-size: 0.875rem; color: var(--texto-suave); margin-bottom: 14px;">
      Mover al paciente <strong id="traslado-paciente-nombre" style="color: #fff;"></strong> desde <strong id="traslado-camilla-origen-nombre" style="color: #60a5fa;"></strong>.
    </p>

    <div style="margin-bottom: 14px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Nueva Cama / Camilla Destino:</label>
      <select name="camilla_destino_id" required style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.9375rem;">
        <option value="">-- Selecciona camilla libre --</option>
        <?php foreach ($camillasDisponiblesParaTraslado as $cDisp): ?>
          <option value="<?= (int)$cDisp['id'] ?>"><?= e($cDisp['codigo']) ?> · <?= e($cDisp['piso'] ?: ucfirst($cDisp['area'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="margin-bottom: 20px;">
      <label style="display: block; font-size: 0.8125rem; color: var(--texto-suave); margin-bottom: 6px;">Motivo del Traslado:</label>
      <input type="text" name="motivo_traslado" placeholder="Ej. Pasa de urgencias a piso de hospitalización por mejoría" style="width: 100%; padding: 8px 12px; background: #121212; border: 1px solid #333; border-radius: 6px; color: #fff; font-size: 0.875rem;">
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 10px;">
      <button type="button" class="boton boton--secundario" onclick="document.getElementById('modal-trasladar').close();">Cancelar</button>
      <button type="submit" class="boton boton--primario">Ejecutar Traslado</button>
    </div>
  </form>
</dialog>

<script>
function abrirModalAsignarDirecto(camillaId, codigo) {
  const sel = document.getElementById('select-camilla-destino');
  if (sel) sel.value = String(camillaId);
  const modal = document.getElementById('modal-asignar-camilla');
  if (modal) modal.showModal();
}

function abrirModalTraslado(camillaId, codigo, pacNombre) {
  document.getElementById('traslado-origen-id').value = String(camillaId);
  document.getElementById('traslado-camilla-origen-nombre').textContent = codigo;
  document.getElementById('traslado-paciente-nombre').textContent = pacNombre;
  const modal = document.getElementById('modal-trasladar');
  if (modal) modal.showModal();
}
</script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
