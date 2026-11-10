<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('farmacia');

$pdo = db();
$institucionId = institucionId();
$usuario = usuarioActual();
$errorGeneral = 'No se pudo guardar el movimiento. Revisa los datos e inténtalo de nuevo.';

$leerTexto = static function (string $campo, int $limite = 500): string {
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? mb_substr(trim($valor), 0, $limite) : '';
};
$leerCantidad = static function (string $valor): ?string {
    $valor = str_replace(',', '.', trim($valor));
    if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $valor)) {
        return null;
    }
    return (float) $valor > 0 ? $valor : null;
};
$fechaValida = static function (string $valor): bool {
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $fecha !== false && $fecha->format('Y-m-d') === $valor;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
        redirigir('/farmacia/');
    }

    $accion = $leerTexto('accion', 30);
    $operacionId = $leerTexto('operacion_id', 48);
    if (!in_array($accion, ['entrada', 'ajuste', 'conteo', 'dispensacion'], true)
        || !preg_match('/^[a-f0-9]{48}$/', $operacionId)) {
        mensajeFlash('error', 'La operación no es válida. Recarga la página e inténtalo de nuevo.');
        redirigir('/farmacia/');
    }

    $resultado = null;
    try {
        $pdo->beginTransaction();

        if ($accion === 'entrada') {
            $medicamentoId = filter_var($_POST['medicamento_id'] ?? null, FILTER_VALIDATE_INT);
            $nombreMedicamento = $leerTexto('medicamento', 300);
            $codigoLote = $leerTexto('codigo_lote', 80);
            $caducidad = $leerTexto('caducidad', 10);
            $unidad = $leerTexto('unidad', 40);
            $cantidad = $leerCantidad($leerTexto('cantidad', 20));
            $costo = $leerTexto('costo_unitario', 20);
            $nota = $leerTexto('nota');
            if (!$medicamentoId || $nombreMedicamento === '' || $codigoLote === '' || !$fechaValida($caducidad)
                || $caducidad < date('Y-m-d') || $unidad === '' || !$cantidad || mb_strlen($nota) < 3) {
                throw new DomainException('Completa medicamento del catálogo, lote, caducidad vigente, unidad, cantidad y referencia de entrada.');
            }
            if ($costo !== '' && !preg_match('/^\d{1,9}(?:[.,]\d{1,2})?$/', $costo)) {
                throw new DomainException('El costo unitario debe ser un importe válido.');
            }
            $catalogo = $pdo->prepare(
                'SELECT nombre FROM medicamentos WHERE id = ? AND institucion_id = ? AND activo = 1 FOR UPDATE'
            );
            $catalogo->execute([$medicamentoId, $institucionId]);
            $medicamento = $catalogo->fetchColumn();
            if (!$medicamento) {
                throw new DomainException('El medicamento ya no está disponible en el catálogo. Búscalo de nuevo.');
            }
            $pdo->prepare(
                'INSERT INTO farmacia_lotes
                    (institucion_id, medicamento_id, codigo_lote, caducidad, unidad, existencia, costo_unitario, creado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE existencia = existencia + VALUES(existencia),
                                         costo_unitario = COALESCE(VALUES(costo_unitario), costo_unitario)'
            )->execute([
                $institucionId, $medicamentoId, $codigoLote, $caducidad, $unidad, $cantidad,
                $costo === '' ? null : str_replace(',', '.', $costo), (int) $usuario['id'],
            ]);
            $lote = $pdo->prepare(
                'SELECT id FROM farmacia_lotes
                  WHERE institucion_id = ? AND medicamento_id = ? AND codigo_lote = ? AND caducidad = ? AND unidad = ?
                  FOR UPDATE'
            );
            $lote->execute([$institucionId, $medicamentoId, $codigoLote, $caducidad, $unidad]);
            $loteId = (int) $lote->fetchColumn();
            $pdo->prepare(
                "INSERT INTO farmacia_movimientos
                    (institucion_id, lote_id, medicamento_id, tipo, cantidad, usuario_id, operacion_id, nota)
                 VALUES (?, ?, ?, 'entrada', ?, ?, ?, ?)"
            )->execute([$institucionId, $loteId, $medicamentoId, $cantidad, (int) $usuario['id'], $operacionId, $nota]);
            $resultado = ['id' => (int) $pdo->lastInsertId(), 'texto' => "Entrada registrada: $medicamento, lote $codigoLote."];
        } elseif ($accion === 'ajuste') {
            $loteId = filter_var($_POST['lote_id'] ?? null, FILTER_VALIDATE_INT);
            $tipo = $leerTexto('tipo_ajuste', 30);
            $cantidad = $leerCantidad($leerTexto('cantidad', 20));
            $nota = $leerTexto('nota');
            if (!$loteId || !in_array($tipo, ['ajuste_entrada', 'ajuste_salida', 'merma'], true)
                || !$cantidad || mb_strlen($nota) < 3) {
                throw new DomainException('Selecciona el lote, el tipo de ajuste y una cantidad válida; explica el motivo.');
            }
            $consultaLote = $pdo->prepare(
                'SELECT id, medicamento_id, codigo_lote, existencia FROM farmacia_lotes
                  WHERE id = ? AND institucion_id = ? FOR UPDATE'
            );
            $consultaLote->execute([$loteId, $institucionId]);
            $lote = $consultaLote->fetch(PDO::FETCH_ASSOC);
            if (!$lote) {
                throw new DomainException('El lote seleccionado ya no existe en esta institución.');
            }
            if ($tipo !== 'ajuste_entrada') {
                $actualizar = $pdo->prepare(
                    'UPDATE farmacia_lotes SET existencia = existencia - ?
                      WHERE id = ? AND institucion_id = ? AND existencia >= ?'
                );
                $actualizar->execute([$cantidad, $loteId, $institucionId, $cantidad]);
                if ($actualizar->rowCount() !== 1) {
                    throw new DomainException('La cantidad supera las existencias disponibles.');
                }
            } else {
                $pdo->prepare('UPDATE farmacia_lotes SET existencia = existencia + ? WHERE id = ? AND institucion_id = ?')
                    ->execute([$cantidad, $loteId, $institucionId]);
            }
            $pdo->prepare(
                'INSERT INTO farmacia_movimientos
                    (institucion_id, lote_id, medicamento_id, tipo, cantidad, usuario_id, operacion_id, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $institucionId, $loteId, (int) $lote['medicamento_id'], $tipo, $cantidad,
                (int) $usuario['id'], $operacionId, $nota,
            ]);
            $resultado = ['id' => (int) $pdo->lastInsertId(), 'texto' => 'Ajuste de existencias registrado.'];
        } elseif ($accion === 'conteo') {
            $loteId = filter_var($_POST['lote_id'] ?? null, FILTER_VALIDATE_INT);
            $conteoTexto = str_replace(',', '.', $leerTexto('conteo', 20));
            $nota = $leerTexto('nota');
            if (!$loteId || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $conteoTexto) || mb_strlen($nota) < 3) {
                throw new DomainException('Indica el conteo físico del lote y explica el motivo del ajuste.');
            }
            [$unidades, $fraccion] = array_pad(explode('.', $conteoTexto, 2), 2, '');
            $conteoMil = ((int) $unidades * 1000) + (int) str_pad($fraccion, 3, '0');
            $conteo = sprintf('%d.%03d', intdiv($conteoMil, 1000), $conteoMil % 1000);
            $consultaLote = $pdo->prepare(
                'SELECT id, medicamento_id, codigo_lote, existencia FROM farmacia_lotes
                  WHERE id = ? AND institucion_id = ? FOR UPDATE'
            );
            $consultaLote->execute([$loteId, $institucionId]);
            $lote = $consultaLote->fetch(PDO::FETCH_ASSOC);
            if (!$lote) {
                throw new DomainException('El lote seleccionado ya no existe en esta institución.');
            }
            $existenciaMil = (int) round((float) $lote['existencia'] * 1000);
            if ($conteoMil === $existenciaMil) {
                throw new DomainException('El conteo coincide con la existencia registrada; no se generó ningún movimiento.');
            }
            $tipo = $conteoMil > $existenciaMil ? 'ajuste_entrada' : 'ajuste_salida';
            $diferenciaMil = abs($conteoMil - $existenciaMil);
            $cantidad = sprintf('%d.%03d', intdiv($diferenciaMil, 1000), $diferenciaMil % 1000);
            $pdo->prepare(
                'UPDATE farmacia_lotes SET existencia = ? WHERE id = ? AND institucion_id = ?'
            )->execute([$conteo, $loteId, $institucionId]);
            $pdo->prepare(
                'INSERT INTO farmacia_movimientos
                    (institucion_id, lote_id, medicamento_id, tipo, cantidad, usuario_id, operacion_id, nota)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $institucionId, $loteId, (int) $lote['medicamento_id'], $tipo, $cantidad,
                (int) $usuario['id'], $operacionId, 'Conteo físico: ' . $nota,
            ]);
            $resultado = ['id' => (int) $pdo->lastInsertId(), 'texto' => 'Conteo físico registrado y existencia conciliada.'];
        } else {
            $prescripcion = $leerTexto('prescripcion', 60);
            $partes = explode('|', $prescripcion);
            $recetaId = count($partes) === 2 ? filter_var($partes[0], FILTER_VALIDATE_INT) : false;
            $medicamentoId = count($partes) === 2 ? filter_var($partes[1], FILTER_VALIDATE_INT) : false;
            $loteId = filter_var($_POST['lote_id'] ?? null, FILTER_VALIDATE_INT);
            $cantidad = $leerCantidad($leerTexto('cantidad', 20));
            $nota = $leerTexto('nota');
            if (!$recetaId || !$medicamentoId || !$loteId || !$cantidad || mb_strlen($nota) < 3
                || !isset($_POST['confirmacion'])) {
                throw new DomainException('Selecciona receta y lote, indica la cantidad y confirma que revisaste la vigencia de la prescripción.');
            }

            $consultaReceta = $pdo->prepare(
                "SELECT h.paciente_id, h.detalle, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
                   FROM historial_clinico h
                   INNER JOIN pacientes p ON p.id = h.paciente_id
                  WHERE h.id = ? AND h.institucion_id = ? AND h.tipo = 'receta'
                    AND p.institucion_id = ? AND p.eliminado_en IS NULL
                  FOR UPDATE"
            );
            $consultaReceta->execute([$recetaId, $institucionId, $institucionId]);
            $receta = $consultaReceta->fetch(PDO::FETCH_ASSOC);
            if (!$receta) {
                throw new DomainException('La receta no existe o el paciente ya no está disponible.');
            }
            $detalle = is_array($receta['detalle']) ? $receta['detalle'] : json_decode((string) $receta['detalle'], true);
            $medicamentoRecetado = null;
            foreach ((array) ($detalle['medicamentos'] ?? []) as $item) {
                if ((int) ($item['medicamento_id'] ?? 0) === (int) $medicamentoId) {
                    $medicamentoRecetado = $item;
                    break;
                }
            }
            if (!$medicamentoRecetado || ($medicamentoRecetado['estado_tratamiento'] ?? 'activo') !== 'activo') {
                throw new DomainException('El medicamento seleccionado no forma parte de una receta vigente.');
            }
            $medicamentoActivo = $pdo->prepare(
                'SELECT 1 FROM medicamentos WHERE id = ? AND institucion_id = ? AND activo = 1 FOR UPDATE'
            );
            $medicamentoActivo->execute([$medicamentoId, $institucionId]);
            if (!$medicamentoActivo->fetchColumn()) {
                throw new DomainException('El medicamento de la receta ya no está activo en el catálogo de esta institución.');
            }
            $hoy = date('Y-m-d');
            $inicio = (string) ($medicamentoRecetado['fecha_inicio'] ?? '');
            $fin = (string) ($medicamentoRecetado['fecha_fin'] ?? '');
            if (($inicio !== '' && $inicio > $hoy) || ($fin !== '' && $fin < $hoy)) {
                throw new DomainException('La fecha de inicio o fin de la prescripción no permite dispensar hoy.');
            }

            $consultaLote = $pdo->prepare(
                'SELECT id, medicamento_id, codigo_lote, caducidad, existencia, unidad
                   FROM farmacia_lotes WHERE id = ? AND institucion_id = ? FOR UPDATE'
            );
            $consultaLote->execute([$loteId, $institucionId]);
            $lote = $consultaLote->fetch(PDO::FETCH_ASSOC);
            if (!$lote || (int) $lote['medicamento_id'] !== (int) $medicamentoId) {
                throw new DomainException('El lote seleccionado no corresponde al medicamento de la receta.');
            }
            if ($lote['caducidad'] < $hoy) {
                throw new DomainException('No se puede dispensar un lote caducado.');
            }
            $actualizar = $pdo->prepare(
                'UPDATE farmacia_lotes SET existencia = existencia - ?
                  WHERE id = ? AND institucion_id = ? AND existencia >= ? AND caducidad >= CURDATE()'
            );
            $actualizar->execute([$cantidad, $loteId, $institucionId, $cantidad]);
            if ($actualizar->rowCount() !== 1) {
                throw new DomainException('La cantidad supera las existencias disponibles.');
            }
            $nombrePaciente = trim($receta['nombre'] . ' ' . $receta['primer_apellido'] . ' ' . $receta['segundo_apellido']);
            $pdo->prepare(
                "INSERT INTO farmacia_movimientos
                    (institucion_id, lote_id, medicamento_id, tipo, cantidad, paciente_id, receta_id,
                     confirmacion_revisada, usuario_id, operacion_id, nota)
                 VALUES (?, ?, ?, 'dispensacion', ?, ?, ?, 1, ?, ?, ?)"
            )->execute([
                $institucionId, $loteId, $medicamentoId, $cantidad, (int) $receta['paciente_id'],
                $recetaId, (int) $usuario['id'], $operacionId, $nota,
            ]);
            $resultado = [
                'id' => (int) $pdo->lastInsertId(),
                'texto' => "Dispensación documentada para {$receta['folio']} · $nombrePaciente.",
            ];
        }

        $pdo->commit();
        registrarBitacora('farmacia_movimientos', $resultado['id'], 'crear', $accion, null, 'Movimiento de farmacia documentado.');
        mensajeFlash('exito', $resultado['texto']);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mensajeFlash('error', $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[farmacia] ' . $e->getMessage());
        mensajeFlash('error', str_contains($e->getMessage(), 'uq_farmacia_operacion')
            ? 'Esta operación ya se había procesado. Recarga la página para continuar.'
            : $errorGeneral);
    }
    redirigir('/farmacia/');
}

