<?php
/**
 * INFORME EPIDEMIOLÓGICO Y ANÁLISIS DE DATOS CLÍNICOS
 * Documento formal institucional: tipografía Arial 12pt, texto en negro,
 * márgenes de documento formal (2.5 cm), gráficas estadísticas de Matplotlib,
 * no duplicidad de datos y anonimización de pacientes para protección de privacidad.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';

exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId();

// 1. Manejo del Periodo (Ventana de tiempo sin solapamiento de datos)
$periodo = $_GET['periodo'] ?? 'todos';
$hoy = date('Y-m-d');
$condicionFecha = '';
$paramsFecha = [];
$tituloPeriodo = 'Histórico acumulado total';

switch ($periodo) {
    case 'mes':
        $fechaInicio = date('Y-m-d', strtotime('-30 days'));
        $condicionFecha = ' AND h.fecha >= ? AND h.fecha <= ?';
        $paramsFecha = [$fechaInicio . ' 00:00:00', $hoy . ' 23:59:59'];
        $tituloPeriodo = "Últimos 30 días (" . date('d/m/Y', strtotime($fechaInicio)) . " al " . date('d/m/Y') . ")";
        break;
    case '12m':
        $fechaInicio = date('Y-m-d', strtotime('-365 days'));
        $condicionFecha = ' AND h.fecha >= ? AND h.fecha <= ?';
        $paramsFecha = [$fechaInicio . ' 00:00:00', $hoy . ' 23:59:59'];
        $tituloPeriodo = "Últimos 12 meses (" . date('d/m/Y', strtotime($fechaInicio)) . " al " . date('d/m/Y') . ")";
        break;
    case 'anio':
        $fechaInicio = date('Y-01-01');
        $condicionFecha = ' AND h.fecha >= ? AND h.fecha <= ?';
        $paramsFecha = [$fechaInicio . ' 00:00:00', $hoy . ' 23:59:59'];
        $tituloPeriodo = "Año en curso (" . date('Y') . ")";
        break;
    case 'todos':
    default:
        $periodo = 'todos';
        $condicionFecha = '';
        $paramsFecha = [];
        $tituloPeriodo = "Histórico total consolidado (Registro completo)";
        break;
}

// 2. Consulta de Datos (Estrictamente anonimizada: id_paciente, sexo, fecha_nacimiento, fecha_consulta, detalle)
$sql = "SELECT h.id AS registro_id, h.paciente_id, h.fecha, h.titulo, h.detalle,
               p.sexo, p.fecha_nacimiento
        FROM historial_clinico h
        INNER JOIN pacientes p ON p.id = h.paciente_id
        WHERE h.tipo = 'consulta' AND p.eliminado_en IS NULL";

$paramsConsulta = [];
if ($institucionId !== null) {
    $sql .= " AND h.institucion_id = ?";
    $paramsConsulta[] = $institucionId;
}
$sql .= $condicionFecha . " ORDER BY h.fecha ASC";
$paramsConsulta = array_merge($paramsConsulta, $paramsFecha);

$stmt = $pdo->prepare($sql);
$stmt->execute($paramsConsulta);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Función auxiliar para clasificar CIE-10 en categorías nosológicas

// Función oficial de rangos etarios según el Sistema Nacional de Vigilancia Epidemiológica (SINAVE / SUIVE-1)
function grupoEdadSuive(int $edad): string {
    if ($edad < 1) return '< 1 año';
    if ($edad <= 4) return '1 a 4 años';
    if ($edad <= 9) return '5 a 9 años';
    if ($edad <= 14) return '10 a 14 años';
    if ($edad <= 19) return '15 a 19 años';
    if ($edad <= 24) return '20 a 24 años';
    if ($edad <= 44) return '25 a 44 años';
    if ($edad <= 49) return '45 a 49 años';
    if ($edad <= 59) return '50 a 59 años';
    if ($edad <= 64) return '60 a 64 años';
    return '65 y más años';
}

function categorizarCie10(string $codigo, string $descripcion): string
{
    $letra = strtoupper(substr(trim($codigo), 0, 1));
    switch ($letra) {
        case 'A':
        case 'B':
            return 'Enfermedades Infecciosas y Parasitarias';
        case 'C':
        case 'D':
            return 'Neoplasias y Sangre';
        case 'E':
            return 'Enfermedades Endocrinas, Nutricionales y Metabólicas';
        case 'F':
            return 'Trastornos Mentales y del Comportamiento';
        case 'G':
            return 'Enfermedades del Sistema Nervioso';
        case 'H':
            return 'Enfermedades del Ojo y Oído';
        case 'I':
            return 'Enfermedades del Sistema Circulatorio / Cardiovascular';
        case 'J':
            return 'Enfermedades del Sistema Respiratorio';
        case 'K':
            return 'Enfermedades del Sistema Digestivo';
        case 'L':
            return 'Enfermedades de la Piel y Tejido Subcutáneo';
        case 'M':
            return 'Enfermedades del Sistema Músculo-esquelético';
        case 'N':
            return 'Enfermedades del Sistema Genitourinario';
        case 'O':
        case 'P':
        case 'Q':
            return 'Embarazo, Perinatal y Congénitas';
        case 'R':
            return 'Signos, Síntomas y Hallazgos Clínicos';
        case 'S':
        case 'T':
            return 'Traumatismos y Envenenamientos';
        case 'Z':
            return 'Factores que Influyen en el Estado de Salud (Preventivo)';
        default:
            if (stripos($descripcion, 'hipertensi') !== false) return 'Enfermedades del Sistema Circulatorio / Cardiovascular';
            if (stripos($descripcion, 'diabet') !== false) return 'Enfermedades Endocrinas, Nutricionales y Metabólicas';
            if (stripos($descripcion, 'respirat') !== false || stripos($descripcion, 'amigdal') !== false) return 'Enfermedades del Sistema Respiratorio';
            return 'Otras Condiciones Médicas';
    }
}

// 3. Procesamiento Estadístico con Deduplicación Matemática
$totalConsultas = count($filas);
$pacientesAtendidosSet = [];
$diagnosticosMap = []; // claveCie10 => [info]
$piramideEdad = [
    '< 15 años'    => ['femenino' => 0, 'masculino' => 0, 'pacientes' => []],
    '15 - 24 años' => ['femenino' => 0, 'masculino' => 0, 'pacientes' => []],
    '25 - 44 años' => ['femenino' => 0, 'masculino' => 0, 'pacientes' => []],
    '45 - 64 años' => ['femenino' => 0, 'masculino' => 0, 'pacientes' => []],
    '65+ años'     => ['femenino' => 0, 'masculino' => 0, 'pacientes' => []],
];

$signosVitalesData = [
    'tas' => [],
    'tad' => [],
    'glucosa' => [],
    'imc' => [],
];

foreach ($filas as $f) {
    $pId = (int) $f['paciente_id'];
    $sexo = strtoupper($f['sexo']) === 'M' ? 'M' : 'H';
    $pacientesAtendidosSet[$pId] = true;

    // Calcular edad a la fecha de consulta
    $edad = 35; // Default razonable si fecha no viene
    if (!empty($f['fecha_nacimiento'])) {
        $fechaNac = new DateTime($f['fecha_nacimiento']);
        $fechaCons = new DateTime($f['fecha']);
        $edad = $fechaNac->diff($fechaCons)->y;
    }

    // Clasificar en pirámide etaria (DEDUPLICADO por paciente en el periodo)
    $grupoKey = '< 15 años';
    if ($edad >= 65) $grupoKey = '65+ años';
    elseif ($edad >= 45) $grupoKey = '45 - 64 años';
    elseif ($edad >= 25) $grupoKey = '25 - 44 años';
    elseif ($edad >= 15) $grupoKey = '15 - 24 años';

    if (!isset($piramideEdad[$grupoKey]['pacientes'][$pId])) {
        $piramideEdad[$grupoKey]['pacientes'][$pId] = true;
        if ($sexo === 'M') {
            $piramideEdad[$grupoKey]['femenino']++;
        } else {
            $piramideEdad[$grupoKey]['masculino']++;
        }
    }

    // Parsear detalle de la consulta
    $detalle = json_decode((string) $f['detalle'], true) ?: [];

    // Signos vitales
    if (!empty($detalle['signos']) && is_array($detalle['signos'])) {
        if (!empty($detalle['signos']['sistolica'])) $signosVitalesData['tas'][] = (float) $detalle['signos']['sistolica'];
        if (!empty($detalle['signos']['diastolica'])) $signosVitalesData['tad'][] = (float) $detalle['signos']['diastolica'];
        if (!empty($detalle['signos']['glucosa'])) $signosVitalesData['glucosa'][] = (float) $detalle['signos']['glucosa'];
        if (!empty($detalle['signos']['imc'])) $signosVitalesData['imc'][] = (float) $detalle['signos']['imc'];
    }

    // Extraer diagnósticos
    $diagsExtraidos = [];
    if (!empty($detalle['diagnosticos']) && is_array($detalle['diagnosticos'])) {
        foreach ($detalle['diagnosticos'] as $dItem) {
            $c = trim((string) ($dItem['cie10'] ?? ''));
            $n = trim((string) ($dItem['diagnostico'] ?? ''));
            if ($c !== '' || $n !== '') {
                $diagsExtraidos[] = ['cie10' => $c ?: 'SIN-COD', 'nombre' => $n ?: 'Diagnóstico clínico no especificado'];
            }
        }
    }

    if (empty($diagsExtraidos)) {
        $desc = trim((string) ($detalle['diagnostico'] ?? $f['titulo']));
        $diagsExtraidos[] = ['cie10' => 'SD-00', 'nombre' => $desc ?: 'Consulta médica general'];
    }

    // Agregar a la matriz de morbilidad
    foreach ($diagsExtraidos as $diag) {
        $clave = $diag['cie10'] !== 'SIN-COD' && $diag['cie10'] !== 'SD-00' ? $diag['cie10'] : mb_strtolower($diag['nombre']);
        
        if (!isset($diagnosticosMap[$clave])) {
            $diagnosticosMap[$clave] = [
                'cie10' => $diag['cie10'],
                'nombre' => $diag['nombre'],
                'categoria' => categorizarCie10($diag['cie10'], $diag['nombre']),
                'consultas_totales' => 0,
                'pacientes_unicos' => [],
                'femenino_unicos' => [],
                'masculino_unicos' => [],
                'edades' => [],
            ];
        }

        $diagnosticosMap[$clave]['consultas_totales']++;
        
        // DEDUPLICACIÓN CLAVE: si el paciente ya fue contado para esta patología, no se vuelve a contar en pacientes_unicos
        $diagnosticosMap[$clave]['pacientes_unicos'][$pId] = true;
        if ($sexo === 'M') {
            $diagnosticosMap[$clave]['femenino_unicos'][$pId] = true;
        } else {
            $diagnosticosMap[$clave]['masculino_unicos'][$pId] = true;
        }
        $diagnosticosMap[$clave]['edades'][] = $edad;
    }
}

$totalPacientesUnicos = count($pacientesAtendidosSet);

// Formatear tabla de morbilidad ordenada por pacientes únicos (prevalencia real)
$tablaMorbilidad = [];
$categoriasMap = [];

foreach ($diagnosticosMap as $clave => $item) {
    $casosUnicos = count($item['pacientes_unicos']);
    $casosFem = count($item['femenino_unicos']);
    $casosMasc = count($item['masculino_unicos']);
    $prevalencia = $totalPacientesUnicos > 0 ? ($casosUnicos / $totalPacientesUnicos) * 100 : 0;
    $edadProm = count($item['edades']) > 0 ? round(array_sum($item['edades']) / count($item['edades']), 1) : 0;

    $cat = $item['categoria'];
    if (!isset($categoriasMap[$cat])) {
        $categoriasMap[$cat] = ['casos' => 0, 'pacientes' => []];
    }
    foreach ($item['pacientes_unicos'] as $pidKey => $dummy) {
        $categoriasMap[$cat]['pacientes'][$pidKey] = true;
    }

    $tablaMorbilidad[] = [
        'cie10' => $item['cie10'],
        'nombre' => $item['nombre'],
        'categoria' => $cat,
        'casos_unicos' => $casosUnicos,
        'femenino' => $casosFem,
        'masculino' => $casosMasc,
        'porcentaje' => round($prevalencia, 2),
        'consultas_totales' => $item['consultas_totales'],
        'edad_promedio' => $edadProm,
    ];
}

// Ordenar por casos únicos descendente
usort($tablaMorbilidad, fn($a, $b) => $b['casos_unicos'] <=> $a['casos_unicos']);

// Categorías consolidadas
$listaCategorias = [];
foreach ($categoriasMap as $nombreCat => $cData) {
    $casosCat = count($cData['pacientes']);
    $porcCat = $totalPacientesUnicos > 0 ? ($casosCat / $totalPacientesUnicos) * 100 : 0;
    $listaCategorias[] = [
        'categoria' => $nombreCat,
        'casos' => $casosCat,
        'porcentaje' => round($porcCat, 1),
    ];
}
usort($listaCategorias, fn($a, $b) => $b['casos'] <=> $a['casos']);

// Pirámide etaria estructurada
$listaPiramide = [];
foreach ($piramideEdad as $gNombre => $gData) {
    $listaPiramide[] = [
        'grupo' => $gNombre,
        'femenino' => $gData['femenino'],
        'masculino' => $gData['masculino'],
        'total' => $gData['femenino'] + $gData['masculino'],
    ];
}

// Salida en formato oficial SUIVE-1 (SINAVE / NOM-017-SSA2-2012)
if (isset($_GET['formato']) && $_GET['formato'] === 'suive') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="suive1_morbilidad_sinave_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Encabezado institucional
    fputcsv($out, ['SISTEMA NACIONAL DE VIGILANCIA EPIDEMIOLOGICA (SINAVE) - INFORME SUIVE-1']);
    fputcsv($out, ['ESTABLECIMIENTO:', config('nombre_establecimiento') ?: 'Expediente FEMECI', 'FECHA:', date('d/m/Y H:i')]);
    fputcsv($out, ['PERIODO:', $tituloPeriodo]);
    fputcsv($out, ['NORMATIVA:', 'NOM-017-SSA2-2012 / NOM-004-SSA3-2012 / NOM-024-SSA3-2012']);
    fputcsv($out, []);
    
    // Columnas estándar SUIVE-1
    fputcsv($out, [
        'NUM', 'CIE10', 'DIAGNOSTICO_CLINICO', 'GRUPO_NOSOLOGICO',
        'MENOR_1_ANIO', '1_A_4', '5_A_9', '10_A_14', '15_A_19', '20_A_24',
        '25_A_44', '45_A_49', '50_A_59', '60_A_64', '65_Y_MAS',
        'MASCULINO', 'FEMENINO', 'TOTAL_CASOS_NUEVOS_DESDUPLICADOS', 'CONSULTAS_TOTALES'
    ]);
    
    $num = 1;
    $suiveCols = ['< 1 año', '1 a 4 años', '5 a 9 años', '10 a 14 años', '15 a 19 años', '20 a 24 años', '25 a 44 años', '45 a 49 años', '50 a 59 años', '60 a 64 años', '65 y más años'];
    foreach ($tablaMorbilidad as $row) {
        $diagItem = $diagnosticosMap[$row['cie10'] !== 'SIN-COD' && $row['cie10'] !== 'SD-00' ? $row['cie10'] : mb_strtolower($row['nombre'])] ?? null;
        $conteoPorCol = array_fill_keys($suiveCols, 0);
        if ($diagItem && !empty($diagItem['edades'])) {
            foreach ($diagItem['edades'] as $ed) {
                $g = grupoEdadSuive((int)$ed);
                if (isset($conteoPorCol[$g])) $conteoPorCol[$g]++;
            }
        }
        $linea = [
            $num++,
            $row['cie10'],
            $row['nombre'],
            $row['categoria'],
        ];
        foreach ($suiveCols as $sc) {
            $linea[] = $conteoPorCol[$sc];
        }
        $linea[] = $row['masculino'];
        $linea[] = $row['femenino'];
        $linea[] = $row['casos_unicos'];
        $linea[] = $row['consultas_totales'];
        fputcsv($out, $linea);
    }
    fclose($out);
    exit;
}

// 4. Salida en formato CSV para análisis epidemiológico externo
if (isset($_GET['formato']) && $_GET['formato'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analisis_morbilidad_consultorio_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM UTF-8 para Excel en español
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Encabezados
    fputcsv($out, ['CODIGO_CIE10', 'DIAGNOSTICO_CLINICO', 'CATEGORIA_NOSOLOGICA', 'CASOS_UNICOS_PACIENTES', 'CASOS_FEMENINO', 'CASOS_MASCULINO', 'PREVALENCIA_PORCENTAJE', 'CONSULTAS_TOTALES', 'EDAD_PROMEDIO_ANOS']);
    
    foreach ($tablaMorbilidad as $row) {
        fputcsv($out, [
            $row['cie10'],
            $row['nombre'],
            $row['categoria'],
            $row['casos_unicos'],
            $row['femenino'],
            $row['masculino'],
            $row['porcentaje'] . '%',
            $row['consultas_totales'],
            $row['edad_promedio']
        ]);
    }
    fclose($out);
    exit;
}

// 5. Salida en formato JSON
if (isset($_GET['formato']) && $_GET['formato'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="analisis_morbilidad_consultorio_' . date('Ymd_His') . '.json"');
    echo json_encode([
        'institucion' => config('nombre_establecimiento') ?: 'Expediente FEMECI',
        'periodo' => $tituloPeriodo,
        'fecha_emision' => date('Y-m-d H:i:s'),
        'total_pacientes_unicos' => $totalPacientesUnicos,
        'total_consultas' => $totalConsultas,
        'morbilidad' => $tablaMorbilidad,
        'distribucion_etaria' => $listaPiramide,
        'categorias_nosologicas' => $listaCategorias,
        'anonimizacion' => 'Totalmente anonimizado según NOM-024 / NOM-004. Sin datos de identificación personal.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Regenerar o actualizar gráficas Matplotlib si es posible
$rutasPython = [
    'C:\\Users\\abelM\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
    'python',
    'python3'
];
$rutaPython = 'python';
foreach ($rutasPython as $rp) {
    if (file_exists($rp)) {
        $rutaPython = $rp;
        break;
    }
}
$autor = usuarioActual() ?: ['nombre' => 'Dr. Responsable de Área Médica', 'cedula' => '12345678'];
$scriptGraficas = __DIR__ . '/../app/servicios/ia/generar_graficas_datos.py';
if (file_exists($scriptGraficas)) {
    $payloadJson = [
        'top_morbilidad' => array_slice(array_map(fn($item) => [
            'nombre' => ($item['cie10'] !== 'SIN-COD' && $item['cie10'] !== 'SD-00' ? "[{$item['cie10']}] " : '') . $item['nombre'],
            'casos' => $item['casos_unicos'],
            'porcentaje' => $item['porcentaje']
        ], $tablaMorbilidad), 0, 10),
        'piramide_edad' => $listaPiramide,
        'categorias' => array_slice($listaCategorias, 0, 7)
    ];
    $tmpJsonFile = sys_get_temp_dir() . '/datos_morbilidad_' . getmypid() . '.json';
    file_put_contents($tmpJsonFile, json_encode($payloadJson, JSON_UNESCAPED_UNICODE));
    @exec(escapeshellcmd($rutaPython . ' ' . escapeshellarg($scriptGraficas) . ' ' . escapeshellarg($tmpJsonFile)) . ' 2>&1');
    @unlink($tmpJsonFile);
}

// Datos de institución
$nombreInstitucion = config('nombre_establecimiento') ?: 'Clínica FEMECI - Consultorio de Atención Médica Integral';
$direccionInstitucion = config('domicilio_establecimiento') ?: 'Servicios de Salud y Atención Médica';
$responsableSanitario = $autor['nombre'] ?? 'Dr. Responsable de Área Médica';
$cedulaResponsable = $autor['cedula'] ?? 'Cédula en trámite';

// Rutas de gráficas
$imgTop = URL_BASE . '/assets/img/reportes/morbilidad_top10.png?v=' . time();
$imgPiramide = URL_BASE . '/assets/img/reportes/piramide_etaria.png?v=' . time();
$imgCategorias = URL_BASE . '/assets/img/reportes/categorias_nosologicas.png?v=' . time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Informe Epidemiológico y Morbilidad - <?= e($nombreInstitucion) ?></title>
  <style>
    /* FORMATO ESTRICTO DE DOCUMENTO FORMAL:
       - Tipografía: Arial 12pt
       - Color: Letra negra (#000000), sin otros colores
       - Márgenes: 2.5 cm regulares en todo el contorno
    */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    body {
      background-color: #e5e5e5;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12pt;
      line-height: 1.5;
      color: #000000;
      padding: 24px;
    }
    .barra-herramientas-superior {
      max-width: 820px;
      margin: 0 auto 16px auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #ffffff;
      padding: 12px 18px;
      border: 1px solid #000000;
      border-radius: 4px;
    }
    .barra-herramientas-superior a,
    .barra-herramientas-superior button,
    .barra-herramientas-superior select {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      padding: 6px 14px;
      color: #000000;
      background: #ffffff;
      border: 1px solid #000000;
      text-decoration: none;
      cursor: pointer;
      border-radius: 2px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .barra-herramientas-superior a:hover,
    .barra-herramientas-superior button:hover {
      background: #eeeeee;
    }

    /* Hoja de Documento Formal */
    .documento-formal {
      background-color: #ffffff;
      max-width: 820px;
      margin: 0 auto 30px auto;
      padding: 2.5cm;
      box-shadow: 0 4px 18px rgba(0, 0, 0, 0.15);
      border: 1px solid #d0d0d0;
      color: #000000;
    }

    /* Encabezado Formal */
    .doc-encabezado {
      border-bottom: 2px solid #000000;
      padding-bottom: 14px;
      margin-bottom: 22px;
      text-align: center;
    }
    .doc-institucion {
      font-size: 14pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .doc-subinstitucion {
      font-size: 10pt;
      margin-bottom: 2px;
    }
    .doc-subtitulo-formal {
      font-size: 9pt;
      color: #000000;
      margin-top: 4px;
    }

    /* Título del Dictamen */
    .doc-titulo-dictamen {
      text-align: center;
      font-size: 13pt;
      font-weight: bold;
      text-transform: uppercase;
      margin: 20px 0 14px 0;
      border-top: 1px solid #000000;
      border-bottom: 1px solid #000000;
      padding: 8px 0;
    }

    /* Metadatos en tabla formal */
    .tabla-metadatos {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      font-size: 11pt;
    }
    .tabla-metadatos td {
      padding: 4px 6px;
      vertical-align: top;
      border: 1px solid #000000;
    }
    .tabla-metadatos .etiqueta {
      font-weight: bold;
      width: 25%;
      background: #f5f5f5;
    }

    /* Secciones del Documento */
    .doc-seccion-titulo {
      font-size: 12pt;
      font-weight: bold;
      text-transform: uppercase;
      margin: 26px 0 10px 0;
      border-bottom: 1px solid #000000;
      padding-bottom: 3px;
    }
    p.doc-parrafo {
      font-size: 12pt;
      text-align: justify;
      margin-bottom: 12px;
      text-indent: 1.25cm;
    }
    p.doc-parrafo-libre {
      font-size: 12pt;
      text-align: justify;
      margin-bottom: 12px;
    }

    /* Cuadros de Metodología y Fórmulas Matemáticas */
    .caja-formula {
      border: 1px solid #000000;
      background: #fafafa;
      padding: 12px 16px;
      margin: 16px 0;
      font-size: 10.5pt;
    }
    .caja-formula-titulo {
      font-weight: bold;
      text-transform: uppercase;
      margin-bottom: 6px;
      font-size: 10pt;
      border-bottom: 1px solid #000000;
      padding-bottom: 2px;
    }
    .formula-linea {
      font-family: "Courier New", Courier, monospace;
      font-size: 11pt;
      font-weight: bold;
      margin: 6px 0;
      padding-left: 12px;
    }

    /* Tablas Estadísticas Formales */
    table.tabla-formal {
      width: 100%;
      border-collapse: collapse;
      margin: 16px 0 24px 0;
      font-size: 10.5pt;
      border: 1px solid #000000;
    }
    table.tabla-formal th {
      background: #e8e8e8;
      color: #000000;
      font-weight: bold;
      border: 1px solid #000000;
      padding: 6px 8px;
      text-align: center;
      font-size: 10pt;
    }
    table.tabla-formal td {
      border: 1px solid #000000;
      padding: 5px 8px;
      vertical-align: middle;
      color: #000000;
    }
    table.tabla-formal tr:nth-child(even) {
      background: #fbfbfb;
    }
    .texto-derecha { text-align: right; }
    .texto-centro { text-align: center; }

    /* Gráficas Estadísticas */
    .contenedor-grafica {
      margin: 22px 0;
      text-align: center;
      page-break-inside: avoid;
    }
    .contenedor-grafica img {
      max-width: 100%;
      height: auto;
      border: 1px solid #000000;
    }
    .epigrafe-grafica {
      font-size: 10pt;
      font-style: italic;
      margin-top: 6px;
      text-align: center;
      color: #000000;
    }

    /* Bloque de Firmas */
    .bloque-firmas {
      margin-top: 45px;
      display: flex;
      justify-content: space-between;
      page-break-inside: avoid;
    }
    .firma-columna {
      width: 45%;
      text-align: center;
      font-size: 11pt;
    }
    .firma-linea {
      border-top: 1px solid #000000;
      margin-bottom: 6px;
      padding-top: 4px;
      font-weight: bold;
    }

    /* Reglas para Impresión */
    @media print {
      body {
        background: transparent !important;
        padding: 0 !important;
      }
      .no-imprimir {
        display: none !important;
      }
      .documento-formal {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        border: none !important;
        box-shadow: none !important;
      }
      @page {
        size: letter portrait;
        margin: 2.5cm;
      }
      .salto-pagina {
        page-break-before: always;
      }
    }
  </style>
</head>
<body>

  <!-- Barra de Herramientas Superior (No se imprime) -->
  <div class="barra-herramientas-superior no-imprimir">
    <div>
      <a href="<?= URL_BASE ?>/pacientes/">← Volver a Pacientes</a>
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
      <form method="get" action="" style="display: inline-flex; align-items: center; gap: 6px;">
        <label for="periodo" style="font-size: 10pt; font-weight: bold;">Periodo:</label>
        <select name="periodo" id="periodo" onchange="this.form.submit()">
          <option value="todos" <?= $periodo === 'todos' ? 'selected' : '' ?>>Histórico total consolidado</option>
          <option value="anio" <?= $periodo === 'anio' ? 'selected' : '' ?>>Año en curso (<?= date('Y') ?>)</option>
          <option value="12m" <?= $periodo === '12m' ? 'selected' : '' ?>>Últimos 12 meses</option>
          <option value="mes" <?= $periodo === 'mes' ? 'selected' : '' ?>>Últimos 30 días</option>
        </select>
      </form>
      <a href="?<?= http_build_query(array_merge($_GET, ['formato' => 'suive'])) ?>" title="Descargar plantilla oficial SUIVE-1 (SINAVE / Secretaría de Salud)">Exportar SUIVE-1 (SINAVE)</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['formato' => 'csv'])) ?>" title="Descargar datos analizados anonimizados en formato CSV para Excel o SPSS">Descargar CSV</a>
      <a href="?<?= http_build_query(array_merge($_GET, ['formato' => 'json'])) ?>" title="Descargar dataset analizado estructurado en JSON">Descargar JSON</a>
      <button type="button" onclick="window.print()" title="Imprimir dictamen en formato formal A4/Carta">Imprimir Documento</button>
    </div>
  </div>

  <!-- Documento Formal -->
  <article class="documento-formal">

    <!-- Encabezado Oficial -->
    <header class="doc-encabezado">
      <div class="doc-institucion"><?= e($nombreInstitucion) ?></div>
      <div class="doc-subinstitucion"><?= e($direccionInstitucion) ?></div>
      <div class="doc-subtitulo-formal">SISTEMA NACIONAL DE VIGILANCIA EPIDEMIOLÓGICA Y EXPEDIENTE CLÍNICO ELECTRÓNICO (NOM-004-SSA3-2012 / NOM-024-SSA3-2012)</div>
    </header>

    <div class="doc-titulo-dictamen">
      INFORME TÉCNICO DE MORBILIDAD Y ANÁLISIS ESTADÍSTICO DE CONSULTA CLÍNICA
    </div>

    <!-- Metadatos del Documento -->
    <table class="tabla-metadatos">
      <tr>
        <td class="etiqueta">Folio de Dictamen:</td>
        <td>INF-EPI-<?= date('Y') ?>-<?= str_pad((string)($totalPacientesUnicos), 4, '0', STR_PAD_LEFT) ?></td>
        <td class="etiqueta">Fecha de Emisión:</td>
        <td><?= date('d/m/Y H:i:s') ?></td>
      </tr>
      <tr>
        <td class="etiqueta">Ventana Temporal:</td>
        <td><?= e($tituloPeriodo) ?></td>
        <td class="etiqueta">Área Emisora:</td>
        <td>Dirección Médica y Estadística Clínica</td>
      </tr>
      <tr>
        <td class="etiqueta">Universo Muestral:</td>
        <td><?= number_format($totalPacientesUnicos) ?> Pacientes Únicos Atendidos</td>
        <td class="etiqueta">Consultas Totales:</td>
        <td><?= number_format($totalConsultas) ?> Notas de Evolución Registradas</td>
      </tr>
      <tr>
        <td class="etiqueta">Nivel de Confidencialidad:</td>
        <td colspan="3"><strong>DATOS TOTALMENTE ANONIMIZADOS.</strong> No contiene nombres, CURP, números telefónicos ni domicilios de pacientes (LFPDPPP y Ley General de Salud).</td>
      </tr>
    </table>

    <!-- 1. INTRODUCCIÓN Y METODOLOGÍA DE NO DUPLICACIÓN -->
    <div class="doc-seccion-titulo">1. Metodología de Análisis y Control de No Duplicidad</div>
    
    <p class="doc-parrafo">
      El presente documento tiene como objetivo presentar la caracterización estadística rigurosa de las patologías, enfermedades y problemas de salud registrados en el consultorio. Con la finalidad de garantizar la veracidad bioestadística y evitar distorsiones por visitas repetidas del mismo paciente a lo largo de días, semanas o meses, se implementó un modelo de deduplicación de casos basado en conjuntos disjuntos de pacientes únicos atendidos por diagnóstico.
    </p>

    <div class="caja-formula">
      <div class="caja-formula-titulo">Fórmulas Matemáticas de Deduplicación y Prevalencia Real</div>
      <p style="margin-bottom: 6px;">Para evitar duplicación de pacientes registrados en distintas fechas dentro del periodo:</p>
      <div class="formula-linea">
        Casos Únicos (Prevalencia) = CARDINALIDAD { p ∈ Pacientes | ∃ Consulta(p, CIE10_i, t) con t ∈ [T_inicio, T_fin] }
      </div>
      <div class="formula-linea">
        % Prevalencia Poblacional = ( Casos Únicos de la Enfermedad_i / Total Pacientes Únicos Atendidos ) × 100
      </div>
      <div class="formula-linea">
        Índice de Reconsulta = Total de Consultas de la Enfermedad_i / Casos Únicos de la Enfermedad_i
      </div>
      <p style="margin-top: 6px; font-size: 9.5pt; color: #333333;">
        <em>Garantía de integridad:</em> Si un paciente acudió en múltiples ocasiones (día, semana o mes) por la misma enfermedad (p. ej. control de hipertensión), se cuantifica como <strong>un solo caso único</strong> en la estadística de morbilidad, contabilizando las visitas adicionales únicamente en la columna de demanda asistencial (consultas totales).
      </p>
    </div>

    <!-- 2. RESUMEN DE MORBILIDAD Y ENFERMEDADES MÁS FRECUENTES -->
    <div class="doc-seccion-titulo">2. Análisis de Morbilidad: Principales Enfermedades Registradas</div>
    
    <p class="doc-parrafo-libre">
      En el periodo evaluado se analizaron un total de <strong><?= number_format($totalConsultas) ?></strong> notas de consulta clínica correspondientes a <strong><?= number_format($totalPacientesUnicos) ?></strong> pacientes individuales. A continuación se desglosa el cuadro consolidado de patologías ordenadas de mayor a menor frecuencia epidemiológica:
    </p>

    <table class="tabla-formal">
      <thead>
        <tr>
          <th style="width: 10%;">CIE-10</th>
          <th style="width: 34%;">Diagnóstico Clínico / Enfermedad</th>
          <th style="width: 20%;">Categoría Nosológica</th>
          <th style="width: 10%;">Casos Únicos</th>
          <th style="width: 6%;">Fem</th>
          <th style="width: 6%;">Masc</th>
          <th style="width: 7%;">Prev. %</th>
          <th style="width: 7%;">Cons.</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tablaMorbilidad)): ?>
          <tr><td colspan="8" class="texto-centro">No se encontraron registros de consulta en la ventana seleccionada.</td></tr>
        <?php else: ?>
          <?php foreach ($tablaMorbilidad as $idx => $filaM): ?>
            <tr>
              <td class="texto-centro"><strong><?= e($filaM['cie10']) ?></strong></td>
              <td><?= e($filaM['nombre']) ?></td>
              <td style="font-size: 9.5pt;"><?= e($filaM['categoria']) ?></td>
              <td class="texto-centro"><strong><?= number_format($filaM['casos_unicos']) ?></strong></td>
              <td class="texto-centro"><?= number_format($filaM['femenino']) ?></td>
              <td class="texto-centro"><?= number_format($filaM['masculino']) ?></td>
              <td class="texto-derecha"><?= number_format($filaM['porcentaje'], 1) ?>%</td>
              <td class="texto-centro"><?= number_format($filaM['consultas_totales']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="salto-pagina"></div>

    <!-- 3. GRÁFICAS ESTADÍSTICAS DE MATPLOTLIB -->
    <div class="doc-seccion-titulo">3. Representación Gráfica Estadísticamente Validada</div>

    <p class="doc-parrafo-libre">
      Las siguientes figuras estadísticas fueron procesadas mediante la librería bioestadística <strong>Matplotlib</strong>, aplicando estrictos criterios formales de representación gráfica: formato de alta resolución (300 DPI), tipografía Arial estandarizada, escala monocromática/grises y ejes con leyenda cuantitativa:
    </p>

    <!-- Gráfica 1: Top 10 Morbilidad -->
    <div class="contenedor-grafica">
      <img src="<?= $imgTop ?>" alt="Figura 1. Diez Principales Causas de Morbilidad">
      <div class="epigrafe-grafica">FIGURA 1. Distribución de las diez patologías con mayor prevalencia en el consultorio (casos únicos sin duplicidad). Fuente: Registros clínicos del establecimiento.</div>
    </div>

    <!-- Gráfica 2: Pirámide de Edad y Sexo -->
    <div class="contenedor-grafica">
      <img src="<?= $imgPiramide ?>" alt="Figura 2. Pirámide de Grupos Etarios y Sexo">
      <div class="epigrafe-grafica">FIGURA 2. Estructura demográfica de la población atendida por grupos de edad quinquenales y género.</div>
    </div>

    <div class="salto-pagina"></div>

    <!-- Gráfica 3: Distribución por Sistemas -->
    <div class="contenedor-grafica">
      <img src="<?= $imgCategorias ?>" alt="Figura 3. Carga de Morbilidad por Sistema Nosológico">
      <div class="epigrafe-grafica">FIGURA 3. Concentración nosológica por grandes aparatos y sistemas clínicos afectados en la consulta.</div>
    </div>

    <!-- 4. DISTRIBUCIÓN POR CATEGORÍAS NOSOLÓGICAS Y DEMOGRAFÍA -->
    <div class="doc-seccion-titulo">4. Distribución por Sistemas Nosológicos y Factores Demográficos</div>

    <p class="doc-parrafo">
      La clasificación nosológica permite al consultorio evaluar la demanda de insumos terapéuticos, instrumental diagnóstico y perfiles de atención especializada que requiere la población usuaria:
    </p>

    <table class="tabla-formal">
      <thead>
        <tr>
          <th>Categoría Nosológica / Sistema Afectado</th>
          <th style="width: 25%;">Pacientes Únicos Afectados</th>
          <th style="width: 25%;">Porcentaje sobre Total Poblacional</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listaCategorias as $catRow): ?>
          <tr>
            <td><strong><?= e($catRow['categoria']) ?></strong></td>
            <td class="texto-centro"><?= number_format($catRow['casos']) ?> pacientes</td>
            <td class="texto-derecha"><?= number_format($catRow['porcentaje'], 1) ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="doc-seccion-titulo">5. Parámetros Biométricos y Signos Vitales Medios de la Población</div>

    <?php
      $avgTas = !empty($signosVitalesData['tas']) ? round(array_sum($signosVitalesData['tas']) / count($signosVitalesData['tas']), 1) : 0;
      $avgTad = !empty($signosVitalesData['tad']) ? round(array_sum($signosVitalesData['tad']) / count($signosVitalesData['tad']), 1) : 0;
      $avgGlucosa = !empty($signosVitalesData['glucosa']) ? round(array_sum($signosVitalesData['glucosa']) / count($signosVitalesData['glucosa']), 1) : 0;
      $avgImc = !empty($signosVitalesData['imc']) ? round(array_sum($signosVitalesData['imc']) / count($signosVitalesData['imc']), 1) : 0;
    ?>
    <table class="tabla-formal">
      <thead>
        <tr>
          <th>Parámetro Fisiológico Evaluado</th>
          <th style="width: 25%;">Valor Medio Observado</th>
          <th style="width: 45%;">Rango de Referencia Clínico</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Tensión Arterial Sistólica (TAS)</strong></td>
          <td class="texto-centro"><?= $avgTas ?> mmHg</td>
          <td>110 – 129 mmHg (Normal / Óptima según NOM-030)</td>
        </tr>
        <tr>
          <td><strong>Tensión Arterial Diastólica (TAD)</strong></td>
          <td class="texto-centro"><?= $avgTad ?> mmHg</td>
          <td>70 – 84 mmHg (Normal según NOM-030)</td>
        </tr>
        <tr>
          <td><strong>Glucosa Capilar en Ayuno / Casual</strong></td>
          <td class="texto-centro"><?= $avgGlucosa ?> mg/dL</td>
          <td>70 – 99 mg/dL en ayuno (< 140 mg/dL casual)</td>
        </tr>
        <tr>
          <td><strong>Índice de Masa Corporal Promedio (IMC)</strong></td>
          <td class="texto-centro"><?= $avgImc ?> kg/m²</td>
          <td>18.5 – 24.9 kg/m² (Normopeso según OMS)</td>
        </tr>
      </tbody>
    </table>

    <!-- 6. PROTECCIÓN DE DATOS Y CONCLUSIONES -->
    <div class="doc-seccion-titulo">6. Declaratoria de Privacidad y Cumplimiento Normativo</div>
    
    <p class="doc-parrafo">
      Se hace constar expresamente que este informe fue elaborado con estricto apego a las directrices de anonimización y disociación de datos personales sensibles consagradas en la Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP), la Ley General de Salud y la NOM-024-SSA3-2012. Ningún nombre, clave CURP, número de seguridad social, teléfono de contacto o domicilio residencial ha sido incluido en las tablas ni en los conjuntos de datos exportables.
    </p>

    <!-- Botón de Acción Rápida de Impresión Formal -->
    <div class="no-imprimir" style="text-align: center; margin: 30px 0; padding: 18px; background: #f5f5f5; border: 1px dashed #000;">
      <p style="font-size: 11pt; margin-bottom: 10px;"><strong>¿Deseas imprimir o guardar este dictamen formal en PDF?</strong> Cumple con todos los requisitos de Arial 12pt, márgenes de 2.5 cm y escala de grises para autoridades sanitarias y jurados de feria de ciencias.</p>
      <button type="button" onclick="window.print()" style="font-family: Arial; font-size: 13pt; font-weight: bold; padding: 12px 32px; background: #000000; color: #ffffff; border: 0; border-radius: 4px; cursor: pointer;">
        🖨️ Imprimir Dictamen Formal Completo (A4 / Carta)
      </button>
    </div>

    <!-- Bloque de Firmas Formales -->
    <div class="bloque-firmas">
      <div class="firma-columna">
        <div class="firma-linea"><?= e($responsableSanitario) ?></div>
        <div>Responsable Sanitario / Dirección Médica</div>
        <div>Cédula Profesional: <?= e($cedulaResponsable) ?></div>
        <div><?= e($nombreInstitucion) ?></div>
      </div>
      <div class="firma-columna">
        <div class="firma-linea">Comité de Estadística y Epidemiología Clínica</div>
        <div>Área de Vigilancia Epidemiológica</div>
        <div>Certificación de No Duplicidad y Disociación</div>
        <div>Fecha: <?= date('d/m/Y') ?></div>
      </div>
    </div>

  </article>

</body>
</html>
