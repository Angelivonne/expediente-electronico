<?php
/**
 * Detalle de una entrada del historial clínico, de cualquier tipo (NOM-004).
 *
 * Variables antes de incluirlo:
 *   $registro     fila de historial_clinico (con "detalle" en JSON y "titulo", "fecha").
 *   $modoDetalle  'compacto' (acordeón del perfil; el título ya se ve en la cabecera) o
 *                 'documento' (pacientes/evento.php: documento a cuerpo completo, imprimible).
 *
 * Solo muestra lo capturado: los campos vacíos no aparecen. Las variables auxiliares llevan el
 * prefijo $dc y se eliminan al final, para no chocar con las de la página que lo incluye.
 */
require_once __DIR__ . '/../../datos/registros_clinicos.php';
require_once __DIR__ . '/bloques_nota.php';

$dcModo      = ($modoDetalle ?? 'compacto') === 'documento' ? 'documento' : 'compacto';
$dcDocumento = $dcModo === 'documento';
$dcD         = json_decode((string) ($registro['detalle'] ?? ''), true) ?: [];
$dcTipo      = (string) ($registro['tipo'] ?? '');
$dcH         = $dcDocumento ? 'h2' : 'h3';

// Bloques del panel de nota por campo: el texto plano del campo es la llave para reconocerlos.
$dcBloquesCampo = [];
foreach (($dcD['_bloques'] ?? []) as $dcClave => $dcJson) {
    $dcTextoCampo = trim((string) ($dcD[$dcClave] ?? ''));
    $dcLimpios = sanearBloquesNota($dcJson);
    if ($dcTextoCampo !== '' && $dcLimpios) {
        $dcBloquesCampo[$dcTextoCampo] = $dcLimpios;
    }
}

/** Bloque de texto con su encabezado; vacío si no hay contenido. Conserva el formato de bloques si existe. */
$dcBloque = function (string $titulo, $texto, string $clase = '') use ($dcH, $dcBloquesCampo): string {
    $texto = trim((string) ($texto ?? ''));
    if ($texto === '') {
        return '';
    }
    $cuerpo = isset($dcBloquesCampo[$texto])
        ? renderBloquesNota($dcBloquesCampo[$texto])
        : '<p class="detalle-clinico__texto">' . nl2br(e($texto)) . '</p>';
    return '<section class="detalle-clinico__bloque' . ($clase ? ' ' . $clase : '') . '">'
         . "<$dcH class=\"detalle-clinico__titulo\">" . e($titulo) . "</$dcH>"
         . $cuerpo . '</section>';
};

/** Datos breves en rejilla (etiqueta => valor); omite los vacíos. */
$dcDatos = function (array $pares): string {
    $pares = array_filter($pares, fn ($v) => trim((string) $v) !== '');
    if (!$pares) {
        return '';
    }
    $html = '<dl class="detalle-clinico__datos">';
    foreach ($pares as $etiqueta => $valor) {
        $html .= '<div><dt>' . e($etiqueta) . '</dt><dd>' . e((string) $valor) . '</dd></div>';
    }
    return $html . '</dl>';
};

/** Signos vitales con unidad, más el IMC calculado si hay peso y talla. */
$dcSignos = function (array $signos) use ($dcH): string {
    $filas = '';
    foreach (SIGNOS_VITALES as $clave => [$etiqueta, , $unidad]) {
        if (isset($signos[$clave]) && $signos[$clave] !== '') {
            $filas .= '<div><dt>' . e($etiqueta) . '</dt><dd>' . e((string) $signos[$clave]) . ' <small>' . e($unidad) . '</small></dd></div>';
        }
    }
    if (($imc = imcDesdeSignos($signos)) !== null) {
        $filas .= '<div><dt>Índice de masa corporal</dt><dd>' . e(number_format($imc, 1)) . ' <small>kg/m²</small></dd></div>';
    }
    return $filas === '' ? '' : '<section class="detalle-clinico__bloque">'
        . "<$dcH class=\"detalle-clinico__titulo\">Signos vitales</$dcH>"
        . '<dl class="detalle-clinico__signos">' . $filas . '</dl></section>';
};

