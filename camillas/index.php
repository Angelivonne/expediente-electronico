<?php
/**
 * Módulo de Censo de Camas, Camillas y Hospitalización.
 * Control operativo de camas censables, urgencias y hospitalización
 * conforme a la NOM-004-SSA3-2012 y NOM-016-SSA3-2012.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/flujo.php';
exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId() ?: 1;
$usuario = usuarioActual() ?: ['id' => 1, 'nombre' => 'Personal'];
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
        error_log('[camillas] ' . $e->getMessage());
        mensajeFlash('error', $error);
        return false;
    }
};

// Procesar acciones de gestión de camillas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Intenta de nuevo.');
        redirigir('/camillas/');
    }

    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
    $leerTexto = static fn (string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
    $leerId = static function (string $campo): int {
        $valor = $_POST[$campo] ?? null;
        if (!is_string($valor) && !is_int($valor)) {
            return 0;
        }
        $id = filter_var($valor, FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? $id : 0;
    };
    $permisosAccion = [
        'asignar' => 'camillas',
        'liberar' => 'camillas',
        'marcar_limpieza' => 'camillas',
        'marcar_mantenimiento' => 'camillas',
        'marcar_disponible' => 'camillas',
        'trasladar' => 'camillas',
        'crear_camilla' => 'configurar',
    ];
    if (isset($permisosAccion[$accion])) {
        exigirPermiso($permisosAccion[$accion]);
    }

    if ($accion === 'asignar') {
        $camillaId = $leerId('camilla_id');
        $pacienteId = $leerId('paciente_id');
        $diag = mb_substr(trim($leerTexto('diagnostico_ingreso')), 0, 255);
        $notas = trim($leerTexto('notas'));

        if ($camillaId && $pacienteId) {
            $guardado = $transaccion(function () use ($pdo, $camillaId, $pacienteId, $diag, $notas, $institucionId, $usuario): void {
                $paciente = $pdo->prepare('SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
                $paciente->execute([$pacienteId, $institucionId]);
                if (!$paciente->fetchColumn()) {
                    throw new DomainException('El paciente no existe en esta institución o fue dado de baja.');
                }
                $asignacion = $pdo->prepare("SELECT id FROM camillas WHERE institucion_id = ? AND paciente_id = ? AND estado = 'ocupada' LIMIT 1 FOR UPDATE");
                $asignacion->execute([$institucionId, $pacienteId]);
                if ($asignacion->fetchColumn()) {
                    throw new DomainException('Este paciente ya tiene una cama o camilla ocupada.');
                }
                $stmt = $pdo->prepare("UPDATE camillas SET estado = 'ocupada', paciente_id = ?, ingreso_en = NOW(), diagnostico_ingreso = ?, notas = ? WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL");
                $stmt->execute([$pacienteId, $diag, $notas, $camillaId, $institucionId]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('La cama ya no está disponible. Actualiza el censo e intenta de nuevo.');
                }
                $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'ingreso', ?, ?, NOW())");
                $stmtMov->execute([$institucionId, $camillaId, $pacienteId, $diag ?: 'Ingreso a camilla', $usuario['id']]);
                sincronizarIngresoHospitalizacion($pdo, $pacienteId, $institucionId, (int) $usuario['id'], (string) $usuario['nombre']);
                registrarBitacora('camillas', $camillaId, 'actualizar', 'paciente_id', null, (string) $pacienteId);
            }, 'No se pudo registrar el ingreso. Intenta de nuevo.');
            if ($guardado) {
            mensajeFlash('exito', 'Paciente asignado correctamente a la cama/camilla.');
            }
        } else {
            mensajeFlash('error', 'Selecciona el paciente y la camilla.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'liberar') {
        $camillaId = $leerId('camilla_id');
        $pasarALimpieza = $leerTexto('limpieza') === '1';

        if ($camillaId) {
            $guardado = $transaccion(function () use ($pdo, $camillaId, $pasarALimpieza, $institucionId, $usuario): void {
                $cam = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ? FOR UPDATE");
                $cam->execute([$camillaId, $institucionId]);
                $cData = $cam->fetch(PDO::FETCH_ASSOC);
                if (!$cData || $cData['estado'] !== 'ocupada' || !$cData['paciente_id']) {
                    throw new DomainException('La cama ya no tiene un paciente asignado.');
                }
                $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'alta', 'Alta hospitalaria / Liberación de camilla', ?, NOW())");
                $stmtMov->execute([$institucionId, $camillaId, (int) $cData['paciente_id'], $usuario['id']]);
                $nuevoEstado = $pasarALimpieza ? 'limpieza' : 'disponible';
                $stmt = $pdo->prepare("UPDATE camillas SET estado = ?, paciente_id = NULL, ingreso_en = NULL, diagnostico_ingreso = NULL, notas = NULL WHERE id = ? AND institucion_id = ? AND estado = 'ocupada'");
                $stmt->execute([$nuevoEstado, $camillaId, $institucionId]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('La cama cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                cerrarFlujoPorAltaHospitalaria($pdo, (int) $cData['paciente_id'], $institucionId, (int) $usuario['id'], (string) $usuario['nombre']);
                registrarBitacora('camillas', $camillaId, 'actualizar', 'estado', 'ocupada', $nuevoEstado);
            }, 'No se pudo registrar el alta. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', $pasarALimpieza ? 'Cama liberada y puesta en desinfección.' : 'Cama liberada y disponible.');
            }
        }
        redirigir('/camillas/');
    }

    if ($accion === 'marcar_disponible') {
        $camillaId = $leerId('camilla_id');
        if ($camillaId) {
            $guardado = $transaccion(function () use ($pdo, $camillaId, $institucionId): void {
                $camilla = $pdo->prepare("SELECT estado, paciente_id FROM camillas WHERE id = ? AND institucion_id = ? AND estado IN ('limpieza', 'mantenimiento', 'reservada') FOR UPDATE");
                $camilla->execute([$camillaId, $institucionId]);
                $actual = $camilla->fetch(PDO::FETCH_ASSOC);
                if (!$actual || $actual['paciente_id'] !== null) {
                    throw new DomainException('La cama ya no está en limpieza, mantenimiento o reserva. Actualiza el censo e intenta de nuevo.');
                }
                $stmt = $pdo->prepare("UPDATE camillas SET estado = 'disponible' WHERE id = ? AND institucion_id = ? AND estado = ? AND paciente_id IS NULL");
                $stmt->execute([$camillaId, $institucionId, $actual['estado']]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('La cama cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                registrarBitacora('camillas', $camillaId, 'actualizar', 'estado', $actual['estado'], 'disponible');
            }, 'No se pudo actualizar la disponibilidad. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Estado actualizado. La cama está disponible para ocupación.');
            }
        }
        redirigir('/camillas/');
    }

    if ($accion === 'marcar_mantenimiento') {
        $camillaId = $leerId('camilla_id');
        if ($camillaId) {
            $guardado = $transaccion(function () use ($pdo, $camillaId, $institucionId): void {
                $camilla = $pdo->prepare("SELECT id FROM camillas WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL FOR UPDATE");
                $camilla->execute([$camillaId, $institucionId]);
                if (!$camilla->fetchColumn()) {
                    throw new DomainException('La cama ya no está disponible. Actualiza el censo e intenta de nuevo.');
                }
                $stmt = $pdo->prepare("UPDATE camillas SET estado = 'mantenimiento' WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL");
                $stmt->execute([$camillaId, $institucionId]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('La cama cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                registrarBitacora('camillas', $camillaId, 'actualizar', 'estado', 'disponible', 'mantenimiento');
            }, 'No se pudo poner la cama en mantenimiento. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Cama retirada temporalmente del censo disponible por mantenimiento.');
            }
        }
        redirigir('/camillas/');
    }

    if ($accion === 'marcar_limpieza') {
        $camillaId = $leerId('camilla_id');
        if ($camillaId) {
            $guardado = $transaccion(function () use ($pdo, $camillaId, $institucionId): void {
                $camilla = $pdo->prepare("SELECT id FROM camillas WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL FOR UPDATE");
                $camilla->execute([$camillaId, $institucionId]);
                if (!$camilla->fetchColumn()) {
                    throw new DomainException('La cama ya no está disponible. Actualiza el censo e intenta de nuevo.');
                }
                $stmt = $pdo->prepare("UPDATE camillas SET estado = 'limpieza' WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL");
                $stmt->execute([$camillaId, $institucionId]);
                if ($stmt->rowCount() !== 1) {
                    throw new DomainException('La cama cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                registrarBitacora('camillas', $camillaId, 'actualizar', 'estado', 'disponible', 'limpieza');
            }, 'No se pudo poner la cama en desinfección. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Cama puesta en desinfección.');
            }
        }
        redirigir('/camillas/');
    }

    if ($accion === 'crear_camilla') {
        $codigo = mb_substr(trim($leerTexto('codigo')), 0, 30);
        $areaSolicitada = $leerTexto('area');
        $area = in_array($areaSolicitada, ['urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'], true) ? $areaSolicitada : 'hospitalizacion';
        $piso = mb_substr(trim($leerTexto('piso')), 0, 40);
        $tipoSolicitado = $leerTexto('tipo');
        $tipo = in_array($tipoSolicitado, ['camilla', 'cama_censable', 'cama_no_censable', 'cuna', 'incubadora'], true) ? $tipoSolicitado : 'cama_censable';

        if ($codigo !== '') {
            $guardado = $transaccion(function () use ($pdo, $codigo, $area, $piso, $tipo, $institucionId): void {
                $existente = $pdo->prepare('SELECT id FROM camillas WHERE institucion_id = ? AND codigo = ? LIMIT 1 FOR UPDATE');
                $existente->execute([$institucionId, $codigo]);
                if ($existente->fetchColumn()) {
                    throw new DomainException('Ya existe una cama o camilla con ese código en esta institución.');
                }
                $stmt = $pdo->prepare("INSERT INTO camillas (institucion_id, codigo, area, piso, tipo, estado) VALUES (?, ?, ?, ?, ?, 'disponible')");
                $stmt->execute([$institucionId, $codigo, $area, $piso, $tipo]);
                registrarBitacora('camillas', (int) $pdo->lastInsertId(), 'crear', 'codigo', null, $codigo);
            }, 'No se pudo agregar la cama o camilla. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', "Cama/Camilla {$codigo} agregada correctamente al censo.");
            }
        } else {
            mensajeFlash('error', 'El código de la cama o camilla es obligatorio.');
        }
        redirigir('/camillas/');
    }

    if ($accion === 'trasladar') {
        $camillaOrigenId = $leerId('camilla_origen_id');
        $camillaDestinoId = $leerId('camilla_destino_id');
        $motivo = mb_substr(trim($leerTexto('motivo_traslado')), 0, 255);

        if ($camillaOrigenId && $camillaDestinoId && $camillaOrigenId !== $camillaDestinoId) {
            $guardado = $transaccion(function () use ($pdo, $camillaOrigenId, $camillaDestinoId, $motivo, $institucionId, $usuario): void {
                $qOrig = $pdo->prepare("SELECT * FROM camillas WHERE id = ? AND institucion_id = ? FOR UPDATE");
                $qOrig->execute([$camillaOrigenId, $institucionId]);
                $origen = $qOrig->fetch(PDO::FETCH_ASSOC);
                if (!$origen || $origen['estado'] !== 'ocupada' || !$origen['paciente_id']) {
                    throw new DomainException('La cama de origen ya no tiene un paciente asignado.');
                }
                $qDest = $pdo->prepare("SELECT id FROM camillas WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL FOR UPDATE");
                $qDest->execute([$camillaDestinoId, $institucionId]);
                if (!$qDest->fetchColumn()) {
                    throw new DomainException('La cama de destino ya no está disponible.');
                }
                $pacienteId = (int) $origen['paciente_id'];
                $stmtDest = $pdo->prepare("UPDATE camillas SET estado = 'ocupada', paciente_id = ?, ingreso_en = ?, diagnostico_ingreso = ?, notas = ? WHERE id = ? AND institucion_id = ? AND estado = 'disponible' AND paciente_id IS NULL");
                $stmtDest->execute([$pacienteId, $origen['ingreso_en'], $origen['diagnostico_ingreso'], $origen['notas'], $camillaDestinoId, $institucionId]);
                if ($stmtDest->rowCount() !== 1) {
                    throw new DomainException('La cama de destino cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                $stmtOrig = $pdo->prepare("UPDATE camillas SET estado = 'limpieza', paciente_id = NULL, ingreso_en = NULL, diagnostico_ingreso = NULL, notas = NULL WHERE id = ? AND institucion_id = ? AND estado = 'ocupada' AND paciente_id = ?");
                $stmtOrig->execute([$camillaOrigenId, $institucionId, $pacienteId]);
                if ($stmtOrig->rowCount() !== 1) {
                    throw new DomainException('La cama de origen cambió de estado. Actualiza el censo e intenta de nuevo.');
                }
                $stmtMov = $pdo->prepare("INSERT INTO camillas_movimientos (institucion_id, camilla_id, paciente_id, tipo_movimiento, camilla_origen_id, motivo, usuario_id, fecha) VALUES (?, ?, ?, 'traslado', ?, ?, ?, NOW())");
                $stmtMov->execute([$institucionId, $camillaDestinoId, $pacienteId, $camillaOrigenId, $motivo ?: 'Traslado interno de cama', $usuario['id']]);
                registrarBitacora('camillas', $camillaDestinoId, 'actualizar', 'paciente_id', null, (string) $pacienteId);
            }, 'No se pudo completar el traslado. Intenta de nuevo.');
            if ($guardado) {
                mensajeFlash('exito', 'Paciente trasladado exitosamente.');
            }
        } else {
            mensajeFlash('error', 'Selecciona camas de origen y destino distintas.');
        }
        redirigir('/camillas/');
    }
}

// Filtros del censo
$areasValidas = ['urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'];
$estadosValidos = ['disponible', 'ocupada', 'limpieza', 'mantenimiento', 'reservada'];
$areaSolicitada = is_string($_GET['area'] ?? null) ? $_GET['area'] : '';
$areaFiltro = in_array($areaSolicitada, $areasValidas, true) ? $areaSolicitada : 'todas';
$estadoSolicitado = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';
$estadoFiltro = in_array($estadoSolicitado, $estadosValidos, true) ? $estadoSolicitado : 'todas';
$busquedaSolicitada = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
$busqueda = mb_substr(trim($busquedaSolicitada), 0, 100);

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
if ($estadoFiltro !== 'todas') {
    $sql .= " AND c.estado = ?";
    $params[] = $estadoFiltro;
}
if ($busqueda !== '') {
    [$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda([
        'c.codigo',
        'c.piso',
        'c.area',
        'c.tipo',
        'c.estado',
        'c.diagnostico_ingreso',
        'c.notas',
        "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
        'p.folio',
        'p.curp',
        'p.alergias',
    ], $busqueda);
    $sql .= " AND $filtroBusqueda";
    array_push($params, ...$parametrosBusqueda);
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
    SUM(estado = 'mantenimiento') as mantenimiento,
    SUM(estado = 'reservada') as reservadas,
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
$mantenimiento = (int)($conteos['mantenimiento'] ?? 0);
$reservadas    = (int)($conteos['reservadas'] ?? 0);
$totalUrg     = (int)($conteos['urgencias'] ?? 0);
$totalHosp    = (int)($conteos['hosp'] ?? 0);
$totalUti     = (int)($conteos['uti'] ?? 0);

// Camillas disponibles para traslados
$camillasLibres = array_filter($todasCamillas, fn($c) => $c['estado'] === 'disponible');
$urlArea = static fn (string $area): string => '?' . http_build_query(array_filter(
    ['area' => $area, 'estado' => $estadoFiltro, 'q' => $busqueda],
    static fn ($valor): bool => $valor !== '' && $valor !== 'todas'
));
$urlExportar = URL_BASE . '/camillas/exportar.php?' . http_build_query(array_filter(
    ['area' => $areaFiltro, 'estado' => $estadoFiltro, 'q' => $busqueda],
    static fn ($valor): bool => $valor !== '' && $valor !== 'todas'
));

$tituloPagina = 'Censo de Camas y Camillas';
$seccionActiva = 'camillas';
$claseContenido = 'pagina--ancha';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente pagina--ancha camillas-pagina" data-censo-camillas>

  <div class="pagina__cabecera">
    <h1 class="pagina__titulo">Censo de Camas y Camillas</h1>
    <p class="pagina__subtitulo">El alta cambia el estado a desinfección; cuando el personal termina y confirma la limpieza, marca la cama como disponible.</p>
  </div>

  <dl class="hoja-paciente__resumen camillas__resumen">
    <div><dt><?= icono('cama') ?> Capacidad instalada</dt><dd><?= $totalCamas ?></dd></div>
    <div><dt><?= icono('usuarios') ?> Ocupadas</dt><dd><?= $ocupadas ?></dd></div>
    <div><dt><?= icono('escudo_ok') ?> Disponibles</dt><dd><?= $disponibles ?></dd></div>
    <div><dt><?= icono('chispa') ?> En desinfección</dt><dd><?= $limpieza ?></dd></div>
  </dl>

  <div class="camillas__contenido">
    <nav class="camillas__areas" aria-label="Filtrar por área">
      <h2>Áreas</h2>
      <a href="<?= e($urlArea('todas')) ?>"<?= $areaFiltro === 'todas' ? ' aria-current="page"' : '' ?>>Todas <span><?= $totalCamas ?></span></a>
      <a href="<?= e($urlArea('urgencias')) ?>"<?= $areaFiltro === 'urgencias' ? ' aria-current="page"' : '' ?>>Urgencias <span><?= $totalUrg ?></span></a>
      <a href="<?= e($urlArea('hospitalizacion')) ?>"<?= $areaFiltro === 'hospitalizacion' ? ' aria-current="page"' : '' ?>>Hospitalización <span><?= $totalHosp ?></span></a>
      <a href="<?= e($urlArea('cirugia')) ?>"<?= $areaFiltro === 'cirugia' ? ' aria-current="page"' : '' ?>>Cirugía <span><?= (int) ($conteos['cirugia'] ?? 0) ?></span></a>
      <a href="<?= e($urlArea('terapia_intensiva')) ?>"<?= $areaFiltro === 'terapia_intensiva' ? ' aria-current="page"' : '' ?>>Terapia intensiva <span><?= $totalUti ?></span></a>
    </nav>

  <section class="bd camillas__tabla" data-bd>
    <div class="bd__cabecera">
      <div class="bd__herramientas">
        <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar en el expediente" aria-label="Buscar en el expediente"><?= icono('buscar') ?></button>
        <form class="camillas__filtro-estado" method="get" action="<?= URL_BASE ?>/camillas/">
          <?php if ($areaFiltro !== 'todas'): ?><input type="hidden" name="area" value="<?= e($areaFiltro) ?>"><?php endif; ?>
          <?php if ($busqueda !== ''): ?><input type="hidden" name="q" value="<?= e($busqueda) ?>"><?php endif; ?>
          <label class="sr-only" for="camillas-estado-filtro">Filtrar por estado</label>
          <select id="camillas-estado-filtro" name="estado">
            <option value="todas"<?= $estadoFiltro === 'todas' ? ' selected' : '' ?>>Todos los estados</option>
            <option value="disponible"<?= $estadoFiltro === 'disponible' ? ' selected' : '' ?>>Disponibles (<?= $disponibles ?>)</option>
            <option value="ocupada"<?= $estadoFiltro === 'ocupada' ? ' selected' : '' ?>>Ocupadas (<?= $ocupadas ?>)</option>
            <option value="limpieza"<?= $estadoFiltro === 'limpieza' ? ' selected' : '' ?>>En desinfección (<?= $limpieza ?>)</option>
            <option value="mantenimiento"<?= $estadoFiltro === 'mantenimiento' ? ' selected' : '' ?>>En mantenimiento (<?= $mantenimiento ?>)</option>
            <option value="reservada"<?= $estadoFiltro === 'reservada' ? ' selected' : '' ?>>Reservadas (<?= $reservadas ?>)</option>
          </select>
          <button type="submit" class="boton-icono" title="Filtrar por estado" aria-label="Filtrar por estado"><?= icono('adelante') ?></button>
        </form>
        <?php if (puede('camillas')): ?><button type="button" class="boton-icono camillas__accion-principal" data-camillas-modal-abrir="modal-asignar-camilla" title="Asignar paciente" aria-label="Asignar paciente">
          <?= icono('agregar') ?>
        </button><?php endif; ?>
        <details class="camillas__menu">
          <summary class="boton-icono" title="Más opciones del censo" aria-label="Más opciones del censo" aria-haspopup="menu">
            <?= icono('mas') ?>
          </summary>
          <div class="camillas__menu-lista" role="menu" aria-label="Más opciones del censo">
            <?php if (puede('configurar')): ?>
              <button type="button" role="menuitem" data-camillas-modal-abrir="modal-nueva-camilla"><?= icono('agregar') ?><span>Nueva cama o camilla</span></button>
            <?php endif; ?>
            <a href="<?= URL_BASE ?>/camillas/kardex.php" role="menuitem"><?= icono('documento') ?><span>Kardex</span></a>
            <a href="<?= URL_BASE ?>/camillas/historial.php" role="menuitem"><?= icono('reloj') ?><span>Historial de movimientos</span></a>
            <?php if (puede('importar_exportar')): ?>
              <a href="<?= e($urlExportar) ?>" role="menuitem"><?= icono('tabla') ?><span>Exportar censo</span></a>
            <?php endif; ?>
          </div>
        </details>
      </div>
    </div>

    <div class="bd__marco" role="region" aria-label="Censo de camas; desplaza horizontalmente para ver todas las columnas" tabindex="0">
      <table class="bd-tabla">
        <caption class="solo-lector">Censo de camas y camillas por área, estado y paciente</caption>
        <thead>
          <tr>
            <th scope="col"><button type="button"><?= icono('cama') ?> Cama / Camilla</button></th>
            <th scope="col"><button type="button"><?= icono('escudo') ?> Estado</button></th>
            <th scope="col"><button type="button"><?= icono('usuario') ?> Paciente</button></th>
            <th scope="col"><button type="button"><?= icono('documento') ?> Diagnóstico / Motivo</button></th>
            <th scope="col"><button type="button"><?= icono('calendario') ?> Ingreso / estancia</button></th>
            <th scope="col" class="camillas__encabezado-acciones"><span class="solo-lector">Opciones</span><?= icono('mas') ?></th>
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
            $esDisponible = $c['estado'] === 'disponible';
            $esMantenimiento = $c['estado'] === 'mantenimiento';
            $esReservada = $c['estado'] === 'reservada';
            $tieneAcciones = $esOcupada || (puede('camillas') && ($esLimpieza || $esDisponible || $esMantenimiento || $esReservada));
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
                  <span><?= e($c['codigo']) ?></span>
                </span>
                <small class="camillas__ubicacion"><?= e($c['piso'] ?: ucfirst($c['area'])) ?> · <?= e($tipoTexto) ?></small>
              </td>
              <td>
                <?php if ($esOcupada): ?>
                  <span class="camillas__estado camillas__estado--ocupada">Ocupada</span>
                <?php elseif ($esLimpieza): ?>
                  <span class="camillas__estado camillas__estado--limpieza">En limpieza</span>
                <?php elseif ($esMantenimiento): ?>
                  <span class="camillas__estado camillas__estado--mantenimiento">En mantenimiento</span>
                <?php elseif ($esReservada): ?>
                  <span class="camillas__estado camillas__estado--reservada">Reservada</span>
                <?php elseif ($esDisponible): ?>
                  <span class="camillas__estado camillas__estado--disponible">Disponible</span>
                <?php else: ?>
                  <span class="camillas__estado camillas__estado--desconocido">Estado no reconocido</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($esOcupada && $c['paciente_id']): ?>
                  <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= (int)$c['paciente_id'] ?>">
                    <?= e($nombrePac) ?>
                  </a>
                  <small class="camillas__folio">Folio: <?= e($c['pac_folio']) ?></small>
                <?php else: ?>
                  <span style="color: var(--texto-tenue);">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?= e($c['diagnostico_ingreso'] ?: '—') ?>
              </td>
              <td>
                <?php if ($c['ingreso_en']): ?>
                  <time datetime="<?= e(date('Y-m-d', strtotime($c['ingreso_en']))) ?>"><?= e(fechaCorta($c['ingreso_en'])) ?></time>
                  <?php if ($esOcupada): ?><small class="camillas__estancia"><?= $diasEstancia ?> <?= $diasEstancia === 1 ? 'día' : 'días' ?></small><?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="camillas__celda-acciones">
                <?php if ($tieneAcciones): ?>
                  <details class="camillas__menu camillas__menu--fila">
                    <summary class="boton-icono" title="Acciones para <?= e($c['codigo']) ?>" aria-label="Acciones para <?= e($c['codigo']) ?>" aria-haspopup="menu">
                      <?= icono('mas') ?>
                    </summary>
                    <div class="camillas__menu-lista" role="menu" aria-label="Acciones para <?= e($c['codigo']) ?>">
                <?php if ($esOcupada): ?>
                  <a href="<?= URL_BASE ?>/camillas/kardex.php?paciente_id=<?= (int)$c['paciente_id'] ?>" role="menuitem"><?= icono('documento') ?><span>Ver Kardex</span></a>
                  <?php if (puede('camillas')): ?>
                    <button type="button" role="menuitem" data-camillas-trasladar data-camilla-id="<?= (int) $c['id'] ?>" data-camilla-codigo="<?= e($c['codigo']) ?>" data-paciente-nombre="<?= e($nombrePac) ?>">
                      <?= icono('adelante') ?><span>Trasladar paciente</span>
                    </button>
                    <form method="post" action="" data-confirmar-alta>
                      <?= campoCsrf() ?>
                      <input type="hidden" name="accion" value="liberar">
                      <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="limpieza" value="1">
                      <button type="submit" role="menuitem" class="camillas__menu-alta">
                        <?= icono('salir') ?><span>Dar de alta</span>
                      </button>
                    </form>
                  <?php endif; ?>
                <?php elseif ($esLimpieza): ?>
                  <?php if (puede('camillas')): ?><form method="post" action="">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="marcar_disponible">
                    <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" role="menuitem" class="camillas__accion-disponible">
                      <?= icono('escudo_ok') ?><span>Finalizar desinfección</span>
                    </button>
                  </form><?php endif; ?>
                <?php elseif ($esMantenimiento || $esReservada): ?>
                  <?php if (puede('camillas')): ?><form method="post" action="" data-confirmar-estado="<?= $esReservada ? 'reservada' : 'mantenimiento' ?>">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion" value="marcar_disponible">
                    <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" role="menuitem" class="camillas__accion-disponible">
                      <?= icono('escudo_ok') ?><span><?= $esReservada ? 'Cancelar reserva' : 'Finalizar mantenimiento' ?></span>
                    </button>
                  </form><?php endif; ?>
                <?php elseif ($esDisponible): ?>
                  <button type="button" role="menuitem" data-camillas-asignar="<?= (int) $c['id'] ?>">
                    <?= icono('agregar') ?><span>Asignar paciente</span>
                  </button>
                  <?php if (puede('camillas')): ?>
                    <form method="post" action="">
                      <?= campoCsrf() ?>
                      <input type="hidden" name="accion" value="marcar_limpieza">
                      <input type="hidden" name="camilla_id" value="<?= (int) $c['id'] ?>">
                      <button type="submit" role="menuitem"><?= icono('escudo') ?><span>Poner en desinfección</span></button>
                    </form>
                    <form method="post" action="" data-confirmar-estado="mantenimiento">
                      <?= campoCsrf() ?>
                      <input type="hidden" name="accion" value="marcar_mantenimiento">
                      <input type="hidden" name="camilla_id" value="<?= (int)$c['id'] ?>">
                      <button type="submit" role="menuitem"><?= icono('alerta') ?><span>Enviar a mantenimiento</span></button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
                    </div>
                  </details>
                <?php else: ?>
                  <span class="solo-lector">Sin acciones disponibles</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="bd__vacio" data-bd-vacio <?= $todasCamillas ? 'hidden' : '' ?>>
      <?= $busqueda !== '' ? 'Ninguna cama o paciente coincide con la búsqueda.' : 'Aún no hay camas o camillas registradas en esta área.' ?>
    </p>
  </section>
  </div>

</div>

<!-- Modal: Asignar Paciente a Cama -->
<?php if (puede('camillas')): ?><dialog id="modal-asignar-camilla" class="modal camillas__modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 500px; width: 95%;">
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
        <div class="camillas__pacientes" data-camillas-pacientes>
          <input type="search" data-camillas-paciente-buscar placeholder="Buscar por nombre o folio" autocomplete="off" aria-label="Buscar paciente activo sin cama asignada">
          <div class="camillas__pacientes-resultados" data-camillas-paciente-resultados role="region" aria-label="Resultados de pacientes"></div>
          <div class="camillas__pacientes-paginacion" data-camillas-paciente-paginacion hidden>
            <button type="button" data-camillas-paciente-anterior disabled>Anterior</button>
            <span data-camillas-paciente-pagina></span>
            <button type="button" data-camillas-paciente-siguiente disabled>Siguiente</button>
          </div>
          <select name="paciente_id" required data-camillas-paciente-seleccion aria-label="Paciente seleccionado">
            <option value="">Selecciona un paciente de los resultados...</option>
          </select>
          <p class="camillas__pacientes-estado" data-camillas-paciente-estado role="status" aria-live="polite">Escribe para buscar pacientes activos sin cama asignada.</p>
        </div>
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
      <button type="button" class="boton boton--sutil" data-camillas-modal-cerrar="modal-asignar-camilla">Cancelar</button>
      <button type="submit" class="boton boton--primario">Confirmar Ingreso</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<!-- Modal: Traslado Interno de Cama -->
<?php if (puede('camillas')): ?><dialog id="modal-trasladar" class="modal camillas__modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 480px; width: 95%;">
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
      <button type="button" class="boton boton--sutil" data-camillas-modal-cerrar="modal-trasladar">Cancelar</button>
      <button type="submit" class="boton boton--primario">Ejecutar Traslado</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<!-- Modal: Crear Nueva Cama / Camilla -->
<?php if (puede('configurar')): ?><dialog id="modal-nueva-camilla" class="modal camillas__modal" style="background: #181818; color: #fff; border: 1px solid #333; border-radius: 10px; padding: 22px; max-width: 480px; width: 95%;">
  <form method="post" action="">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion" value="crear_camilla">

    <div class="ficha__titulo-fila" style="margin-bottom: 16px;">
      <h3 class="ficha__titulo" style="font-size: 1.1rem; margin: 0;">Agregar Nueva Cama / Camilla</h3>
    </div>

    <div style="display: flex; flex-direction: column; gap: 12px;">
      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Código o Identificador *</label>
        <input type="text" name="codigo" required placeholder="Ej. Camilla U-06, Cama 104-B..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
        <div>
          <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Área Clínica *</label>
          <select name="area" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
            <option value="urgencias">Urgencias</option>
            <option value="hospitalizacion" selected>Hospitalización</option>
            <option value="cirugia">Cirugía</option>
            <option value="terapia_intensiva">Terapia Intensiva (UTI)</option>
          </select>
        </div>
        <div>
          <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Piso / Servicio</label>
          <input type="text" name="piso" placeholder="Ej. Planta Baja, Piso 1..." style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
        </div>
      </div>

      <div>
        <label style="font-size: var(--fs-xs); color: var(--texto-suave); display: block; margin-bottom: 4px;">Tipo de Unidad *</label>
        <select name="tipo" required style="width: 100%; background: #222; border: 1px solid #3a3a3a; padding: 7px 10px; border-radius: 6px; color: #fff;">
          <option value="cama_censable">Cama censable</option>
          <option value="camilla">Camilla</option>
          <option value="cama_no_censable">Cama no censable / Observación</option>
          <option value="cuna">Cuna</option>
          <option value="incubadora">Incubadora</option>
        </select>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px;">
      <button type="button" class="boton boton--sutil" data-camillas-modal-cerrar="modal-nueva-camilla">Cancelar</button>
      <button type="submit" class="boton boton--primario">Guardar Cama</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
