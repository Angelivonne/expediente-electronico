<?php
/**
 * Motor único de datos — vistas.
 *
 * Convierte la definición de una entidad en columnas para pintar (tabla, ficha, formulario).
 * Es el único lugar donde se decide cómo se ve un campo según su tipo; ninguna pantalla lo repite.
 *
 * Si la entidad declara 'vista' => ['columnas' => [...], 'grupos' => [...]], se respeta ese orden,
 * sus títulos, anchos e iconos y sus columnas calculadas (clave 'calculo').
 */
require_once __DIR__ . '/entidades.php';

/** Ancho por defecto de una columna según el tipo de campo. */
const MOTOR_ANCHOS = [
    'texto' => 220, 'curp' => 190, 'correo' => 220, 'fecha' => 150, 'fecha_hora' => 170,
    'numero' => 110, 'casilla' => 90, 'opcion' => 140,
];

/** Icono de la columna según el tipo de campo. */
function iconoPorTipoMotor(string $tipo): string
{
    return match ($tipo) {
        'fecha', 'fecha_hora' => 'calendario',
        'numero'              => 'numero',
        'casilla'             => 'casilla',
        'opcion'              => 'seleccion',
        'correo'              => 'mensaje',
        default               => 'texto',
    };
}

/** Columnas de una entidad para una vista de tabla, en el orden declarado. */
function columnasVista(string $clave, array $opciones = []): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad) {
        return [];
    }

    $definicion = $entidad['vista']['columnas'] ?? null;
    if (!is_array($definicion)) {
        // Sin vista declarada: una columna por campo, en el orden del registro
        $definicion = [];
        foreach (array_keys($entidad['campos']) as $campo) {
            $definicion[] = ['clave' => $campo];
        }
    }

    // Límites de grupo por posición (los grupos son rangos consecutivos de columnas)
    $limites = [];
    $acum    = 0;
    foreach ((array) ($entidad['vista']['grupos'] ?? []) as $nombre => $cuantas) {
        $acum     += (int) $cuantas;
        $limites[] = ['nombre' => (string) $nombre, 'hasta' => $acum];
    }
    $grupoDe = function (int $i) use ($limites): ?string {
        foreach ($limites as $limite) {
            if ($i < $limite['hasta']) {
                return $limite['nombre'];
            }
        }
        return null;
    };

    $columnas = [];
    foreach ($definicion as $i => $spec) {
        $campo   = (string) ($spec['clave'] ?? '');
        $def     = $entidad['campos'][$campo] ?? null;
        $calculo = $spec['calculo'] ?? null;
        $tipo    = (string) ($def['tipo'] ?? 'texto');

        $columnas[] = [
            'clave'    => $campo,
            'campo'    => $calculo ? null : ($def ? $campo : null),
            'editable' => !$calculo && $def !== null && empty($def['solo_lectura']) && ($spec['editable'] ?? true),
            'calculo'  => $calculo,
            'titulo'   => (string) ($spec['titulo'] ?? $def['texto'] ?? $campo),
            'tipo'     => $calculo ? 'calculo' : $tipo,
            'icono'    => (string) ($spec['icono'] ?? $def['icono'] ?? iconoPorTipoMotor($calculo ? 'texto' : $tipo)),
            'ancho'    => (int) ($spec['ancho'] ?? $def['ancho'] ?? (MOTOR_ANCHOS[$tipo] ?? 220)),
            'orden'    => (string) ($spec['orden'] ?? ($tipo === 'numero' ? 'numero' : (in_array($tipo, ['fecha', 'fecha_hora'], true) ? 'fecha' : 'texto'))),
            'editor'   => in_array($tipo, ['casilla', 'opcion'], true) ? 'opciones' : 'texto',
            'opciones' => $def['opciones'] ?? ($tipo === 'casilla' ? ['1' => 'Sí', '0' => 'No'] : null),
            'grupo'    => $grupoDe((int) $i),
        ];
    }
    return $columnas;
}

/** Opciones de los editores en línea de una vista (campo => [valor => texto]). */
function opcionesEditorMotor(string $clave): array
{
    $opciones = [];
    foreach (columnasVista($clave) as $columna) {
        if (!empty($columna['opciones']) && $columna['editable'] && $columna['campo'] !== null) {
            $opciones[$columna['campo']] = $columna['opciones'];
        }
    }
    return $opciones;
}