/** Diagnósticos en renglones (con CIE-10 si se capturó) o el texto de las entradas anteriores. */
$dcDiagnosticos = function (array $d, string $titulo = 'Diagnósticos') use ($dcH, $dcBloque): string {
    $lista = array_filter($d['diagnosticos'] ?? [], fn ($x) => is_array($x) && ($x['descripcion'] ?? '') !== '');
    if (!$lista) {
        return $dcBloque($titulo === 'Diagnósticos' ? 'Diagnóstico' : $titulo, $d['diagnostico'] ?? '');
    }
    $html = '<section class="detalle-clinico__bloque">' . "<$dcH class=\"detalle-clinico__titulo\">" . e($titulo) . "</$dcH>"
          . '<ol class="detalle-clinico__diagnosticos">';
    foreach ($lista as $x) {
        $html .= '<li><span>' . e($x['descripcion']) . '</span>'
               . (!empty($x['cie10']) ? '<span class="detalle-clinico__cie">CIE-10 ' . e($x['cie10']) . '</span>' : '') . '</li>';
    }
    return $html . '</ol></section>';
};

/** Días entre dos fechas (estancia), o cadena vacía. */
$dcDias = function (?string $desde, ?string $hasta): string {
    if (!$desde || !$hasta) {
        return '';
    }
    $dias = (new DateTime(substr($desde, 0, 10)))->diff(new DateTime(substr($hasta, 0, 10)))->days;
    return $dias === 1 ? '1 día' : $dias . ' días';
};

$dcFecha = fn (?string $f): string => $f ? fechaCorta($f) : '';

// Cuerpo libre capturado con el editor de bloques (misma presentación que la sección de una nota)
$dcCuerpo = sanearBloquesNota($dcD['cuerpo'] ?? []);
$dcCuerpoHtml = $dcCuerpo
    ? '<section class="detalle-clinico__bloque detalle-clinico__cuerpo">'
      . "<$dcH class=\"detalle-clinico__titulo\">Nota</$dcH>" . renderBloquesNota($dcCuerpo) . '</section>'
    : '';
?>
<div class="detalle-clinico detalle-clinico--<?= $dcModo ?>" data-tipo="<?= e($dcTipo) ?>">
<?= $dcCuerpoHtml ?>
<?php switch ($dcTipo):
    case 'consulta': ?>
  <?= $dcDocumento ? $dcBloque('Motivo de consulta', $registro['titulo'] ?? '') : '' ?>
  <?= $dcDatos(['Tipo de atención' => $dcD['modalidad_consulta'] ?? '']) ?>
  <?= $dcBloque('Padecimiento actual', $dcD['padecimiento'] ?? '') ?>
  <?= $dcBloque('Interrogatorio por aparatos y sistemas', $dcD['aparatos_sistemas'] ?? '') ?>
  <?= $dcSignos($dcD['signos'] ?? []) ?>
  <?= $dcBloque('Exploración física', $dcD['exploracion'] ?? '') ?>
  <?= $dcBloque('Resultados relevantes de estudios', $dcD['resultados'] ?? '') ?>
  <?= $dcDiagnosticos($dcD) ?>
  <?= $dcBloque('Evolución', $dcD['nota'] ?? '') ?>
  <?= $dcBloque('Plan y tratamiento', $dcD['plan'] ?? '') ?>
  <?= $dcBloque('Pronóstico', $dcD['pronostico'] ?? '') ?>
<?php break;

    case 'urgencias': ?>
  <?= $dcDocumento ? $dcBloque('Motivo de la atención', $registro['titulo'] ?? '') : '' ?>
  <?= $dcDatos(['Destino del paciente' => $dcD['destino'] ?? '']) ?>
  <?= $dcBloque('Resumen del interrogatorio', $dcD['interrogatorio'] ?? '') ?>
  <?= $dcSignos($dcD['signos'] ?? []) ?>
  <?= $dcBloque('Exploración física', $dcD['exploracion'] ?? '') ?>
  <?= $dcBloque('Estado mental', $dcD['estado_mental'] ?? '') ?>
  <?= $dcBloque('Resultados relevantes de estudios', $dcD['resultados'] ?? '') ?>
  <?= $dcDiagnosticos($dcD, 'Diagnósticos o problemas clínicos') ?>
  <?= $dcBloque('Tratamiento', $dcD['plan'] ?? '') ?>
  <?= $dcBloque('Pronóstico', $dcD['pronostico'] ?? '') ?>
