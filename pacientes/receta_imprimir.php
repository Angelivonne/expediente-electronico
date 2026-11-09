<?php
/**
 * FORMATO OFICIAL DE RECETA MÉDICA INSTITUCIONAL
 * Cumplimiento estricto de:
 * - Artículos 28, 29, 30 y 31 del Reglamento de Insumos para la Salud (RIS)
 * - NOM-004-SSA3-2012 (Del expediente clínico - Prescripción médica)
 * - NOM-072-SSA1-2012 (Etiquetado y denominación genérica obligatoria)
 * - NOM-024-SSA3-2012 (Sistemas de registro electrónico y firma digital)
 * - Disposiciones vigentes de COFEPRIS
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';

exigirPermiso('ver_expediente');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    mensajeFlash('error', 'Receta no especificada.');
    redirigir('/pacientes/');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT h.*, p.nombre, p.primer_apellido, p.segundo_apellido, p.folio, p.fecha_nacimiento,
            p.sexo, p.curp, p.alergias, u.nombre AS autor_nombre, u.cedula_profesional AS autor_cedula,
            u.firma AS autor_firma
     FROM historial_clinico h
     INNER JOIN pacientes p ON p.id = h.paciente_id
     LEFT JOIN usuarios u ON u.id = h.autor_id
     WHERE h.id = ? AND h.tipo = 'receta' AND p.eliminado_en IS NULL"
);
$stmt->execute([$id]);
$receta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$receta) {
    mensajeFlash('error', 'La receta médica solicitada no existe.');
    redirigir('/pacientes/');
}

$detalle = json_decode((string) $receta['detalle'], true) ?: [];
$prescriptor = $detalle['prescriptor'] ?? [];

// Datos del Paciente
$pacienteNombre = trim("{$receta['nombre']} {$receta['primer_apellido']} {$receta['segundo_apellido']}");
$pacienteFolio = $receta['folio'] ?: 'EXP-' . str_pad((string) $receta['paciente_id'], 6, '0', STR_PAD_LEFT);
$pacienteEdad = !empty($receta['fecha_nacimiento']) ? edad($receta['fecha_nacimiento']) : null;
$pacienteSexo = SEXOS[$receta['sexo']] ?? ($receta['sexo'] === 'M' ? 'Femenino' : 'Masculino');
$pacienteAlergias = trim((string) ($receta['alergias'] ?? ''));

// Datos del Establecimiento e Institución
$institucionId = $receta['institucion_id'] ?: institucionId();
$stmtInst = $pdo->prepare("SELECT * FROM instituciones WHERE id = ?");
$stmtInst->execute([$institucionId]);
$institucion = $stmtInst->fetch(PDO::FETCH_ASSOC) ?: [];

$nombreEstablecimiento = $prescriptor['establecimiento'] ?? ($institucion['nombre'] ?? config('nombre_establecimiento') ?: 'Expediente FEMECI');
$domicilioEstablecimiento = $prescriptor['domicilio'] ?? ($institucion['domicilio'] ?? config('domicilio_establecimiento') ?: '');
$telefonoEstablecimiento = $institucion['telefono'] ?? config('telefono_establecimiento') ?: '';
$cluesEstablecimiento = $institucion['clues'] ?? '';
$logoUrl = logoInstitucionUrl();

// Datos del Médico Prescriptor (Art. 29 RIS)
$medicoNombre = $prescriptor['nombre'] ?? ($receta['autor_nombre'] ?: 'Médico Tratante');
$medicoCedula = ($prescriptor['cedula'] ?? '') ?: ($receta['autor_cedula'] ?: 'En trámite');
$medicoInstitucionTitulo = $prescriptor['institucion'] ?? ($institucion['nombre'] ?? 'Universidad Nacional');
$medicoFirmaArchivo = (string) ($receta['autor_firma'] ?? '');
$medicoFirmaUrl = $medicoFirmaArchivo !== '' && is_file(__DIR__ . '/../' . $medicoFirmaArchivo) ? URL_BASE . '/' . $medicoFirmaArchivo : null;

// Buscar signos vitales y diagnóstico de la consulta más cercana
$stmtConsulta = $pdo->prepare(
    "SELECT fecha, titulo, detalle FROM historial_clinico
     WHERE paciente_id = ? AND tipo = 'consulta' AND fecha <= ?
     ORDER BY fecha DESC LIMIT 1"
);
$stmtConsulta->execute([$receta['paciente_id'], $receta['fecha'] . ' 23:59:59']);
$consultaCercana = $stmtConsulta->fetch(PDO::FETCH_ASSOC);

$signosVitales = [];
$diagnosticos = [];
if ($consultaCercana) {
    $detCons = json_decode((string) $consultaCercana['detalle'], true) ?: [];
    $signosVitales = $detCons['signos'] ?? [];
    if (!empty($detCons['diagnosticos']) && is_array($detCons['diagnosticos'])) {
        foreach ($detCons['diagnosticos'] as $dItem) {
            $c = trim((string) ($dItem['cie10'] ?? ''));
            $n = trim((string) ($dItem['diagnostico'] ?? ''));
            if ($c !== '' || $n !== '') {
                $diagnosticos[] = ($c !== '' ? "[$c] " : '') . $n;
            }
        }
    }
    if (empty($diagnosticos) && !empty($detCons['diagnostico'])) {
        $diagnosticos[] = $detCons['diagnostico'];
    }
}

// Medicamentos e Indicaciones
$medicamentos = $detalle['medicamentos'] ?? [];
$indicacionesGenerales = trim((string) ($detalle['indicaciones'] ?? ''));

// Firma digital y huella electrónica
$firmaEvento = firmaDeRegistroClinico((int) $receta['id']);
$huellaSeguridad = huellaRegistroClinico($receta);
$folioReceta = 'REC-' . str_pad((string) $receta['id'], 6, '0', STR_PAD_LEFT);
$urlVerificacion = URL_BASE . '/pacientes/verificar.php?huella=' . urlencode($huellaSeguridad);
$qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=115x115&margin=2&data=' . urlencode($urlVerificacion);

$fechaExpedicion = date('d/m/Y', strtotime($receta['fecha']));
$horaExpedicion = substr($receta['fecha'], 11, 5);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receta Médica Oficial - <?= e($folioReceta) ?> - <?= e($pacienteNombre) ?></title>
  <style>
    /* =====================================================================
       DISEÑO PROFESIONAL DE RECETA MÉDICA (NORMATIVA COFEPRIS / RIS / NOM-004)
       - Tipografía: Arial, Helvetica, sans-serif
       - Letra de alta legibilidad, contraste clínico estricto
       - Márgenes exactos para hoja Carta o Media Carta
       ===================================================================== */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      background-color: #525659;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      line-height: 1.35;
      color: #000000;
      padding: 20px 10px;
    }
    
    /* Barra Superior de Herramientas (No Imprimible) */
    .barra-impresion {
      max-width: 800px;
      margin: 0 auto 16px auto;
      background: #ffffff;
      padding: 10px 16px;
      border-radius: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    }
    .barra-impresion a,
    .barra-impresion button {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      font-weight: bold;
      padding: 7px 14px;
      border: 1px solid #000000;
      border-radius: 3px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      color: #000000;
    }
    .barra-impresion button.btn-primario {
      background: #000000;
      color: #ffffff;
    }
    .barra-impresion button:hover,
    .barra-impresion a:hover {
      background: #eeeeee;
    }
    .barra-impresion button.btn-primario:hover {
      background: #333333;
    }

    /* Hoja de Receta Médica */
    .contenedor-hoja {
      max-width: 800px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .receta-hoja {
      background: #ffffff;
      padding: 18mm 16mm;
      min-height: 250mm;
      box-shadow: 0 4px 18px rgba(0,0,0,0.3);
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      border: 1px solid #d0d0d0;
    }

    /* Encabezado Institucional y del Médico */
    .receta-encabezado {
      border-bottom: 2px solid #000000;
      padding-bottom: 10px;
      margin-bottom: 12px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 15px;
    }
    .receta-marca {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .receta-logo {
      max-height: 65px;
      max-width: 130px;
      object-fit: contain;
    }
    .receta-inst-titulo {
      font-size: 13pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #000000;
    }
    .receta-inst-sub {
      font-size: 8.5pt;
      color: #222222;
      margin-top: 1px;
    }
    .receta-medico-datos {
      text-align: right;
      font-size: 8.5pt;
      color: #000000;
      min-width: 220px;
    }
    .receta-medico-nombre {
      font-size: 11pt;
      font-weight: bold;
      text-transform: uppercase;
      color: #000000;
      margin-bottom: 2px;
    }
    .receta-folio-fecha {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #f4f4f4;
      border: 1px solid #000000;
      padding: 4px 8px;
      font-size: 9pt;
      font-weight: bold;
      margin-bottom: 10px;
    }

    /* Datos del Paciente */
    .receta-paciente-bloque {
      border: 1px solid #000000;
      padding: 8px 10px;
      margin-bottom: 12px;
      font-size: 9pt;
      background: #ffffff;
    }
    .receta-grid-paciente {
      display: grid;
      grid-template-columns: 3fr 1.2fr 1fr 1.2fr;
      gap: 4px 8px;
      margin-bottom: 6px;
    }
    .receta-grid-somatometria {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 6px;
      padding-top: 6px;
      border-top: 1px dashed #666666;
      font-size: 8.5pt;
    }
    .etiqueta-pac {
      font-weight: bold;
      text-transform: uppercase;
      font-size: 7.5pt;
      color: #333333;
      display: block;
    }
    .alergias-alerta {
      margin-top: 6px;
      padding: 4px 8px;
      background: #fafafa;
      border: 1px solid #000000;
      font-weight: bold;
      font-size: 8.5pt;
      text-transform: uppercase;
    }
    .alergias-alerta.sin-alergias {
      font-weight: normal;
      color: #333333;
    }

    /* Diagnóstico */
    .receta-diagnostico {
      font-size: 9pt;
      margin-bottom: 12px;
      padding: 4px 8px;
      background: #f9f9f9;
      border-left: 3px solid #000000;
    }

    /* Símbolo Rx / Prescripción */
    .receta-rx-simbolo {
      font-size: 20pt;
      font-weight: bold;
      font-family: "Times New Roman", Times, serif;
      margin-bottom: 4px;
      display: inline-block;
      color: #000000;
    }

    /* Medicamentos */
    .receta-medicamentos-lista {
      list-style: none;
      margin-bottom: 16px;
    }
    .receta-med-item {
      margin-bottom: 12px;
      padding-bottom: 8px;
      border-bottom: 1px dotted #bbbbbb;
      page-break-inside: avoid;
    }
    .receta-med-item:last-child {
      border-bottom: none;
    }
    .receta-med-linea1 {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      font-size: 10.5pt;
      font-weight: bold;
    }
    .receta-med-nombre {
      text-transform: uppercase;
    }
    .receta-med-cantidad {
      font-size: 9.5pt;
      font-weight: bold;
      color: #000000;
      white-space: nowrap;
    }
    .receta-med-linea2 {
      font-size: 9.5pt;
      margin-top: 2px;
      color: #111111;
      padding-left: 8px;
    }
    .receta-med-linea3 {
      font-size: 9pt;
      margin-top: 2px;
      color: #333333;
      padding-left: 8px;
      font-style: italic;
    }

    /* Indicaciones Generales */
    .receta-indicaciones-bloque {
      margin-top: 10px;
      padding: 8px 10px;
      border: 1px solid #000000;
      background: #fafafa;
      page-break-inside: avoid;
    }
    .receta-indicaciones-titulo {
      font-size: 8.5pt;
      font-weight: bold;
      text-transform: uppercase;
      margin-bottom: 4px;
      border-bottom: 1px solid #cccccc;
      padding-bottom: 2px;
    }
    .receta-indicaciones-texto {
      font-size: 9pt;
      white-space: pre-line;
      color: #000000;
    }

    /* Pie de Receta: Firma, QR y Leyenda */
    .receta-pie {
      margin-top: 20px;
      padding-top: 10px;
      border-top: 1.5px solid #000000;
      page-break-inside: avoid;
    }
    .receta-pie-grid {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 15px;
    }
    .receta-qr-caja {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .receta-qr-img {
      width: 75px;
      height: 75px;
      border: 1px solid #000000;
    }
    .receta-qr-texto {
      font-size: 7pt;
      color: #333333;
      max-width: 170px;
      line-height: 1.25;
    }
    .receta-firma-caja {
      text-align: center;
      width: 250px;
    }
    .receta-firma-trazo {
      max-height: 48px;
      max-width: 160px;
      object-fit: contain;
      margin-bottom: 2px;
    }
    .receta-firma-linea {
      border-top: 1px solid #000000;
      margin-bottom: 3px;
    }
    .receta-firma-medico {
      font-weight: bold;
      font-size: 9pt;
      text-transform: uppercase;
    }
    .receta-firma-cedula {
      font-size: 8pt;
      color: #000000;
    }
    .receta-leyenda-cofepris {
      margin-top: 10px;
      text-align: center;
      font-size: 7pt;
      color: #444444;
      line-height: 1.3;
      border-top: 1px solid #e0e0e0;
      padding-top: 4px;
    }

    /* Reglas de Impresión */
    @media print {
      body {
        background: transparent !important;
        padding: 0 !important;
      }
      .barra-impresion {
        display: none !important;
      }
      .contenedor-hoja {
        max-width: 100% !important;
        gap: 0 !important;
      }
      .receta-hoja {
        box-shadow: none !important;
        border: none !important;
        padding: 10mm 12mm !important;
        min-height: auto !important;
      }
      @page {
        size: letter portrait;
        margin: 8mm 10mm;
      }
    }
  </style>
</head>
<body>

  <!-- Barra de Acciones (No se imprime) -->
  <div class="barra-impresion">
    <div>
      <a href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $receta['paciente_id'] ?>#historial">← Volver al Expediente</a>
    </div>
    <div style="display: flex; gap: 8px;">
      <button type="button" class="btn-primario" onclick="window.print()">🖨️ Imprimir Receta Médica</button>
      <button type="button" onclick="document.body.classList.toggle('modo-duplicado')">📋 Ver Duplicado (Original / Copia)</button>
    </div>
  </div>

  <div class="contenedor-hoja">
    <!-- RECETA MÉDICA INSTITUCIONAL -->
    <article class="receta-hoja">
      <div>
        <!-- ENCABEZADO OFICIAL -->
        <header class="receta-encabezado">
          <div class="receta-marca">
            <?php if ($logoUrl): ?>
              <img src="<?= e($logoUrl) ?>" alt="Logo Institución" class="receta-logo">
            <?php endif; ?>
            <div>
              <div class="receta-inst-titulo"><?= e($nombreEstablecimiento) ?></div>
              <?php if ($domicilioEstablecimiento): ?>
                <div class="receta-inst-sub"><?= e($domicilioEstablecimiento) ?></div>
              <?php endif; ?>
              <div class="receta-inst-sub">
                <?= $telefonoEstablecimiento ? 'Tel: ' . e($telefonoEstablecimiento) . ' · ' : '' ?>
                <?= $cluesEstablecimiento ? 'CLUES: ' . e($cluesEstablecimiento) . ' · ' : '' ?>
                Expediente Clínico Electrónico NOM-004-SSA3-2012
              </div>
            </div>
          </div>
          <div class="receta-medico-datos">
            <div class="receta-medico-nombre">Dr(a). <?= e($medicoNombre) ?></div>
            <div><strong>Céd. Profesional:</strong> <?= e($medicoCedula) ?></div>
            <?php if (!empty($prescriptor['cedula_especialidad'])): ?>
              <div><strong>Céd. Especialidad:</strong> <?= e($prescriptor['cedula_especialidad']) ?></div>
            <?php endif; ?>
            <div><strong>Egresado de:</strong> <?= e($medicoInstitucionTitulo) ?></div>
          </div>
        </header>

        <!-- FOLIO Y FECHA -->
        <div class="receta-folio-fecha">
          <span>FOLIO DE RECETA: <strong><?= e($folioReceta) ?></strong></span>
          <span>EXPEDICIÓN: <strong><?= e($fechaExpedicion) ?> (<?= e($horaExpedicion) ?> hrs)</strong></span>
        </div>

        <!-- DATOS DEL PACIENTE -->
        <section class="receta-paciente-bloque">
          <div class="receta-grid-paciente">
            <div>
              <span class="etiqueta-pac">Nombre del Paciente</span>
              <strong><?= e($pacienteNombre) ?></strong>
            </div>
            <div>
              <span class="etiqueta-pac">No. Expediente</span>
              <span><?= e($pacienteFolio) ?></span>
            </div>
            <div>
              <span class="etiqueta-pac">Edad / Sexo</span>
              <span><?= $pacienteEdad !== null ? $pacienteEdad . ' años' : '—' ?> · <?= e($pacienteSexo) ?></span>
            </div>
            <div>
              <span class="etiqueta-pac">CURP</span>
              <span><?= e($receta['curp'] ?: '—') ?></span>
            </div>
          </div>

          <?php
            $peso = !empty($signosVitales['peso']) ? $signosVitales['peso'] . ' kg' : '—';
            $talla = !empty($signosVitales['talla']) ? $signosVitales['talla'] . ' m' : '—';
            $ta = (!empty($signosVitales['sistolica']) && !empty($signosVitales['diastolica']))
                  ? $signosVitales['sistolica'] . '/' . $signosVitales['diastolica'] . ' mmHg' : '—';
            $temp = !empty($signosVitales['temperatura']) ? $signosVitales['temperatura'] . ' °C' : '—';
            $imc = !empty($signosVitales['imc']) ? $signosVitales['imc'] . ' kg/m²' : '—';
          ?>
          <div class="receta-grid-somatometria">
            <div><span class="etiqueta-pac">Peso:</span> <?= e($peso) ?></div>
            <div><span class="etiqueta-pac">Talla:</span> <?= e($talla) ?></div>
            <div><span class="etiqueta-pac">T.A.:</span> <?= e($ta) ?></div>
            <div><span class="etiqueta-pac">Temp:</span> <?= e($temp) ?></div>
            <div><span class="etiqueta-pac">IMC:</span> <?= e($imc) ?></div>
          </div>

          <div class="alergias-alerta <?= empty($pacienteAlergias) || sinAlergiasRegistradas($pacienteAlergias) ? 'sin-alergias' : '' ?>">
            <strong>ALERGIAS:</strong> <?= !empty($pacienteAlergias) && !sinAlergiasRegistradas($pacienteAlergias) ? e($pacienteAlergias) : 'INTERROGADAS Y NEGADAS' ?>
          </div>
        </section>

        <!-- DIAGNÓSTICO -->
        <?php if (!empty($diagnosticos)): ?>
          <div class="receta-diagnostico">
            <strong>Diagnóstico Clínico (CIE-10):</strong> <?= e(implode(' · ', $diagnosticos)) ?>
          </div>
        <?php endif; ?>

        <!-- CUERPO DE PRESCRIPCIÓN MÉDICA -->
        <div class="receta-rx-simbolo">℞</div>

        <ul class="receta-medicamentos-lista">
          <?php if (empty($medicamentos)): ?>
            <li class="receta-med-item"><em>No se registraron renglones de medicamentos en esta receta.</em></li>
          <?php else: ?>
            <?php foreach ($medicamentos as $idx => $m): ?>
              <li class="receta-med-item">
                <div class="receta-med-linea1">
                  <span class="receta-med-nombre"><?= ($idx + 1) . '. ' . e($m['nombre'] ?? '') ?><?= !empty($m['presentacion']) ? ' (' . e($m['presentacion']) . ')' : '' ?></span>
                  <?php if (!empty($m['cantidad'])): ?>
                    <span class="receta-med-cantidad">CANTIDAD: <?= e($m['cantidad']) ?></span>
                  <?php endif; ?>
                </div>
                <div class="receta-med-linea2">
                  <strong>Dosificación y Vía:</strong> <?= e($m['dosis'] ?? '') ?>
                  <?= !empty($m['via']) ? ' · Vía ' . mb_strtolower(e($m['via'])) : '' ?>
                  <?= !empty($m['periodicidad']) ? ' · ' . e($m['periodicidad']) : '' ?>
                  <?= !empty($m['duracion']) ? ' · Durante ' . e($m['duracion']) : '' ?>
                </div>
                <?php if (!empty($m['indicaciones_toma'])): ?>
                  <div class="receta-med-linea3"><?= e($m['indicaciones_toma']) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>

        <!-- INDICACIONES GENERALES / CUIDADOS -->
        <?php if (!empty($indicacionesGenerales)): ?>
          <section class="receta-indicaciones-bloque">
            <div class="receta-indicaciones-titulo">Indicaciones y Cuidados Generales</div>
            <div class="receta-indicaciones-texto"><?= e($indicacionesGenerales) ?></div>
          </section>
        <?php endif; ?>
      </div>

      <!-- PIE DE RECETA: FIRMA, QR Y COFEPRIS -->
      <footer class="receta-pie">
        <div class="receta-pie-grid">
          <div class="receta-qr-caja">
            <img src="<?= e($qrCodeUrl) ?>" alt="QR Validación" class="receta-qr-img">
            <div class="receta-qr-texto">
              <strong>Verificación Digital:</strong><br>
              Escanea para validar autenticidad en el sistema clínico institucional.<br>
              Huella: <code><?= substr($huellaSeguridad, 0, 16) ?>...</code>
            </div>
          </div>

          <div class="receta-firma-caja">
            <?php if ($medicoFirmaUrl): ?>
              <img src="<?= e($medicoFirmaUrl) ?>" alt="Firma Médico" class="receta-firma-trazo">
            <?php else: ?>
              <div style="height: 38px;"></div>
            <?php endif; ?>
            <div class="receta-firma-linea"></div>
            <div class="receta-firma-medico">Dr(a). <?= e($medicoNombre) ?></div>
            <div class="receta-firma-cedula">Cédula Profesional: <?= e($medicoCedula) ?></div>
            <div style="font-size: 7.5pt; color: #555555;">Firma de quien prescribe</div>
          </div>
        </div>

        <div class="receta-leyenda-cofepris">
          Receta médica expedida de conformidad con los Artículos 28, 29, 30 y 31 del Reglamento de Insumos para la Salud y la NOM-004-SSA3-2012.<br>
          Vigencia máxima de 30 días a partir de su expedición. Prohibida su venta o surtimiento sin prescripción médica. No se deje al alcance de los niños.
        </div>
      </footer>
    </article>
  </div>

</body>
</html>
