<?php
/** Consulta de solo lectura de un expediente compartido con autorización individual vigente. */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/conexiones.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';

exigirPermiso('ver_expediente');
exigirPermiso('conexiones');
if (!conexionesCanalProtegido()) {
    http_response_code(403);
    exit('La consulta requiere HTTPS o acceso local desde localhost en esta computadora.');
}
$demoPacienteId = filter_var($_GET['demo_paciente'] ?? 0, FILTER_VALIDATE_INT);
$modoDemo = $demoPacienteId !== false && $demoPacienteId > 0;
if ($modoDemo) {
    if (institucionId() !== 9 || !conexionesCanalProtegido()) {
        http_response_code(403);
        exit('La consulta DEMO requiere una sesión de la clínica destino y un canal HTTPS protegido.');
    }
    $consulta = db()->prepare('SELECT x.id AS conexion_id, p.id AS paciente_id, p.folio, p.nombre, p.primer_apellido,
                                      p.segundo_apellido, p.fecha_nacimiento, p.sexo, p.curp, p.domicilio,
                                      p.municipio_estado, p.alergias, p.grupo_sanguineo, p.padecimientos_cronicos,
                                      p.medicamentos_actuales, o.nombre AS clinica_origen, o.domicilio AS domicilio_origen,
                                      o.entidad AS entidad_origen, o.clues AS clues_origen, x.acuerdo_version
                                 FROM conexiones_clinicas x
                                 JOIN pacientes p ON p.institucion_id = x.institucion_a_id
                                                 AND p.id = ? AND p.eliminado_en IS NULL
                                 JOIN instituciones o ON o.id = x.institucion_a_id
                                WHERE x.institucion_a_id = 1 AND x.institucion_b_id = 9
                                  AND x.estado = \'conectada\' AND x.acuerdo_version = \'DEMO-prueba-no-legal-v1\'');
    $consulta->execute([$demoPacienteId]);
    $autorizacion = $consulta->fetch();
    if ($autorizacion) {
        $autorizacion['alcance'] = 'expediente';
        $autorizacion['finalidad'] = 'Consulta de prueba local, solo lectura; datos declarados ficticios, sin consentimiento individual.';
        $autorizacion['vence_en'] = '';
    }
} else {
    $autorizacionId = (int) ($_GET['autorizacion'] ?? 0);
    $consulta = db()->prepare('SELECT a.*, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido, p.fecha_nacimiento, p.sexo,
                                      p.curp, p.domicilio, p.municipio_estado, p.alergias, p.grupo_sanguineo, p.padecimientos_cronicos,
                                      p.medicamentos_actuales, o.nombre AS clinica_origen, o.domicilio AS domicilio_origen,
                                      o.entidad AS entidad_origen, o.clues AS clues_origen, x.acuerdo_version
                                 FROM conexiones_autorizaciones a
                                 JOIN conexiones_clinicas x ON x.id = a.conexion_id AND x.estado = \'conectada\'
                                 JOIN pacientes p ON p.id = a.paciente_id AND p.institucion_id = a.institucion_origen_id AND p.eliminado_en IS NULL
                                 JOIN instituciones o ON o.id = a.institucion_origen_id
                                WHERE a.id = ? AND a.institucion_destino_id = ? AND a.revocado_en IS NULL AND a.vence_en >= NOW()');
    $consulta->execute([$autorizacionId, institucionId()]);
    $autorizacion = $consulta->fetch();
}
if (!$autorizacion) {
    http_response_code(404);
    exit('No hay una autorización vigente para consultar este expediente.');
}
exigirPermiso('ver_expediente');
$paciente = trim($autorizacion['nombre'] . ' ' . $autorizacion['primer_apellido'] . ' ' . $autorizacion['segundo_apellido']);
$maxRegistros = $autorizacion['alcance'] === 'expediente' ? 2000 : 5;
$historial = db()->prepare('SELECT id, tipo, fecha, titulo, detalle, autor_nombre, autor_cedula FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha DESC, id DESC LIMIT ' . $maxRegistros);
$historial->execute([(int) $autorizacion['paciente_id']]);
$registros = $historial->fetchAll();
if ($modoDemo) {
    registrarBitacora('conexiones_clinicas', (int) $autorizacion['conexion_id'], 'acceso', 'consulta_demo_local', null, 'Expediente ' . $autorizacion['folio']);
} else {
    registrarBitacora('conexiones_autorizaciones', $autorizacionId, 'acceso', 'consulta_compartida', null, $autorizacion['alcance'] . ' · expediente ' . $autorizacion['folio']);
}

$tituloPagina = 'Expediente compartido · ' . $paciente;
$seccionActiva = 'pacientes';
$claseContenido = 'pagina--ancha conexiones__expediente';
$migas = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => $paciente]];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e($paciente) ?></h1>
  <p class="pagina__subtitulo">Expediente compartido por <?= e($autorizacion['clinica_origen']) ?> · <?= e($autorizacion['alcance'] === 'expediente' ? 'Expediente completo' : 'Resumen clínico') ?></p>
  <?php if ($modoDemo): ?>
    <p class="conexiones__modo">DEMO · DATOS DECLARADOS FICTICIOS · SIN CONSENTIMIENTO INDIVIDUAL · SOLO LECTURA · CANAL PROTEGIDO</p>
  <?php elseif (str_starts_with((string) $autorizacion['acuerdo_version'], 'DEMO-') || str_starts_with((string) $autorizacion['consentimiento_nombre'], 'DEMO-')): ?>
    <p class="conexiones__modo">DEMO · DOCUMENTOS NO FIRMADOS</p>
  <?php endif; ?>
  <p class="conexiones__nota"><?= $modoDemo ? e($autorizacion['finalidad']) : 'Finalidad autorizada: ' . e($autorizacion['finalidad']) . ' · Vigente hasta ' . e(fechaCorta($autorizacion['vence_en'])) ?></p>
