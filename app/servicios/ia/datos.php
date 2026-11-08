<?php
/**
 * Herramienta «datos» del asistente: una sola puerta a todas las entidades del motor.
 *
 * La IA puede describir, listar, obtener, crear, actualizar y dar de baja cualquier entidad
 * (pacientes, medicamentos, diagnósticos, catálogos, citas…) sin herramientas nuevas por pantalla.
 * Los permisos y la bitácora los aplica el motor, igual que en la interfaz.
 */
require_once __DIR__ . '/../motor/almacen.php';

function herramientaDatosIa(): array
{
    return [
        'nombre'      => 'datos',
        'descripcion' => 'Consulta o modifica cualquier entidad del sistema: pacientes, medicamentos, diagnósticos, catálogos, historial, citas. Acciones: describir (campos y tipos), listar (con búsqueda y filtros), agrupar (conteos por campo), obtener, crear, actualizar y eliminar. Usa «describir» antes de crear o actualizar para conocer los campos y sus reglas.',
        'esquema'     => [
            'type'       => 'object',
            'properties' => [
                'accion'  => ['type' => 'string', 'enum' => ['describir', 'listar', 'agrupar', 'obtener', 'crear', 'actualizar', 'eliminar']],
                'campo'   => ['type' => 'string', 'description' => 'Campo por el que agrupar (solo para agrupar).'],
                'entidad' => ['type' => 'string', 'description' => 'Clave de la entidad: pacientes, medicamentos, cie10 o cualquier catalogo_* (especialidades, municipios, vías…).'],
                'id'      => ['type' => 'integer', 'description' => 'Registro (para obtener, actualizar o eliminar).'],
                'q'       => ['type' => 'string', 'description' => 'Texto a buscar.'],
                'filtro'  => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Valores exactos por campo, p. ej. {"activo": 1}.'],
                'datos'   => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Campos del registro para crear o actualizar.'],
                'limite'  => ['type' => 'integer', 'description' => 'Cuántas filas devolver (máximo 200).'],
            ],
            'required'   => ['accion', 'entidad'],
        ],
    ];
}

/** Ejecuta la herramienta. Devuelve la respuesta uniforme del asistente. */
function iaDatos(array $e): array
{
    $clave   = (string) ($e['entidad'] ?? '');
    $entidad = entidadMotor($clave);
    if (!$entidad) {
        return fallaIa('Esa entidad no existe. Entidades disponibles: ' . implode(', ', array_keys(entidadesMotor())) . '.');
    }
    if (!puede($entidad['permiso'] ?? 'ver_expediente')) {
        return fallaIa('Tu rol no tiene permiso para usar «' . $entidad['titulo'] . '».');
    }

    $accion = (string) ($e['accion'] ?? '');
    switch ($accion) {
        case 'describir':
            return exitoIa(motorDescribir($clave) ?? [], 'Consultando los campos de ' . $entidad['titulo'] . '…');

        case 'listar':
            $lista = motorListar($clave, [
                'q'      => mb_substr(trim((string) ($e['q'] ?? '')), 0, 120),
                'filtro' => is_array($e['filtro'] ?? null) ? $e['filtro'] : [],
                'limite' => max(1, min(200, (int) ($e['limite'] ?? 25))),
                'pagina' => 1,
            ]);
            return exitoIa(
                ['entidad' => $clave, 'total' => $lista['total'], 'paginas' => $lista['paginas'], 'filas' => $lista['filas']],
                $lista['total'] === 0
                    ? 'No encontré registros en ' . $entidad['titulo'] . '.'
                    : 'Encontré ' . $lista['total'] . ' registro(s) en ' . $entidad['titulo'] . '.'
            );

        case 'agrupar':
            $campo = (string) ($e['campo'] ?? '');
            if ($campo === '') {
                return fallaIa('Indica el campo por el que quieres agrupar.');
            }
            $grupos = motorAgrupar($clave, $campo, [
                'q'      => mb_substr(trim((string) ($e['q'] ?? '')), 0, 120),
                'filtro' => is_array($e['filtro'] ?? null) ? $e['filtro'] : [],
            ], max(1, min(100, (int) ($e['limite'] ?? 25))));
            $total = array_sum(array_column($grupos, 'conteo'));
            return exitoIa(
                ['entidad' => $clave, 'campo' => $campo, 'total' => $total, 'grupos' => $grupos],
                $grupos ? 'Agrupé ' . $total . ' registro(s) de ' . $entidad['titulo'] . ' por ' . $campo . '.' : 'No hay registros que agrupar.'
            );

        case 'obtener':
            $fila = motorObtener($clave, (int) ($e['id'] ?? 0));
            return $fila === null
                ? fallaIa('El registro no existe en ' . $entidad['titulo'] . '.')
                : exitoIa(['entidad' => $clave, 'registro' => $fila], 'Leyendo el registro de ' . $entidad['titulo'] . '…');

        case 'crear':
            if (!is_array($e['datos'] ?? null)) {
                return fallaIa('Faltan los datos del registro que quieres crear.');
            }
            $r = motorCrear($clave, $e['datos']);
            return empty($r['ok'])
                ? fallaIa(erroresMotorIa($r))
                : exitoIa(['id' => (int) $r['id'], 'folio' => $r['folio'] ?? null], 'Registro creado en ' . $entidad['titulo'] . '.', null, true);

        case 'actualizar':
            if ((int) ($e['id'] ?? 0) <= 0 || !is_array($e['datos'] ?? null)) {
                return fallaIa('Indica el registro y los datos que quieres cambiar.');
            }
            $r = motorActualizar($clave, (int) $e['id'], $e['datos']);
            return empty($r['ok'])
                ? fallaIa(erroresMotorIa($r))
                : exitoIa(['id' => (int) $e['id'], 'cambios' => (int) ($r['cambios'] ?? 0)], 'Registro actualizado en ' . $entidad['titulo'] . '.', null, true);

        case 'eliminar':
            $r = motorEliminar($clave, (int) ($e['id'] ?? 0));
            return empty($r['ok'])
                ? fallaIa(erroresMotorIa($r))
                : exitoIa(['id' => (int) $e['id']], 'Registro dado de baja en ' . $entidad['titulo'] . '.', null, true);
    }

    return fallaIa('Acción no válida. Usa describir, listar, obtener, crear, actualizar o eliminar.');
}

/** Texto legible de los errores de validación del motor. */
function erroresMotorIa(array $resultado): string
{
    $errores = $resultado['errores'] ?? [];
    if (!$errores) {
        return 'No se pudo completar la operación.';
    }
    $partes = [];
    foreach ($errores as $campo => $mensaje) {
        $partes[] = ($campo === 'general' ? '' : $campo . ': ') . rtrim($mensaje, '.');
    }
    return 'Revisa los datos: ' . implode('; ', $partes) . '.';
}
