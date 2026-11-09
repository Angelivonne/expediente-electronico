<?php
/**
 * Motor único de datos — almacenes (adaptadores).
 *
 * Traduce una entidad a consultas sobre el lugar donde vive. Hoy: adaptador `tabla`
 * (pacientes, medicamentos, cie10, catálogos). Después: `pagina` (bases del espacio) y
 * `documento` (historial clínico). La interfaz y las herramientas de la IA solo hablan con
 * estas funciones, así que no necesitan saber de qué tabla viene cada dato.
 *
 * Reglas de seguridad:
 *   - La tabla y las columnas salen del registro (constante del sistema), no de la petición;
 *     aun así se validan con una lista de caracteres permitidos.
 *   - Toda consulta filtra por institución cuando la entidad lo declara.
 *   - Los valores se normalizan por tipo de campo antes de devolverlos.
 */
require_once __DIR__ . '/entidades.php';

/** Institución de la consulta: la de la sesión o la que pida la tarea (CLI/pruebas). */
function motorInstitucion(array $opciones = []): ?int
{
    if (array_key_exists('institucion_id', $opciones)) {
        return $opciones['institucion_id'] === null ? null : (int) $opciones['institucion_id'];
    }
    return function_exists('institucionId') ? institucionId() : null;
}

/** Condiciones (WHERE) y parámetros de una entidad, sin orden ni paginación. */
function motorCondiciones(array $entidad, array $opciones): array
{
    $almacen = $entidad['almacen'];
    $where   = [];
    $params  = [];

    if (!empty($almacen['institucion']) && empty($opciones['_sin_institucion'])) {
        $tabla = (string) ($almacen['tabla'] ?? '');
        // Seguro por defecto: solo añadir el filtro si confirmamos que la columna existe
        $tieneInstitucion = false;
        if (function_exists('columnaExiste') && $tabla !== '') {
            $tieneInstitucion = columnaExiste($tabla, 'institucion_id');
        }
        if ($tieneInstitucion) {
            $params[] = motorInstitucion($opciones);
            $where[]  = 'c.institucion_id = ?';
        }
    }
    if (!empty($almacen['baja'])) {
        if (preg_match('/^[a-z0-9_]{1,40}$/', (string) $almacen['baja'])) {
            $where[] = '`' . $almacen['baja'] . '` IS NULL';
        }
    }

    // Filtros exactos: solo campos declarados
    foreach ((array) ($opciones['filtro'] ?? []) as $campo => $valor) {
        $definicion = campoMotor($entidad['clave'], (string) $campo);
        if (!$definicion || $valor === null) {
            continue;
        }
        $where[]  = '`' . $campo . '` = ?';
        $params[] = ($definicion['tipo'] ?? '') === 'casilla' ? (int) $valor : (string) $valor;
    }

    // Rangos de fecha: la columna debe estar declarada y ser de tipo fecha.
    foreach ((array) ($opciones['rango'] ?? []) as $campo => $rango) {
        $definicion = campoMotor($entidad['clave'], (string) $campo);
        if (!$definicion || ($definicion['tipo'] ?? '') !== 'fecha' || !is_array($rango)) {
            continue;
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $limite => $operador) {
            $fecha = trim((string) ($rango[$limite] ?? ''));
            if ($fecha === '') {
                continue;
            }
            $fechaValida = DateTime::createFromFormat('!Y-m-d', $fecha);
            if (!$fechaValida || $fechaValida->format('Y-m-d') !== $fecha) {
                continue;
            }
            $where[] = '`' . $campo . '` ' . $operador . ' ?';
            $params[] = $fecha;
        }
    }

    // Búsqueda libre: cada palabra puede aparecer en cualquiera de los campos declarados.
    $q = trim((string) ($opciones['q'] ?? ''));
    if ($q !== '') {
        $camposBusqueda = [];
        $expresiones = (array) ($entidad['busqueda_sql'] ?? []);
        if ($expresiones) {
            foreach (array_slice($expresiones, 0, 6) as $expresion) {
                if (preg_match('/^[A-Za-z0-9_().,\' ]{1,160}$/', (string) $expresion)) {
                    $camposBusqueda[] = '(' . $expresion . ')';
                }
            }
        } else {
            foreach ((array) ($entidad['busqueda'] ?? []) as $campo) {
                if (preg_match('/^[a-z0-9_]{1,40}$/', (string) $campo)) {
                    $camposBusqueda[] = '`' . $campo . '`';
                }
            }
        }
        if ($camposBusqueda) {
            [$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda($camposBusqueda, $q);
            $where[] = $filtroBusqueda;
            $params = array_merge($params, $parametrosBusqueda);
        }
    }

    // Filtro por ids concretos
    if (!empty($opciones['ids']) && is_array($opciones['ids'])) {
        $ids = array_values(array_filter(array_map('intval', $opciones['ids']), fn ($i) => $i > 0));
        if ($ids) {
            $where[] = 'c.id IN (' . implode(',', $ids) . ')';
        }
    }

    return [$where ? implode(' AND ', $where) : '1 = 1', $params];
}

/** Normaliza una fila al tipo de cada campo declarado. */
function motorNormalizarFila(array $entidad, array $fila): array
{
    $normal = $fila;
    $normal['id'] = (int) ($fila['id'] ?? 0);
    $normal['titulo'] = (string) ($fila['titulo'] ?? '');
    foreach ($entidad['campos'] as $campo => $definicion) {
        $valor = $fila[$campo] ?? null;
        $normal[$campo] = match ($definicion['tipo'] ?? 'texto') {
            'casilla'    => (bool) $valor,
            'numero'     => $valor === null || $valor === '' ? null : $valor + 0,
            'fecha'      => $valor ? substr((string) $valor, 0, 10) : null,
            default      => $valor === null ? null : (string) $valor,
        };
    }
    if (!empty($entidad['extras'])) {
        $datos = json_decode((string) ($fila['extras'] ?? ''), true);
        $normal['extras'] = is_array($datos) ? $datos : [];
    }
    return $normal;
}

/** Lista paginada: ['filas' => [], 'total' => int, 'pagina' => int, 'paginas' => int, 'limite' => int]. */
function motorListar(string $clave, array $opciones = []): array
{
    $limite = max(1, min(500, (int) ($opciones['limite'] ?? 50)));
    $pagina = max(1, (int) ($opciones['pagina'] ?? 1));
    $vacio  = ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'limite' => $limite];

    $entidad = entidadMotor($clave);
    if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla') {
        return $vacio;
    }
    $tabla = (string) $entidad['almacen']['tabla'];
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return $vacio;
    }

    // Columnas: expresiones de la vista (catálogos con uniones) o columnas declaradas
    $select = ['c.id'];
    if (!empty($entidad['columnas_sql'])) {
        foreach ($entidad['columnas_sql'] as $columna) {
            $expresion = (string) ($columna[0] ?? '');
            $alias     = preg_replace('/[^a-z0-9_]/', '', (string) ($columna[1] ?? ''));
            if ($alias === '' || !preg_match('/^[A-Za-z0-9_.(),`\' ]{1,200}$/', $expresion)) {
                continue;
            }
            $select[] = $expresion . ' AS `' . $alias . '`';
        }
        if (!empty($entidad['titulo_sql'])) {
            $select[] = '(' . $entidad['titulo_sql'] . ') AS `titulo`';
        }
    } else {
        foreach (array_keys($entidad['campos']) as $campo) {
            if (preg_match('/^[a-z0-9_]{1,40}$/', (string) $campo)) {
                if (!function_exists('columnaExiste') || columnaExiste($tabla, (string) $campo)) {
                    $select[] = 'c.`' . $campo . '`';
                }
            }
        }
        if (!empty($entidad['titulo_sql'])) {
            $select[] = '(' . $entidad['titulo_sql'] . ') AS `titulo`';
        }
        $columnaExtras = (string) ($entidad['extras'] ?? '');
        if ($columnaExtras !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $columnaExtras)) {
            $select[] = 'c.`' . $columnaExtras . '` AS `extras`';
        }
    }
    $desde = (string) ($entidad['desde'] ?? ('`' . $tabla . '` c'));

    [$where, $params] = motorCondiciones($entidad, $opciones);

    try {
        $cuenta = db()->prepare("SELECT COUNT(*) FROM $desde WHERE $where");
        $cuenta->execute($params);
        $total = (int) $cuenta->fetchColumn();
    } catch (Throwable $e) {
        // Si falla por columna faltante (ej. institucion_id en Railway), reintentar sin filtro de institución
        if ($e->getCode() == '42S22' || strpos($e->getMessage(), '1054') !== false) {
            error_log('[motorListar] columna faltante, reintentando sin institucion_id: ' . $e->getMessage());
            $opcionesSinInst = $opciones;
            $opcionesSinInst['_sin_institucion'] = true;
            // Forzar recarga de caché para que se detecte el estado real
            if (function_exists('columnaExiste')) {
                columnaExiste($tabla, 'institucion_id', true);
            }
            [$where, $params] = motorCondiciones($entidad, $opcionesSinInst);
            $cuenta = db()->prepare("SELECT COUNT(*) FROM $desde WHERE $where");
            $cuenta->execute($params);
            $total = (int) $cuenta->fetchColumn();
        } else {
            throw $e;
        }
    }

    $paginas = max(1, (int) ceil($total / $limite));
    $pagina  = min($pagina, $paginas);
    $salto   = ($pagina - 1) * $limite;

    $orden = (string) ($entidad['orden'] ?? 'id');
    $ordenPedido = (string) ($opciones['orden'] ?? '');
    if ($ordenPedido !== '' && campoMotor($clave, $ordenPedido) && (!function_exists('columnaExiste') || columnaExiste($tabla, $ordenPedido))) {
        $sentido = strtolower((string) ($opciones['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $orden = 'c.`' . $ordenPedido . '` ' . $sentido;
    } elseif (!preg_match('/^[A-Za-z0-9_,`. ]+$/', $orden)) {
        $orden = 'id';
    }

    $consulta = db()->prepare(
        'SELECT ' . implode(', ', $select) . " FROM $desde WHERE $where ORDER BY $orden LIMIT $limite OFFSET $salto"
    );
    $consulta->execute($params);

    return [
        'filas'   => array_map(fn ($f) => motorNormalizarFila($entidad, $f), $consulta->fetchAll()),
        'total'   => $total,
        'pagina'  => $pagina,
        'paginas' => $paginas,
        'limite'  => $limite,
    ];
}

/** Un registro por id (normalizado), o null si no existe en la institución. */
function motorObtener(string $clave, int $id, array $opciones = []): ?array
{
    if ($id <= 0) {
        return null;
    }
    $resultado = motorListar($clave, $opciones + ['ids' => [$id], 'limite' => 1, 'pagina' => 1]);
    return $resultado['filas'][0] ?? null;
}

/** Cuántos registros hay (con la misma búsqueda y filtros). */
function motorContar(string $clave, array $opciones = []): int
{
    $entidad = entidadMotor($clave);
    if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla') {
        return 0;
    }
    $tabla = (string) $entidad['almacen']['tabla'];
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return 0;
    }
    [$where, $params] = motorCondiciones($entidad, $opciones);
    $cuenta = db()->prepare("SELECT COUNT(*) FROM `$tabla` c WHERE $where");
    $cuenta->execute($params);
    return (int) $cuenta->fetchColumn();
}

