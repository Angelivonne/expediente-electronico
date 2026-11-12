<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/consentimiento_morbilidad.php';

exigirPermiso('consentimiento_morbilidad');
exigirPermiso('ver_expediente');

$pacienteId = (int) ($_GET['id'] ?? $_POST['paciente_id'] ?? 0);
$consultaPaciente = db()->prepare(
    'SELECT id, nombre, primer_apellido, segundo_apellido
     FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL'
);
$consultaPaciente->execute([$pacienteId, institucionId()]);
$paciente = $consultaPaciente->fetch(PDO::FETCH_ASSOC);
if (!$paciente) {
    mensajeFlash('error', 'El expediente no existe o no pertenece a esta institución.');
    redirigir('/pacientes/');
}
$nombrePaciente = trim($paciente['nombre'] . ' ' . $paciente['primer_apellido'] . ' ' . ($paciente['segundo_apellido'] ?? ''));
$idUsuario = (int) usuarioActual()['id'];
$institucion = db()->prepare('SELECT nombre FROM instituciones WHERE id = ?');
$institucion->execute([institucionId()]);
$nombreInstitucion = trim((string) $institucion->fetchColumn()) ?: 'la clínica';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a cargar el formulario.');
        redirigir('/pacientes/consentimiento_morbilidad.php?id=' . $pacienteId);
    }

    $accion = (string) ($_POST['accion'] ?? '');
    $pdo = db();
    $archivoGuardado = null;
    try {
        if ($accion === 'otorgar') {
            $destinatario = trim((string) ($_POST['destinatario'] ?? ''));
            $tipoOtorgante = (string) ($_POST['otorgante_tipo'] ?? '');
            $otorgante = trim((string) ($_POST['otorgante_nombre'] ?? ''));
            $relacion = trim((string) ($_POST['relacion'] ?? ''));
            $fechaOtorgamiento = (string) ($_POST['fecha_otorgamiento'] ?? '');
            $declaraRevisado = ($_POST['declara_revisado'] ?? '') === '1';
            $fechaValida = DateTime::createFromFormat('!Y-m-d', $fechaOtorgamiento);

            if ($destinatario === '' || mb_strlen($destinatario) > 200 || preg_match('/[\x00-\x1F\x7F]/', $destinatario)) {
                throw new InvalidArgumentException('Indica un destinatario concreto (máximo 200 caracteres).');
            }
            if (!in_array($tipoOtorgante, ['paciente', 'representante'], true) || $otorgante === '' || mb_strlen($otorgante) > 160) {
                throw new InvalidArgumentException('Indica quién otorgó la autorización.');
            }
            if ($tipoOtorgante === 'representante' && ($relacion === '' || mb_strlen($relacion) > 100)) {
                throw new InvalidArgumentException('Indica la relación de quien representa al paciente.');
            }
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fechaOtorgamiento || $fechaOtorgamiento > date('Y-m-d')) {
                throw new InvalidArgumentException('La fecha de firma no es válida.');
            }
            if (!$declaraRevisado) {
                throw new InvalidArgumentException('Confirma que el texto, el destinatario y la copia firmada coinciden.');
            }

            $archivoGuardado = guardarEvidenciaConsentimientoMorbilidad($_FILES['evidencia'] ?? [], (int) institucionId());
            $texto = textoConsentimientoMorbilidad($nombreInstitucion, $destinatario);
            $pdo->beginTransaction();
            $bloqueoPaciente = $pdo->prepare('SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
            $bloqueoPaciente->execute([$pacienteId, institucionId()]);
            if (!$bloqueoPaciente->fetchColumn()) {
                throw new RuntimeException('El expediente ya no está disponible en esta institución.');
            }
            $activa = $pdo->prepare("SELECT id FROM consentimientos_morbilidad WHERE institucion_id = ? AND paciente_id = ? AND estado = 'vigente' LIMIT 1");
            $activa->execute([institucionId(), $pacienteId]);
            if ($activa->fetchColumn()) {
                throw new InvalidArgumentException('Ya existe una autorización vigente. Revócala antes de registrar una nueva.');
            }

            $pdo->prepare(
                'INSERT INTO consentimientos_morbilidad
                 (institucion_id, paciente_id, version, texto_consentimiento, texto_sha256, destinatario,
                  otorgante_tipo, otorgante_nombre, relacion, fecha_otorgamiento, capturado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                institucionId(), $pacienteId, VERSION_CONSENTIMIENTO_MORBILIDAD, $texto, hash('sha256', $texto),
                $destinatario, $tipoOtorgante, $otorgante, $tipoOtorgante === 'representante' ? $relacion : null,
                $fechaOtorgamiento, $idUsuario,
            ]);
            $consentimientoId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO consentimientos_morbilidad_eventos
                 (consentimiento_id, institucion_id, paciente_id, tipo, usuario_id, solicitante_tipo,
                  solicitante_nombre, relacion, evidencia_archivo, evidencia_nombre, evidencia_mime, evidencia_sha256)
                 VALUES (?, ?, ?, 'otorgado', ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $consentimientoId, institucionId(), $pacienteId, $idUsuario, $tipoOtorgante, $otorgante,
                $tipoOtorgante === 'representante' ? $relacion : null, $archivoGuardado['archivo'],
                $archivoGuardado['nombre'], $archivoGuardado['mime'], $archivoGuardado['sha256'],
            ]);
            $pdo->commit();
            registrarBitacora('consentimientos_morbilidad', $consentimientoId, 'otorgar', 'destinatario', null, $destinatario);
            mensajeFlash('exito', 'Autorización registrada con su copia firmada y huella digital.');
        } elseif ($accion === 'revocar') {
            $consentimientoId = (int) ($_POST['consentimiento_id'] ?? 0);
            $tipoSolicitante = (string) ($_POST['solicitante_tipo'] ?? '');
            $solicitante = trim((string) ($_POST['solicitante_nombre'] ?? ''));
            $relacion = trim((string) ($_POST['relacion'] ?? ''));
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if (!in_array($tipoSolicitante, ['paciente', 'representante'], true) || $solicitante === '' || mb_strlen($solicitante) > 160) {
                throw new InvalidArgumentException('Indica quién solicita retirar la autorización.');
            }
            if ($tipoSolicitante === 'representante' && ($relacion === '' || mb_strlen($relacion) > 100)) {
                throw new InvalidArgumentException('Indica la relación de quien representa al paciente.');
            }
            if ($motivo === '' || mb_strlen($motivo) > 500) {
                throw new InvalidArgumentException('Registra el motivo o canal por el que se solicitó la revocación.');
            }
            if (isset($_FILES['evidencia']['error']) && $_FILES['evidencia']['error'] !== UPLOAD_ERR_NO_FILE) {
                $archivoGuardado = guardarEvidenciaConsentimientoMorbilidad($_FILES['evidencia'], (int) institucionId());
            }

            $pdo->beginTransaction();
            $actualizar = $pdo->prepare(
                "UPDATE consentimientos_morbilidad SET estado = 'revocado', revocado_en = NOW(), revocado_por = ?
                 WHERE id = ? AND institucion_id = ? AND paciente_id = ? AND estado = 'vigente'"
            );
            $actualizar->execute([$idUsuario, $consentimientoId, institucionId(), $pacienteId]);
            if ($actualizar->rowCount() !== 1) {
                throw new InvalidArgumentException('La autorización ya no está vigente o no pertenece a este expediente.');
            }
            $pdo->prepare(
                "INSERT INTO consentimientos_morbilidad_eventos
                 (consentimiento_id, institucion_id, paciente_id, tipo, usuario_id, solicitante_tipo,
                  solicitante_nombre, relacion, motivo, evidencia_archivo, evidencia_nombre, evidencia_mime, evidencia_sha256)
                 VALUES (?, ?, ?, 'revocado', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $consentimientoId, institucionId(), $pacienteId, $idUsuario, $tipoSolicitante, $solicitante,
                $tipoSolicitante === 'representante' ? $relacion : null, $motivo,
                $archivoGuardado['archivo'] ?? null, $archivoGuardado['nombre'] ?? null,
                $archivoGuardado['mime'] ?? null, $archivoGuardado['sha256'] ?? null,
            ]);
            $pdo->commit();
            registrarBitacora('consentimientos_morbilidad', $consentimientoId, 'revocar', 'motivo', null, $motivo);
            mensajeFlash('exito', 'Autorización revocada. No se incluirá en nuevas exportaciones.');
        } else {
            throw new InvalidArgumentException('La acción solicitada no es válida.');
        }
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($archivoGuardado && is_file($archivoGuardado['ruta']) && !unlink($archivoGuardado['ruta'])) {
            error_log('[consentimiento_morbilidad] No se pudo retirar una evidencia de una operación no confirmada.');
        }
        mensajeFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($archivoGuardado && is_file($archivoGuardado['ruta']) && !unlink($archivoGuardado['ruta'])) {
            error_log('[consentimiento_morbilidad] No se pudo retirar una evidencia de una operación no confirmada.');
        }
        error_log('[consentimiento_morbilidad] ' . $e->getMessage());
        mensajeFlash('error', 'No se pudo guardar el cambio. La operación no se completó.');
    }
    redirigir('/pacientes/consentimiento_morbilidad.php?id=' . $pacienteId);
}

