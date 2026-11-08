<?php
/**
 * Guarda un campo editado en línea desde un catálogo del sistema (CIE-10 o medicamentos).
 *
 * Las tablas de /cie10/ y /medicamentos/ reutilizan el editor en línea de app.js, que antes
 * siempre apuntaba a la API de pacientes: por eso al editar una celda respondía «el paciente
 * no existe». Este endpoint aplica las reglas de cada catálogo y deja el cambio en la bitácora.
 *
 * POST JSON {catalogo, id, campo, valor} con el token CSRF en X-CSRF-Token.
 * Responde {ok, valor, texto} o {ok: false, error}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/datos/catalogos_sistema.php';

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
exigirPermiso('ver_expediente');

/** Campos editables de cada catálogo y su validación (el nombre de la columna es la clave). */
const CAMPOS_CATALOGO = [
    'cie10' => [
        'codigo'      => ['max' => 7,   'mayusculas' => true, 'obligatorio' => true],
        'descripcion' => ['max' => 200, 'obligatorio' => true],
        'capitulo'    => ['max' => 80],
        'activo'      => ['opciones' => ['0', '1']],
    ],
    'medicamentos' => [
        'clave'         => ['max' => 30,  'mayusculas' => true, 'obligatorio' => true],
        'nombre'        => ['max' => 160, 'obligatorio' => true],
        'presentacion'  => ['max' => 80],
        'concentracion' => ['max' => 40],
        'via'           => ['max' => 40],
        'laboratorio'   => ['max' => 120],
        'activo'        => ['opciones' => ['0', '1']],
    ],
];

$entrada  = json_decode(file_get_contents('php://input'), true) ?: [];
$clave    = (string) ($entrada['catalogo'] ?? '');
$catalogo = catalogoSistema($clave);
$campos   = CAMPOS_CATALOGO[$clave] ?? null;
if (!$catalogo || !$campos || empty($catalogo['tabla'])) {
    responder(400, ['ok' => false, 'error' => 'Ese catálogo no se puede editar desde la tabla.']);
}

$id    = (int) ($entrada['id'] ?? 0);
$campo = (string) ($entrada['campo'] ?? '');
$valor = trim((string) ($entrada['valor'] ?? ''));
if ($id <= 0 || !isset($campos[$campo])) {
    responder(400, ['ok' => false, 'error' => 'Campo no válido para este catálogo.']);
}

$regla = $campos[$campo];
if (!empty($regla['mayusculas'])) {
    $valor = mb_strtoupper($valor);
}
if ($valor === '' && !empty($regla['obligatorio'])) {
    responder(422, ['ok' => false, 'error' => 'Este campo es obligatorio.']);
}
if ($valor !== '' && isset($regla['max']) && mb_strlen($valor) > $regla['max']) {
    responder(422, ['ok' => false, 'error' => "Máximo {$regla['max']} caracteres."]);
}
if (isset($regla['opciones']) && !in_array($valor, $regla['opciones'], true)) {
    responder(422, ['ok' => false, 'error' => 'Selecciona una opción de la lista.']);
}

$tabla = $catalogo['tabla'];
$actual = db()->prepare("SELECT `$campo` FROM `$tabla` WHERE id = ? AND institucion_id = ?");
$actual->execute([$id, institucionId()]);
$fila = $actual->fetch();
if (!$fila) {
    responder(404, ['ok' => false, 'error' => 'El registro ya no existe.']);
}
$anterior = $fila[$campo];
$nuevo    = $campo === 'activo' ? (int) $valor : ($valor === '' ? null : $valor);

if ((string) $nuevo !== (string) $anterior) {
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE `$tabla` SET `$campo` = ? WHERE id = ? AND institucion_id = ?")
        ->execute([$nuevo, $id, institucionId()]);
    registrarBitacora($tabla, $id, 'actualizar', $campo, $anterior === null ? null : (string) $anterior, $nuevo === null ? null : (string) $nuevo);
    $pdo->commit();
}

responder(200, [
    'ok'    => true,
    'valor' => $nuevo === null ? '' : (string) $nuevo,
    'texto' => $campo === 'activo' ? ((int) $nuevo ? 'Sí' : 'No') : (string) $nuevo,
]);