/** Descripción para las herramientas de la IA: campos, tipos y obligatorios. */
function motorDescribir(string $clave): ?array
{
    $entidad = entidadMotor($clave);
    if (!$entidad) {
        return null;
    }
    $campos = [];
    foreach ($entidad['campos'] as $nombre => $definicion) {
        $campos[$nombre] = [
            'texto'   => $definicion['texto'] ?? $nombre,
            'tipo'    => $definicion['tipo'] ?? 'texto',
            'req'     => !empty($definicion['req']),
            'lectura' => !empty($definicion['solo_lectura']),
        ];
    }
    return ['entidad' => $clave, 'titulo' => $entidad['titulo'], 'solo_lectura' => !empty($entidad['solo_lectura']), 'campos' => $campos];
}

/**
 * Valida los datos de una entidad según sus campos declarados.
 * $parcial: solo valida los campos presentes (para actualizar).
 * Devuelve [campos listos, errores por campo, campos de perfil].
 */
function motorValidar(string $clave, array $datos, bool $parcial = false, int $id = 0): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad) {
        return [[], ['general' => 'La entidad no existe.'], []];
    }
    $limpios = [];
    $errores = [];
    foreach ($entidad['campos'] as $campo => $definicion) {
        if (!empty($definicion['solo_lectura'])) {
            continue;
        }
        if ($parcial && !array_key_exists($campo, $datos)) {
            continue;
        }
        $valor = $datos[$campo] ?? null;
        $texto = is_scalar($valor) ? trim((string) $valor) : '';
        $tipo  = (string) ($definicion['tipo'] ?? 'texto');

        if ($texto === '') {
            if (!empty($definicion['req'])) {
                $errores[$campo] = 'Campo obligatorio';
            }
            $limpios[$campo] = null;
            continue;
        }

        switch ($tipo) {
            case 'casilla':
                $limpios[$campo] = in_array(mb_strtolower($texto), ['1', 'on', 'true', 'sí', 'si'], true) ? 1 : 0;
                break;
            case 'numero':
                if (!is_numeric($texto) || !is_finite((float) $texto)) {
                    $errores[$campo] = 'Escribe un número válido';
                    break;
                }
                $numero = $texto + 0;
                if (!empty($definicion['entero']) && floor((float) $numero) !== (float) $numero) {
                    $errores[$campo] = 'Escribe un número entero';
                } elseif (isset($definicion['min']) && $numero < $definicion['min']) {
                    $errores[$campo] = 'El valor mínimo es ' . $definicion['min'];
                } elseif (isset($definicion['max']) && $numero > $definicion['max']) {
                    $errores[$campo] = 'El valor máximo es ' . $definicion['max'];
                } else {
                    $limpios[$campo] = $numero;
                }
                break;
            case 'fecha':
                $fecha = DateTime::createFromFormat('!Y-m-d', $texto);
                if (!$fecha || $fecha->format('Y-m-d') !== $texto) {
                    $errores[$campo] = 'Fecha no válida';
                } else {
                    $limpios[$campo] = $texto;
                }
                break;
            case 'correo':
                if (!filter_var($texto, FILTER_VALIDATE_EMAIL)) {
                    $errores[$campo] = 'Correo no válido';
                } else {
                    $limpios[$campo] = $texto;
                }
                break;
            case 'curp':
                $texto = mb_strtoupper($texto);
                if (!curpValida($texto)) {
                    $errores[$campo] = 'La CURP no es válida';
                } else {
                    // La CURP no se repite dentro de la institución
                    if ($clave === 'pacientes') {
                        $repetida = db()->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id = ? AND id <> ?');
                        $repetida->execute([$texto, motorInstitucion(), $id]);
                        if ($folio = $repetida->fetchColumn()) {
                            $errores[$campo] = "Esta CURP ya está registrada (expediente $folio).";
                        }
                    }
                    $limpios[$campo] = $texto;
                }
                break;
            case 'opcion':
                if (!in_array($texto, array_keys((array) ($definicion['opciones'] ?? [])), true)) {
                    $errores[$campo] = 'Opción no válida';
                } else {
                    $limpios[$campo] = $texto;
                }
                break;
            default:
                if (isset($definicion['max']) && mb_strlen($texto) > (int) $definicion['max']) {
                    $errores[$campo] = 'Máximo ' . (int) $definicion['max'] . ' caracteres';
                } else {
                    $limpios[$campo] = $texto;
                }
                break;
        }
    }

    $relacionesFinanzas = match ($clave) {
        'finanzas_movimientos' => [
            'paciente_id' => ['tabla' => 'pacientes', 'institucion' => true],
            'profesional_id' => ['tabla' => 'usuarios', 'institucion' => true, 'activo' => true, 'profesional' => true],
            'establecimiento_id' => ['tabla' => 'catalogo_establecimientos', 'activo' => true],
            'factura_id' => ['tabla' => 'finanzas_facturas', 'institucion' => true],
        ],
        'finanzas_facturas' => [
            'paciente_id' => ['tabla' => 'pacientes', 'institucion' => true],
        ],
        default => [],
    };
    foreach ($relacionesFinanzas as $campoRelacion => $relacion) {
        if (!array_key_exists($campoRelacion, $limpios) || $limpios[$campoRelacion] === null) {
            continue;
        }
        $idRelacionado = filter_var((string) $limpios[$campoRelacion], FILTER_VALIDATE_INT);
        if ($idRelacionado === false || $idRelacionado <= 0) {
            $errores[$campoRelacion] = 'Selecciona un vínculo válido.';
            continue;
        }
        $limpios[$campoRelacion] = $idRelacionado;
        $sqlRelacion = 'SELECT 1 FROM `' . $relacion['tabla'] . '` WHERE id = ?';
        $parametrosRelacion = [$idRelacionado];
        if (!empty($relacion['institucion']) && (!function_exists('columnaExiste') || columnaExiste($relacion['tabla'], 'institucion_id'))) {
            $sqlRelacion .= ' AND institucion_id = ?';
            $parametrosRelacion[] = motorInstitucion();
        }
        if (!empty($relacion['activo'])) {
            $sqlRelacion .= ' AND activo = 1';
        }
        if (!empty($relacion['profesional'])) {
            $sqlRelacion .= " AND rol <> 'paciente'";
        }
        $consultaRelacion = db()->prepare($sqlRelacion . ' LIMIT 1');
        $consultaRelacion->execute($parametrosRelacion);
        if (!$consultaRelacion->fetchColumn()) {
            $errores[$campoRelacion] = 'El vínculo seleccionado no pertenece a esta institución o ya no está disponible.';
        }
    }

    $perfil = [];
    foreach ((array) ($entidad['perfil'] ?? []) as $campo => $max) {
        if ($parcial && !array_key_exists($campo, $datos)) {
            continue;
        }
        $texto = is_scalar($datos[$campo] ?? null) ? trim((string) $datos[$campo]) : '';
        if (mb_strlen($texto) > $max) {
            $errores[$campo] = 'Texto demasiado largo';
        } elseif ($texto !== '') {
            $perfil[$campo] = $texto;
        }
    }

    return [$limpios, $errores, $perfil];
}