<?php break;

    case 'interconsulta': ?>
  <?= $dcDatos(['Servicio que solicita' => $dcD['servicio_solicitante'] ?? '', 'Servicio consultado' => $dcD['servicio_consultado'] ?? '']) ?>
  <?= $dcBloque('Motivo de la interconsulta', $dcD['motivo'] ?? '') ?>
  <?= $dcBloque('Resumen clínico', $dcD['resumen'] ?? '') ?>
  <?= $dcSignos($dcD['signos'] ?? []) ?>
  <?= $dcBloque('Criterios diagnósticos', $dcD['criterios'] ?? '') ?>
  <?= $dcBloque('Plan de estudios', $dcD['plan_estudios'] ?? '') ?>
  <?= $dcBloque('Sugerencias diagnósticas y de tratamiento', $dcD['sugerencias'] ?? '') ?>
  <?= $dcDiagnosticos($dcD) ?>
  <?= $dcBloque('Pronóstico', $dcD['pronostico'] ?? '') ?>
<?php break;

    case 'referencia': ?>
  <?= $dcDatos([
      'Tipo de envío' => $dcD['tipo_envio'] ?? '', 'Establecimiento que envía' => $dcD['establecimiento'] ?? '',
      'Establecimiento que recibe' => $dcD['destino'] ?? '', 'Servicio que recibe' => $dcD['servicio_destino'] ?? '',
  ]) ?>
  <?= $dcBloque('Motivo de envío', $dcD['motivo'] ?? '') ?>
  <?= $dcBloque('Resumen clínico', $dcD['resumen'] ?? '') ?>
  <?= $dcSignos($dcD['signos'] ?? []) ?>
  <?= $dcDiagnosticos($dcD, 'Impresión diagnóstica') ?>
  <?= $dcBloque('Terapéutica empleada', $dcD['terapeutica'] ?? '') ?>
<?php break;

    case 'egreso': ?>
  <?= $dcDatos([
      'Ingreso' => $dcFecha($dcD['ingreso'] ?? null), 'Egreso' => $dcFecha($registro['fecha'] ?? null),
      'Estancia' => $dcDias($dcD['ingreso'] ?? null, $registro['fecha'] ?? null),
      'Motivo del egreso' => $dcD['motivo_egreso'] ?? '', 'Servicio' => $dcD['servicio'] ?? '',
  ]) ?>
  <?= $dcDiagnosticos($dcD, 'Diagnósticos finales') ?>
  <?= $dcBloque('Resumen de la evolución y estado actual', $dcD['resumen'] ?? '') ?>
  <?= $dcBloque('Manejo durante la estancia', $dcD['manejo'] ?? '') ?>
  <?= $dcBloque('Problemas clínicos pendientes', $dcD['pendientes'] ?? '') ?>
  <?= $dcBloque('Plan de manejo y tratamiento', $dcD['plan'] ?? '') ?>
  <?= $dcBloque('Recomendaciones para la vigilancia ambulatoria', $dcD['recomendaciones'] ?? '') ?>
  <?= $dcBloque('Atención de factores de riesgo', $dcD['factores_riesgo'] ?? '') ?>
  <?= $dcBloque('Pronóstico', $dcD['pronostico'] ?? '') ?>
  <?= $dcBloque('Causas de la defunción', $dcD['causas_defuncion'] ?? '', 'detalle-clinico__bloque--alerta') ?>