$filtro = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
if (($_GET['exportar'] ?? '') === 'existencias') {
    $exportar = $pdo->prepare(
        'SELECT m.clave_compendio, m.nombre, m.concentracion, l.codigo_lote, l.caducidad, l.existencia, l.unidad, l.costo_unitario
           FROM farmacia_lotes l
           LEFT JOIN medicamentos m ON m.id = l.medicamento_id
          WHERE l.institucion_id = ?
          ORDER BY m.nombre, l.caducidad, l.codigo_lote'
    );
    $exportar->execute([$institucionId]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventario_farmacia_' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $salida = fopen('php://output', 'w');
    fprintf($salida, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($salida, ['Clave', 'Medicamento', 'Concentración', 'Lote', 'Caducidad', 'Existencia', 'Unidad', 'Costo unitario']);
    $seguroCsv = static fn (string $valor): string => preg_match('/^\s*[=+\-@]/u', $valor) ? "'" . $valor : $valor;
    foreach ($exportar->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        fputcsv($salida, [
            $seguroCsv((string) $fila['clave_compendio']),
            $seguroCsv((string) $fila['nombre']),
            $seguroCsv((string) $fila['concentracion']),
            $seguroCsv((string) $fila['codigo_lote']),
            (string) $fila['caducidad'],
            (string) $fila['existencia'],
            $seguroCsv((string) $fila['unidad']),
            (string) ($fila['costo_unitario'] ?? ''),
        ]);
    }
    fclose($salida);
    exit;
}

$indicadores = $pdo->prepare(
    "SELECT COUNT(*) AS lotes,
            COUNT(DISTINCT medicamento_id) AS medicamentos,
            COALESCE(SUM(caducidad < CURDATE()), 0) AS caducados,
            COALESCE(SUM(caducidad >= CURDATE() AND caducidad <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS por_caducar
       FROM farmacia_lotes WHERE institucion_id = ? AND existencia > 0"
);
$indicadores->execute([$institucionId]);
$resumen = $indicadores->fetch(PDO::FETCH_ASSOC) ?: ['lotes' => 0, 'medicamentos' => 0, 'caducados' => 0, 'por_caducar' => 0];

$sqlFiltroLotes = " FROM farmacia_lotes l
                    LEFT JOIN medicamentos m ON m.id = l.medicamento_id
                   WHERE l.institucion_id = ?";
$parametrosLotes = [$institucionId];
if ($filtro !== '') {
    $sqlFiltroLotes .= ' AND (m.nombre LIKE ? OR m.clave_compendio LIKE ? OR l.codigo_lote LIKE ?)';
    $patron = '%' . $filtro . '%';
    array_push($parametrosLotes, $patron, $patron, $patron);
}
$conteoLotes = $pdo->prepare('SELECT COUNT(*)' . $sqlFiltroLotes);
$conteoLotes->execute($parametrosLotes);
$totalLotes = (int) $conteoLotes->fetchColumn();
$paginasLotes = max(1, (int) ceil($totalLotes / 100));
$paginaLotes = min($paginasLotes, max(1, filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$offsetLotes = ($paginaLotes - 1) * 100;
$consultaLotes = $pdo->prepare(
    "SELECT l.id, l.medicamento_id, l.codigo_lote, l.caducidad, l.existencia, l.unidad, l.costo_unitario,
                    COALESCE(m.nombre, 'Medicamento retirado del catálogo') AS medicamento,
                    m.concentracion, m.clave_compendio
       " . $sqlFiltroLotes . ' ORDER BY (l.existencia <= 0), l.caducidad, m.nombre LIMIT 100 OFFSET ' . $offsetLotes
);
$consultaLotes->execute($parametrosLotes);
$lotes = $consultaLotes->fetchAll(PDO::FETCH_ASSOC);

$consultaRecetas = $pdo->prepare(
    "SELECT h.id, h.paciente_id, h.fecha, h.detalle, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
       FROM historial_clinico h
       INNER JOIN pacientes p ON p.id = h.paciente_id
      WHERE h.institucion_id = ? AND h.tipo = 'receta' AND p.institucion_id = ?
        AND p.eliminado_en IS NULL
      ORDER BY h.fecha DESC LIMIT 500"
);
$consultaRecetas->execute([$institucionId, $institucionId]);
$recetasDispensables = [];
foreach ($consultaRecetas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $detalle = is_array($fila['detalle']) ? $fila['detalle'] : json_decode((string) $fila['detalle'], true);
    foreach ((array) ($detalle['medicamentos'] ?? []) as $medicamento) {
        $medicamentoId = (int) ($medicamento['medicamento_id'] ?? 0);
        $inicio = (string) ($medicamento['fecha_inicio'] ?? '');
        $fin = (string) ($medicamento['fecha_fin'] ?? '');
        if (!$medicamentoId || ($medicamento['estado_tratamiento'] ?? 'activo') !== 'activo'
            || ($inicio !== '' && $inicio > date('Y-m-d')) || ($fin !== '' && $fin < date('Y-m-d'))) {
            continue;
        }
        $recetasDispensables[] = [
            'receta_id' => (int) $fila['id'],
            'medicamento_id' => $medicamentoId,
            'paciente_id' => (int) $fila['paciente_id'],
            'folio' => (string) $fila['folio'],
            'paciente' => trim($fila['nombre'] . ' ' . $fila['primer_apellido'] . ' ' . $fila['segundo_apellido']),
            'fecha' => (string) $fila['fecha'],
            'medicamento' => (string) ($medicamento['nombre'] ?? 'Medicamento'),
            'dosis' => (string) ($medicamento['dosis'] ?? ''),
            'via' => (string) ($medicamento['via'] ?? ''),
            'periodicidad' => (string) ($medicamento['periodicidad'] ?? ''),
        ];
    }
}

$paginaMovimientos = max(1, filter_var($_GET['movimientos_pagina'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$conteoMovimientos = $pdo->prepare('SELECT COUNT(*) FROM farmacia_movimientos WHERE institucion_id = ?');
$conteoMovimientos->execute([$institucionId]);
$totalMovimientos = (int) $conteoMovimientos->fetchColumn();
$paginasMovimientos = max(1, (int) ceil($totalMovimientos / 100));
$paginaMovimientos = min($paginasMovimientos, $paginaMovimientos);
$offsetMovimientos = ($paginaMovimientos - 1) * 100;
$consultaMovimientos = $pdo->prepare(
    "SELECT f.id, f.tipo, f.cantidad, f.nota, f.creado_en, f.receta_id, f.paciente_id, f.confirmacion_revisada,
            l.codigo_lote, COALESCE(m.nombre, 'Medicamento retirado del catálogo') AS medicamento,
            m.concentracion, l.unidad,
            p.folio, p.nombre AS paciente_nombre, p.primer_apellido, p.segundo_apellido, u.nombre AS responsable
       FROM farmacia_movimientos f
       LEFT JOIN medicamentos m ON m.id = f.medicamento_id
       INNER JOIN farmacia_lotes l ON l.id = f.lote_id
       LEFT JOIN pacientes p ON p.id = f.paciente_id AND p.institucion_id = f.institucion_id
       LEFT JOIN usuarios u ON u.id = f.usuario_id
      WHERE f.institucion_id = ? ORDER BY f.id DESC LIMIT 100 OFFSET $offsetMovimientos"
);
$consultaMovimientos->execute([$institucionId]);
$movimientos = $consultaMovimientos->fetchAll(PDO::FETCH_ASSOC);

$tokens = [
    'entrada' => bin2hex(random_bytes(24)),
    'ajuste' => bin2hex(random_bytes(24)),
    'dispensacion' => bin2hex(random_bytes(24)),
];
$tokensConteo = [];
foreach ($lotes as $lote) {
    $tokensConteo[(int) $lote['id']] = bin2hex(random_bytes(24));
}
$tituloPagina = 'Farmacia';
$seccionActiva = 'farmacia';
$migas = [['texto' => 'Farmacia']];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente farmacia-pagina">
  <div class="pagina__cabecera farmacia__cabecera">
    <div>
      <h1 class="pagina__titulo">Farmacia</h1>
      <p class="farmacia__descripcion">Inventario por lote, dispensación y movimientos.</p>
    </div>
    <div class="farmacia__acciones-cabecera" aria-label="Acciones de farmacia">
      <button class="farmacia__accion" type="button" data-farmacia-abrir="entrada"><?= icono('agregar') ?><span>Registrar entrada</span></button>
      <button class="farmacia__accion" type="button" data-farmacia-abrir="ajuste"><?= icono('editar') ?><span>Ajustar existencias</span></button>
      <button class="farmacia__accion" type="button" data-farmacia-abrir="dispensacion"><?= icono('pildora') ?><span>Dispensar receta</span></button>
      <a class="boton-icono farmacia__exportar" href="?exportar=existencias" title="Exportar existencias CSV" aria-label="Exportar existencias CSV"><?= icono('flecha_abajo') ?></a>
    </div>
  </div>

  <section class="farmacia__resumen" aria-label="Resumen de inventario">
    <div><span>Lotes con existencia</span><strong><?= (int) $resumen['lotes'] ?></strong></div>
    <div><span>Medicamentos distintos</span><strong><?= (int) $resumen['medicamentos'] ?></strong></div>
    <div class="<?= (int) $resumen['caducados'] > 0 ? 'farmacia__alerta' : '' ?>"><span>Lotes caducados</span><strong><?= (int) $resumen['caducados'] ?></strong></div>
    <div class="<?= (int) $resumen['por_caducar'] > 0 ? 'farmacia__aviso' : '' ?>"><span>Caducan en 30 días</span><strong><?= (int) $resumen['por_caducar'] ?></strong></div>
  </section>

  <section class="farmacia__bloque-operaciones" aria-labelledby="farmacia-operaciones-titulo">
    <div class="farmacia__bloque-cabecera">
      <div>
        <h2 id="farmacia-operaciones-titulo">Operaciones</h2>
        <p>Selecciona una acción para abrir únicamente el formulario que necesitas.</p>
      </div>
    </div>
    <div class="farmacia__operaciones">
    <details class="farmacia__operacion" id="farmacia-operacion-entrada" data-farmacia-operacion="entrada">
      <summary><?= icono('agregar') ?><span><strong>Registrar entrada</strong><small>Recibir existencias y asociarlas con un lote.</small></span></summary>
      <form method="post" class="farmacia__formulario">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="entrada">
        <input type="hidden" name="operacion_id" value="<?= e($tokens['entrada']) ?>">
        <div class="campo" data-fila>
          <label class="campo__etiqueta" for="farmacia-medicamento">Medicamento del catálogo</label>
          <div class="campo__control">
            <input id="farmacia-medicamento" name="medicamento" data-catalogo="medicamentos" autocomplete="off" placeholder="Buscar medicamento…" required>
            <input type="hidden" name="medicamento_id" data-medicamento-id>
          </div>
        </div>
        <label class="campo"><span class="campo__etiqueta">Número de lote</span><input name="codigo_lote" maxlength="80" required></label>
        <label class="campo"><span class="campo__etiqueta">Caducidad</span><input type="date" name="caducidad" min="<?= date('Y-m-d') ?>" required></label>
        <label class="campo"><span class="campo__etiqueta">Cantidad recibida</span><input type="number" name="cantidad" min="0.001" max="999999999.999" step="0.001" required></label>
        <label class="campo"><span class="campo__etiqueta">Unidad de inventario</span><input name="unidad" maxlength="40" placeholder="caja, frasco, pieza…" required></label>
        <label class="campo"><span class="campo__etiqueta">Costo por unidad (opcional)</span><input type="number" name="costo_unitario" min="0" max="999999999.99" step="0.01"></label>
        <label class="campo farmacia__campo-completo"><span class="campo__etiqueta">Referencia de compra / nota</span><input name="nota" maxlength="500" required></label>
        <button class="boton farmacia__guardar" type="submit">Guardar entrada</button>
      </form>
    </details>

    <details class="farmacia__operacion" id="farmacia-operacion-ajuste" data-farmacia-operacion="ajuste">
      <summary><?= icono('editar') ?><span><strong>Ajustar existencias</strong><small>Documentar conteos, mermas o correcciones.</small></span></summary>
      <form method="post" class="farmacia__formulario">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="ajuste">
        <input type="hidden" name="operacion_id" value="<?= e($tokens['ajuste']) ?>">
        <label class="campo farmacia__campo-completo">
          <span class="campo__etiqueta">Lote</span>
          <select name="lote_id" required>
            <option value="">Selecciona lote</option>
            <?php foreach ($lotes as $lote): if ((float) $lote['existencia'] <= 0) continue; ?>
              <option value="<?= (int) $lote['id'] ?>"><?= e($lote['medicamento'] . ' · lote ' . $lote['codigo_lote'] . ' · ' . number_format((float) $lote['existencia'], 3) . ' ' . $lote['unidad']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="campo"><span class="campo__etiqueta">Tipo de ajuste</span>
          <select name="tipo_ajuste" required>
            <option value="ajuste_salida">Salida por conteo / corrección</option>
            <option value="merma">Merma o destrucción</option>
            <option value="ajuste_entrada">Entrada por corrección documentada</option>
          </select>
        </label>
        <label class="campo"><span class="campo__etiqueta">Cantidad</span><input type="number" name="cantidad" min="0.001" max="999999999.999" step="0.001" required></label>
        <label class="campo farmacia__campo-completo"><span class="campo__etiqueta">Motivo y referencia</span><input name="nota" maxlength="500" required></label>
        <button class="boton farmacia__guardar" type="submit">Registrar ajuste</button>
      </form>
    </details>

    <details class="farmacia__operacion" id="farmacia-operacion-dispensacion" data-farmacia-operacion="dispensacion">
      <summary><?= icono('pildora') ?><span><strong>Documentar dispensación</strong><small>Vincular receta, paciente y lote dispensado.</small></span></summary>
      <?php if ($recetasDispensables && $lotes): ?>
        <form method="post" class="farmacia__formulario" data-farmacia-dispensacion>
          <?= campoCsrf() ?>
          <input type="hidden" name="accion" value="dispensacion">
          <input type="hidden" name="operacion_id" value="<?= e($tokens['dispensacion']) ?>">
          <label class="campo">
            <span class="campo__etiqueta">Receta vigente</span>
            <select name="prescripcion" required data-farmacia-receta>
              <option value="">Selecciona paciente y medicamento</option>
              <?php foreach ($recetasDispensables as $receta): ?>
                <option value="<?= (int) $receta['receta_id'] ?>|<?= (int) $receta['medicamento_id'] ?>" data-medicamento-id="<?= (int) $receta['medicamento_id'] ?>">
                  <?= e($receta['folio'] . ' · ' . $receta['paciente'] . ' · ' . $receta['medicamento'] . ' · ' . fechaCorta($receta['fecha'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">
            <span class="campo__etiqueta">Lote a dispensar</span>
            <select name="lote_id" required data-farmacia-lote>
              <option value="">Selecciona lote compatible</option>
              <?php foreach ($lotes as $lote): if ((float) $lote['existencia'] <= 0 || $lote['caducidad'] < date('Y-m-d')) continue; ?>
                <option value="<?= (int) $lote['id'] ?>" data-medicamento-id="<?= (int) $lote['medicamento_id'] ?>">
                  <?= e($lote['medicamento'] . ' · lote ' . $lote['codigo_lote'] . ' · caduca ' . fechaCorta($lote['caducidad']) . ' · ' . number_format((float) $lote['existencia'], 3) . ' ' . $lote['unidad']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo"><span class="campo__etiqueta">Cantidad en la unidad del lote</span><input type="number" name="cantidad" min="0.001" max="999999999.999" step="0.001" required></label>
          <label class="campo farmacia__campo-completo"><span class="campo__etiqueta">Referencia / nota de dispensación</span><input name="nota" maxlength="500" required></label>
          <label class="farmacia__confirmacion farmacia__campo-completo">
            <input type="checkbox" name="confirmacion" value="1" required>
            <span>Confirmo que revisé la receta, la identidad del paciente y las fechas del tratamiento. Este registro documenta dispensación; no confirma administración clínica.</span>
          </label>
          <button class="boton farmacia__guardar" type="submit">Registrar dispensación</button>
        </form>
      <?php else: ?>
        <p class="farmacia__nota">Para dispensar se requiere una receta vigente vinculada a un medicamento del catálogo y un lote disponible no caducado. No se descuentan medicamentos por registrar dosis en el Kardex.</p>
      <?php endif; ?>
    </details>
    </div>
  </section>

  <section class="farmacia__seccion" aria-labelledby="farmacia-existencias">
    <div class="farmacia__titulo-fila">
      <div><h2 id="farmacia-existencias">Existencias por lote</h2><p>El catálogo define medicamentos; aquí se registran cantidades reales y sus movimientos.</p></div>
      <form method="get" class="farmacia__busqueda">
        <label class="sr-only" for="farmacia-buscar">Buscar medicamento o lote</label>
        <input id="farmacia-buscar" type="search" name="q" value="<?= e($filtro) ?>" placeholder="Buscar medicamento o lote">
        <button class="boton-icono" type="submit" title="Buscar" aria-label="Buscar"><?= icono('buscar') ?></button>
      </form>
    </div>
    <div class="bd__marco">
      <table class="bd-tabla">
        <caption class="solo-lector">Existencias de farmacia por medicamento y lote</caption>
        <thead><tr><th>Medicamento</th><th>Lote</th><th>Caducidad</th><th>Existencia y conteo</th><th>Estado</th><th class="num">Costo unitario</th></tr></thead>
        <tbody>
          <?php if (!$lotes): ?><tr><td colspan="6" class="farmacia__vacio">No hay lotes que coincidan. Registra una entrada para iniciar el inventario.</td></tr><?php endif; ?>
          <?php foreach ($lotes as $lote):
            $caducado = $lote['caducidad'] < date('Y-m-d');
            $porCaducar = !$caducado && $lote['caducidad'] <= date('Y-m-d', strtotime('+30 days'));
          ?>
            <tr>
              <td class="bd-tabla__celda-titulo"><span class="bd-tabla__titulo"><?= e($lote['medicamento']) ?></span><small><?= e(trim(($lote['concentracion'] ?? '') . ' ' . ($lote['clave_compendio'] ?? ''))) ?></small></td>
              <td><?= e($lote['codigo_lote']) ?></td>
              <td><?= e(fechaCorta($lote['caducidad'])) ?></td>
              <td>
                <form method="post" class="farmacia__conteo-en-linea">
                  <?= campoCsrf() ?>
                  <input type="hidden" name="accion" value="conteo">
                  <input type="hidden" name="operacion_id" value="<?= e($tokensConteo[(int) $lote['id']]) ?>">
                  <input type="hidden" name="lote_id" value="<?= (int) $lote['id'] ?>">
                  <label>
                    <span class="sr-only">Conteo físico de <?= e($lote['medicamento'] . ', lote ' . $lote['codigo_lote']) ?></span>
                    <input type="number" name="conteo" min="0" max="999999999.999" step="0.001" value="<?= e(number_format((float) $lote['existencia'], 3, '.', '')) ?>" required>
                  </label>
                  <span class="farmacia__unidad"><?= e($lote['unidad']) ?></span>
                  <label>
                    <span class="sr-only">Motivo del conteo de <?= e($lote['medicamento'] . ', lote ' . $lote['codigo_lote']) ?></span>
                    <input type="text" name="nota" maxlength="500" placeholder="Motivo del conteo" required>
                  </label>
                  <button class="farmacia__guardar-conteo" type="submit" title="Guardar conteo físico">Guardar</button>
                </form>
              </td>
              <td><span class="farmacia__estado<?= $caducado ? ' farmacia__estado--error' : ($porCaducar ? ' farmacia__estado--aviso' : '') ?>"><?= $caducado ? 'Caducado · no dispensar' : ($porCaducar ? 'Próximo a caducar' : ((float) $lote['existencia'] > 0 ? 'Disponible' : 'Sin existencias')) ?></span></td>
              <td class="num"><?= $lote['costo_unitario'] === null ? '—' : '$' . e(number_format((float) $lote['costo_unitario'], 2)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($paginasLotes > 1): ?>
      <nav class="farmacia__paginacion" aria-label="Páginas de existencias">
        <span><?= $totalLotes ?> lotes · Página <?= $paginaLotes ?> de <?= $paginasLotes ?></span>
        <?php if ($paginaLotes > 1): ?><a href="?<?= e(http_build_query(['q' => $filtro, 'pagina' => $paginaLotes - 1, 'movimientos_pagina' => $paginaMovimientos])) ?>">Anterior</a><?php endif; ?>
        <?php if ($paginaLotes < $paginasLotes): ?><a href="?<?= e(http_build_query(['q' => $filtro, 'pagina' => $paginaLotes + 1, 'movimientos_pagina' => $paginaMovimientos])) ?>">Siguiente</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>

  <section class="farmacia__seccion" aria-labelledby="farmacia-movimientos">
    <div class="farmacia__titulo-fila"><div><h2 id="farmacia-movimientos">Movimientos recientes</h2><p>Registro cronológico con responsable, motivo y vínculo a receta cuando corresponde.</p></div></div>
    <div class="bd__marco">
      <table class="bd-tabla">
        <caption class="solo-lector">Últimos movimientos de farmacia</caption>
        <thead><tr><th>Fecha</th><th>Movimiento</th><th>Medicamento y lote</th><th class="num">Cantidad</th><th>Paciente / receta</th><th>Responsable y nota</th></tr></thead>
        <tbody>
          <?php if (!$movimientos): ?><tr><td colspan="6" class="farmacia__vacio">Todavía no hay movimientos de inventario.</td></tr><?php endif; ?>
          <?php foreach ($movimientos as $movimiento):
            $tipoMovimiento = match ($movimiento['tipo']) {
                'entrada' => 'Entrada',
                'ajuste_entrada' => 'Ajuste positivo',
                'ajuste_salida' => 'Ajuste negativo',
                'merma' => 'Merma',
                'dispensacion' => 'Dispensación',
                default => 'Movimiento',
            };
            $nombrePaciente = trim(($movimiento['paciente_nombre'] ?? '') . ' ' . ($movimiento['primer_apellido'] ?? '') . ' ' . ($movimiento['segundo_apellido'] ?? ''));
          ?>
            <tr>
              <td><?= e(fechaCorta($movimiento['creado_en'])) ?></td>
              <td><?= e($tipoMovimiento) ?></td>
              <td><?= e($movimiento['medicamento'] . ' · ' . $movimiento['codigo_lote']) ?></td>
              <td class="num"><?= e(number_format((float) $movimiento['cantidad'], 3)) ?> <?= e($movimiento['unidad']) ?></td>
              <td><?= $movimiento['paciente_id'] ? e(($movimiento['folio'] ?? '') . ' · ' . $nombrePaciente . ' · receta #' . $movimiento['receta_id']) . ($movimiento['confirmacion_revisada'] ? '<small>Receta e identidad revisadas</small>' : '') : '—' ?></td>
              <td><?= e($movimiento['responsable'] ?: 'Usuario') ?><small><?= e($movimiento['nota']) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($paginasMovimientos > 1): ?>
      <nav class="farmacia__paginacion" aria-label="Páginas de movimientos">
        <span><?= $totalMovimientos ?> movimientos · Página <?= $paginaMovimientos ?> de <?= $paginasMovimientos ?></span>
        <?php if ($paginaMovimientos > 1): ?><a href="?<?= e(http_build_query(['q' => $filtro, 'pagina' => $paginaLotes, 'movimientos_pagina' => $paginaMovimientos - 1])) ?>#farmacia-movimientos">Anteriores</a><?php endif; ?>
        <?php if ($paginaMovimientos < $paginasMovimientos): ?><a href="?<?= e(http_build_query(['q' => $filtro, 'pagina' => $paginaLotes, 'movimientos_pagina' => $paginaMovimientos + 1])) ?>#farmacia-movimientos">Más recientes</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
</div>
