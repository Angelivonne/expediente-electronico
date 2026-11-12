<?php
/**
 * Busca valores de los catálogos generales para la paleta común.
 * GET q. Solo consulta; omite CIE-10 y medicamentos, que tienen buscadores propios.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/datos/catalogos_sistema.php';
require_once __DIR__ . '/../../app/servicios/motor/almacen.php';

exigirPermiso('ver_expediente');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$busqueda = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($busqueda, 'UTF-8') < 2) {
    echo json_encode(['total' => 0, 'catalogos' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$resultados = [];
$total = 0;
foreach (catalogosSistema() as $clave => $catalogo) {
    if (in_array($clave, ['cie10', 'medicamentos'], true) || empty($catalogo['busqueda'])) {
        continue;
    }

    try {
        [$condicion, $parametros] = condicionBusqueda((array) $catalogo['busqueda'], $busqueda);
        $tabla = (string) $catalogo['tabla'];
        if (!empty($catalogo['institucion']) && columnaExiste($tabla, 'institucion_id')) {
            $condicion = 'c.institucion_id = ? AND ' . $condicion;
            array_unshift($parametros, institucionId());
        }

        $columnasVista = (array) ($catalogo['columnas'] ?? []);
        $nombreColumna = $columnasVista[0] ?? null;
        foreach ($columnasVista as $columnaVista) {
            if (in_array((string) ($columnaVista[1] ?? ''), ['nombre', 'valor'], true)) {
                $nombreColumna = $columnaVista;
                break;
            }
        }
        $detalleColumna = $columnasVista[1] ?? $nombreColumna;
        foreach ($columnasVista as $columnaVista) {
            if (in_array((string) ($columnaVista[1] ?? ''), ['clave', 'codigo'], true)) {
                $detalleColumna = $columnaVista;
                break;
            }
        }
        if (!is_array($nombreColumna) || !is_array($detalleColumna)) {
            continue;
        }
        $expresionNombre = (string) ($nombreColumna[0] ?? '');
        $expresionDetalle = (string) ($detalleColumna[0] ?? '');
        $desde = (string) ($catalogo['desde'] ?? ('`' . $tabla . '` c'));
        if (!preg_match('/^[A-Za-z0-9_().,`\' ]{1,240}$/', $expresionNombre)
            || !preg_match('/^[A-Za-z0-9_().,`\' ]{1,240}$/', $expresionDetalle)
            || !preg_match('/^[A-Za-z0-9_().,`\' ]{1,500}$/', $desde)) {
            continue;
        }

        $orden = (string) ($catalogo['orden'] ?? 'c.id');
        if (!preg_match('/^[A-Za-z0-9_().,`\' ]{1,240}$/', $orden)) {
            $orden = 'c.id';
        }
        $consulta = db()->prepare(
            "SELECT c.id, ($expresionNombre) AS nombre, ($expresionDetalle) AS detalle
               FROM $desde
              WHERE $condicion
              ORDER BY $orden
              LIMIT 3"
        );
        $consulta->execute($parametros);
        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            $resultados[] = [
                'tipo' => 'catalogo',
                'nombre' => $nombre,
                'detalle' => (string) ($fila['detalle'] ?? ''),
                'catalogo' => (string) $catalogo['titulo'],
                'url' => URL_BASE . '/catalogos/editar_tabla.php?' . http_build_query([
                    'tabla' => (string) $clave,
                    'q' => $busqueda,
                ]),
            ];
            $total++;
        }
    } catch (Throwable $errorBusqueda) {
        // Los catálogos opcionales o aún no migrados no interrumpen la búsqueda general.
        continue;
    }

    if (count($resultados) >= 20) {
        break;
    }
}

echo json_encode(['total' => $total, 'catalogos' => array_slice($resultados, 0, 20)], JSON_UNESCAPED_UNICODE);
