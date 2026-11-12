<?php
/** Funciones compartidas para exportar e importar los CSV de Catálogos. */

function catalogoNormalizarCabeceraCsv(string $texto): string
{
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
    $texto = mb_strtolower(trim((string) $texto), 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n',
    ]);
    $texto = preg_replace('/[^a-z0-9]+/', '_', $texto);
    return trim((string) $texto, '_');
}

/** Lee el esquema real de una tabla validada por el registro de Catálogos. */
function catalogoColumnasCsv(string $tabla): array
{
    if (!preg_match('/^[a-z0-9_]{1,64}$/i', $tabla)) {
        throw new InvalidArgumentException('La tabla del catálogo no es válida.');
    }

    $columnas = [];
    foreach (db()->query('SHOW COLUMNS FROM `' . $tabla . '`') as $columna) {
        $columnas[(string) $columna['Field']] = $columna;
    }
    return $columnas;
}

/** Omite claves técnicas; conserva las columnas de datos y referencias importables. */
function catalogoCamposCsv(array $columnas): array
{
    $protegidas = ['id', 'institucion_id', 'uuid', 'creado_en', 'actualizado_en', 'sinc_modificado_en'];
    $campos = [];
    foreach ($columnas as $nombre => $columna) {
        if (in_array($nombre, $protegidas, true) || str_contains((string) ($columna['Extra'] ?? ''), 'GENERATED')) {
            continue;
        }
        if (preg_match('/^[a-z0-9_]{1,64}$/i', (string) $nombre)) {
            $campos[] = (string) $nombre;
        }
    }
    return $campos;
}

/**
 * Devuelve la clave natural a partir de los índices únicos reales.
 * Las tablas geográficas pueden requerir una combinación, por ejemplo entidad_id + clave.
 */
function catalogoLlavesCsv(string $tabla, array $columnas): array
{
    $permitidas = array_flip(catalogoCamposCsv($columnas));
    $indices = [];
    foreach (db()->query('SHOW INDEX FROM `' . $tabla . '`') as $indice) {
        if ((int) $indice['Non_unique'] !== 0 || (string) $indice['Key_name'] === 'PRIMARY') {
            continue;
        }
        $nombre = (string) $indice['Key_name'];
        $posicion = (int) $indice['Seq_in_index'];
        $campo = (string) $indice['Column_name'];
        if ($campo !== 'institucion_id' && isset($permitidas[$campo])) {
            $indices[$nombre][$posicion] = $campo;
        }
    }
    $preferidas = ['clave', 'codigo', 'cp', 'codigo_postal', 'valor', 'clues'];
    $candidatas = [];
    foreach ($indices as $campos) {
        ksort($campos);
        $campos = array_values($campos);
        if ($campos) {
            $preferencia = (count(array_diff($campos, $preferidas)) * 100)
                + (in_array('uuid', $campos, true) ? 10000 : 0)
                + count($campos);
            $candidatas[] = [$preferencia, $campos];
        }
    }
    usort($candidatas, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    return $candidatas[0][1] ?? [];
}