/** Crea un registro. Devuelve ['ok' => true, 'id' => …] o ['ok' => false, 'errores' => […]]. */
function motorCrear(string $clave, array $datos, array $opciones = []): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla') {
        return ['ok' => false, 'errores' => ['general' => 'Esta entidad todavía no admite altas.']];
    }
    $tabla = (string) $entidad['almacen']['tabla'];
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return ['ok' => false, 'errores' => ['general' => 'Almacén no válido.']];
    }
    if (!empty($entidad['solo_lectura'])) {
        return ['ok' => false, 'errores' => ['general' => 'Esta entidad es de solo lectura.']];
    }
    // Autor del comentario: se toma de la sesión si no lo mandan
    if (isset($entidad['campos']['usuario_id']) && empty($datos['usuario_id']) && function_exists('usuarioActual')) {
        $usuarioSesion = usuarioActual();
        if (!empty($usuarioSesion['id'])) {
            $datos['usuario_id'] = (int) $usuarioSesion['id'];
        }
    }
    [$limpios, $errores, $perfil] = motorValidar($clave, $datos, false);
    if ($errores) {
        return ['ok' => false, 'errores' => $errores];
    }
    if ($clave === 'finanzas_facturas') {
        $estadoInicial = (string) ($datos['estado'] ?? 'borrador');
        if (!in_array($estadoInicial, ['borrador', 'emitida'], true)) {
            return ['ok' => false, 'errores' => ['estado' => 'Una factura nueva solo puede quedar como borrador o emitida.']];
        }
        $limpios['estado'] = $estadoInicial;
    }
    if ($clave === 'finanzas_cierres' && array_key_exists('_diferencia_total_calculada', $opciones)) {
        $diferenciaCalculada = $opciones['_diferencia_total_calculada'];
        if (!is_numeric($diferenciaCalculada) || !is_finite((float) $diferenciaCalculada)) {
            return ['ok' => false, 'errores' => ['diferencia_total' => 'La diferencia calculada no es válida.']];
        }
        $limpios['diferencia_total'] = round((float) $diferenciaCalculada, 2);
    }
    $extras = null;
    if (!empty($entidad['extras'])) {
        $extras = [];
        if ($perfil) {
            $extras['_perfil'] = $perfil;
        }
        if (is_array($datos['extras'] ?? null)) {
            $extras += $datos['extras'];
        }
        $extras = $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null;
    }

    $columnas = [];
    $valores  = [];
    $marcas   = [];
    if (!empty($entidad['almacen']['institucion']) && (!function_exists('columnaExiste') || columnaExiste($tabla, 'institucion_id'))) {
        $columnas[] = 'institucion_id';
        $valores[]  = motorInstitucion($opciones);
        $marcas[]   = '?';
    }
    if (!empty($entidad['folio']['prefijo'])) {
        // El folio definitivo depende del id: se pone uno temporal y se corrige tras el alta
        $columnas[] = 'folio';
        $valores[]  = 'TMP-' . bin2hex(random_bytes(6));
        $marcas[]   = '?';
    }
    foreach ($limpios as $campo => $valor) {
        if (function_exists('columnaExiste') && !columnaExiste($tabla, $campo)) {
            continue;
        }
        $columnas[] = '`' . $campo . '`';
        $valores[]  = $valor;
        $marcas[]   = '?';
    }
    if (!empty($entidad['extras'])) {
        $columnas[] = '`' . $entidad['extras'] . '`';
        $valores[]  = $extras;
        $marcas[]   = '?';
    }
    if (!empty($opciones['confirmacion']) && isset($entidad['campos']['confirmacion_datos'])) {
        // Alta desde el formulario: la persona confirmó el origen de los datos
        $columnas[] = 'confirmacion_datos';
        $valores[]  = 1;
        $marcas[]   = '?';
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insertar = $pdo->prepare('INSERT INTO `' . $tabla . '` (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', $marcas) . ')');
        $insertar->execute($valores);
        $id = (int) $pdo->lastInsertId();

        // Folio del expediente: se calcula con el id, así que se pone después del alta
        $folio = null;
        if (!empty($entidad['folio']['prefijo'])) {
            $folio = $entidad['folio']['prefijo'] . str_pad((string) $id, (int) ($entidad['folio']['longitud'] ?? 6), '0', STR_PAD_LEFT);
            $pdo->prepare('UPDATE `' . $tabla . '` SET folio = ? WHERE id = ?')->execute([$folio, $id]);
        }
        registrarBitacora($tabla, $id, 'crear', null, null, $folio);
        $pdo->commit();
        if ($clave === 'comentarios') {
            motorNotificarMenciones((string) ($limpios['texto'] ?? ''), (string) ($limpios['entidad'] ?? ''), (int) ($limpios['registro_id'] ?? 0));
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ok' => true, 'id' => $id, 'folio' => $folio];
}