</div>
<section class="seccion conexiones__ficha-compartida">
  <h2 class="seccion__titulo">Identificación del paciente</h2>
  <dl class="detalle-clinico__datos">
    <div><dt>Expediente</dt><dd><?= e($autorizacion['folio']) ?></dd></div>
    <div><dt>Edad</dt><dd><?= e(edad($autorizacion['fecha_nacimiento'])) ?> años</dd></div>
    <div><dt>Sexo</dt><dd><?= e(SEXOS[$autorizacion['sexo']] ?? '') ?></dd></div>
    <?php if ($autorizacion['curp']): ?><div><dt>CURP</dt><dd><?= e($autorizacion['curp']) ?></dd></div><?php endif; ?>
    <div><dt>Alergias</dt><dd><?= e($autorizacion['alergias'] ?: 'No registradas') ?></dd></div>
    <div><dt>Grupo sanguíneo</dt><dd><?= e($autorizacion['grupo_sanguineo'] ?: 'No registrado') ?></dd></div>
    <div><dt>Padecimientos crónicos</dt><dd><?= e($autorizacion['padecimientos_cronicos'] ?: 'No registrados') ?></dd></div>
    <div><dt>Medicamentos actuales</dt><dd><?= e($autorizacion['medicamentos_actuales'] ?: 'No registrados') ?></dd></div>
    <?php if ($autorizacion['alcance'] === 'expediente'): ?><div><dt>Domicilio</dt><dd><?= e(trim(($autorizacion['domicilio'] ?? '') . ($autorizacion['municipio_estado'] ? ' · ' . $autorizacion['municipio_estado'] : ''), ' ·')) ?></dd></div><?php endif; ?>
  </dl>
</section>
<section class="conexiones__historial-compartido">
  <h2>Notas clínicas<?= $autorizacion['alcance'] === 'resumen' ? ' recientes' : '' ?></h2>
  <?php foreach ($registros as $registro): ?>
    <article class="documento documento--<?= e($registro['tipo']) ?>">
      <header class="documento__cabecera"><div><h3 class="documento__titulo"><?= e(nombreTipoRegistro($registro['tipo'], 'corto')) ?> · <?= e($registro['titulo']) ?></h3><p><?= e(fechaCorta($registro['fecha'])) ?> · <?= e($registro['autor_nombre']) ?><?= $registro['autor_cedula'] ? ' · Cédula ' . e($registro['autor_cedula']) : '' ?></p></div></header>
      <div class="documento__contenido"><?php $modoDetalle = 'documento'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; ?></div>
    </article>
  <?php endforeach; ?>
  <?php if (!$registros): ?><p class="bd__vacio">No hay notas clínicas disponibles en el alcance autorizado.</p><?php endif; ?>
</section>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