<?php break;

    case 'receta': ?>
  <section class="detalle-clinico__bloque">
    <<?= $dcH ?> class="detalle-clinico__titulo">Prescripción</<?= $dcH ?>>
    <ol class="detalle-clinico__medicamentos">
      <?php foreach ($dcD['medicamentos'] ?? [] as $dcM): ?>
        <li>
          <b><?= e($dcM['nombre'] ?? '') ?></b><?php if (!empty($dcM['presentacion'])): ?> <span class="detalle-clinico__presentacion"><?= e($dcM['presentacion']) ?></span><?php endif; ?>
          <span class="detalle-clinico__indicacion"><?= e(implode(' · ', array_filter([
              $dcM['dosis'] ?? '', !empty($dcM['via']) ? 'vía ' . mb_strtolower($dcM['via']) : '', $dcM['periodicidad'] ?? '',
              !empty($dcM['duracion']) ? 'durante ' . $dcM['duracion'] : '', !empty($dcM['cantidad']) ? 'surtir ' . $dcM['cantidad'] : '',
              !empty($dcM['fecha_inicio']) ? 'inicio ' . $dcFecha($dcM['fecha_inicio']) : '', !empty($dcM['fecha_fin']) ? 'fin ' . $dcFecha($dcM['fecha_fin']) : '',
              !empty($dcM['estado_tratamiento']) ? 'estado ' . $dcM['estado_tratamiento'] : '',
          ]))) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?= $dcBloque('Indicaciones', $dcD['indicaciones'] ?? '') ?>
  <?php if (!$dcDocumento && !empty($dcD['prescriptor'])): ?>
    <?= $dcDatos(['Institución que expidió el título' => $dcD['prescriptor']['institucion'] ?? '', 'Establecimiento' => $dcD['prescriptor']['establecimiento'] ?? '']) ?>
  <?php endif; ?>
<?php break;

    case 'hospitalizacion': ?>
  <?= $dcDatos([
      'Ingreso' => $dcFecha($registro['fecha'] ?? null), 'Servicio' => $dcD['servicio'] ?? '', 'Cama' => $dcD['cama'] ?? '',
      'Egreso' => !empty($dcD['egreso']) ? fechaCorta($dcD['egreso']) : 'Hospitalizado',
      'Estancia' => $dcDias($registro['fecha'] ?? null, $dcD['egreso'] ?? null), 'Estado al egreso' => $dcD['estado_egreso'] ?? '',
  ]) ?>
  <?= $dcDocumento ? $dcBloque('Motivo de ingreso', $registro['titulo'] ?? '') : '' ?>
  <?= $dcBloque('Diagnóstico de ingreso', $dcD['diagnostico_ingreso'] ?? '') ?>
  <?= $dcBloque('Evolución', $dcD['evolucion'] ?? '') ?>
  <?= $dcBloque('Diagnóstico de egreso', $dcD['diagnostico'] ?? '') ?>
  <?= $dcBloque('Resumen de la estancia', $dcD['resumen'] ?? '') ?>
<?php break;

    case 'estudio': ?>
  <?= $dcDatos(['Tipo' => $dcD['categoria'] ?? '', 'Realizado en' => $dcD['realizado_en'] ?? '', 'Solicitante' => $dcD['solicitante'] ?? '', 'Fecha del resultado' => $dcFecha($registro['fecha'] ?? null)]) ?>
  <?= $dcDocumento ? $dcBloque('Estudio', $registro['titulo'] ?? '') : '' ?>
  <?= $dcBloque('Resultado', $dcD['resultado'] ?? '') ?>
  <?= $dcBloque('Valores fuera de rango', $dcD['fuera_rango'] ?? '', 'detalle-clinico__bloque--alerta') ?>
  <?= $dcBloque('Interpretación', $dcD['interpretacion'] ?? '') ?>
  <?php if (!empty($dcD['estudio_uid'])): ?>
    <p class="detalle-clinico__texto"><a href="<?= URL_BASE ?>/pacientes/visor.php?paciente=<?= (int) ($registro['paciente_id'] ?? 0) ?>&amp;estudio=<?= rawurlencode($dcD['estudio_uid']) ?>">Abrir archivos asociados en el visor</a></p>
  <?php endif; ?>
