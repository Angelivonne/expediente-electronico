<?php
/** Datos geográficos de pacientes, solicitados solo al abrir el mapa. */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');
require_once __DIR__ . '/../../app/servicios/clinica/mapa.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$porEntidad = array_fill_keys(array_keys(MAPA_MEXICO), []);
$conteos = array_fill_keys(array_keys(MAPA_MEXICO), 0);
$sinEntidad = 0;
$textoAEntidad = [];

$porMunicipio = db()->prepare('SELECT municipio_estado, COUNT(*) AS n FROM pacientes WHERE institucion_id = ? AND eliminado_en IS NULL GROUP BY municipio_estado');
$porMunicipio->execute([institucionId()]);
foreach ($porMunicipio as $fila) {
    $clave = entidadDesdeTexto($fila['municipio_estado']);
    if ($clave === null) {
        $sinEntidad += (int) $fila['n'];
        continue;
    }
    $conteos[$clave] += (int) $fila['n'];
    $textoAEntidad[(string) $fila['municipio_estado']] = $clave;
}

if ($textoAEntidad) {
    $marcas = implode(',', array_fill(0, count($textoAEntidad), '?'));
    $nombres = db()->prepare("SELECT id, nombre, primer_apellido, segundo_apellido, municipio_estado FROM pacientes
                               WHERE institucion_id = ? AND eliminado_en IS NULL AND municipio_estado IN ($marcas)
                               ORDER BY creado_en DESC, id DESC LIMIT 3000");
    $nombres->execute([institucionId(), ...array_keys($textoAEntidad)]);
    foreach ($nombres as $fila) {
        $clave = $textoAEntidad[$fila['municipio_estado']];
        if (count($porEntidad[$clave]) < 40) {
            $porEntidad[$clave][] = [
                'id' => (int) $fila['id'],
                'nombre' => trim("{$fila['nombre']} {$fila['primer_apellido']} {$fila['segundo_apellido']}"),
            ];
        }
    }
}

$maxConteo = max($conteos) ?: 0;
$niveles = array_map(fn ($n) => $n === 0 ? 0 : max(1, (int) ceil($n / $maxConteo * 5)), $conteos);
$textos = [];
$ranking = [];
foreach ($conteos as $clave => $n) {
    $textos[$clave] = ENTIDADES[$clave] . ' · ' . ($n === 1 ? '1 paciente' : "$n pacientes");
    $ranking[] = [
        'clave' => $clave,
        'nombre' => ENTIDADES[$clave],
        'total' => $n,
        'porcentaje' => $maxConteo ? (int) round($n / $maxConteo * 100) : 0,
    ];
}
usort($ranking, fn ($a, $b) => $b['total'] <=> $a['total']);
$cortes = $maxConteo > 0
    ? array_values(array_unique(array_map(fn ($k) => (int) ceil($k / 5 * $maxConteo), [1, 2, 3, 4])))
    : [];

$identificados = array_sum($conteos);
echo json_encode([
    'ok' => true,
    'pacientes' => $porEntidad,
    'total' => $identificados,
    'sinEntidad' => $sinEntidad,
    'conteos' => $conteos,
    'nombres' => array_intersect_key(ENTIDADES, MAPA_MEXICO),
    'ranking' => $ranking,
    'cortes' => $cortes,
    'mapa' => mapaMexico($niveles, $textos),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
