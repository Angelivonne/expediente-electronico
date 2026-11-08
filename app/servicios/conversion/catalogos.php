<?php
// Lista blanca y estructura importable de los catálogos del sistema.
// Los nombres de tabla/columna proceden del registro y del esquema real.
require_once __DIR__ . '/../../datos/catalogos_sistema.php';

const MAXIMO_FILAS_CATALOGO = 5000;

function detalleCatalogoImportacion(string $clave): ?array
{
    static $cache = [];
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }

    $catalogo = catalogoSistema($clave);
    if (!$catalogo) {
        return $cache[$clave] = null;
    }

    $tabla = (string) ($catalogo['tabla'] ?? '');
    if (!preg_match('/^(?:cie10|medicamentos|catalogo_[a-z0-9_]+)$/', $tabla)) {
        return $cache[$clave] = null;
    }

    $columnasRegistradas = [];
    foreach ($catalogo['columnas'] ?? [] as $columna) {
        if (isset($columna[0], $columna[1])
            && preg_match('/^c\.([a-z][a-z0-9_]*)$/i', trim((string) $columna[0]), $coincidencia)) {
            $campo = $coincidencia[1];
            if (preg_match('/^[a-z][a-z0-9_]*$/i', $campo)) {
                $columnasRegistradas[$campo] = isset($columna[2]) ? (string) $columna[2] : (string) $columna[1];
            }
        }
    }

    try {
        $consulta = db()->query("SHOW COLUMNS FROM `$tabla`");
        $esquema = $consulta->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $cache[$clave] = null;
    }

    $reales = [];
    foreach ($esquema as $columna) {
        $nombre = (string) ($columna['Field'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $nombre)) {
            $reales[$nombre] = $columna;
        }
    }
    if (!isset($reales['id'])) {
        return $cache[$clave] = null;
    }

    $columnaClave = null;
    foreach (['clave', 'codigo', 'cp', 'codigo_postal', 'valor'] as $candidata) {
        if (isset($reales[$candidata], $columnasRegistradas[$candidata])) {
            $columnaClave = $candidata;
            break;
        }
    }
    if ($columnaClave === null) {
        return $cache[$clave] = null;
    }

    $definiciones = [];
    $agregar = static function (string $campo, string $columna, string $texto) use (&$definiciones, $reales): void {
        if (!isset($reales[$columna]) || isset($definiciones[$campo])) {
            return;
        }
        $meta = $reales[$columna];
        $tipoSql = strtolower((string) ($meta['Type'] ?? ''));
        if (preg_match('/(?:blob|binary|varbinary|json)/', $tipoSql)) {
            return;
        }
        $extra = strtolower((string) ($meta['Extra'] ?? ''));
        if (str_contains($extra, 'generated') || str_contains($extra, 'auto_increment')) {
            return;
        }
        $tipo = $campo === 'activo' ? 'activo'
            : (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double)/', $tipoSql) ? 'numero'
                : (preg_match('/^(?:date|datetime|timestamp)/', $tipoSql) ? 'fecha' : 'texto'));
        preg_match('/\((\d+)\)/', $tipoSql, $tamano);
        $maximo = isset($tamano[1]) ? (int) $tamano[1] : 10000;
        $requerido = strtoupper((string) ($meta['Null'] ?? 'YES')) === 'NO'
            && ($meta['Default'] ?? null) === null
            && !str_contains($extra, 'auto_increment');
        if ($campo === 'clave') {
            $requerido = true;
        } elseif ($campo === 'activo') {
            $requerido = false;
        }
        $opciones = [];
        if (str_starts_with($tipoSql, 'enum(') && preg_match_all("/'((?:\\\\.|[^'])*)'/", $tipoSql, $valoresEnum)) {
            $opciones = array_map(static fn ($valor) => stripcslashes($valor), $valoresEnum[1]);
        }
        $definiciones[$campo] = [
            'campo' => $campo,
            'columna' => $columna,
            'texto' => $texto,
            'tipo' => $tipo,
            'tipo_sql' => $tipoSql,
            'opciones' => $opciones,
            'req' => $requerido,
            'maximo' => max(1, min($maximo, 10000)),
        ];
    };

    $etiquetaClave = in_array($columnaClave, ['codigo', 'cp', 'codigo_postal'], true) ? 'Clave o código' : 'Clave';
    if ($columnaClave === 'valor') {
        $etiquetaClave = 'Valor';
    }
    $agregar('clave', $columnaClave, $etiquetaClave);
    foreach (['nombre' => 'Nombre', 'descripcion' => 'Descripción', 'activo' => 'Activo'] as $campo => $texto) {
        if (isset($columnasRegistradas[$campo])) {
            $agregar($campo, $campo, $texto);
        }
    }

    foreach ($columnasRegistradas as $columna => $texto) {
        if (isset($definiciones['clave']) && $definiciones['clave']['columna'] === $columna) {
            continue;
        }
        if (in_array($columna, ['id', 'institucion_id', 'nombre', 'descripcion', 'activo'], true)
            || preg_match('/(?:^|_)id$/i', $columna)
            || isset($definiciones[$columna])) {
            continue;
        }
        $agregar($columna, $columna, $texto);
    }

    if (!isset($definiciones['clave']) || (!isset($definiciones['nombre']) && !isset($definiciones['descripcion']))) {
        return $cache[$clave] = null;
    }

    $institucion = isset($reales['institucion_id']);
    if (!empty($catalogo['institucion']) && !$institucion) {
        return $cache[$clave] = null;
    }

    foreach ($reales as $nombre => $meta) {
        if ($nombre === 'id' || $nombre === 'institucion_id' || isset($definiciones['clave']) && $definiciones['clave']['columna'] === $nombre
            || isset($definiciones[$nombre])) {
            continue;
        }
        $extra = strtolower((string) ($meta['Extra'] ?? ''));
        if (str_contains($extra, 'generated') || str_contains($extra, 'auto_increment')) {
            continue;
        }
        $requeridaSinDestino = strtoupper((string) ($meta['Null'] ?? 'YES')) === 'NO'
            && ($meta['Default'] ?? null) === null;
        if ($requeridaSinDestino) {
            return $cache[$clave] = null;
        }
    }

    return $cache[$clave] = [
        'clave' => $clave,
        'titulo' => (string) $catalogo['titulo'],
        'descripcion' => (string) ($catalogo['descripcion'] ?? ''),
        'tabla' => $tabla,
        'institucion' => $institucion,
        'campos' => $definiciones,
        'columna_clave' => $columnaClave,
        'maximo_filas' => MAXIMO_FILAS_CATALOGO,
    ];
}

function catalogosImportables(): array
{
    $salida = [];
    foreach (catalogosSistema() as $clave => $catalogo) {
        try {
            $detalle = detalleCatalogoImportacion((string) $clave);
        } catch (Throwable) {
            $detalle = null;
        }
        if (!$detalle) {
            continue;
        }
        $salida[] = [
            'clave' => $detalle['clave'],
            'texto' => $detalle['titulo'],
            'nota' => $detalle['descripcion'],
            'campos' => array_values(array_map(static fn ($campo) => [
                'campo' => $campo['campo'],
                'texto' => $campo['texto'],
                'req' => $campo['req'],
                'tipo' => $campo['tipo'],
            ], $detalle['campos'])),
        ];
    }
    return $salida;
}
