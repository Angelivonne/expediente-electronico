<?php
/**
 * Vista completa de una entrada del historial (cualquier tipo de la NOM-004), con formato de documento
 * listo para imprimir: establecimiento, paciente, contenido (app/vistas/componentes/historial_detalle.php) y firma.
 * La receta lleva el bloque de quien prescribe y el consentimiento informado las firmas de paciente,
 * médico y dos testigos (NOM-004 5.10, 6.2.6 y 10.1).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
require_once __DIR__ . '/../app/servicios/clinica/firmas_clinicas.php';
require_once __DIR__ . '/../app/vistas/componentes/tarjeta_paciente.php';
require_once __DIR__ . '/../app/vistas/componentes/registro_selector_lateral.php';
// Modo paciente (portal): solo las entradas de su propio expediente, sin accesos al resto del sistema
$modoPaciente = esPaciente();
if (!$modoPaciente) {
    exigirPermiso('ver_expediente');
}

$c = db()->prepare('SELECT h.*, p.nombre, p.primer_apellido, p.segundo_apellido, p.folio, p.fecha_nacimiento, p.sexo, p.curp, p.alergias, p.foto
                      FROM historial_clinico h JOIN pacientes p ON p.id = h.paciente_id WHERE h.id = ? AND p.institucion_id = ? AND p.eliminado_en IS NULL'
                   . ($modoPaciente ? ' AND h.paciente_id = ?' : ''));
$c->execute($modoPaciente ? [(int) ($_GET['id'] ?? 0), institucionId(), (int) pacienteDeSesion()] : [(int) ($_GET['id'] ?? 0), institucionId()]);
$h = $c->fetch();
if (!$h) {
    if ($modoPaciente) {
        redirigir('/portal/');
    }
    mensajeFlash('error', 'La entrada no existe.');
    redirigir('/pacientes/');
}
registrarBitacora('pacientes', (int) $h['paciente_id'], 'acceso', $modoPaciente ? 'portal_historial' : 'consulta_historial', null, (string) $h['id']);
// Comentarios del equipo sobre esta entrada del historial
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_comentario_evento'] ?? '') === '1' && !$modoPaciente) {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a enviar el comentario.');
    } else {
        $altaEvento = motorCrear('comentarios', ['entidad' => 'historial', 'registro_id' => (int) $h['id'],
            'texto' => mb_substr(trim((string) ($_POST['texto'] ?? '')), 0, 1000)]);
        mensajeFlash(!empty($altaEvento['ok']) ? 'exito' : 'error', !empty($altaEvento['ok']) ? 'Comentario agregado.' : 'Escribe un comentario.');
    }
    redirigir('/pacientes/evento.php?id=' . (int) $h['id']);
}
// Revocación de la firma electrónica (quien firmó o un administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_revocar_firma'] ?? '') === '1' && !$modoPaciente) {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } else {
        $resultadoRevocar = revocarFirmaClinica((int) $h['id'], (int) usuarioActual()['id'], (string) ($_POST['motivo'] ?? ''), puede('configurar'));
        mensajeFlash(!empty($resultadoRevocar['ok']) ? 'exito' : 'error', !empty($resultadoRevocar['ok']) ? 'Firma revocada.' : ($resultadoRevocar['error'] ?? 'No se pudo revocar la firma.'));
    }
    redirigir('/pacientes/evento.php?id=' . (int) $h['id']);
}

// Firma electrónica de la entrada (NOM-024)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_firmar_evento'] ?? '') === '1' && !$modoPaciente) {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } elseif (!puede('historial')) {
        mensajeFlash('error', 'Tu rol no permite firmar entradas del historial.');
    } else {
        $resultadoFirma = firmarRegistroClinico($h, (int) usuarioActual()['id']);
        mensajeFlash(!empty($resultadoFirma['ok']) ? 'exito' : 'error', !empty($resultadoFirma['ok']) ? 'Entrada firmada electrónicamente.' : ($resultadoFirma['error'] ?? 'No se pudo firmar.'));
    }
    redirigir('/pacientes/evento.php?id=' . (int) $h['id']);
}
$firmaEvento = firmaDeRegistroClinico((int) $h['id']);
$firmaEventoValida = $firmaEvento ? firmaRegistroClinicoValida($h, $firmaEvento) : false;

$comentariosDelEvento = motorListar('comentarios', ['filtro' => ['entidad' => 'historial', 'registro_id' => (int) $h['id']], 'limite' => 100])['filas'];
$autoresDelEvento = [];
if ($comentariosDelEvento) {
    $idsAutoresEvento = array_values(array_unique(array_map(fn ($c) => (int) $c['usuario_id'], $comentariosDelEvento)));
    $marcasAutores = implode(',', array_fill(0, count($idsAutoresEvento), '?'));
    $stmtAutores = db()->prepare("SELECT id, nombre FROM usuarios WHERE id IN ($marcasAutores)");
    $stmtAutores->execute($idsAutoresEvento);
    foreach ($stmtAutores as $autorEvento) {
        $autoresDelEvento[(int) $autorEvento['id']] = $autorEvento['nombre'];
    }
}

$d         = json_decode($h['detalle'], true) ?: [];
$transcripcion = is_array($d['_transcripcion'] ?? null) ? $d['_transcripcion'] : null;
$transcripcionIntegra = false;
if ($transcripcion) {
    $detalleParaHuella = json_decode((string) $h['detalle']);
    if (is_object($detalleParaHuella)) {
        unset($detalleParaHuella->_transcripcion);
    }
    $huellaTexto = hash('sha256', (string) ($transcripcion['texto_original'] ?? ''));
    $huellaDetalle = huellaDatosTranscripcion($detalleParaHuella);
    $transcripcionIntegra = is_object($detalleParaHuella)
        && isset($transcripcion['huella_texto_sha256'], $transcripcion['huella_detalle_sha256'])
        && hash_equals((string) $transcripcion['huella_texto_sha256'], $huellaTexto)
        && hash_equals((string) $transcripcion['huella_detalle_sha256'], $huellaDetalle);
}
$tipo      = $h['tipo'];
$documento = nombreTipoRegistro($tipo, 'documento');
$paciente  = trim("{$h['nombre']} {$h['primer_apellido']} {$h['segundo_apellido']}");
$prescriptor = $tipo === 'receta' ? ($d['prescriptor'] ?? []) : [];
$consultaClinica = db()->prepare('SELECT nombre, domicilio, entidad, clues, razon_social, telefono FROM instituciones WHERE id = ?');
$consultaClinica->execute([institucionId()]);
$clinicaDocumento = $consultaClinica->fetch() ?: [];
$clinicaDocumento = $d['_institucion'] ?? $clinicaDocumento;
$ubicacionPrescriptor = trim((string) ($prescriptor['domicilio'] ?? ''));
$datosClinicaPresentes = trim(implode(' ', [
    (string) ($clinicaDocumento['domicilio'] ?? ''),
    (string) ($clinicaDocumento['entidad'] ?? ''),
    (string) ($clinicaDocumento['clues'] ?? ''),
])) !== '';
$establecimiento = $prescriptor['establecimiento'] ?? ($clinicaDocumento['nombre'] ?? (config('nombre_establecimiento') ?: 'Expediente clínico'));
$ubicacionClinica = trim(($clinicaDocumento['domicilio'] ?? '')
    . (isset(ENTIDADES[$clinicaDocumento['entidad'] ?? '']) ? ' · ' . ENTIDADES[$clinicaDocumento['entidad']] : ''), ' ·');
$cluesClinica = $clinicaDocumento['clues'] ?? '';
$prefijoClinica = $establecimiento !== ($clinicaDocumento['nombre'] ?? null) && !empty($clinicaDocumento['nombre'])
    ? $clinicaDocumento['nombre'] . ' · ' : '';
$cedula    = ($prescriptor['cedula'] ?? '') ?: $h['autor_cedula'];
$hora      = substr($h['fecha'], 11, 5);
$logoClinica = logoInstitucionUrl();
// Firma dibujada del autor; se captura en Equipo → Persona y vive en assets/subidas/firmas
$consultaFirmaAutor = db()->prepare('SELECT firma FROM usuarios WHERE id = ?');
$consultaFirmaAutor->execute([(int) $h['autor_id']]);
$firmaAutorArchivo = (string) $consultaFirmaAutor->fetchColumn();
$firmaAutorUrl = $firmaAutorArchivo !== '' && is_file(__DIR__ . '/../' . $firmaAutorArchivo)
    ? URL_BASE . '/' . $firmaAutorArchivo : null;
$pacienteTarjeta = $modoPaciente ? null : buscarPaciente((int) $h['paciente_id']);
$tarjetaLateral = $pacienteTarjeta ? tarjetaPaciente($pacienteTarjeta, 'perfil--flotante perfil--lateral') : '';

$tituloPagina   = $documento . ' · ' . $paciente;
$seccionActiva  = 'pacientes';
$pacienteActual = (int) $h['paciente_id'];
$registroActual = (int) $h['id'];
$migas = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => $paciente, 'url' => '/pacientes/ver.php?id=' . $h['paciente_id']], ['texto' => $documento]];
$notificacion = tomarFlash();
if ($modoPaciente) {
    $migas = [['texto' => 'Mi expediente', 'url' => '/portal/'], ['texto' => $documento]];
    $mostrarBanner = false;
    $claseContenido = 'portal__documento';
    require __DIR__ . '/../app/vistas/plantilla/portal_encabezado.php';
} else {
    $claseContenido = 'pagina--ancha';
    $mostrarBanner = true;   // La portada de la sección da vida al documento (configurada en portada_pacientes-evento)
    require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
}
?>

<article class="documento documento--<?= e($tipo) ?>">
  <header class="documento__cabecera">
    <div class="documento__marca">
      <?php if ($logoClinica): ?>
        <img class="documento__logo" src="<?= e($logoClinica) ?>" alt="Logo de <?= e($clinicaDocumento['nombre'] ?? 'la clínica') ?>">
      <?php endif; ?>
      <div>
        <p class="documento__establecimiento"><?= e($establecimiento) ?></p>
        <?php if ($clinicaDocumento && ($datosClinicaPresentes || $ubicacionPrescriptor === '')): ?>
          <p class="documento__domicilio"><?= e($prefijoClinica) ?><?= e($ubicacionClinica) ?><?= $cluesClinica !== '' ? ($ubicacionClinica !== '' ? ' · ' : '') . 'CLUES ' . e($cluesClinica) : '' ?><?= !empty($clinicaDocumento['telefono']) ? ' · Tel. ' . e($clinicaDocumento['telefono']) : '' ?></p>
          <?php $filaRazon = trim(implode(' · ', array_filter([
              (string) ($clinicaDocumento['razon_social'] ?? ''),
              ($clinicaDocumento['rfc'] ?? '') !== '' ? 'RFC ' . $clinicaDocumento['rfc'] : '',
          ], 'strlen'))); ?>
          <?php if ($filaRazon !== ''): ?><p class="documento__domicilio"><?= e($filaRazon) ?></p><?php endif; ?>
        <?php elseif ($ubicacionPrescriptor !== ''): ?>
          <p class="documento__domicilio"><?= e($ubicacionPrescriptor) ?></p>
        <?php endif; ?>
        <h1 class="documento__titulo"><?= e($documento) ?></h1>
      </div>
    </div>
    <p class="documento__fecha"><?= e(fechaCorta($h['fecha'])) ?><?php if ($hora !== '00:00'): ?><small><?= e($hora) ?> h</small><?php endif; ?></p>
  </header>

  <dl class="documento__paciente">
    <div><dt>Paciente</dt><dd><?= e($paciente) ?></dd></div>
    <div><dt>Expediente</dt><dd><?= e($h['folio']) ?></dd></div>
    <div><dt>Edad</dt><dd><?= edad($h['fecha_nacimiento']) ?> años · <?= e(SEXOS[$h['sexo']] ?? '') ?></dd></div>
    <?php if ($h['curp']): ?><div><dt>CURP</dt><dd><?= e($h['curp']) ?></dd></div><?php endif; ?>
    <div class="documento__alergias<?= sinAlergiasRegistradas($h['alergias']) ? ' documento__alergias--sin' : '' ?>"><dt>Alergias</dt><dd><?= e($h['alergias'] ?: 'No registradas') ?></dd></div>
  </dl>

  <div class="documento__contenido">
    <?php $registro = $h; $modoDetalle = 'documento'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; ?>
  </div>

  <?php if (!$modoPaciente && $transcripcion): ?>
    <details class="documento__trazabilidad">
      <summary>Procedencia del dictado · <?= $transcripcionIntegra ? 'huellas coincidentes' : 'huellas no coincidentes' ?></summary>
      <dl>
        <div><dt>Revisó y confirmó</dt><dd><?= e((string) ($transcripcion['confirmado_nombre'] ?? 'No registrado')) ?></dd></div>
        <div><dt>Fecha y hora de confirmación</dt><dd><?= e((string) ($transcripcion['confirmado_en_utc'] ?? 'No registrada')) ?> UTC</dd></div>
        <div><dt>Motor</dt><dd><?= e((string) ($transcripcion['motor'] ?? 'No identificado')) ?></dd></div>
        <div><dt>Huella del texto fuente</dt><dd><code><?= e((string) ($transcripcion['huella_texto_sha256'] ?? '')) ?></code></dd></div>
        <div><dt>Huella de los datos finales</dt><dd><code><?= e((string) ($transcripcion['huella_detalle_sha256'] ?? '')) ?></code></dd></div>
      </dl>
      <p>Las huellas permiten detectar diferencias respecto a esta copia; no son una firma electrónica ni una certificación legal.</p>
      <h2>Texto original reconocido</h2>
      <pre><?= e((string) ($transcripcion['texto_original'] ?? '')) ?></pre>
    </details>
  <?php endif; ?>

  <?php if ($tipo === 'consentimiento'): ?>
    <!-- NOM-004 10.1.1: nombre y firma de quien otorga, del médico y de dos testigos -->
    <footer class="documento__firmas">
      <div class="documento__firma-celda">
        <span class="documento__linea" aria-hidden="true"></span>
        <b><?= e($d['otorgante'] ?? $paciente) ?></b>
        <span><?= e(($d['otorga'] ?? 'Paciente') . (!empty($d['parentesco']) ? ' · ' . $d['parentesco'] : '')) ?></span>
      </div>
      <div class="documento__firma-celda">
        <span class="documento__linea" aria-hidden="true"></span>
        <b><?= e($h['autor_nombre']) ?></b>
        <span><?= $cedula ? 'Médico · cédula profesional ' . e($cedula) : 'Médico' ?></span>
      </div>
      <?php foreach (array_values($d['testigos'] ?? []) as $i => $testigo): ?>
        <div class="documento__firma-celda">
          <span class="documento__linea" aria-hidden="true"></span>
          <b><?= e($testigo) ?></b>
          <span>Testigo <?= $i + 1 ?></span>
        </div>
      <?php endforeach; ?>
    </footer>

  <?php elseif ($tipo === 'receta'): ?>
    <!-- Quien prescribe: nombre, cédula profesional, institución que expidió el título y establecimiento -->
    <footer class="documento__prescriptor">
      <dl class="documento__prescriptor-datos">
        <div><dt>Médico</dt><dd><?= e($prescriptor['nombre'] ?? $h['autor_nombre']) ?></dd></div>
        <div><dt>Cédula profesional</dt><dd><?= $cedula ? e($cedula) : 'Sin cédula registrada' ?></dd></div>
        <?php if (!empty($prescriptor['institucion'])): ?><div><dt>Institución que expidió el título</dt><dd><?= e($prescriptor['institucion']) ?></dd></div><?php endif; ?>
        <div><dt>Fecha de expedición</dt><dd><?= e(fechaCorta($h['fecha'])) ?></dd></div>
      </dl>
    </footer>
  <?php endif; ?>

  <?php
  $selloFirma = !$firmaEvento
      ? ['', 'escudo', 'Sin firma electrónica']
      : ($firmaEventoValida
          ? ['documento__sello--valida', 'escudo_ok', 'Firma válida']
          : ['documento__sello--alerta', 'escudo_alerta', 'Huella no coincide']);
  ?>
  <!-- La firma vive dentro de la hoja: trazo del autor y estado de la firma electrónica en un marco visible -->
  <footer class="documento__firma-panel" aria-label="Firma del documento">
    <div class="documento__firma-panel__cabecera">
      <div>
        <p class="documento__firma-panel__titulo">Firma de quien elabora</p>
        <p class="documento__firma-panel__nota"><?php if ($firmaEvento): ?>Firmado electrónicamente por <b><?= e((string) $firmaEvento['usuario_nombre']) ?></b> el <?= e((string) $firmaEvento['firmado_en']) ?>.<?php else: ?>La nota queda guardada al registrarla; la firma electrónica se aplica cuando la confirmas aquí.<?php endif; ?></p>
      </div>
      <span class="documento__sello <?= $selloFirma[0] ?>"><?= icono($selloFirma[1]) ?><?= e($selloFirma[2]) ?></span>
    </div>
    <div class="documento__firma-panel__cuerpo<?= $tipo === 'consentimiento' ? ' documento__firma-panel__cuerpo--solo' : '' ?>">
      <?php if ($tipo !== 'consentimiento'): ?>
        <div class="documento__firma">
          <?php if ($firmaAutorUrl): ?><img class="documento__firma-img" src="<?= e($firmaAutorUrl) ?>" alt="Firma de <?= e($h['autor_nombre']) ?>"><?php endif; ?>
          <span class="documento__linea" aria-hidden="true"></span>
          <b><?= e($h['autor_nombre']) ?></b>
          <span><?= $cedula ? 'Cédula profesional ' . e($cedula) : 'Sin cédula registrada' ?></span>
        </div>
      <?php endif; ?>
      <dl class="documento__firma-meta">
        <div><dt>Registrado</dt><dd><?= e(fechaCorta($h['creado_en'])) ?> · entrada inmutable (NOM-004 5.10)</dd></div>
        <?php if ($firmaEvento): ?>
          <div><dt>Firma electrónica</dt><dd><?= e((string) $firmaEvento['usuario_nombre']) ?><?= !empty($firmaEvento['cedula']) ? ' · cédula ' . e((string) $firmaEvento['cedula']) : '' ?> · <?= e((string) $firmaEvento['firmado_en']) ?></dd></div>
          <div><dt>Huella SHA-256</dt><dd><code><?= e((string) $firmaEvento['huella']) ?></code></dd></div>
        <?php endif; ?>
      </dl>
    </div>
    <?php if (!$modoPaciente): ?>
      <div class="documento__firma-acciones no-imprimir">
        <?php if ($firmaEvento): ?>
          <?php if ((int) $firmaEvento['usuario_id'] === (int) usuarioActual()['id'] || puede('configurar')): ?>
            <form method="post">
              <?= campoCsrf() ?>
              <input type="hidden" name="accion_revocar_firma" value="1">
              <input name="motivo" maxlength="255" required placeholder="Motivo de la revocación" aria-label="Motivo de la revocación">
              <button type="submit" class="boton boton--sutil">Revocar firma</button>
            </form>
          <?php endif; ?>
        <?php elseif (puede('historial')): ?>
          <form method="post">
            <?= campoCsrf() ?>
            <input type="hidden" name="accion_firmar_evento" value="1">
            <button type="submit" class="boton boton--sutil"><?= icono('escudo_ok') ?> Firmar electrónicamente</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </footer>
</article>

<?php if (!$modoPaciente): ?>
<?php if ($tarjetaLateral !== ''): ?>
<div class="tarjeta-lateral" data-tarjeta-lateral>
  <button type="button" class="tarjeta-lateral__asa" aria-label="Abrir acciones del paciente" aria-expanded="false"></button>
  <?= $tarjetaLateral ?>
  <?= selectorRegistroLateral((int) $h['paciente_id']) ?>
</div>
<?php endif; ?>
<script type="application/json" id="registro-exportacion"><?= json_encode([
    'id' => (int) $h['id'],
    'paciente' => ['id' => (int) $h['paciente_id'], 'nombre' => $paciente, 'folio' => $h['folio']],
    'tipo' => $tipo,
    'documento' => $documento,
    'titulo' => $h['titulo'],
    'fecha' => $h['fecha'],
    'profesional' => ['nombre' => $h['autor_nombre'], 'cedula' => $cedula],
    'detalle' => $d,
    'registrado_en' => $h['creado_en'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>

<?php if (!$modoPaciente): ?>
<section class="historial-comentarios historial-comentarios--documento no-imprimir" aria-label="Comentarios del evento">
  <p class="historial-comentarios__titulo">Comentarios del evento<?= $comentariosDelEvento ? ' (' . count($comentariosDelEvento) . ')' : '' ?></p>
  <?php if ($comentariosDelEvento): ?>
    <ul class="historial-comentarios__lista">
      <?php foreach ($comentariosDelEvento as $comentarioDelEvento): ?>
        <li>
          <span class="historial-comentarios__texto"><?= e((string) $comentarioDelEvento['texto']) ?></span>
          <span class="historial-comentarios__meta"><?= e($autoresDelEvento[(int) $comentarioDelEvento['usuario_id']] ?? 'Equipo') ?> · <?= e(fechaCorta(substr((string) $comentarioDelEvento['creado_en'], 0, 10))) ?><?= !empty($comentarioDelEvento['resuelto_en']) ? ' · Resuelto' : '' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin comentarios en este evento.</p>
  <?php endif; ?>
  <?php if (puede('historial')): ?>
    <form method="post" class="historial-comentarios__form">
      <?= campoCsrf() ?>
      <input type="hidden" name="accion_comentario_evento" value="1">
      <input name="texto" maxlength="1000" required placeholder="Comentario del evento… usa @nombre para mencionar" aria-label="Comentario del evento">
      <button type="submit" class="boton boton--sutil">Comentar</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="documento__acciones">
  <a class="boton boton--fantasma" href="<?= URL_BASE ?><?= $modoPaciente ? '/portal/#historial' : '/pacientes/ver.php?id=' . (int) $h['paciente_id'] . '#historial' ?>"><?= icono('atras') ?> Volver al expediente</a>
  <?php if (puede('historial')): ?>
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/nuevo_registro.php?paciente=<?= (int) $h['paciente_id'] ?>"><?= icono('agregar') ?> Nuevo registro</a>
  <?php endif; ?>
  <?php if ($tipo === 'receta'): ?>
    <a class="boton boton--primario" href="<?= URL_BASE ?>/pacientes/receta_imprimir.php?id=<?= (int) $h['id'] ?>" target="_blank" rel="noopener"><?= icono('documento') ?> Imprimir Receta Oficial</a>
  <?php endif; ?>
  <button type="button" class="boton boton--secundario" data-imprimir><?= icono('documento') ?> Imprimir</button>
</div>

<?php require __DIR__ . '/../app/vistas/plantilla/' . ($modoPaciente ? 'portal_pie.php' : 'pie.php'); ?>
