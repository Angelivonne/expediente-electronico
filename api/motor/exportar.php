<?php
/**
 * Motor único — exportación CSV de una entidad con la vista actual.
 *
 * GET ?entidad=pacientes&q=…&campo=sexo&valor=M&orden=nombre&dir=asc
 * Respeta permisos y el límite de exportaciones; el archivo abre directo en Excel (UTF-8 con BOM).
 */
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../../app/servicios/motor/vistas.php';
require_once __DIR__ . '/../../app/servicios/conversion/csv.php';

$clave   = (string) ($_GET['entidad'] ?? '');
$entidad = entidadMotor($clave);
if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla') {
    http_response_code(404);
    exit('Esa entidad no existe.');
}
exigirPermiso($entidad['permiso'] ?? 'ver_expediente');

// Límite de exportaciones: 30 por cada 10 minutos y usuario
$claveLimite = 'exportar:' . (int) usuarioActual()['id'];
if (limiteSuperado($claveLimite, 30)) {
    http_response_code(429);
    exit('Demasiadas exportaciones seguidas. Espera unos minutos.');
}
contarIntento($claveLimite, 600);

$busqueda = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$campo    = trim((string) ($_GET['campo'] ?? ''));
$valor    = trim((string) ($_GET['valor'] ?? ''));
$filtro   = ($campo !== '' && $valor !== '') ? [$campo => $valor] : [];
$orden    = trim((string) ($_GET['orden'] ?? ''));
$dir      = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$desde    = trim((string) ($_GET['desde'] ?? ''));
$hasta    = trim((string) ($_GET['hasta'] ?? ''));
$rango    = [];
foreach (['desde' => $desde, 'hasta' => $hasta] as $limite => $fecha) {
    if ($fecha !== '') {
        $fechaValida = DateTime::createFromFormat('!Y-m-d', $fecha);
        if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
            http_response_code(400);
            exit('El rango de fechas no es válido.');
        }
        $rango['fecha'][$limite] = $fecha;
    }
}
if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    http_response_code(400);
    exit('La fecha inicial no puede ser posterior a la final.');
}

$opcionesLista = ['q' => $busqueda, 'filtro' => $filtro, 'orden' => $orden, 'dir' => $dir, 'limite' => 500, 'pagina' => 1];
if ($rango) {
    $opcionesLista['rango'] = $rango;
}
$pdo = db();
$pdo->beginTransaction();
try {
    $lista = motorListar($clave, $opcionesLista);
    if ($lista['total'] > 5000) {
        $pdo->rollBack();
        http_response_code(413);
        exit('La exportación supera 5,000 registros. Acota filtros o usa el módulo completo de importación y exportación.');
    }
    $filas = $lista['filas'];
    $paginasExportar = (int) ceil($lista['total'] / 500);
    for ($paginaExportar = 2; $paginaExportar <= $paginasExportar; $paginaExportar++) {
        $opcionesLista['pagina'] = $paginaExportar;
        $filas = array_merge($filas, motorListar($clave, $opcionesLista)['filas']);
    }
    $pdo->commit();
} catch (Throwable $errorExportacion) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $errorExportacion;
}
$columnas = columnasVista($clave);

$nombreArchivo = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($entidad['titulo'])) . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Cache-Control: no-store');
header('X-Registros: ' . count($filas));
header('X-Total-Registros: ' . $lista['total']);

$salida = fopen('php://output', 'w');
fwrite($salida, "\xEF\xBB\xBF");   // BOM: Excel respeta los acentos
fputcsv($salida, array_map(fn ($c) => (string) $c['titulo'], $columnas));

foreach ($filas as $fila) {
    $linea = [];
    foreach ($columnas as $columna) {
        if (!empty($columna['calculo'])) {
            $dato = valorCalculadoExportacion($columna, $fila);
        } elseif (!empty($columna['campo'])) {
            $dato = $fila[$columna['campo']] ?? '';
        } else {
            $dato = $fila[$columna['clave']] ?? '';
            if ($dato === '' && isset($fila['extras']['_perfil'][$columna['clave']])) {
                $dato = $fila['extras']['_perfil'][$columna['clave']];
            }
        }
        if (is_bool($dato)) {
            $dato = $dato ? 'Sí' : 'No';
        } elseif (($columna['tipo'] ?? '') === 'casilla') {
            $dato = $dato ? 'Sí' : 'No';
        } elseif (!empty($columna['opciones'][$dato])) {
            $dato = $columna['opciones'][$dato];
        }
        $linea[] = convProtegerFormula((string) $dato);
    }
    fputcsv($salida, $linea);
}
fclose($salida);

/** Valor de una columna calculada de la vista, sin depender de la pantalla. */
function valorCalculadoExportacion(array $columna, array $fila): string
{
    $calculo = $columna['calculo'] ?? null;
    if (is_callable($calculo) && !is_string($calculo)) {
        return (string) $calculo($fila);
    }
    $clave = is_string($calculo) ? $calculo : (string) ($columna['clave'] ?? '');
    switch ($clave) {
        case 'nombre_completo':
            return trim(implode(' ', array_filter([$fila['nombre'] ?? '', $fila['primer_apellido'] ?? '', $fila['segundo_apellido'] ?? ''])));
        case 'fecha_nacimiento':
            return ($fila['fecha_nacimiento'] ?? '') !== '' ? fechaCorta((string) $fila['fecha_nacimiento']) : '';
        case 'edad':
            return ($fila['fecha_nacimiento'] ?? '') !== '' ? (string) edad((string) $fila['fecha_nacimiento']) : '';
        case 'confirmacion':
            return !empty($fila['confirmacion_datos']) ? 'Sí' : 'No';
        case 'registro':
            $creado = (string) ($fila['creado_en'] ?? '');
            return $creado !== '' ? trim(fechaCorta(substr($creado, 0, 10)) . ' ' . hora12($creado)) : '';
    }
    return (string) ($fila[$clave] ?? '');
}