<?php
/** Directorio y vínculos entre clínicas. Los expedientes solo se buscan con una autorización individual vigente. */
require_once __DIR__ . '/../app/nucleo/inicio.php';
// Esta vista también usa el catálogo de tipos de institución.
require_once __DIR__ . '/../app/servicios/cuenta/instituciones.php';
require_once __DIR__ . '/../app/servicios/clinica/conexiones.php';

$accesoConexiones = puede('conexiones') || puede('configurar');
if (!$accesoConexiones) http_response_code(403);
$institucion = (int) institucionId();
$usuario = usuarioActual();
$errores = [];
if (!$accesoConexiones) $errores[] = 'Sin permiso para Conexiones.';
$canalProtegido = conexionesCanalProtegido();

/** Guarda una copia PDF fuera del acceso web y devuelve sus metadatos. */
function conexionesGuardarPdf(string $campo): ?array
{
    $archivo = $_FILES[$campo] ?? null;
    if (!$archivo || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > 15 * 1024 * 1024) {
        throw new RuntimeException('PDF máximo 15 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($archivo['tmp_name']) !== 'application/pdf') {
        throw new RuntimeException('Solo PDF.');
    }
    $directorio = __DIR__ . '/../privado/conexiones';
    if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo preparar el resguardo privado del documento.');
    }
    $nombre = bin2hex(random_bytes(20)) . '.pdf';
    $ruta = $directorio . '/' . $nombre;
    if (!move_uploaded_file($archivo['tmp_name'], $ruta)) {
        throw new RuntimeException('No se pudo guardar el documento.');
    }
    chmod($ruta, 0600);
    return [
        'ruta' => 'privado/conexiones/' . $nombre,
        'sha256' => hash_file('sha256', $ruta),
        'nombre' => mb_substr(basename((string) $archivo['name']), 0, 255),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accesoConexiones) {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        try {
            if (in_array($accion, ['solicitar', 'aceptar', 'rechazar', 'revocar'], true) && !puede('configurar')) {
                throw new RuntimeException('Solo un administrador puede gestionar vínculos entre clínicas.');
            }
            if ($accion === 'solicitar') {
                if (!$canalProtegido) throw new RuntimeException('Requiere HTTPS o localhost.');
                $destino = filter_var($_POST['institucion_destino'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$destino || $destino === $institucion) {
                    throw new RuntimeException('Elige otra clínica.');
                }
                if (empty($_POST['convenio_confirmado'])) {
                    throw new RuntimeException('Confirma el convenio firmado.');
                }
                $doc = conexionesGuardarPdf('convenio');
                if (!$doc) {
                    throw new RuntimeException('Adjunta el convenio bilateral firmado en PDF.');
                }
                $a = min($institucion, (int) $destino);
                $b = max($institucion, (int) $destino);
                $existe = db()->prepare('SELECT id FROM conexiones_clinicas WHERE institucion_a_id = ? AND institucion_b_id = ?');
                $existe->execute([$a, $b]);
                if ($existe->fetchColumn()) {
                    @unlink(__DIR__ . '/../' . $doc['ruta']);
                    throw new RuntimeException('Ya existe una conexión.');
                }
                $lado = $institucion === $a ? 'a' : 'b';
                $columnaFecha = 'acuerdo_' . $lado . '_confirmado';
                $columnaUsuario = 'acuerdo_' . $lado . '_usuario';
                $sql = 'INSERT INTO conexiones_clinicas
                          (institucion_a_id, institucion_b_id, solicitada_por, estado, acuerdo_archivo, acuerdo_sha256, acuerdo_nombre,
                           acuerdo_version, ' . $columnaFecha . ', ' . $columnaUsuario . ')
                        VALUES (?, ?, ?, \'pendiente\', ?, ?, ?, \'convenio-externo-v1\', NOW(), ?)';
                db()->prepare($sql)->execute([$a, $b, (int) $usuario['id'], $doc['ruta'], $doc['sha256'], $doc['nombre'], (int) $usuario['id']]);
                $id = (int) db()->lastInsertId();
                registrarBitacora('conexiones_clinicas', $id, 'crear', 'solicitud', null, 'Convenio firmado adjunto');
                mensajeFlash('exito', 'Solicitud enviada.');
                redirigir('/conexiones/#vinculos');
            } elseif ($accion === 'aceptar' || $accion === 'rechazar' || $accion === 'revocar') {
                $id = filter_var($_POST['conexion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $c = db()->prepare('SELECT * FROM conexiones_clinicas WHERE id = ? AND (institucion_a_id = ? OR institucion_b_id = ?)');
                $c->execute([$id ?: 0, $institucion, $institucion]);
                $conexion = $c->fetch();
                if (!$conexion) {
                    throw new RuntimeException('Vínculo no encontrado.');
                }
                if ($accion === 'aceptar') {
                    $sol = db()->prepare('SELECT institucion_id FROM usuarios WHERE id = ?');
                    $sol->execute([(int) $conexion['solicitada_por']]);
                    $instSolicitante = (int) $sol->fetchColumn();
                    if ($institucion === $instSolicitante || $conexion['estado'] !== 'pendiente') {
                        throw new RuntimeException('Solo la clínica invitada acepta.');
                    }
                    if (empty($conexion['acuerdo_archivo']) || empty($conexion['acuerdo_sha256'])) {
                        throw new RuntimeException('Falta el convenio.');
                    }
                    $lado = $institucion === (int) $conexion['institucion_a_id'] ? 'a' : 'b';
                    $columnaFecha = 'acuerdo_' . $lado . '_confirmado';
                    $columnaUsuario = 'acuerdo_' . $lado . '_usuario';
                    db()->prepare('UPDATE conexiones_clinicas SET ' . $columnaFecha . ' = NOW(), ' . $columnaUsuario . ' = ?, estado = \'conectada\' WHERE id = ? AND estado = \'pendiente\'')
                        ->execute([(int) $usuario['id'], (int) $id]);
                    registrarBitacora('conexiones_clinicas', (int) $id, 'actualizar', 'estado', 'pendiente', 'conectada');
                    mensajeFlash('exito', 'Vínculo aprobado.');
                } elseif ($accion === 'rechazar') {
                    if ($conexion['estado'] !== 'pendiente') throw new RuntimeException('Ya no está pendiente.');
                    $sol = db()->prepare('SELECT institucion_id FROM usuarios WHERE id = ?');
                    $sol->execute([(int) $conexion['solicitada_por']]);
                    if ($institucion === (int) $sol->fetchColumn()) {
                        throw new RuntimeException('La solicitud solo puede ser aceptada o rechazada por la clínica invitada.');
                    }
                    db()->prepare('UPDATE conexiones_clinicas SET estado = \'rechazada\' WHERE id = ?')->execute([(int) $id]);
                    registrarBitacora('conexiones_clinicas', (int) $id, 'actualizar', 'estado', 'pendiente', 'rechazada');
                    mensajeFlash('exito', 'Solicitud rechazada.');
                } else {
                    if ($conexion['estado'] !== 'conectada') throw new RuntimeException('Solo conexiones activas.');
                    db()->prepare('UPDATE conexiones_clinicas SET estado = \'revocada\' WHERE id = ?')->execute([(int) $id]);
                    db()->prepare('UPDATE conexiones_autorizaciones SET revocado_en = NOW() WHERE conexion_id = ? AND revocado_en IS NULL')->execute([(int) $id]);
                    registrarBitacora('conexiones_clinicas', (int) $id, 'actualizar', 'estado', 'conectada', 'revocada');
                    mensajeFlash('exito', 'Conexión revocada.');
                }
                redirigir('/conexiones/#vinculos');
            } elseif ($accion === 'autorizar_paciente') {
                exigirPermiso('conexiones');
                exigirPermiso('historial');
                if (!$canalProtegido) throw new RuntimeException('Requiere HTTPS o localhost.');
                $pacienteId = filter_var($_POST['paciente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $destino = filter_var($_POST['institucion_destino'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $alcance = in_array($_POST['alcance'] ?? '', ['resumen', 'expediente'], true) ? $_POST['alcance'] : '';
                $finalidad = mb_substr(trim((string) ($_POST['finalidad'] ?? '')), 0, 500);
                $firmado = trim((string) ($_POST['firmado_en'] ?? ''));
                $vence = trim((string) ($_POST['vence_en'] ?? ''));
                $doc = conexionesGuardarPdf('consentimiento');
                $pac = db()->prepare('SELECT id FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
                $pac->execute([$pacienteId ?: 0, $institucion]);
                if (!$pac->fetchColumn() || !$destino || !$alcance || $finalidad === '' || !$doc) {
                    if ($doc) @unlink(__DIR__ . '/../' . $doc['ruta']);
                    throw new RuntimeException('Completa todos los campos y adjunta el PDF.');
                }
                $fFirmado = DateTime::createFromFormat('!Y-m-d', $firmado);
                $fVence = DateTime::createFromFormat('!Y-m-d', $vence);
                if (!$fFirmado || $fFirmado->format('Y-m-d') !== $firmado || !$fVence || $fVence->format('Y-m-d') !== $vence || $fFirmado > new DateTime('today') || $fVence <= new DateTime('today')) {
                    @unlink(__DIR__ . '/../' . $doc['ruta']);
                    throw new RuntimeException('Fechas inválidas.');
                }
                $rel = db()->prepare('SELECT id FROM conexiones_clinicas WHERE estado = \'conectada\' AND ((institucion_a_id = ? AND institucion_b_id = ?) OR (institucion_a_id = ? AND institucion_b_id = ?))');
                $rel->execute([$institucion, $destino, $destino, $institucion]);
                $conexionId = (int) $rel->fetchColumn();
                if (!$conexionId) {
                    @unlink(__DIR__ . '/../' . $doc['ruta']);
                    throw new RuntimeException('Primero conecta las clínicas.');
                }
                db()->prepare('INSERT INTO conexiones_autorizaciones
                    (conexion_id, institucion_origen_id, institucion_destino_id, paciente_id, alcance, finalidad,
                     consentimiento_archivo, consentimiento_sha256, consentimiento_nombre, firmado_en, vence_en, creado_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$conexionId, $institucion, (int) $destino, (int) $pacienteId, $alcance, $finalidad,
                        $doc['ruta'], $doc['sha256'], $doc['nombre'], $firmado . ' 00:00:00', $vence . ' 23:59:59', (int) $usuario['id']]);
                $authId = (int) db()->lastInsertId();
                registrarBitacora('conexiones_autorizaciones', $authId, 'crear', 'alcance', null, $alcance . ' · destino ' . (int) $destino);
                mensajeFlash('exito', 'Autorización registrada.');
                redirigir('/conexiones/#autorizaciones');
            } elseif ($accion === 'revocar_autorizacion') {
                exigirPermiso('historial');
                $id = filter_var($_POST['autorizacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $c = db()->prepare('UPDATE conexiones_autorizaciones SET revocado_en = NOW() WHERE id = ? AND institucion_origen_id = ? AND revocado_en IS NULL');
                $c->execute([$id ?: 0, $institucion]);
                if (!$c->rowCount()) throw new RuntimeException('Autorización no encontrada.');
                registrarBitacora('conexiones_autorizaciones', (int) $id, 'actualizar', 'estado', 'vigente', 'revocada');
                mensajeFlash('exito', 'Autorización revocada.');
                redirigir('/conexiones/#autorizaciones');
            }
        } catch (Throwable $e) {
            $errores[] = $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar. Verifica la migración y permisos MySQL.';
        }
    }
}

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$clinicas = [];
$conexiones = [];
$autorizaciones = [];
$migracionDisponible = false;
if ($accesoConexiones) {
try {
    // 1) Directorio de clínicas (siempre visible).
    $sql = 'SELECT i.id, i.nombre, i.tipo, i.domicilio, i.entidad, i.clues
              FROM instituciones i WHERE i.id <> ?';
    $params = [$institucion];
    if ($q !== '') {
        $sql .= ' AND (i.nombre LIKE ? OR i.clues LIKE ? OR i.domicilio LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    $sql .= ' ORDER BY i.nombre LIMIT 100';
    $c = db()->prepare($sql); $c->execute($params);
    // Datos heredados pueden traer texto doblemente codificado; se sanea al mostrarlos.
    $clinicas = array_map('saneaProfundo', $c->fetchAll());

    db()->query('SELECT 1 FROM conexiones_clinicas LIMIT 0');
    db()->query('SELECT 1 FROM conexiones_autorizaciones LIMIT 0');

    // 2) Vínculos (todo en una sola lista, sin pestañas).
    $sql = 'SELECT c.*, sol.institucion_id AS solicitada_institucion_id, a.nombre AS clinica_a, a.tipo AS tipo_a, a.domicilio AS domicilio_a, a.entidad AS entidad_a, a.clues AS clues_a,
                   b.nombre AS clinica_b, b.tipo AS tipo_b, b.domicilio AS domicilio_b, b.entidad AS entidad_b, b.clues AS clues_b
              FROM conexiones_clinicas c JOIN instituciones a ON a.id = c.institucion_a_id JOIN instituciones b ON b.id = c.institucion_b_id
              JOIN usuarios sol ON sol.id = c.solicitada_por
             WHERE (c.institucion_a_id = ? OR c.institucion_b_id = ?)
             ORDER BY c.creado_en DESC LIMIT 200';
    $c = db()->prepare($sql); $c->execute([$institucion, $institucion]);
    $conexiones = array_map('saneaProfundo', $c->fetchAll());

    // 3) Autorizaciones individuales emitidas por esta clínica.
    $c = db()->prepare('SELECT a.*, p.folio, CONCAT_WS(\' \', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente,
                               d.nombre AS clinica_destino
                          FROM conexiones_autorizaciones a JOIN pacientes p ON p.id = a.paciente_id
                          JOIN instituciones d ON d.id = a.institucion_destino_id
                         WHERE a.institucion_origen_id = ? ORDER BY a.creado_en DESC LIMIT 200');
    $c->execute([$institucion]); $autorizaciones = array_map('saneaProfundo', $c->fetchAll());
    $migracionDisponible = true;
} catch (Throwable $e) {
    $tablas = [];
    try {
        foreach (['conexiones_clinicas', 'conexiones_autorizaciones'] as $tabla) {
            $consultaTabla = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $consultaTabla->execute([$tabla]);
            $tablas[] = (bool) $consultaTabla->fetchColumn();
        }
    } catch (Throwable) {
        $tablas = [];
    }
    $codigoControlador = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    if (in_array(false, $tablas, true)) {
        $errores[] = 'Falta instalar database/migracion_conexiones_clinicas.sql.';
    } elseif ($codigoControlador === 1142) {
        $errores[] = 'Faltan permisos MySQL para Conexiones. Reaplica su migración como administrador.';
    } else {
        $errores[] = 'Error al consultar MySQL. Conexiones no está disponible.';
    }
}
}

$tituloPagina = 'Conexiones con clínicas';
$seccionActiva = 'conexiones';
$claseContenido = '';
$migas = [['texto' => 'Expediente'], ['texto' => 'Conexiones con clínicas']];
// Solo con vínculos aceptados se puede autorizar un expediente a otra clínica.
$conectadas = array_values(array_filter($conexiones, static fn (array $c): bool => $c['estado'] === 'conectada'));
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<?php if (!$accesoConexiones): ?>
<div class="conexiones__bloqueo" role="alert">Sin permiso para Conexiones.</div>
<?php else: ?>
<div class="pagina__cabecera conexiones__cabecera">
  <div class="conexiones__titulo"><h1 class="pagina__titulo">Conexiones</h1></div>
  <div class="conexiones__herramientas">
    <form class="conexiones__buscar" method="get" action="<?= URL_BASE ?>/conexiones/">
      <label class="sr-only" for="conexiones-q">Buscar clínicas</label>
      <input id="conexiones-q" name="q" value="<?= e($q) ?>" placeholder="Buscar clínicas">
      <button class="boton-icono" type="submit" title="Buscar"><?= icono('buscar') ?></button>
    </form>
    <a class="boton-icono" href="<?= URL_BASE ?>/conexiones/" title="Actualizar" aria-label="Actualizar"><?= icono('reabrir') ?></a>
    <a class="boton-icono" href="<?= URL_BASE ?>/configuracion/" title="Configuración de la institución" aria-label="Configuración de la institución"><?= icono('engrane') ?></a>
    <?php if (puede('configurar') && $migracionDisponible && $clinicas): ?>
      <button class="boton boton--primario conexiones__nueva" type="button" data-conexiones-abrir><?= icono('agregar') ?><span>Agregar</span><?= icono('flecha_abajo') ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if ($errores): ?><div class="conexiones__avisos" role="alert"><?php foreach ($errores as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<?php $flash = tomarFlash(); if ($flash): ?><div class="conexiones__flash conexiones__flash--<?= e($flash['tipo']) ?>" role="status"><?= e($flash['texto']) ?></div><?php endif; ?>

<div class="conexiones">
  <?php
    /** Catálogo: las clínicas se agrupan por entidad federativa con tarjetas cortas (icono, nombre y acción). */
    $vinculadasCon = [];
    foreach ($conexiones as $c) {
        $idOtra = (int) ($c['institucion_a_id'] === $institucion ? $c['institucion_b_id'] : $c['institucion_a_id']);
        $vinculadasCon[$idOtra] = $c['estado'];
    }
    $gruposClinicas = [];
    foreach ($clinicas as $clinica) {
        $claveEntidad = (!empty($clinica['entidad']) && isset(ENTIDADES[$clinica['entidad']])) ? ENTIDADES[$clinica['entidad']] : 'Sin entidad registrada';
        $gruposClinicas[$claveEntidad][] = $clinica;
    }
    ksort($gruposClinicas);
    $inicialesClinica = fn (string $nombre): string => mb_strtoupper(mb_substr(preg_split('/\s+/u', trim($nombre))[0] ?? '', 0, 2));
  ?>
    <?php foreach ($gruposClinicas as $etiquetaEntidad => $grupoClinicas): ?>
      <div class="conexiones__grupo">
        <h2 class="conexiones__grupo-titulo"><?= e($etiquetaEntidad) ?><?= icono('chevron') ?><small><?= count($grupoClinicas) ?></small></h2>
        <div class="conexiones__rejilla">
          <?php foreach ($grupoClinicas as $clinica): $estadoVinculo = $vinculadasCon[(int) $clinica['id']] ?? null; ?>
            <article class="conexiones__clinica" title="<?= e(trim(($clinica['domicilio'] ?? '') . (!empty($clinica['clues']) ? ' · CLUES ' . $clinica['clues'] : ''))) ?>">
              <div class="conexiones__marca" aria-hidden="true"><?= e($inicialesClinica($clinica['nombre'])) ?></div>
              <div class="conexiones__datos"><h2><?= e($clinica['nombre']) ?></h2><p><?= e(TIPOS_INSTITUCION[$clinica['tipo']] ?? 'Clínica') ?><?= !empty($clinica['entidad']) && isset(ENTIDADES[$clinica['entidad']]) ? ' · ' . e(ENTIDADES[$clinica['entidad']]) : '' ?></p></div>
              <?php if ($estadoVinculo === 'conectada'): ?><span class="conexiones__estado conexiones__estado--chispa"><?= icono('chispa') ?><span>Conectada</span></span>
              <?php elseif ($estadoVinculo === 'pendiente'): ?><span class="conexiones__estado conexiones__estado--reloj"><?= icono('reloj') ?><span>Pendiente</span></span>
              <?php elseif ($estadoVinculo !== null): ?><span class="conexiones__estado"><?= e(ucfirst($estadoVinculo)) ?></span>
              <?php elseif (puede('configurar') && $migracionDisponible): ?><a class="conexiones__agregar" href="#solicitar-conexion" data-conexion-destino="<?= (int) $clinica['id'] ?>" title="Conectar con <?= e($clinica['nombre']) ?>"><?= icono('agregar') ?></a><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    
    <?php if (puede('configurar') && $migracionDisponible && $clinicas): ?>
      <details class="conexiones__solicitud" data-conexiones-panel>
        <summary>Nueva</summary>
      <form id="solicitar-conexion" method="post" enctype="multipart/form-data">
        <?= campoCsrf() ?><input type="hidden" name="accion" value="solicitar">
        <div class="conexiones__campos">
          <label class="campo"><span class="campo__etiqueta">Clínica</span><select class="campo__control" name="institucion_destino" required><option value="">Elige una clínica</option><?php foreach ($clinicas as $clinica): ?><option value="<?= (int) $clinica['id'] ?>"><?= e($clinica['nombre']) ?><?= !empty($clinica['clues']) ? ' · ' . e($clinica['clues']) : '' ?></option><?php endforeach; ?></select></label>
          <label class="campo"><span class="campo__etiqueta">Convenio PDF</span><input class="campo__control" type="file" name="convenio" accept="application/pdf,.pdf" required<?= !$canalProtegido ? ' disabled' : '' ?>></label>
        </div>
        <label class="conexiones__confirmacion"><input type="checkbox" name="convenio_confirmado" value="1" required> Convenio firmado por ambas.</label>
        
        <button class="boton boton--primario" type="submit"<?= !$canalProtegido ? ' disabled' : '' ?>>Enviar</button>
      </form>
      </details>
    <?php endif; ?>

  <section class="conexiones__seccion" id="vinculos" aria-label="Vínculos entre clínicas">
    <div class="conexiones__titulo-fila"><h2>Vínculos</h2></div>
    <div class="conexiones__lista">
      <?php foreach ($conexiones as $c): $esA = (int) $c['institucion_a_id'] === $institucion; $otra = $esA ? $c['clinica_b'] : $c['clinica_a']; $otraId = $esA ? (int) $c['institucion_b_id'] : (int) $c['institucion_a_id']; $esInvitada = (int) $c['solicitada_institucion_id'] !== $institucion; ?>
        <article class="conexiones__fila">
          <div class="conexiones__marca" aria-hidden="true"><?= icono('usuarios') ?></div>
          <div class="conexiones__datos"><h2><?= e($otra) ?></h2><p><?= e($c['estado'] === 'conectada' ? 'Conectada' : ucfirst($c['estado'])) ?></p><small><?= e($c['acuerdo_nombre'] ?: 'sin archivo') ?></small></div>
          <?php if ($c['acuerdo_archivo'] && puede('configurar')): ?><a class="boton-icono" href="<?= URL_BASE ?>/conexiones/archivo.php?tipo=convenio&id=<?= (int) $c['id'] ?>" title="Descargar convenio"><?= icono('documento') ?></a><?php endif; ?>
          <?php if ($c['estado'] === 'pendiente' && puede('configurar') && $esInvitada): ?>
            <form method="post" class="conexiones__acciones"><?= campoCsrf() ?><input type="hidden" name="conexion_id" value="<?= (int) $c['id'] ?>"><button class="boton boton--primario" name="accion" value="aceptar">Aceptar</button><button class="boton boton--sutil" name="accion" value="rechazar">Rechazar</button></form>
          <?php elseif ($c['estado'] === 'conectada' && puede('configurar')): ?>
            <form method="post" class="conexiones__acciones" onsubmit="return confirm('Revocar la conexión también invalida las autorizaciones activas. ¿Continuar?')"><?= campoCsrf() ?><input type="hidden" name="conexion_id" value="<?= (int) $c['id'] ?>"><button class="boton boton--sutil" name="accion" value="revocar">Revocar</button></form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      
    </div>
  </section>

  <section class="conexiones__seccion" id="autorizaciones" aria-label="Autorizaciones">
    <div class="conexiones__titulo-fila"><h2>Autorizaciones</h2></div>
    <?php if ($migracionDisponible && puede('historial') && puede('conexiones') && $canalProtegido && $conectadas): ?>
      <details class="conexiones__solicitud">
        <summary>Autorizar</summary>
      <form method="post" enctype="multipart/form-data">
        <?= campoCsrf() ?><input type="hidden" name="accion" value="autorizar_paciente">
        <div class="conexiones__campos">
          <label class="campo"><span class="campo__etiqueta">Paciente</span><select class="campo__control" name="paciente_id" required><option value="">Elige un paciente</option><?php $pacientes = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL ORDER BY primer_apellido, nombre LIMIT 2000'); $pacientes->execute([$institucion]); foreach ($pacientes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['folio'] . ' · ' . trim($p['nombre'] . ' ' . $p['primer_apellido'] . ' ' . $p['segundo_apellido'])) ?></option><?php endforeach; ?></select></label>
          <label class="campo"><span class="campo__etiqueta">Destino</span><select class="campo__control" name="institucion_destino" required><option value="">Elige clínica conectada</option><?php foreach ($conectadas as $c): $esA = (int) $c['institucion_a_id'] === $institucion; ?><option value="<?= $esA ? (int) $c['institucion_b_id'] : (int) $c['institucion_a_id'] ?>"><?= e($esA ? $c['clinica_b'] : $c['clinica_a']) ?></option><?php endforeach; ?></select></label>
          <label class="campo"><span class="campo__etiqueta">Alcance</span><select class="campo__control" name="alcance" required><option value="resumen">Resumen clínico</option><option value="expediente">Expediente completo</option></select></label>
          <label class="campo"><span class="campo__etiqueta">Finalidad</span><input class="campo__control" name="finalidad" maxlength="500" required placeholder="Continuidad de atención, interconsulta…"></label>
          <label class="campo"><span class="campo__etiqueta">Firma</span><input class="campo__control" type="date" name="firmado_en" required></label>
          <label class="campo"><span class="campo__etiqueta">Vigencia hasta</span><input class="campo__control" type="date" name="vence_en" required></label>
          <label class="campo"><span class="campo__etiqueta">PDF autorización</span><input class="campo__control" type="file" name="consentimiento" accept="application/pdf,.pdf" required></label>
        </div>
        <button class="boton boton--primario" type="submit">Registrar</button>
      </form>
      </details>
    <?php elseif (!$canalProtegido): ?><?php elseif (!$conectadas): ?><?php else: ?><?php endif; ?>
    <div class="conexiones__lista">
      <?php foreach ($autorizaciones as $a): ?><article class="conexiones__fila"><div class="conexiones__datos"><h2><?= e($a['paciente']) ?> · <?= e($a['folio']) ?></h2><p><?= e($a['clinica_destino']) ?> · <?= e($a['alcance'] === 'expediente' ? 'Expediente completo' : 'Resumen clínico') ?></p><small><?= e($a['finalidad']) ?> · vence <?= e(fechaCorta($a['vence_en'])) ?> <?= $a['revocado_en'] ? ' · Revocada' : '' ?></small></div><?php if ($canalProtegido && (puede('conexiones') || puede('historial'))): ?><a class="boton-icono" href="<?= URL_BASE ?>/conexiones/archivo.php?tipo=consentimiento&id=<?= (int) $a['id'] ?>" title="Descargar autorización"><?= icono('documento') ?></a><?php endif; ?><?php if (!$a['revocado_en'] && strtotime($a['vence_en']) > time() && puede('historial') && puede('conexiones')): ?><form method="post"><?= campoCsrf() ?><input type="hidden" name="accion" value="revocar_autorizacion"><input type="hidden" name="autorizacion_id" value="<?= (int) $a['id'] ?>"><button class="boton boton--sutil" type="submit">Revocar</button></form><?php endif; ?></article><?php endforeach; ?>
    </div>
  </section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>

