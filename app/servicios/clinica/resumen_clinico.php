<?php
/**
 * Resumen clínico que se arma solo a partir del historial (nada se captura dos veces):
 * lista de diagnósticos, tratamientos activos según sus recetas y hospitalizaciones.
 */

/** Diagnósticos de todas las entradas, agrupados por código CIE-10 (o por descripción), del más reciente al más antiguo. */
function diagnosticosDelHistorial(array $historial): array
{
    $grupos = [];
    foreach ($historial as $h) {
        $d = json_decode($h['detalle'], true) ?: [];
        $lista = $d['diagnosticos'] ?? [];
        if (!$lista && !empty($d['diagnostico'])) {
            $lista = array_map(fn ($t) => ['descripcion' => trim($t), 'cie10' => ''], preg_split('/[;\n]+/', (string) $d['diagnostico']));
        }
        foreach ($lista as $dx) {
            $descripcion = trim((string) ($dx['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $cie = strtoupper(trim((string) ($dx['cie10'] ?? '')));
            $clave = $cie !== '' ? $cie : mb_strtolower($descripcion);
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = ['descripcion' => $descripcion, 'cie10' => $cie, 'ultima' => $h['fecha'], 'primera' => $h['fecha'],
                                   'veces' => 0, 'tipo' => $h['tipo'], 'entrada' => (int) $h['id']];
            }
            $grupos[$clave]['veces']++;
            $grupos[$clave]['primera'] = min($grupos[$clave]['primera'], $h['fecha']);
        }
    }
    return array_values($grupos);
}

/** Último estado documentado de cada medicamento; se muestran tratamientos activos según sus fechas. */
function tratamientosActivosDelHistorial(array $historial): array
{
    $vistos = [];
    $lista = [];
    foreach ($historial as $h) {
        if ($h['tipo'] !== 'receta') {
            continue;
        }
        foreach ((json_decode($h['detalle'], true)['medicamentos'] ?? []) as $m) {
            $clave = !empty($m['medicamento_id'])
                ? 'catalogo:' . (int) $m['medicamento_id']
                : mb_strtolower(trim((string) ($m['nombre'] ?? '')));
            if ($clave === '' || isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;
            if (($m['estado_tratamiento'] ?? '') !== 'activo') continue;
            if (!empty($m['fecha_inicio']) && $m['fecha_inicio'] > date('Y-m-d')) continue;
            if (!empty($m['fecha_fin']) && $m['fecha_fin'] < date('Y-m-d')) continue;
            $lista[] = $m + ['fecha' => $h['fecha'], 'entrada' => (int) $h['id'], 'autor' => $h['autor_nombre']];
        }
    }
    return $lista;
}

/** Hospitalizaciones (con estancia calculada y si sigue hospitalizado). */
function hospitalizacionesDelHistorial(array $historial): array
{
    $lista = [];
    foreach ($historial as $h) {
        if ($h['tipo'] !== 'hospitalizacion') {
            continue;
        }
        $d = json_decode($h['detalle'], true) ?: [];
        $ingreso = substr((string) ($d['ingreso'] ?? $h['fecha']), 0, 10);
        $egreso = !empty($d['egreso']) ? substr($d['egreso'], 0, 10) : null;
        $lista[] = [
            'entrada' => (int) $h['id'], 'motivo' => $h['titulo'], 'servicio' => $d['servicio'] ?? '', 'cama' => $d['cama'] ?? '',
            'ingreso' => $ingreso, 'egreso' => $egreso, 'activa' => $egreso === null,
            'dias' => max(1, (int) round((strtotime($egreso ?? date('Y-m-d')) - strtotime($ingreso)) / 86400)),
            'estado' => $d['estado_egreso'] ?? '',
        ];
    }
    return $lista;
}