<?php break;

    case 'consentimiento': ?>
  <?= $dcDatos([
      'Decisión' => ($dcD['decision'] ?? '') === 'No acepta' ? 'No acepta el procedimiento' : (($dcD['decision'] ?? '') === 'Acepta' ? 'Acepta el procedimiento' : ''),
      'Otorga' => trim(($dcD['otorga'] ?? '') . (!empty($dcD['parentesco']) ? ' (' . $dcD['parentesco'] . ')' : '')),
      'Nombre de quien otorga' => $dcD['otorgante'] ?? '', 'Lugar' => $dcD['lugar'] ?? '',
  ]) ?>
  <?= $dcBloque('Acto autorizado', $dcD['procedimiento'] ?? ($dcDocumento ? ($registro['titulo'] ?? '') : '')) ?>
  <?= $dcBloque('En qué consiste', $dcD['descripcion'] ?? '') ?>
  <?= $dcBloque('Riesgos', $dcD['riesgos'] ?? '') ?>
  <?= $dcBloque('Beneficios esperados', $dcD['beneficios'] ?? '') ?>
  <?= $dcBloque('Alternativas', $dcD['alternativas'] ?? '') ?>
  <?php if (array_key_exists('contingencias', $dcD)): ?>
    <?= $dcBloque('Contingencias y urgencias', $dcD['contingencias']
        ? 'Autoriza al personal de salud a atender las contingencias y urgencias derivadas del acto autorizado, conforme al principio de libertad prescriptiva.'
        : 'No autoriza la atención de contingencias derivadas del acto autorizado sin una nueva carta de consentimiento.') ?>
  <?php endif; ?>
  <?php if (!$dcDocumento && !empty($dcD['testigos'])): ?>
    <?= $dcDatos(['Testigos' => implode(' y ', array_filter($dcD['testigos']))]) ?>
  <?php endif; ?>
<?php break;

    case 'incapacidad': ?>
  <?= $dcDatos([
      'Tipo de incapacidad' => $dcD['tipo_incapacidad'] ?? '', 'Días' => $dcD['dias'] ?? '',
      'Fecha de inicio' => $dcFecha($dcD['fecha_inicio'] ?? null), 'Fecha de fin' => $dcFecha($dcD['fecha_fin'] ?? null),
      'Fecha de la nota' => $dcFecha($registro['fecha'] ?? null),
  ]) ?>
  <?= $dcBloque('Diagnóstico / motivo', $dcD['motivo'] ?? '') ?>
  <?php if (!empty($dcD['diagnostico'])): ?>
    <?= $dcDiagnosticos($dcD, 'Diagnósticos') ?>
  <?php endif; ?>
  <?= $dcBloque('Observaciones', $dcD['observaciones'] ?? '') ?>
<?php break;

    default: ?>
  <?php
    $dcEtiquetas = ['fecha_cirugia' => 'Fecha de cirugía', 'procedimiento' => 'Procedimiento', 'tipo_intervencion' => 'Tipo de intervención',
        'riesgo_quirurgico' => 'Riesgo quirúrgico', 'plan_quirurgico' => 'Plan quirúrgico', 'cuidados' => 'Cuidados',
        'hallazgos' => 'Hallazgos', 'tecnica' => 'Técnica', 'sangrado' => 'Sangrado', 'complicaciones' => 'Complicaciones',
        'estado_final' => 'Estado final', 'evolucion' => 'Evolución', 'plan' => 'Plan', 'pronostico' => 'Pronóstico',
        'valoracion' => 'Valoración', 'medicamentos' => 'Medicamentos', 'observaciones' => 'Observaciones',
        'inicio' => 'Hora de inicio', 'fin' => 'Hora de fin', 'diagnostico' => 'Diagnóstico'];
    foreach (($dcD ?? []) as $dcClave => $dcValor) {
        if ($dcClave === 'diagnosticos' || is_array($dcValor) || $dcValor === '') continue;
        echo $dcBloque($dcEtiquetas[$dcClave] ?? ucfirst(str_replace('_', ' ', $dcClave)), (string) $dcValor);
    }
    if (!empty($dcD['diagnosticos'])) echo $dcDiagnosticos($dcD, 'Diagnósticos');
  ?>
<?php endswitch; ?>
</div>
<?php unset($dcModo, $dcDocumento, $dcD, $dcTipo, $dcH, $dcBloque, $dcBloquesCampo, $dcDatos, $dcSignos, $dcDiagnosticos, $dcDias, $dcFecha, $dcM, $dcCuerpo, $dcCuerpoHtml); ?>
