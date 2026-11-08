<?php
/**
 * Motor único de datos — guardar un campo editado en línea.
 *
 * POST JSON {entidad, id, campo, valor} con el token CSRF en X-CSRF-Token.
 * Responde {ok, valor, texto} o {ok: false, error}.
 *
 * Sustituye a los endpoints por pantalla: la tabla, la ficha, los formularios y las herramientas
 * de la IA escriben por aquí. Las reglas salen del registro de entidades; los permisos y la
 * bitácora se aplican en un solo lugar.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../../app/vistas/componentes/bloques_nota.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}

$entrada = json_decode((string) file_get_contents('php://input'), true) ?: [];
$clave   = (string) ($entrada['entidad'] ?? $entrada['catalogo'] ?? '');
$entidad = entidadMotor($clave);
if (!$entidad) {
    responder(400, ['ok' => false, 'error' => 'Esa entidad no existe.']);
}
exigirPermiso($entidad['permiso'] ?? 'ver_expediente');

$id     = (int) ($entrada['id'] ?? 0);
$campo  = (string) ($entrada['campo'] ?? '');
$texto  = trim((string) ($entrada['valor'] ?? ''));

// Campos clínicos de paciente con panel de nota: sus bloques viajan junto al texto plano
$bloques = ($clave === 'pacientes' && in_array($campo, CAMPOS_NOTA_PACIENTE, true)) ? ($entrada['bloques'] ?? null) : null;

if ($id <= 0) {
    responder(400, ['ok' => false, 'error' => 'Registro no válido.']);
}

// Propiedades agregadas por el administrador (pacientes): viven en la columna extras
if (str_starts_with($campo, 'extra_') && !empty($entidad['extras'])) {
    $prop = null;
    foreach (json_decode((string) config('pacientes_propiedades'), true) ?: [] as $p) {
        if (($p['clave'] ?? '') !== '' && 'extra_' . $p['clave'] === $campo) {
            $prop = $p;
            break;
        }
    }
    if (!$prop) {
        responder(400, ['ok' => false, 'error' => 'La propiedad ya no existe.']);
    }

    $tipoProp   = (string) ($prop['tipo'] ?? 'texto');
    $nuevoExtra = $texto === '' ? null : $texto;
    $textoExtra = $texto;
    if ($texto !== '') {
        switch ($tipoProp) {
            case 'numero':
                $numero = str_replace(',', '.', $texto);
                if (!is_numeric($numero)) {
                    responder(422, ['ok' => false, 'error' => 'Escribe un número.']);
                }
                $nuevoExtra = $numero + 0;
                $textoExtra = (string) $nuevoExtra;
                break;
            case 'fecha':
                $fecha = DateTime::createFromFormat('!Y-m-d', $texto);
                if (!$fecha || $fecha->format('Y-m-d') !== $texto) {
                    responder(422, ['ok' => false, 'error' => 'Usa una fecha válida (AAAA-MM-DD).']);
                }
                break;
            case 'seleccion':
                if (!in_array($texto, (array) ($prop['opciones'] ?? []), true)) {
                    responder(422, ['ok' => false, 'error' => 'Selecciona una opción de la lista.']);
                }
                break;
            case 'casilla':
                if (!in_array($texto, ['0', '1'], true)) {
                    responder(422, ['ok' => false, 'error' => 'Selecciona Sí o No.']);
                }
                $nuevoExtra = $texto === '1';
                $textoExtra = $nuevoExtra ? 'Sí' : 'No';
                break;
            default:
                if (mb_strlen($texto) > 500) {
                    responder(422, ['ok' => false, 'error' => 'Máximo 500 caracteres.']);
                }
                break;
        }
    }

    // FOR UPDATE: dos guardados en línea simultáneos no se pisan el JSON de extras.
    $pdo = db();
    $pdo->beginTransaction();
    $actual = $pdo->prepare('SELECT extras FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
    $actual->execute([$id, institucionId()]);
    $fila = $actual->fetch();
    if (!$fila) {
        responder(404, ['ok' => false, 'error' => 'El registro no existe o fue dado de baja.']);
    }
    $extras   = json_decode((string) $fila['extras'], true) ?: [];
    $anterior = $extras[$prop['clave']] ?? null;
    if ($nuevoExtra === null) {
        unset($extras[$prop['clave']]);
    } else {
        $extras[$prop['clave']] = $nuevoExtra;
    }

    $pdo->prepare('UPDATE pacientes SET extras = ? WHERE id = ? AND institucion_id = ?')
        ->execute([$extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $id, institucionId()]);
    registrarBitacora('pacientes', $id, 'actualizar', mb_substr((string) $prop['nombre'], 0, 60),
        is_scalar($anterior) ? (is_bool($anterior) ? ($anterior ? 'Sí' : 'No') : (string) $anterior) : null,
        $nuevoExtra === null ? null : $textoExtra);
    $pdo->commit();

    responder(200, [
        'ok'    => true,
        'valor' => is_bool($nuevoExtra) ? ($nuevoExtra ? '1' : '0') : (string) ($nuevoExtra ?? ''),
        'texto' => $nuevoExtra === null ? '' : $textoExtra,
    ]);
}

$def = campoMotor($clave, $campo);
if (!$def || !empty($def['solo_lectura'])) {
    // Campos de perfil (viven en extras['_perfil']): los edita la ficha del paciente
    if (!isset($entidad['perfil'][$campo])) {
        responder(400, ['ok' => false, 'error' => 'Campo no válido para esta entidad.']);
    }
    if (mb_strlen($texto) > (int) $entidad['perfil'][$campo]) {
        responder(422, ['ok' => false, 'error' => 'El texto es demasiado largo.']);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $actual = $pdo->prepare('SELECT extras FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL FOR UPDATE');
    $actual->execute([$id, institucionId()]);
    $fila = $actual->fetch();
    if (!$fila) {
        responder(404, ['ok' => false, 'error' => 'El registro no existe o fue dado de baja.']);
    }
    $extras   = json_decode((string) $fila['extras'], true) ?: [];
    $anterior = (string) ($extras['_perfil'][$campo] ?? '');
    if ($texto === '') {
        unset($extras['_perfil'][$campo]);
    } else {
        $extras['_perfil'][$campo] = $texto;
    }
    $cambioBloques = in_array($campo, CAMPOS_NOTA_PACIENTE, true) && sincronizarBloquesCampo($extras, $campo, $texto, $bloques, $anterior);
    if ($texto !== $anterior || $cambioBloques) {
        $pdo->prepare('UPDATE pacientes SET extras = ? WHERE id = ? AND institucion_id = ?')
            ->execute([$extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null, $id, institucionId()]);
        if ($texto !== $anterior) {
            registrarBitacora('pacientes', $id, 'actualizar', $campo, $anterior !== '' ? $anterior : null, $texto !== '' ? $texto : null);
        }
    }
    $pdo->commit();
    responder(200, ['ok' => true, 'valor' => $texto, 'texto' => $texto]);
}

// ---- Validación por tipo de campo ----
$tipo  = (string) ($def['tipo'] ?? 'texto');
$nuevo = $texto === '' ? null : $texto;
if ($texto === '' && !empty($def['req'])) {
    responder(422, ['ok' => false, 'error' => 'Este campo es obligatorio.']);
}

switch ($tipo) {
    case 'casilla':
        if (!in_array($texto, ['0', '1'], true)) {
            responder(422, ['ok' => false, 'error' => 'Selecciona Sí o No.']);
        }
        $nuevo = (int) $texto;
        break;

    case 'numero':
        if ($texto !== '' && !is_numeric($texto)) {
            responder(422, ['ok' => false, 'error' => 'Escribe un número.']);
        }
        $nuevo = $texto === '' ? null : $texto + 0;
        break;

    case 'fecha':
        if ($texto !== '') {
            $fecha = DateTime::createFromFormat('!Y-m-d', $texto);
            if (!$fecha || $fecha->format('Y-m-d') !== $texto) {
                responder(422, ['ok' => false, 'error' => 'Usa una fecha válida (AAAA-MM-DD).']);
            }
        }
        break;

    case 'correo':
        if ($texto !== '' && !filter_var($texto, FILTER_VALIDATE_EMAIL)) {
            responder(422, ['ok' => false, 'error' => 'Correo electrónico no válido.']);
        }
        break;

    case 'curp':
        $nuevo = $texto === '' ? null : mb_strtoupper($texto);
        if ($nuevo !== null && !curpValida($nuevo)) {
            responder(422, ['ok' => false, 'error' => 'La CURP no es válida. Revisa el formato y el dígito verificador.']);
        }
        break;

    case 'opcion':
        if (!in_array($texto, array_keys((array) ($def['opciones'] ?? [])), true)) {
            responder(422, ['ok' => false, 'error' => 'Selecciona una opción de la lista.']);
        }
        break;

    default:
        if (isset($def['max']) && mb_strlen($texto) > (int) $def['max']) {
            responder(422, ['ok' => false, 'error' => 'Máximo ' . (int) $def['max'] . ' caracteres.']);
        }
        break;
}

// ---- Localizar el registro ----
$almacen = $entidad['almacen'];
$tabla   = (string) ($almacen['tabla'] ?? '');
if (($almacen['tipo'] ?? '') !== 'tabla' || !preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
    responder(400, ['ok' => false, 'error' => 'Esta entidad todavía no se puede editar en línea.']);
}
$where  = 'id = ?';
$params = [$id];
if (!empty($almacen['institucion'])) {
    $where   .= ' AND institucion_id = ?';
    $params[] = institucionId();
}
if (!empty($almacen['baja']) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $almacen['baja'])) {
    $where .= ' AND `' . $almacen['baja'] . '` IS NULL';
}

// Motivo de consulta (pacientes) guarda también sus bloques de nota en la columna extras
$columnaExtras = (string) ($entidad['extras'] ?? '');
$traeExtras = $clave === 'pacientes' && $campo === 'motivo_consulta' && preg_match('/^[a-z0-9_]{1,40}$/', $columnaExtras);
$pdo = db();
$pdo->beginTransaction();
$actual = $pdo->prepare("SELECT `$campo`" . ($traeExtras ? ", `$columnaExtras`" : '') . " FROM `$tabla` WHERE $where FOR UPDATE");
$actual->execute($params);
$fila = $actual->fetch();
if (!$fila) {
    responder(404, ['ok' => false, 'error' => 'El registro no existe o fue dado de baja.']);
}

// La CURP no se repite dentro de la institución
if ($clave === 'pacientes' && $campo === 'curp' && $nuevo !== null) {
    $repetida = db()->prepare('SELECT folio FROM pacientes WHERE curp = ? AND institucion_id = ? AND id <> ?');
    $repetida->execute([$nuevo, institucionId(), $id]);
    if ($folio = $repetida->fetchColumn()) {
        responder(422, ['ok' => false, 'error' => "Esta CURP ya está registrada (expediente $folio)."]);
    }
}

// ---- Guardar + bitácora ----
$anterior = $fila[$campo];
$extrasFila = [];
$cambioBloques = false;
if ($traeExtras) {
    $extrasFila = json_decode((string) ($fila[$columnaExtras] ?? ''), true) ?: [];
    $cambioBloques = sincronizarBloquesCampo($extrasFila, $campo, $texto, $bloques, (string) $anterior);
}
if ((string) $anterior !== (string) $nuevo || $cambioBloques) {
    if ((string) $anterior !== (string) $nuevo) {
        $pdo->prepare("UPDATE `$tabla` SET `$campo` = ? WHERE $where")->execute(array_merge([$nuevo], $params));
        registrarBitacora($tabla, $id, 'actualizar', $campo,
            $anterior === null ? null : (string) $anterior,
            $nuevo === null ? null : (string) $nuevo);
    }
    if ($cambioBloques) {
        $pdo->prepare("UPDATE `$tabla` SET `$columnaExtras` = ? WHERE $where")
            ->execute(array_merge([$extrasFila ? json_encode($extrasFila, JSON_UNESCAPED_UNICODE) : null], $params));
    }
}
$pdo->commit();

$textoRespuesta = match ($tipo) {
    'casilla' => (int) $nuevo ? 'Sí' : 'No',
    'fecha'   => $nuevo === null ? '' : fechaCorta((string) $nuevo),
    default   => (string) ($nuevo ?? ''),
};

responder(200, [
    'ok'    => true,
    'valor' => $tipo === 'casilla' ? (string) (int) $nuevo : (string) ($nuevo ?? ''),
    'texto' => $textoRespuesta,
    'entidad' => $clave,
    'campo'   => $campo,
]);
