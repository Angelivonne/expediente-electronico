<?php
/**
 * Aviso de privacidad (LFPDPPP). Página pública, sin sesión.
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/app/nucleo/inicio.php';
require_once __DIR__ . '/app/servicios/cuenta/instituciones.php';

$tituloPagina = 'Aviso de privacidad';
$institucionAviso = null;
$institucionesAviso = [];
$idInstitucionAviso = filter_input(INPUT_GET, 'institucion', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if ($idInstitucionAviso) {
    $consultaInstitucionAviso = db()->prepare('SELECT nombre, tipo, domicilio, entidad FROM instituciones WHERE id = ?');
    $consultaInstitucionAviso->execute([$idInstitucionAviso]);
    $institucionAviso = $consultaInstitucionAviso->fetch() ?: null;
} elseif (institucionId() !== null) {
    $institucionAviso = institucionActual();
} else {
    $institucionesAviso = db()->query('SELECT id, nombre, tipo, domicilio, entidad FROM instituciones ORDER BY nombre')->fetchAll();
    if (count($institucionesAviso) === 1) {
        $institucionAviso = $institucionesAviso[0];
        $institucionesAviso = [];
    }
}
$domicilioAviso = $institucionAviso
    ? trim(($institucionAviso['domicilio'] ?? '') . (isset(ENTIDADES[$institucionAviso['entidad'] ?? '']) ? ' · ' . ENTIDADES[$institucionAviso['entidad']] : ''), ' ·')
    : '';
$claseContenido = 'acceso__contenido--aviso';
$claseVentana = 'acceso__ventana--aviso';
$hojasAcceso = ['css/modulos/aviso_privacidad.css'];
require __DIR__ . '/app/vistas/plantilla/acceso_encabezado.php';
?>
<h1 class="acceso__titulo">Aviso de privacidad</h1>
<p class="acceso__subtitulo">Protección de datos personales en el expediente clínico.</p>

<div class="aviso-privacidad__texto">
  <?php if ($institucionAviso): ?>
    <p><b>Responsable.</b> <?= e($institucionAviso['nombre']) ?>, <?= e(TIPOS_INSTITUCION[$institucionAviso['tipo']] ?? 'establecimiento de atención médica') ?>, con domicilio en <?= e($domicilioAviso ?: 'domicilio no capturado') ?>, es responsable del tratamiento de los datos del expediente que genera.</p>
  <?php else: ?>
    <p><b>Responsable.</b> Selecciona la clínica que integra el expediente para consultar sus datos y este aviso de privacidad.</p>
    <?php if ($institucionesAviso): ?>
      <ul class="aviso-privacidad__instituciones">
        <?php foreach ($institucionesAviso as $opcionInstitucion): ?>
          <li><a href="<?= URL_BASE ?>/aviso-privacidad.php?institucion=<?= (int) $opcionInstitucion['id'] ?>"><?= e($opcionInstitucion['nombre']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>La clínica aún no ha registrado sus datos institucionales.</p>
    <?php endif; ?>
  <?php endif; ?>
  <p><b>Datos tratados.</b> Datos de identificación y contacto, domicilio, fecha de nacimiento, CURP, antecedentes, diagnóstico, tratamiento, recetas, estudios y demás información clínica. Los datos de salud son sensibles.</p>
  <p><b>Finalidades necesarias.</b> Identificar al paciente; prestar y dar seguimiento a la atención; integrar, conservar y consultar el expediente clínico; elaborar notas, recetas, solicitudes y resultados; coordinar citas y al personal que participa en la atención; y cumplir obligaciones sanitarias, administrativas y legales.</p>
  <p><b>Finalidades que requieren autorización específica.</b> Compartir información clínica con otra institución para continuidad de atención se hará mediante autorización documentada para el paciente, la clínica receptora, la información y la finalidad. Una autorización general no sustituye el consentimiento informado de un procedimiento médico.</p>
  <p><b>Transferencias.</b> La información podrá comunicarse a autoridades competentes cuando una disposición aplicable lo permita o exija. Para una transferencia a otra clínica se identificará a la receptora y se recabará la autorización que corresponda, salvo los supuestos legales aplicables.</p>
  <p><b>Cómo limitar uso o divulgación.</b> La persona titular puede solicitar que sus datos no se usen para finalidades opcionales o que se limite su divulgación, mediante escrito dirigido al responsable en el domicilio de la clínica indicada arriba. Esto no limita los usos necesarios para la atención ni las obligaciones de conservación que establezca la ley.</p>
  <p><b>Derechos ARCO y revocación.</b> La persona titular o su representante puede solicitar acceso, rectificación, cancelación u oposición, o revocar su consentimiento cuando proceda. Presente un escrito en el domicilio del responsable indicando su nombre, medio para recibir respuesta, derecho que solicita, datos relacionados y documento para acreditar identidad o representación. La cancelación puede estar limitada por obligaciones de conservación del expediente.</p>
  <p><b>Conservación y protección.</b> El expediente se conserva al menos cinco años desde el último acto médico, conforme a la NOM-004-SSA3-2012 y las disposiciones aplicables. El acceso al sistema se controla por cuenta y permisos; el personal debe guardar confidencialidad. Se aplican medidas administrativas, técnicas y físicas de resguardo.</p>
  <p><b>Cambios al aviso.</b> La versión vigente se publica en esta página. Cuando haya cambios sustanciales se comunicarán por los medios disponibles de la institución antes de aplicarlos a nuevas finalidades.</p>
  <p class="aviso-privacidad__fecha">Aviso vigente desde el 5 de noviembre de 2026.</p>
</div>

<p class="alta__ayuda" style="margin-top:16px"><a href="<?= URL_BASE ?>/acceso/">Volver al inicio de sesión</a></p>

<?php require __DIR__ . '/app/vistas/plantilla/acceso_pie.php'; ?>
