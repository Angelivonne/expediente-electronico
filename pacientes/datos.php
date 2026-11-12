<?php
/**
 * INFORME EPIDEMIOLÓGICO Y ANÁLISIS DE DATOS CLÍNICOS
 * Morbilidad hospitalaria y ambulatoria, clasificación nosológica CIE-10,
 * pirámide etaria y exportación oficial SUIVE-1 (SINAVE).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
require_once __DIR__ . '/../app/servicios/clinica/consentimiento_morbilidad.php';

exigirPermiso('ver_expediente');

$pdo = db();
$institucionId = institucionId();
$formatoSolicitado = is_string($_GET['formato'] ?? null) ? $_GET['formato'] : '';
$destinatarioCompartir = '';
$pacientesAutorizadosCompartir = [];
if (in_array($formatoSolicitado, ['csv', 'suive'], true)) {
    exigirPermiso('exportar_morbilidad');
} elseif ($formatoSolicitado === 'compartir') {
    exigirPermiso('compartir_morbilidad');
    $destinatarioCompartir = trim((string) ($_GET['destinatario'] ?? ''));
    if ($destinatarioCompartir === '' || mb_strlen($destinatarioCompartir) > 200 || preg_match('/[\x00-\x1F\x7F]/', $destinatarioCompartir)) {
        http_response_code(400);
        exit('Selecciona un destinatario con una autorización vigente.');
    }
    $validaDestinatario = $pdo->prepare(
        "SELECT 1 FROM consentimientos_morbilidad c
         WHERE c.institucion_id = ? AND c.version = ? AND c.estado = 'vigente' AND c.destinatario = ?
           AND c.texto_sha256 = SHA2(c.texto_consentimiento, 256)
           AND EXISTS (
             SELECT 1 FROM consentimientos_morbilidad_eventos ce
             WHERE ce.consentimiento_id = c.id AND ce.institucion_id = c.institucion_id
               AND ce.paciente_id = c.paciente_id AND ce.tipo = 'otorgado'
               AND ce.evidencia_archivo IS NOT NULL AND ce.evidencia_sha256 IS NOT NULL
           )
         LIMIT 1"
    );
    $validaDestinatario->execute([$institucionId, VERSION_CONSENTIMIENTO_MORBILIDAD, $destinatarioCompartir]);
    if (!$validaDestinatario->fetchColumn()) {
        http_response_code(400);
        exit('El destinatario no tiene autorizaciones vigentes registradas.');
    }
    $consentimientosDestino = $pdo->prepare(
        "SELECT c.id, c.paciente_id, c.texto_consentimiento, c.texto_sha256,
                ce.evidencia_archivo, ce.evidencia_sha256
         FROM consentimientos_morbilidad c
         INNER JOIN consentimientos_morbilidad_eventos ce
           ON ce.consentimiento_id = c.id AND ce.institucion_id = c.institucion_id
          AND ce.paciente_id = c.paciente_id
          AND ce.tipo = 'otorgado'
         WHERE c.institucion_id = ? AND c.version = ? AND c.estado = 'vigente'
           AND c.destinatario = ? AND ce.evidencia_archivo IS NOT NULL AND ce.evidencia_sha256 IS NOT NULL"
    );
    $consentimientosDestino->execute([$institucionId, VERSION_CONSENTIMIENTO_MORBILIDAD, $destinatarioCompartir]);
    foreach ($consentimientosDestino->fetchAll(PDO::FETCH_ASSOC) as $consentimiento) {
        $rutaEvidencia = rutaEvidenciaConsentimientoMorbilidad($institucionId, (string) $consentimiento['evidencia_archivo']);
        $hashTextoValido = hash_equals(
            (string) $consentimiento['texto_sha256'],
            hash('sha256', (string) $consentimiento['texto_consentimiento'])
        );
        $hashArchivo = $rutaEvidencia ? hash_file('sha256', $rutaEvidencia) : false;
        if ($hashTextoValido && is_string($hashArchivo) && hash_equals((string) $consentimiento['evidencia_sha256'], $hashArchivo)) {
            $pacientesAutorizadosCompartir[(int) $consentimiento['paciente_id']] = true;
        } else {
            error_log('[morbilidad] Se excluyó el consentimiento ' . (int) $consentimiento['id'] . ' por evidencia ausente o con integridad no verificable.');
        }
    }
    if (!$pacientesAutorizadosCompartir) {
        http_response_code(409);
        exit('No se generó el informe: no hay evidencia íntegra de autorización vigente para ese destinatario.');
    }
}

$destinatariosMorbilidad = [];
if (puede('compartir_morbilidad')) {
    $consultaDestinatarios = $pdo->prepare(
        "SELECT DISTINCT c.destinatario FROM consentimientos_morbilidad c
         WHERE c.institucion_id = ? AND c.version = ? AND c.estado = 'vigente'
           AND c.texto_sha256 = SHA2(c.texto_consentimiento, 256)
           AND EXISTS (
             SELECT 1 FROM consentimientos_morbilidad_eventos ce
             WHERE ce.consentimiento_id = c.id AND ce.institucion_id = c.institucion_id
               AND ce.paciente_id = c.paciente_id AND ce.tipo = 'otorgado'
               AND ce.evidencia_archivo IS NOT NULL AND ce.evidencia_sha256 IS NOT NULL
           )
         ORDER BY c.destinatario"
    );
    $consultaDestinatarios->execute([$institucionId, VERSION_CONSENTIMIENTO_MORBILIDAD]);
    $destinatariosMorbilidad = $consultaDestinatarios->fetchAll(PDO::FETCH_COLUMN);
}

// 1. Manejo del Periodo
$periodo = $_GET['periodo'] ?? 'todos';
$hoy = date('Y-m-d');
$condicionFecha = '';
$paramsFecha = [];
$fechaAnteriorInicio = null;
$fechaAnteriorFin = null;
$tituloPeriodo = 'Histórico acumulado total';

switch ($periodo) {
    case 'mes':
        $fechaInicio = date('Y-m-d', strtotime('-29 days'));
        $fechaAnteriorFin = date('Y-m-d', strtotime($fechaInicio . ' -1 day'));
        $fechaAnteriorInicio = date('Y-m-d', strtotime($fechaAnteriorFin . ' -29 days'));
        $condicionFecha = ' AND h.fecha >= ? AND h.fecha <= ?';
        $paramsFecha = [$fechaInicio . ' 00:00:00', $hoy . ' 23:59:59'];
        $tituloPeriodo = "Últimos 30 días (" . date('d/m/Y', strtotime($fechaInicio)) . " al " . date('d/m/Y') . ")";
        break;
    case '12m':
        $fechaInicio = date('Y-m-d', strtotime('-364 days'));
        $fechaAnteriorFin = date('Y-m-d', strtotime($fechaInicio . ' -1 day'));
        $fechaAnteriorInicio = date('Y-m-d', strtotime($fechaAnteriorFin . ' -364 days'));
        $condicionFecha = ' AND h.fecha >= ? AND h.fecha <= ?';
        $paramsFecha = [$fechaInicio . ' 00:00:00', $hoy . ' 23:59:59'];
        $tituloPeriodo = "Últimos 12 meses (" . date('d/m/Y', strtotime($fechaInicio)) . " al " . date('d/m/Y') . ")";
        break;
    case 'anio':
        $fechaInicio = date('Y-01-01');
        $fechaAnteriorInicio = date('Y-01-01', strtotime('-1 year'));
        $fechaAnteriorFin = date('Y-m-d', strtotime('-1 year', strtotime($hoy)));
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
        WHERE h.tipo = 'consulta' AND p.eliminado_en IS NULL
          AND h.institucion_id = ? AND p.institucion_id = ?";

$paramsConsulta = [$institucionId, $institucionId];
if ($formatoSolicitado === 'compartir') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM consentimientos_morbilidad cm
        WHERE cm.institucion_id = p.institucion_id AND cm.paciente_id = p.id
          AND cm.version = ? AND cm.estado = 'vigente' AND cm.destinatario = ?
          AND cm.texto_sha256 = SHA2(cm.texto_consentimiento, 256)
          AND EXISTS (
            SELECT 1 FROM consentimientos_morbilidad_eventos ce
            WHERE ce.consentimiento_id = cm.id AND ce.institucion_id = cm.institucion_id
              AND ce.paciente_id = cm.paciente_id AND ce.tipo = 'otorgado'
              AND ce.evidencia_archivo IS NOT NULL AND ce.evidencia_sha256 IS NOT NULL
          )
    )";
    $paramsConsulta = array_merge($paramsConsulta, [VERSION_CONSENTIMIENTO_MORBILIDAD, $destinatarioCompartir]);
}
$sql .= $condicionFecha . " ORDER BY h.fecha ASC";
$paramsConsulta = array_merge($paramsConsulta, $paramsFecha);

$stmt = $pdo->prepare($sql);
$stmt->execute($paramsConsulta);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($formatoSolicitado === 'compartir') {
    $filas = array_values(array_filter(
        $filas,
        static fn (array $fila): bool => isset($pacientesAutorizadosCompartir[(int) $fila['paciente_id']])
    ));
}

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

function categorizarCie10(string $codigo): string {
    $codigo = strtoupper(trim($codigo));
    if (in_array($codigo, ['SIN-COD', 'SD-00'], true)) {
        return 'Diagnóstico sin código CIE-10';
    }

    if (preg_match('/^D(\d{2})/', $codigo, $coincidencia)) {
        $numero = (int) $coincidencia[1];
        if ($numero < 50) {
            return 'Neoplasias';
        }
        if ($numero <= 89) {
            return 'Enfermedades de la sangre y del sistema inmunitario';
        }
        return 'Otros trastornos de la sangre y del sistema inmunitario';
    }

    $letra = substr($codigo, 0, 1);
    return match ($letra) {
        'A', 'B' => 'Enfermedades infecciosas y parasitarias',
        'C' => 'Neoplasias',
        'E' => 'Enfermedades endocrinas, nutricionales y metabólicas',
        'F' => 'Trastornos mentales y del comportamiento',
        'G' => 'Enfermedades del sistema nervioso',
        'H' => preg_match('/^H([0-9]{2})/', $codigo, $coincidencia) && (int) $coincidencia[1] >= 60
            ? 'Enfermedades del oído y de la apófisis mastoides'
            : 'Enfermedades del ojo y sus anexos',
        'I' => 'Enfermedades del sistema circulatorio',
        'J' => 'Enfermedades del sistema respiratorio',
        'K' => 'Enfermedades del sistema digestivo',
        'L' => 'Enfermedades de la piel y del tejido subcutáneo',
        'M' => 'Enfermedades del sistema osteomuscular y del tejido conjuntivo',
        'N' => 'Enfermedades del sistema genitourinario',
        'O' => 'Embarazo, parto y puerperio',
        'P' => 'Afecciones originadas en el periodo perinatal',
        'Q' => 'Malformaciones congénitas y anomalías cromosómicas',
        'R' => 'Síntomas, signos y hallazgos clínicos no clasificados en otra parte',
        'S', 'T' => 'Traumatismos, envenenamientos y otras consecuencias de causas externas',
        'V', 'W', 'X', 'Y' => 'Causas externas de morbilidad y mortalidad',
        'Z' => 'Factores que influyen en el estado de salud y contacto con servicios',
        'U' => 'Códigos para propósitos especiales',
        default => 'Código CIE-10 sin categoría reconocida',
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
    $sexo = mb_strtoupper(trim((string)$f['sexo']));
    $sexoFemenino = in_array($sexo, ['M', 'F', 'FEMENINO', 'MUJER'], true);
    $sexoMasculino = in_array($sexo, ['H', 'MASCULINO', 'HOMBRE'], true);
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
        if ($sexoFemenino) {
            $piramideEdad[$grupoE]['femenino']++;
            $totalFem++;
        } elseif ($sexoMasculino) {
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
            $n = trim((string) ($dItem['descripcion'] ?? $dItem['diagnostico'] ?? $dItem['nombre'] ?? ''));
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
                'categoria' => categorizarCie10($diag['cie10']),
                'consultas_totales' => 0,
                'pacientes_unicos' => [],
                'femenino_unicos' => [],
                'masculino_unicos' => [],
                'edades' => [],
            ];
        }

        $diagnosticosMap[$clave]['consultas_totales']++;
        $diagnosticosMap[$clave]['pacientes_unicos'][$pId] = true;
        if ($sexoFemenino) {
            $diagnosticosMap[$clave]['femenino_unicos'][$pId] = true;
        } elseif ($sexoMasculino) {
            $diagnosticosMap[$clave]['masculino_unicos'][$pId] = true;
        }
        $diagnosticosMap[$clave]['edades'][] = $edad;
    }
}

$totalPacientesUnicos = count($pacientesAtendidosSet);

$comparacionPeriodo = null;
if ($fechaAnteriorInicio !== null && $fechaAnteriorFin !== null) {
    $consultaAnterior = $pdo->prepare(
        "SELECT COUNT(*) AS consultas, COUNT(DISTINCT h.paciente_id) AS pacientes
           FROM historial_clinico h
           INNER JOIN pacientes p ON p.id = h.paciente_id
          WHERE h.tipo = 'consulta' AND p.eliminado_en IS NULL
            AND h.institucion_id = ? AND p.institucion_id = ?
            AND h.fecha >= ? AND h.fecha <= ?"
    );
    $consultaAnterior->execute([
        $institucionId,
        $institucionId,
        $fechaAnteriorInicio . ' 00:00:00',
        $fechaAnteriorFin . ' 23:59:59',
    ]);
    $anterior = $consultaAnterior->fetch(PDO::FETCH_ASSOC) ?: ['consultas' => 0, 'pacientes' => 0];
    $comparacionPeriodo = [
        'titulo' => date('d/m/Y', strtotime($fechaAnteriorInicio)) . ' al ' . date('d/m/Y', strtotime($fechaAnteriorFin)),
        'consultas' => (int) $anterior['consultas'],
        'pacientes' => (int) $anterior['pacientes'],
    ];
}
$textoVariacion = static function (int $actual, int $anterior): string {
    $cambio = $actual - $anterior;
    $texto = ($cambio > 0 ? '+' : '') . $cambio;
    return $anterior > 0 ? $texto . ' (' . round(($cambio / $anterior) * 100, 1) . '%)' : $texto;
};

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
$textoCsv = static fn (string $valor): string => preg_match('/^\s*[=+\-@]/u', $valor) ? "'" . $valor : $valor;
if (isset($_GET['formato']) && $_GET['formato'] === 'compartir') {
    $diagnosticosIncluidos = count(array_filter($tablaMorbilidad, static fn (array $fila): bool => $fila['casos_unicos'] >= 5));
    registrarBitacora(
        'informes_morbilidad',
        0,
        'exportar',
        'agregada',
        null,
        mb_substr(
            'destinatario=' . $destinatarioCompartir . '; periodo=' . $tituloPeriodo
            . '; pacientes_autorizados=' . $totalPacientesUnicos . '; diagnosticos=' . $diagnosticosIncluidos,
            0,
            1000
        )
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="morbilidad_agregada_' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Resumen agregado de morbilidad']);
    fputcsv($out, ['Periodo', $tituloPeriodo]);
    fputcsv($out, ['Destinatario autorizado', $textoCsv($destinatarioCompartir)]);
    fputcsv($out, ['Finalidad autorizada', 'Análisis estadístico agregado de morbilidad']);
    fputcsv($out, ['Regla de privacidad', 'Solo se incluyen diagnósticos con al menos 5 pacientes; no se incluyen identificadores, edades ni sexo.']);
    fputcsv($out, ['Autorización', 'Solo se incluyen pacientes con autorización vigente registrada para este destinatario al generar el informe.']);
    fputcsv($out, ['Límite', 'La revocación impide informes futuros; no recupera copias ya entregadas.']);
    fputcsv($out, []);
    fputcsv($out, ['CIE-10', 'Diagnóstico', 'Categoría', 'Pacientes (mínimo 5)']);
    $incluidos = 0;
    foreach ($tablaMorbilidad as $row) {
        if ($row['casos_unicos'] < 5) {
            continue;
        }
        fputcsv($out, [
            $textoCsv((string) $row['cie10']),
            $textoCsv((string) $row['nombre']),
            $textoCsv((string) $row['categoria']),
            (int) $row['casos_unicos'],
        ]);
        $incluidos++;
    }
    if ($incluidos === 0) {
        fputcsv($out, ['', 'No hay diagnósticos que alcancen el mínimo de privacidad.', '', '']);
    }
    fclose($out);
    exit;
}

if (isset($_GET['formato']) && $_GET['formato'] === 'suive') {
    registrarBitacora('informes_morbilidad', 0, 'exportar', 'suive', null, $tituloPeriodo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="suive1_epidemiologia_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['SISTEMA NACIONAL DE VIGILANCIA EPIDEMIOLOGICA (SINAVE) - SUIVE-1']);
    fputcsv($out, ['ESTABLECIMIENTO:', config('nombre_establecimiento') ?: 'Expediente clínico', 'FECHA:', date('d/m/Y H:i')]);
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
        $linea = [$num++, $textoCsv((string) $row['cie10']), $textoCsv((string) $row['nombre']), $textoCsv((string) $row['categoria'])];
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
    registrarBitacora('informes_morbilidad', 0, 'exportar', 'detallado', null, $tituloPeriodo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="morbilidad_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['CODIGO_CIE10', 'DIAGNOSTICO', 'CATEGORIA', 'CASOS_UNICOS', 'FEMENINO', 'MASCULINO', 'PREVALENCIA', 'CONSULTAS_TOTALES', 'EDAD_MEDIA']);
    foreach ($tablaMorbilidad as $row) {
        fputcsv($out, [$textoCsv((string) $row['cie10']), $textoCsv((string) $row['nombre']), $textoCsv((string) $row['categoria']), $row['casos_unicos'], $row['femenino'], $row['masculino'], $row['porcentaje'] . '%', $row['consultas_totales'], $row['edad_promedio']]);
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
$imgTop = $imgPiramide = $imgCategorias = null;
$imagenes = [];
if (file_exists($scriptGraficas) && function_exists('exec')) {
    $payloadJson = [
        'top_morbilidad' => array_slice(array_map(fn($item) => [
            'nombre' => ($item['cie10'] !== 'SIN-COD' && $item['cie10'] !== 'SD-00' ? "[{$item['cie10']}] " : '') . $item['nombre'],
            'casos' => $item['casos_unicos'],
            'porcentaje' => $item['porcentaje']
        ], $tablaMorbilidad), 0, 10),
        'piramide_edad' => $listaPiramide,
        'categorias' => array_slice($listaCategorias, 0, 7)
    ];
    $directorioTmp = null;
    try {
        $directorioTmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'morbilidad_' . bin2hex(random_bytes(16));
        if (!mkdir($directorioTmp, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de las gráficas.');
        }
        $tmpJsonFile = $directorioTmp . DIRECTORY_SEPARATOR . 'datos.json';
        if (file_put_contents($tmpJsonFile, json_encode($payloadJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar la información agregada de las gráficas.');
        }
        $salida = [];
        $codigoSalida = 0;
        $comando = escapeshellarg($rutaPython) . ' ' . escapeshellarg($scriptGraficas)
            . ' ' . escapeshellarg($tmpJsonFile) . ' ' . escapeshellarg($directorioTmp) . ' 2>&1';
        exec($comando, $salida, $codigoSalida);
        if ($codigoSalida !== 0) {
            throw new RuntimeException('El generador terminó con error: ' . substr(implode("\n", $salida), 0, 1000));
        }
        foreach ([
            'morbilidad_top10.png' => 'top',
            'piramide_etaria.png' => 'piramide',
            'categorias_nosologicas.png' => 'categorias',
        ] as $archivo => $clave) {
            $rutaGrafica = $directorioTmp . DIRECTORY_SEPARATOR . $archivo;
            if (!is_file($rutaGrafica)) {
                throw new RuntimeException('El generador no creó la gráfica ' . $archivo . '.');
            }
            $contenido = file_get_contents($rutaGrafica);
            if ($contenido === false) {
                throw new RuntimeException('No se pudo leer la gráfica ' . $archivo . '.');
            }
            $imagenes[$clave] = 'data:image/png;base64,' . base64_encode($contenido);
        }
        $imgTop = $imagenes['top'];
        $imgPiramide = $imagenes['piramide'];
        $imgCategorias = $imagenes['categorias'];
    } catch (Throwable $e) {
        error_log('[morbilidad] No se pudieron generar las gráficas: ' . $e->getMessage());
    } finally {
        if ($directorioTmp !== null) {
            foreach (['datos.json', 'morbilidad_top10.png', 'piramide_etaria.png', 'categorias_nosologicas.png'] as $archivoTmp) {
                $rutaTmp = $directorioTmp . DIRECTORY_SEPARATOR . $archivoTmp;
                if (is_file($rutaTmp) && !unlink($rutaTmp)) {
                    error_log('[morbilidad] No se pudo retirar el archivo temporal ' . $archivoTmp . '.');
                }
            }
            if (is_dir($directorioTmp) && !rmdir($directorioTmp)) {
                error_log('[morbilidad] No se pudo retirar el directorio temporal de las gráficas.');
            }
        }
    }
}

$tituloPagina = 'Morbilidad';
$seccionActiva = 'datos';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina pagina--expediente morbilidad-pagina">

  <header class="morbilidad__impresion">
    <p class="morbilidad__impresion-institucion"><?= e(config('nombre_establecimiento') ?: 'Establecimiento de salud') ?></p>
    <h1>Informe de morbilidad</h1>
    <p class="morbilidad__impresion-periodo">Periodo: <?= e($tituloPeriodo) ?> <span>·</span> Emitido: <?= e(date('d/m/Y H:i')) ?></p>
    <dl class="morbilidad__impresion-resumen">
      <div><dt>Consultas</dt><dd><?= $totalConsultas ?></dd></div>
      <div><dt>Pacientes únicos</dt><dd><?= $totalPacientesUnicos ?></dd></div>
      <div><dt>Diagnósticos registrados</dt><dd><?= count($tablaMorbilidad) ?></dd></div>
      <div><dt>Categorías presentes</dt><dd><?= count($listaCategorias) ?></dd></div>
    </dl>
    <p class="morbilidad__impresion-alcance">Resumen interno: incluye solo diagnósticos asentados en las consultas del periodo; no representa el catálogo completo ni sustituye la validación clínica o epidemiológica.</p>
  </header>

  <div class="pagina__cabecera no-imprimir">
    <h1 class="pagina__titulo">Morbilidad</h1>
  </div>

  <div class="morbilidad__distribucion">
    <section class="bd morbilidad__tabla" data-bd>
    <div class="bd__cabecera">
      <div class="morbilidad__tabla-titulo">
        <h2 class="bd__titulo">Diagnósticos</h2>
        <span class="bd__contador"><?= count($tablaMorbilidad) ?></span>
      </div>
      <p class="morbilidad__alcance">Solo diagnósticos presentes en consultas registradas para este periodo.</p>

      <div class="bd__herramientas">
        <details class="morbilidad__acciones">
          <summary class="boton-icono" title="Exportar o imprimir" aria-label="Exportar o imprimir">
            <?= icono('mas') ?>
          </summary>
          <div class="morbilidad__acciones-lista" aria-label="Exportar o imprimir">
            <?php if (puede('exportar_morbilidad')): ?>
              <a href="?<?= e(http_build_query(array_merge($_GET, ['formato' => 'suive']))) ?>"><?= icono('documento') ?><span>Descargar SUIVE-1</span></a>
              <a href="?<?= e(http_build_query(array_merge($_GET, ['formato' => 'csv']))) ?>"><?= icono('tabla') ?><span>Descargar CSV detallado</span></a>
            <?php endif; ?>
            <?php if (puede('compartir_morbilidad')): ?>
              <?php if ($destinatariosMorbilidad): ?>
                <form action="<?= URL_BASE ?>/pacientes/datos.php" method="get" class="morbilidad__compartir-form">
                  <input type="hidden" name="formato" value="compartir">
                  <input type="hidden" name="periodo" value="<?= e($periodo) ?>">
                  <label for="morbilidad-destinatario">Destinatario autorizado</label>
                  <select id="morbilidad-destinatario" name="destinatario" required>
                    <option value="">Selecciona un destinatario</option>
                    <?php foreach ($destinatariosMorbilidad as $destinatario): ?>
                      <option value="<?= e($destinatario) ?>"><?= e($destinatario) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit"><?= icono('escudo') ?><span>CSV agregado autorizado</span></button>
                </form>
              <?php else: ?>
                <span class="morbilidad__sin-autorizaciones">No hay autorizaciones vigentes documentadas para compartir.</span>
              <?php endif; ?>
            <?php endif; ?>
            <button type="button" data-morbilidad-imprimir><?= icono('documento') ?><span>Imprimir informe</span></button>
          </div>
        </details>
      </div>
    </div>

    <div class="bd__marco morbilidad__tabla-marco" role="region" aria-label="Tabla de diagnósticos; desplaza horizontalmente para ver todas las columnas" tabindex="0">
      <table class="bd-tabla">
        <caption class="solo-lector">Diagnósticos y estadísticas de morbilidad</caption>
        <thead>
          <tr>
            <th scope="col"><button type="button"><span>Código CIE-10</span></button></th>
            <th scope="col"><button type="button"><span>Diagnóstico clínico</span></button></th>
            <th scope="col"><button type="button"><span>Categoría nosológica</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Casos únicos</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Mujeres / hombres</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Prevalencia</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Consultas</span></button></th>
            <th scope="col" class="num"><button type="button"><span>Edad media</span></button></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tablaMorbilidad as $row): ?>
            <tr>
              <td>
                <span class="morbilidad__codigo"><?= e($row['cie10']) ?></span>
              </td>
              <td class="bd-tabla__celda-titulo">
                <span class="bd-tabla__titulo">
                  <span><?= e($row['nombre']) ?></span>
                </span>
              </td>
              <td><?= e($row['categoria']) ?></td>
              <td class="num morbilidad__casos"><?= (int)$row['casos_unicos'] ?></td>
              <td class="num"><?= (int)$row['femenino'] ?> / <?= (int)$row['masculino'] ?></td>
              <td class="num morbilidad__prevalencia"><?= $row['porcentaje'] ?>%</td>
              <td class="num"><?= (int)$row['consultas_totales'] ?></td>
              <td class="num"><?= $row['edad_promedio'] ?> a</td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tablaMorbilidad): ?>
            <tr><td class="bd__vacio" colspan="8">No hay diagnósticos registrados para el periodo seleccionado.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    </section>

    <aside class="morbilidad__lateral" aria-label="Resumen y gráficas">
      <dl class="hoja-paciente__resumen morbilidad__resumen" aria-label="Resumen de morbilidad">
        <div><dt>Total consultas</dt><dd><?= $totalConsultas ?></dd><?php if ($comparacionPeriodo): ?><small class="morbilidad__variacion" title="Comparado con <?= e($comparacionPeriodo['titulo']) ?>"><?= e($textoVariacion($totalConsultas, $comparacionPeriodo['consultas'])) ?> vs. periodo anterior</small><?php endif; ?></div>
        <div><dt>Pacientes únicos</dt><dd><?= $totalPacientesUnicos ?></dd><?php if ($comparacionPeriodo): ?><small class="morbilidad__variacion" title="Comparado con <?= e($comparacionPeriodo['titulo']) ?>"><?= e($textoVariacion($totalPacientesUnicos, $comparacionPeriodo['pacientes'])) ?> vs. periodo anterior</small><?php endif; ?></div>
        <div><dt>Patología principal</dt><dd class="morbilidad__patologia" title="<?= e($tablaMorbilidad[0]['nombre'] ?? '—') ?>"><?= e($tablaMorbilidad[0]['nombre'] ?? '—') ?></dd></div>
        <div><dt>Prevalencia principal</dt><dd><?= ($tablaMorbilidad[0]['porcentaje'] ?? 0) ?>%</dd></div>
        <div><dt>Mujeres / hombres</dt><dd><?= ($totalFem + $totalMasc) > 0 ? round(($totalFem / ($totalFem + $totalMasc)) * 100) : 0 ?>% / <?= ($totalFem + $totalMasc) > 0 ? round(($totalMasc / ($totalFem + $totalMasc)) * 100) : 0 ?>%</dd></div>
        <div><dt>Categorías</dt><dd><?= count($listaCategorias) ?></dd></div>
      </dl>

      <section class="morbilidad__graficas" aria-labelledby="morbilidad-graficas-titulo">
        <div class="ficha__titulo-fila">
          <h2 class="ficha__titulo" id="morbilidad-graficas-titulo">Gráficas</h2>
          <span class="ficha__fuente"><?= e($tituloPeriodo) ?></span>
        </div>

        <nav class="morbilidad__periodos" aria-label="Periodo del informe">
          <a href="?periodo=todos"<?= $periodo === 'todos' ? ' aria-current="page"' : '' ?>>Histórico</a>
          <a href="?periodo=anio"<?= $periodo === 'anio' ? ' aria-current="page"' : '' ?>>Este año</a>
          <a href="?periodo=12m"<?= $periodo === '12m' ? ' aria-current="page"' : '' ?>>12 meses</a>
          <a href="?periodo=mes"<?= $periodo === 'mes' ? ' aria-current="page"' : '' ?>>30 días</a>
        </nav>

        <div class="morbilidad__figuras">
          <figure class="morbilidad__figura">
            <?php if ($imgTop): ?><img src="<?= e($imgTop) ?>" alt="Gráfica de las diez principales causas de morbilidad"><?php else: ?><p class="morbilidad__grafica-vacia">Gráfica no disponible en este momento.</p><?php endif; ?>
            <figcaption>Diez principales causas de morbilidad</figcaption>
          </figure>
          <figure class="morbilidad__figura">
            <?php if ($imgPiramide): ?><img src="<?= e($imgPiramide) ?>" alt="Gráfica de pacientes por grupo de edad y sexo"><?php else: ?><p class="morbilidad__grafica-vacia">Gráfica no disponible en este momento.</p><?php endif; ?>
            <figcaption>Grupos de edad y sexo</figcaption>
          </figure>
          <figure class="morbilidad__figura">
            <?php if ($imgCategorias): ?><img src="<?= e($imgCategorias) ?>" alt="Gráfica de distribución por categorías nosológicas"><?php else: ?><p class="morbilidad__grafica-vacia">Gráfica no disponible en este momento.</p><?php endif; ?>
            <figcaption>Distribución por categorías nosológicas</figcaption>
          </figure>
        </div>
      </section>
    </aside>
  </div>

  <footer class="morbilidad__impresion-firmas">
    <div><span>Responsable de revisión</span></div>
    <div><span>Validación institucional</span></div>
  </footer>

</div>

<?php if (!empty($_GET['imprimir'])): ?>
<script>
window.addEventListener('DOMContentLoaded', () => {
  window.print();
});
</script>
<?php endif; ?>



<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