$consultaActual = db()->prepare(
    "SELECT c.*, u.nombre AS capturado_por_nombre
     FROM consentimientos_morbilidad c
     LEFT JOIN usuarios u ON u.id = c.capturado_por
     WHERE c.institucion_id = ? AND c.paciente_id = ? AND c.estado = 'vigente'
     ORDER BY c.id DESC LIMIT 1"
);
$consultaActual->execute([institucionId(), $pacienteId]);
$autorizacionVigente = $consultaActual->fetch(PDO::FETCH_ASSOC) ?: null;
$consultaEventos = db()->prepare(
    'SELECT e.*, u.nombre AS usuario_nombre FROM consentimientos_morbilidad_eventos e
     LEFT JOIN usuarios u ON u.id = e.usuario_id
     WHERE e.institucion_id = ? AND e.paciente_id = ? ORDER BY e.id DESC'
);
$consultaEventos->execute([institucionId(), $pacienteId]);
$eventos = $consultaEventos->fetchAll(PDO::FETCH_ASSOC);

$tituloPagina = 'Autorización de estadísticas';
$seccionActiva = 'pacientes';
$mostrarBanner = false;
$migas = [
    ['texto' => 'Pacientes', 'url' => '/pacientes/'],
    ['texto' => $nombrePaciente, 'url' => '/pacientes/ver.php?id=' . $pacienteId],
    ['texto' => 'Autorización de estadísticas'],
];
$notificacion = tomarFlash();
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente consentimiento-morbilidad">
  <header class="consentimiento-morbilidad__cabecera">
    <div>
      <p class="consentimiento-morbilidad__sobrelinea">Expediente · <?= e($nombrePaciente) ?></p>
      <h1>Autorización para estadísticas agregadas</h1>
      <p>Registro independiente de los consentimientos de procedimientos clínicos.</p>
    </div>
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $pacienteId ?>">Volver al expediente</a>
  </header>

  <section class="ficha__seccion consentimiento-morbilidad__alcance" aria-labelledby="consentimiento-alcance">
    <h2 class="ficha__titulo" id="consentimiento-alcance">Alcance de esta autorización</h2>
    <p>Autoriza únicamente generar y compartir estadísticas agregadas con el destinatario que se escriba en el documento firmado. Las exportaciones excluyen identificadores, edad y sexo, y suprimen diagnósticos con menos de cinco pacientes que sí tengan autorización vigente para ese mismo destinatario.</p>
    <p>El umbral de cinco reduce el riesgo, pero no garantiza anonimización frente a cruces con otras fuentes o comparaciones entre informes. Revisa cada archivo antes de entregarlo.</p>
    <p>La autorización es voluntaria y revocable para futuros informes. La revocación no puede recuperar copias ya entregadas. El sistema guarda el texto aceptado, el actor que lo registró, fechas y la huella SHA-256 de los archivos; esa huella detecta cambios del archivo, pero no verifica la identidad ni autenticidad de una firma.</p>
  </section>

  <?php if ($autorizacionVigente): ?>
    <section class="ficha__seccion" aria-labelledby="autorizacion-activa">
      <h2 class="ficha__titulo" id="autorizacion-activa">Autorización vigente</h2>
      <dl class="consentimiento-morbilidad__datos">
        <div><dt>Destinatario</dt><dd><?= e($autorizacionVigente['destinatario']) ?></dd></div>
        <div><dt>Otorgada por</dt><dd><?= e($autorizacionVigente['otorgante_nombre']) ?><?= $autorizacionVigente['relacion'] ? ' · ' . e($autorizacionVigente['relacion']) : '' ?></dd></div>
        <div><dt>Fecha de firma</dt><dd><?= e(fechaCorta($autorizacionVigente['fecha_otorgamiento'])) ?></dd></div>
        <div><dt>Registrada por</dt><dd><?= e($autorizacionVigente['capturado_por_nombre'] ?? 'Usuario no disponible') ?> · <?= e($autorizacionVigente['creado_en']) ?></dd></div>
        <div><dt>Versión</dt><dd><?= e($autorizacionVigente['version']) ?></dd></div>
        <div><dt>Texto íntegro aceptado</dt><dd><?= e($autorizacionVigente['texto_consentimiento']) ?></dd></div>
      </dl>
    </section>

    <details class="ficha__seccion consentimiento-morbilidad__formulario">
      <summary>Registrar revocación</summary>
      <form method="post" enctype="multipart/form-data" class="rejilla">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="revocar">
        <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
        <input type="hidden" name="consentimiento_id" value="<?= (int) $autorizacionVigente['id'] ?>">
        <label class="campo"><span>Quién solicita retirar la autorización</span><select name="solicitante_tipo" required><option value="paciente">Paciente</option><option value="representante">Representante</option></select></label>
        <label class="campo"><span>Nombre de quien solicita</span><input name="solicitante_nombre" maxlength="160" required></label>
        <label class="campo"><span>Relación (si es representante)</span><input name="relacion" maxlength="100"></label>
        <label class="campo consentimiento-morbilidad__ancho"><span>Motivo o canal de solicitud</span><textarea name="motivo" maxlength="500" required placeholder="Por ejemplo: solicitud verbal presencial o documento recibido"></textarea></label>
        <label class="campo consentimiento-morbilidad__ancho"><span>Evidencia de revocación (opcional, PDF/JPG/PNG, máximo 8 MB)</span><input type="file" name="evidencia" accept="application/pdf,image/jpeg,image/png"></label>
        <div class="consentimiento-morbilidad__acciones"><button class="boton boton--secundario" type="submit">Guardar revocación</button></div>
      </form>
    </details>
  <?php else: ?>
    <section class="ficha__seccion consentimiento-morbilidad__formulario" aria-labelledby="nueva-autorizacion">
      <h2 class="ficha__titulo" id="nueva-autorizacion">Registrar autorización</h2>
      <p>La clínica debe obtener y conservar la copia firmada antes de registrar el permiso. El personal confirma los datos transcritos del documento; no se trata de una firma electrónica del paciente.</p>
      <form method="post" enctype="multipart/form-data" class="rejilla">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="otorgar">
        <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
        <label class="campo consentimiento-morbilidad__ancho"><span>Destinatario concreto</span><input name="destinatario" maxlength="200" required placeholder="Nombre de la institución, autoridad u organización"></label>
        <label class="campo"><span>Quién otorga</span><select name="otorgante_tipo" required><option value="paciente">Paciente</option><option value="representante">Representante</option></select></label>
        <label class="campo"><span>Nombre completo de quien firma</span><input name="otorgante_nombre" maxlength="160" required value="<?= e($nombrePaciente) ?>"></label>
        <label class="campo"><span>Relación (si es representante)</span><input name="relacion" maxlength="100"></label>
        <label class="campo"><span>Fecha de firma</span><input type="date" name="fecha_otorgamiento" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></label>
        <label class="campo consentimiento-morbilidad__ancho"><span>Copia firmada (PDF/JPG/PNG, máximo 8 MB)</span><input type="file" name="evidencia" accept="application/pdf,image/jpeg,image/png" required></label>
        <div class="consentimiento-morbilidad__texto consentimiento-morbilidad__ancho">
          <strong>Texto para revisar y firmar (versión <?= e(VERSION_CONSENTIMIENTO_MORBILIDAD) ?>)</strong>
          <p data-consentimiento-texto><?= e(textoConsentimientoMorbilidad($nombreInstitucion, '__DESTINATARIO__')) ?></p>
        </div>
        <div class="consentimiento-morbilidad__acciones">
          <button class="boton boton--fantasma" type="button" data-imprimir-consentimiento>Imprimir texto para firma</button>
        </div>
        <label class="casilla consentimiento-morbilidad__ancho"><input type="checkbox" name="declara_revisado" value="1" required><span>Confirmo que el documento firmado contiene este alcance, el mismo destinatario y la misma versión. La atención clínica no depende de esta autorización.</span></label>
        <div class="consentimiento-morbilidad__acciones"><button class="boton boton--secundario" type="submit">Guardar autorización y evidencia</button></div>
      </form>
    </section>
    <section class="consentimiento-morbilidad__impresion" aria-label="Texto de autorización para firma">
      <h1>Autorización para compartir estadísticas agregadas de morbilidad</h1>
      <p><strong>Institución:</strong> <?= e($nombreInstitucion) ?></p>
      <p><strong>Paciente:</strong> <?= e($nombrePaciente) ?></p>
      <p><strong>Destinatario:</strong> <span data-consentimiento-destinatario>[destinatario indicado arriba]</span></p>
      <p data-consentimiento-impresion-texto><?= e(textoConsentimientoMorbilidad($nombreInstitucion, '[destinatario indicado arriba]')) ?></p>
      <p><strong>Versión del texto:</strong> <?= e(VERSION_CONSENTIMIENTO_MORBILIDAD) ?></p>
      <div class="consentimiento-morbilidad__firma"><span>Nombre y firma del paciente o representante</span></div>
      <div class="consentimiento-morbilidad__firma"><span>Relación con el paciente (si aplica)</span></div>
      <div class="consentimiento-morbilidad__firma consentimiento-morbilidad__firma--fecha"><span>Fecha de firma</span></div>
    </section>
  <?php endif; ?>

  <section class="ficha__seccion" aria-labelledby="historial-autorizacion">
    <h2 class="ficha__titulo" id="historial-autorizacion">Historial de cambios</h2>
    <?php if (!$eventos): ?>
      <p>No hay autorizaciones registradas.</p>
    <?php else: ?>
      <ol class="consentimiento-morbilidad__historial">
        <?php foreach ($eventos as $evento): ?>
          <li>
            <div><strong><?= $evento['tipo'] === 'otorgado' ? 'Autorización otorgada' : 'Autorización revocada' ?></strong><span><?= e($evento['creado_en']) ?> · <?= e($evento['usuario_nombre'] ?? 'Usuario no disponible') ?></span></div>
            <p><?= e($evento['solicitante_nombre'] ?? '') ?><?= !empty($evento['motivo']) ? ' · ' . e($evento['motivo']) : '' ?></p>
            <?php if (!empty($evento['evidencia_archivo'])): ?>
              <a href="<?= URL_BASE ?>/api/pacientes/consentimiento_morbilidad_archivo.php?evento=<?= (int) $evento['id'] ?>">Descargar evidencia · <?= e($evento['evidencia_nombre']) ?> · SHA-256 <?= e($evento['evidencia_sha256']) ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</div>
<script nonce="<?= nonceCsp() ?>">
  document.querySelectorAll('[data-consentimiento-texto]').forEach((nodo) => {
    const destinatario = document.querySelector('[name="destinatario"]');
    if (!destinatario) return;
    const actualizar = () => {
      const destino = destinatario.value.trim() || '[destinatario indicado arriba]';
      const texto = <?= json_encode(textoConsentimientoMorbilidad($nombreInstitucion, '__DESTINATARIO__'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('__DESTINATARIO__', destino);
      nodo.textContent = texto;
      const textoImpresion = document.querySelector('[data-consentimiento-impresion-texto]');
      const destinatarioImpresion = document.querySelector('[data-consentimiento-destinatario]');
      if (textoImpresion) textoImpresion.textContent = texto;
      if (destinatarioImpresion) destinatarioImpresion.textContent = destino;
    };
    destinatario.addEventListener('input', actualizar);
    actualizar();
  });
  document.querySelector('[data-imprimir-consentimiento]')?.addEventListener('click', () => window.print());
</script>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