/** Actualiza campos de un registro. Devuelve ['ok' => true, 'cambios' => n] o errores. */
function motorActualizar(string $clave, int $id, array $datos, array $opciones = []): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla' || $id <= 0) {
        return ['ok' => false, 'errores' => ['general' => 'No se puede actualizar este registro.']];
    }
    $tabla = (string) $entidad['almacen']['tabla'];
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return ['ok' => false, 'errores' => ['general' => 'Almacén no válido.']];
    }
    if (!empty($entidad['solo_lectura'])) {
        return ['ok' => false, 'errores' => ['general' => 'Esta entidad es de solo lectura.']];
    }
    [$limpios, $errores, $perfil] = motorValidar($clave, $datos, true, $id);
    if ($errores) {
        return ['ok' => false, 'errores' => $errores];
    }

    $where  = 'id = ?';
    $params = [$id];
    if (!empty($entidad['almacen']['institucion']) && (!function_exists('columnaExiste') || columnaExiste($tabla, 'institucion_id'))) {
        $where   .= ' AND institucion_id = ?';
        $params[] = motorInstitucion($opciones);
    }
    if (!empty($entidad['almacen']['baja'])) {
        $where .= ' AND `' . $entidad['almacen']['baja'] . '` IS NULL';
    }
    $actual = db()->prepare('SELECT * FROM `' . $tabla . '` WHERE ' . $where);
    $actual->execute($params);
    $original = $actual->fetch();
    if (!$original) {
        return ['ok' => false, 'errores' => ['general' => 'El registro no existe o fue dado de baja.']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    $cambios = 0;
    try {
        foreach ($limpios as $campo => $nuevo) {
            if (function_exists('columnaExiste') && !columnaExiste($tabla, $campo)) {
                continue;
            }
            $anterior = $original[$campo] ?? null;
            if ((string) $anterior === (string) $nuevo) {
                continue;
            }
            $pdo->prepare('UPDATE `' . $tabla . '` SET `' . $campo . '` = ? WHERE ' . $where)->execute(array_merge([$nuevo], $params));
            registrarBitacora($tabla, $id, 'actualizar', $campo,
                $anterior === null ? null : (string) $anterior,
                $nuevo === null ? null : (string) $nuevo);
            $cambios++;
        }
        if (!empty($entidad['extras']) && ($perfil || is_array($datos['extras'] ?? null))) {
            $extras = json_decode((string) $original[$entidad['extras']], true) ?: [];
            $texto  = fn ($v) => $v === null ? null : (is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v);
            $cambiosExtras = 0;
            foreach ($perfil as $campo => $valor) {
                $antes = (string) ($extras['_perfil'][$campo] ?? '');
                if ($antes === (string) $valor) {
                    continue;
                }
                registrarBitacora($tabla, $id, 'actualizar', $campo, $antes !== '' ? $antes : null, $valor !== '' ? $valor : null);
                $extras['_perfil'][$campo] = $valor;
                $cambiosExtras++;
            }
            foreach ((array) ($datos['extras'] ?? []) as $claveExtra => $valor) {
                $antes = $extras[$claveExtra] ?? null;
                if (json_encode($antes) === json_encode($valor)) {
                    continue;
                }
                registrarBitacora($tabla, $id, 'actualizar', mb_substr((string) $claveExtra, 0, 60), $texto($antes), $texto($valor));
                if ($valor === null) {
                    unset($extras[$claveExtra]);
                } else {
                    $extras[$claveExtra] = $valor;
                }
                $cambiosExtras++;
            }
            if ($cambiosExtras > 0) {
                $pdo->prepare('UPDATE `' . $tabla . '` SET `' . $entidad['extras'] . '` = ? WHERE ' . $where)
                    ->execute(array_merge([json_encode($extras, JSON_UNESCAPED_UNICODE)], $params));
                $cambios += $cambiosExtras;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($cambios > 0) {
        motorGuardarVersion($clave, $id, $original);
    }
    return ['ok' => true, 'cambios' => $cambios];
}

/** Baja lógica de un registro (solo entidades con columna de baja). */
function motorEliminar(string $clave, int $id, array $opciones = []): array
{
    $entidad = entidadMotor($clave);
    $baja    = (string) ($entidad['almacen']['baja'] ?? '');
    if (!$entidad || $id <= 0 || !preg_match('/^[a-z0-9_]{1,40}$/', $baja)) {
        return ['ok' => false, 'errores' => ['general' => 'Esta entidad no admite bajas.']];
    }
    $tabla  = (string) $entidad['almacen']['tabla'];
    $where  = 'id = ?';
    $params = [$id];
    if (!empty($entidad['almacen']['institucion']) && (!function_exists('columnaExiste') || columnaExiste($tabla, 'institucion_id'))) {
        $where   .= ' AND institucion_id = ?';
        $params[] = motorInstitucion($opciones);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE `' . $tabla . '` SET `' . $baja . '` = NOW() WHERE ' . $where . ' AND `' . $baja . '` IS NULL')
            ->execute($params);
        $cambios = $pdo->query('SELECT ROW_COUNT()')->fetchColumn();
        if ((int) $cambios > 0) {
            registrarBitacora($tabla, $id, 'eliminar', $baja, null, date('Y-m-d H:i:s'));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return (int) $cambios > 0
        ? ['ok' => true]
        : ['ok' => false, 'errores' => ['general' => 'El registro no existe o ya estaba dado de baja.']];
}

/** Conteo de registros agrupado por un campo: [{valor, conteo}]. Sirve para vistas y para la IA. */
function motorAgrupar(string $clave, string $campo, array $opciones = [], int $limite = 25): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad || ($entidad['almacen']['tipo'] ?? '') !== 'tabla') {
        return [];
    }
    $definicion = campoMotor($clave, $campo);
    if (!$definicion || !preg_match('/^[a-z0-9_]{1,40}$/', $campo)) {
        return [];
    }
    $tabla = (string) $entidad['almacen']['tabla'];
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return [];
    }
    $desde  = (string) ($entidad['desde'] ?? ('`' . $tabla . '` c'));
    [$where, $params] = motorCondiciones($entidad, $opciones);
    $limite = max(1, min(100, $limite));

    $consulta = db()->prepare(
        "SELECT c.`$campo` AS valor, COUNT(*) AS conteo FROM $desde WHERE $where
          GROUP BY c.`$campo` ORDER BY conteo DESC, valor LIMIT $limite"
    );
    $consulta->execute($params);
    $grupos = [];
    foreach ($consulta->fetchAll() as $fila) {
        $valor = $fila['valor'];
        if (($definicion['tipo'] ?? '') === 'casilla') {
            $valor = (bool) $valor;
        }
        $grupos[] = ['valor' => $valor, 'conteo' => (int) $fila['conteo']];
    }
    return $grupos;
}

/** Guarda el estado anterior de un registro cuando cambia (auditoría y restauración). */
function motorGuardarVersion(string $clave, int $id, array $original): void
{
    try {
        $usuario = 0;
        if (function_exists('usuarioActual')) {
            $usuario = (int) (usuarioActual()['id'] ?? 0);
        }
        $pdo = db();
        $pdo->prepare('INSERT INTO registro_versiones (institucion_id, entidad, registro_id, usuario_id, datos, creado_en) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([motorInstitucion(), $clave, $id, $usuario, json_encode($original, JSON_UNESCAPED_UNICODE)]);
        // Retención: las últimas 100 versiones por registro
        $pdo->prepare('DELETE FROM registro_versiones WHERE entidad = ? AND registro_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM registro_versiones WHERE entidad = ? AND registro_id = ? ORDER BY id DESC LIMIT 100) AS ultimas)')
            ->execute([$clave, $id, $clave, $id]);
    } catch (Throwable $e) {
        // La auditoría no debe tumbar la operación principal
    }
}
/** Avisa por notificación a las personas mencionadas con @nombre en un comentario. */
function motorNotificarMenciones(string $texto, string $entidad, int $registroId): void
{
    if ($texto === '' || !preg_match_all('/@([\p{L}][\p{L}\p{N}_-]{1,40})/u', $texto, $coincidencias)) {
        return;
    }
    try {
        require_once __DIR__ . '/../../servicios/cuenta/notificaciones.php';
        $autor = function_exists('usuarioActual') ? (int) (usuarioActual()['id'] ?? 0) : 0;
        $avisados = [];
        foreach (array_unique($coincidencias[1]) as $nombre) {
            $consulta = db()->prepare('SELECT id FROM usuarios WHERE id <> ? AND nombre LIKE ? LIMIT 5');
            $consulta->execute([$autor, $nombre . '%']);
            foreach ($consulta->fetchAll() as $filaUsuario) {
                $avisados[(int) $filaUsuario['id']] = true;
            }
        }
        $url = match ($entidad) {
            'pacientes' => URL_BASE . '/pacientes/ver.php?id=' . $registroId . '#comentarios',
            'historial' => URL_BASE . '/pacientes/evento.php?id=' . $registroId,
            'citas'     => URL_BASE . '/agenda/',
            default     => URL_BASE,
        };
        foreach (array_keys($avisados) as $usuarioId) {
            crearNotificacion($usuarioId, 'mencion', 'Te mencionaron en un comentario', mb_substr($texto, 0, 200), $url);
        }
    } catch (Throwable $e) {
        // Una mención fallida no impide guardar el comentario
    }
}