<?php
/**
 * INFORME EPIDEMIOLÓGICO Y ANÁLISIS DE DATOS CLÍNICOS
 * Morbilidad hospitalaria y ambulatoria, clasificación nosológica CIE-10,
 * pirámide etaria y exportación oficial SUIVE-1 (SINAVE).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';

exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId();

// 1. Manejo del Periodo
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
        $tituloPeriodo = "Histórico total consolidado";
        break;
}

// 2. Consulta de Datos anonimizada
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

function categorizarCie10(string $codigo, string $descripcion): string {
    $letra = strtoupper(substr(trim($codigo), 0, 1));
    return match ($letra) {
        'A', 'B' => 'Enfermedades Infecciosas y Parasitarias',
        'C', 'D' => 'Neoplasias y Sangre',
        'E'      => 'Enfermedades Endocrinas y Metabólicas',
        'F'      => 'Trastornos Mentales y del Comportamiento',
        'G'      => 'Enfermedades del Sistema Nervioso',
        'H'      => 'Enfermedades del Ojo y Oído',
        'I'      => 'Enfermedades del Sistema Circulatorio',
        'J'      => 'Enfermedades del Sistema Respiratorio',
        'K'      => 'Enfermedades del Sistema Digestivo',
        'L'      => 'Enfermedades de la Piel',
        'M'      => 'Sistema Osteomuscular y Tejido Conjuntivo',
        'N'      => 'Enfermedades del Sistema Genitourinario',
        default  => 'Otras Causas de Atención Médica'
    };
}

$totalConsultas = count($filas);
$pacientesAtendidosSet = [];
$diagnosticosMap = [];
$piramideEdad = [
    '< 1 año' => ['femenino' => 0, 'masculino' => 0],
    '1 a 4 años' => ['femenino' => 0, 'masculino' => 0],
    '5 a 9 años' => ['femenino' => 0, 'masculino' => 0],
    '10 a 14 años' => ['femenino' => 0, 'masculino' => 0],
    '15 a 19 años' => ['femenino' => 0, 'masculino' => 0],
    '20 a 24 años' => ['femenino' => 0, 'masculino' => 0],
    '25 a 44 años' => ['femenino' => 0, 'masculino' => 0],
    '45 a 49 años' => ['femenino' => 0, 'masculino' => 0],
    '50 a 59 años' => ['femenino' => 0, 'masculino' => 0],
    '60 a 64 años' => ['femenino' => 0, 'masculino' => 0],
    '65 y más años' => ['femenino' => 0, 'masculino' => 0],
];
$totalFem = 0;
$totalMasc = 0;

foreach ($filas as $f) {
    $pId = (int)$f['paciente_id'];
    $sexo = strtoupper(trim((string)$f['sexo']));
    $fnac = $f['fecha_nacimiento'];
    $fcons = substr((string)$f['fecha'], 0, 10);

    $edad = 0;
    if (!empty($fnac) && $fnac !== '0000-00-00') {
        try {
            $d1 = new DateTime($fnac);
            $d2 = new DateTime($fcons ?: 'now');
            $edad = max(0, $d1->diff($d2)->y);
        } catch (Throwable $e) {
            $edad = 0;
        }
    }

    if (!isset($pacientesAtendidosSet[$pId])) {
        $pacientesAtendidosSet[$pId] = true;
        $grupoE = grupoEdadSuive($edad);
        if ($sexo === 'M') {
            $piramideEdad[$grupoE]['femenino']++;
            $totalFem++;
        } else {
            $piramideEdad[$grupoE]['masculino']++;
            $totalMasc++;
        }
    }

    $detalle = [];
    if (!empty($f['detalle'])) {
        $detalle = is_array($f['detalle']) ? $f['detalle'] : (json_decode((string)$f['detalle'], true) ?: []);
    }

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

usort($tablaMorbilidad, fn($a, $b) => $b['casos_unicos'] <=> $a['casos_unicos']);

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

$listaPiramide = [];
foreach ($piramideEdad as $gNombre => $gData) {
    $listaPiramide[] = [
        'grupo' => $gNombre,
        'femenino' => $gData['femenino'],
        'masculino' => $gData['masculino'],
        'total' => $gData['femenino'] + $gData['masculino'],
    ];
}

// Exportación SUIVE-1
if (isset($_GET['formato']) && $_GET['formato'] === 'suive') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="suive1_epidemiologia_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['SISTEMA NACIONAL DE VIGILANCIA EPIDEMIOLOGICA (SINAVE) - SUIVE-1']);
    fputcsv($out, ['ESTABLECIMIENTO:', config('nombre_establecimiento') ?: 'Expediente FEMECI', 'FECHA:', date('d/m/Y H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['NUM', 'CIE10', 'DIAGNOSTICO', 'CATEGORIA', '<1', '1-4', '5-9', '10-14', '15-19', '20-24', '25-44', '45-49', '50-59', '60-64', '65+', 'MASC', 'FEM', 'TOTAL_CASOS', 'CONSULTAS']);
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
        $linea = [$num++, $row['cie10'], $row['nombre'], $row['categoria']];
        foreach ($suiveCols as $sc) { $linea[] = $conteoPorCol[$sc]; }
        $linea[] = $row['masculino'];
        $linea[] = $row['femenino'];
        $linea[] = $row['casos_unicos'];
        $linea[] = $row['consultas_totales'];
        fputcsv($out, $linea);
    }
    fclose($out);
    exit;
}

// Exportación CSV
if (isset($_GET['formato']) && $_GET['formato'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="morbilidad_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['CODIGO_CIE10', 'DIAGNOSTICO', 'CATEGORIA', 'CASOS_UNICOS', 'FEMENINO', 'MASCULINO', 'PREVALENCIA', 'CONSULTAS_TOTALES', 'EDAD_MEDIA']);
    foreach ($tablaMorbilidad as $row) {
        fputcsv($out, [$row['cie10'], $row['nombre'], $row['categoria'], $row['casos_unicos'], $row['femenino'], $row['masculino'], $row['porcentaje'] . '%', $row['consultas_totales'], $row['edad_promedio']]);
    }
    fclose($out);
    exit;
}

// Gráficas
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

$imgTop = URL_BASE . '/assets/img/reportes/morbilidad_top10.png?v=' . time();
$imgPiramide = URL_BASE . '/assets/img/reportes/piramide_etaria.png?v=' . time();
$imgCategorias = URL_BASE . '/assets/img/reportes/categorias_nosologicas.png?v=' . time();

$tituloPagina = 'Datos y Morbilidad';
$seccionActiva = 'datos';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente">

  <div class="pagina__cabecera">
    <h1 class="pagina__titulo">Datos Clínicos y Morbilidad</h1>
    <p class="pagina__subtitulo">Análisis estadístico de consulta clínica, distribución nosológica y SUIVE-1 (SINAVE) · NOM-017-SSA2-2012</p>
  </div>

  <!-- Resumen Métrico Nativo (hoja-paciente__resumen) -->
  <dl class="hoja-paciente__resumen">
    <div><dt>Total consultas</dt><dd><?= $totalConsultas ?></dd></div>
    <div><dt>Pacientes únicos</dt><dd><?= $totalPacientesUnicos ?></dd></div>
    <div><dt>Patología #1</dt><dd title="<?= e($tablaMorbilidad[0]['nombre'] ?? '—') ?>"><?= e(mb_substr($tablaMorbilidad[0]['nombre'] ?? '—', 0, 20)) ?></dd></div>
    <div><dt>Prevalencia #1</dt><dd><?= ($tablaMorbilidad[0]['porcentaje'] ?? 0) ?>%</dd></div>
    <div><dt>Mujeres</dt><dd><?= ($totalFem + $totalMasc) > 0 ? round(($totalFem / ($totalFem + $totalMasc)) * 100) : 0 ?>%</dd></div>
    <div><dt>Hombres</dt><dd><?= ($totalFem + $totalMasc) > 0 ? round(($totalMasc / ($totalFem + $totalMasc)) * 100) : 0 ?>%</dd></div>
    <div><dt>Categorías</dt><dd><?= count($listaCategorias) ?></dd></div>
  </dl>

  <!-- Gráficas Estadísticas en Sección Limpia -->
  <section class="ficha__seccion" style="margin-top: 24px;">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Distribución Gráfica Epidemiológica</h2>
      <span class="ficha__fuente">Periodo: <?= e($tituloPeriodo) ?></span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-top: 14px;">
      <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 8px; padding: 12px; text-align: center;">
        <img src="<?= e($imgTop) ?>" alt="Top 10 Morbilidad" style="max-width: 100%; height: auto; border-radius: 4px;">
        <div style="font-size: var(--fs-xs); color: var(--texto-tenue); margin-top: 6px;">Figura 1. Diez principales causas de morbilidad</div>
      </div>
      <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 8px; padding: 12px; text-align: center;">
        <img src="<?= e($imgPiramide) ?>" alt="Pirámide Etaria" style="max-width: 100%; height: auto; border-radius: 4px;">
        <div style="font-size: var(--fs-xs); color: var(--texto-tenue); margin-top: 6px;">Figura 2. Pirámide de grupos etarios y género</div>
      </div>
      <div style="background: #181818; border: 1px solid #2a2a2a; border-radius: 8px; padding: 12px; text-align: center;">
        <img src="<?= e($imgCategorias) ?>" alt="Sistemas Nosológicos" style="max-width: 100%; height: auto; border-radius: 4px;">
        <div style="font-size: var(--fs-xs); color: var(--texto-tenue); margin-top: 6px;">Figura 3. Distribución por categorías nosológicas</div>
      </div>
    </div>
  </section>

  <!-- Tabla Nativa de Morbilidad (bd / bd-tabla) -->
  <section class="bd" data-bd style="margin-top: 28px;">
    <div class="bd__cabecera">
      <h2 class="bd__titulo">Matriz de Morbilidad y Prevalencia Clínica</h2>

      <div class="bd__herramientas">
        <!-- Filtros de tiempo -->
        <a href="?periodo=todos" class="boton<?= $periodo === 'todos' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Histórico total</a>
        <a href="?periodo=anio" class="boton<?= $periodo === 'anio' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">Año <?= date('Y') ?></a>
        <a href="?periodo=12m" class="boton<?= $periodo === '12m' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">12 meses</a>
        <a href="?periodo=mes" class="boton<?= $periodo === 'mes' ? ' boton--primario' : ' boton--sutil' ?>" style="font-size: var(--fs-xs);">30 días</a>

        <a href="?<?= http_build_query(array_merge($_GET, ['formato' => 'suive'])) ?>" class="boton boton--secundario" style="margin-left: 6px;">
          <?= icono('documento') ?> SUIVE-1
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['formato' => 'csv'])) ?>" class="boton boton--secundario">
          <?= icono('tabla') ?> CSV
        </a>
        <button type="button" class="boton boton--primario bd__nuevo" onclick="window.print();">
          <?= icono('documento') ?> Imprimir informe
        </button>
      </div>
    </div>

    <div class="bd__marco">
      <table class="bd-tabla">
        <thead>
          <tr>
            <th scope="col"><button type="button"><?= icono('etiqueta') ?> <span>Código CIE-10</span></button></th>
            <th scope="col"><button type="button"><?= icono('documento') ?> <span>Diagnóstico clínico</span></button></th>
            <th scope="col"><button type="button"><?= icono('carpeta') ?> <span>Categoría nosológica</span></button></th>
            <th scope="col" class="num"><button type="button"><?= icono('usuarios') ?> <span>Casos únicos</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Fem.</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Masc.</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Prevalencia</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Consultas</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Edad media</span></button></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tablaMorbilidad as $row): ?>
            <tr>
              <td>
                <span style="font-family: monospace; color: #93c5fd; font-weight: 600;"><?= e($row['cie10']) ?></span>
              </td>
              <td class="bd-tabla__celda-titulo">
                <span class="bd-tabla__titulo">
                  <span><?= e($row['nombre']) ?></span>
                </span>
              </td>
              <td><?= e($row['categoria']) ?></td>
              <td class="num" style="font-weight: 600; color: #fff;"><?= (int)$row['casos_unicos'] ?></td>
              <td class="num"><?= (int)$row['femenino'] ?></td>
              <td class="num"><?= (int)$row['masculino'] ?></td>
              <td class="num" style="color: #93c5fd; font-weight: 600;"><?= $row['porcentaje'] ?>%</td>
              <td class="num"><?= (int)$row['consultas_totales'] ?></td>
              <td class="num"><?= $row['edad_promedio'] ?> a</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<style>
@media print {
  .barra-lateral, .encabezado, .pestanas, .bd__herramientas, .boton {
    display: none !important;
  }
  body, .contenido, .pagina {
    background: #fff !important;
    color: #000 !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .bd__marco, table.bd-tabla {
    border: 1px solid #000 !important;
  }
  table.bd-tabla th, table.bd-tabla td {
    color: #000 !important;
    border: 1px solid #ccc !important;
  }
  .hoja-paciente__resumen {
    border-color: #000 !important;
  }
  .hoja-paciente__resumen dd, .hoja-paciente__resumen dt {
    color: #000 !important;
  }
  h1, h2, h3 {
    color: #000 !important;
  }
}
</style>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
