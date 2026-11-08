<?php
/**
 * Actividad de la sección abierta (Pacientes, Agenda, ...) para el panel lateral de "Última edición".
 * Mismos datos que la actividad de una página: resumen (tarjeta), detalle (lista de notificaciones)
 * y estadísticas de los últimos 28 días, todo tomado de la bitácora de la institución.
 *
 * GET: api/actividad_seccion.php?seccion=pacientes
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';

header('Content-Type: application/json; charset=UTF-8');

function responderSeccion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$seccion = (string) ($_GET['seccion'] ?? '');

// Cada sección se limita a las tablas que la forman (null = toda la institución)
$tablas = match ($seccion) {
    'pacientes' => ['pacientes', 'historial_clinico', 'imagenes_medicas'],
    'agenda'    => ['citas'],
    default     => null,
};

if ($seccion === 'pacientes' && !puede('ver_expediente')) {
    responderSeccion(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.']);
}
if ($seccion === 'agenda' && !puede('agenda')) {
    responderSeccion(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.']);
}
// Una sección desconocida no puede convertirse en "toda la bitácora de la institución".
if ($tablas === null && !puede('configurar')) {
    responderSeccion(403, ['ok' => false, 'error' => 'Tu rol no tiene permiso para esto.']);
}

$usuarioId = (int) usuarioActual()['id'];
$donde     = $tablas
    ? 'b.tabla IN (' . implode(',', array_fill(0, count($tablas), '?')) . ') AND b.institucion_id = ?'
    : 'b.institucion_id = ?';
$parametros = $tablas ? [...$tablas, institucionId()] : [institucionId()];

$consulta = db()->prepare(
    "SELECT b.tabla, b.registro_id, b.accion, b.campo, b.valor_anterior, b.valor_nuevo, b.creado_en,
            u.id AS usuario_id, u.nombre, u.foto
       FROM bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id
      WHERE $donde
      ORDER BY b.id DESC LIMIT 300"
);
$consulta->execute($parametros);
$filas = $consulta->fetchAll();

// Nombres de los pacientes consultados (para decir «abrió el expediente de …»)
$idsPaciente = [];
foreach ($filas as $f) {
    if ($f['tabla'] === 'pacientes') {
        $idsPaciente[(int) $f['registro_id']] = true;
    }
}
$idsPaciente = array_keys($idsPaciente);
$porPaciente = [];
if ($idsPaciente) {
    $nombres = db()->prepare(
        'SELECT id, CONCAT(nombre, " ", primer_apellido) AS nombre FROM pacientes WHERE id IN ('
        . implode(',', array_fill(0, count($idsPaciente), '?')) . ')'
    );
    $nombres->execute($idsPaciente);
    foreach ($nombres->fetchAll() as $fila) {
        $porPaciente[(int) $fila['id']] = trim((string) $fila['nombre']);
    }
}

/** Nombre legible del campo en la bitácora; no muestra identificadores internos crudos. */
function etiquetaCampoSeccion(string $campo): string
{
    $etiquetas = [
        'nombre' => 'nombre', 'primer_apellido' => 'primer apellido', 'segundo_apellido' => 'segundo apellido',
        'fecha_nacimiento' => 'fecha de nacimiento', 'sexo' => 'sexo', 'telefono' => 'teléfono',
        'correo' => 'correo electrónico', 'direccion' => 'domicilio', 'folio' => 'folio',
        'tipo' => 'tipo de registro', 'fecha' => 'fecha del registro', 'titulo' => 'título',
        'detalle' => 'detalle clínico', 'inicio' => 'fecha y hora', 'fin' => 'hora de término',
        'estado' => 'estado', 'motivo' => 'motivo', 'notas' => 'notas', 'contenido' => 'contenido',
        'profesional_id' => 'profesional asignado', 'paciente_id' => 'paciente asignado',
    ];
    return $etiquetas[$campo] ?? ($campo !== '' ? str_replace('_', ' ', $campo) : 'registro');
}

function valorSeccion(array $f, ?string $valor): ?string
{
    if ($valor === null) {
        return null;
    }
    if ($f['tabla'] === 'historial_clinico' || in_array($f['campo'], ['detalle', 'notas', 'contenido'], true)) {
        return '[contenido protegido]';
    }
    $valor = trim(preg_replace('/\s+/u', ' ', strip_tags($valor)) ?? '');
    if ($valor === '') {
        return '(vacío)';
    }
    if (is_array(json_decode($valor, true))) {
        return '[datos estructurados]';
    }
    return mb_strimwidth($valor, 0, 110, '…', 'UTF-8');
}

/** Describe el movimiento en lenguaje claro y mantiene los valores clínicos fuera del feed. */
function descripcionSeccion(array $f, array $porPaciente, bool $esTu): string
{
    $nombre = $porPaciente[(int) $f['registro_id']] ?? null;
    $entidad = match ($f['tabla']) {
        'pacientes' => 'expediente', 'historial_clinico' => 'registro clínico', 'citas' => 'cita',
        'imagenes_medicas' => 'imagen médica', default => 'registro',
    };
    $articulo = match ($f['tabla']) {
        'pacientes' => 'del expediente', 'historial_clinico' => 'del registro clínico',
        'citas' => 'de la cita', 'imagenes_medicas' => 'de la imagen médica', default => 'del registro',
    };
    return match (true) {
        $f['accion'] === 'crear' => ($esTu ? 'registraste' : 'registró') . ' un nuevo ' . $entidad,
        $f['accion'] === 'eliminar' => ($esTu ? 'eliminaste' : 'eliminó') . ' un ' . $entidad,
        $f['accion'] === 'actualizar' && (string) $f['campo'] !== '' => ($esTu ? 'actualizaste' : 'actualizó')
            . ' el campo «' . etiquetaCampoSeccion((string) $f['campo']) . '» ' . $articulo,
        $f['accion'] === 'actualizar' => $esTu ? 'actualizaste un registro' : 'actualizó un registro',
        $f['tabla'] === 'pacientes' && $nombre !== null => $esTu
            ? "abriste el expediente de {$nombre}" : "abrió el expediente de {$nombre}",
        $f['tabla'] === 'citas' => $esTu ? 'consultaste la agenda' : 'consultó la agenda',
        $f['accion'] === 'salida' => $esTu ? 'cerraste sesión' : 'cerró sesión',
        default => $esTu ? 'consultaste la sección' : 'consultó la sección',
    };
}

function detalleSeccion(array $f): ?string
{
    if ($f['accion'] !== 'actualizar' || $f['campo'] === null) {
        return null;
    }
    if ($f['tabla'] === 'historial_clinico' || in_array($f['campo'], ['detalle', 'notas', 'contenido'], true)) {
        return 'El contenido clínico no se muestra en el registro de actividad.';
    }
    $antes = valorSeccion($f, $f['valor_anterior']);
    $despues = valorSeccion($f, $f['valor_nuevo']);
    return $antes !== null || $despues !== null
        ? 'Antes: ' . ($antes ?? '—') . '  →  Ahora: ' . ($despues ?? '—')
        : 'La bitácora registró el campo, pero no conservó sus valores anterior y nuevo.';
}

$detalle = [];
$resumen = ['edicion' => null, 'creacion' => null];
$huboEdicion = false;
foreach ($filas as $f) {
    $persona = [
        'usuario_id' => (int) $f['usuario_id'],
        'nombre'     => $f['nombre'] ?? 'Sistema',
        'tu'         => (int) $f['usuario_id'] === $usuarioId,
        'foto'       => $f['foto'] ? URL_BASE . '/' . $f['foto'] : null,
        'fecha'      => date('c', strtotime($f['creado_en'])),
        'hace'       => hace($f['creado_en']),
    ];
    $texto = descripcionSeccion($f, $porPaciente, (int) $f['usuario_id'] === $usuarioId);
    $detalleCambio = detalleSeccion($f);
    if ($f['accion'] === 'crear') {
        $resumen['creacion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        $huboEdicion = true;
    } else {
        if ($f['accion'] !== 'acceso') {
            $huboEdicion = true;
            $resumen['edicion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        } elseif ($resumen['edicion'] === null && $resumen['creacion'] === null) {
            $resumen['edicion'] ??= $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
        }
    }

    $ultimo = end($detalle);
    if ($ultimo && $ultimo['texto'] === $texto && $ultimo['detalle'] === $detalleCambio && $ultimo['usuario_id'] === $persona['usuario_id']) {
        continue;
    }
    if (count($detalle) < 60) {
        $detalle[] = $persona + ['texto' => $texto, 'detalle' => $detalleCambio];
    }
}
$resumen['etiqueta_edicion'] = $huboEdicion ? 'Editada por' : 'Última consulta de';
$resumen['etiqueta_creacion'] = 'Creada por';

$desde = (new DateTime('-28 days'))->format('Y-m-d H:i:s');
$cuenta = function (string $acciones) use ($donde, $parametros, $desde): int {
    $c = db()->prepare("SELECT COUNT(*) FROM bitacora b WHERE $donde AND b.accion IN ($acciones) AND b.creado_en >= ?");
    $c->execute([...$parametros, $desde]);
    return (int) $c->fetchColumn();
};
$cuentaDistinta = function (string $acciones) use ($donde, $parametros, $desde): int {
    $c = db()->prepare("SELECT COUNT(DISTINCT b.usuario_id) FROM bitacora b WHERE $donde AND b.accion IN ($acciones) AND b.creado_en >= ?");
    $c->execute([...$parametros, $desde]);
    return (int) $c->fetchColumn();
};

$estadisticas = [
    'vistas'     => $cuenta("'acceso'"),
    'visitantes' => $cuentaDistinta("'acceso'"),
    'ediciones'  => $cuenta("'crear','actualizar','eliminar'"),
    'editores'   => $cuentaDistinta("'crear','actualizar','eliminar'"),
];

// Consultas por día (últimos 28 días) para la gráfica de barras
$porDia = db()->prepare(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS total FROM bitacora b
      WHERE $donde AND b.accion = 'acceso' AND b.creado_en >= ?
      GROUP BY DATE(creado_en)"
);
$porDia->execute([...$parametros, $desde]);
$mapa = array_column($porDia->fetchAll(), 'total', 'dia');
$estadisticas['dias'] = [];
for ($i = 27; $i >= 0; $i--) {
    $dia = (new DateTime("-$i days"))->format('Y-m-d');
    $estadisticas['dias'][] = ['dia' => $dia, 'total' => (int) ($mapa[$dia] ?? 0)];
}

responderSeccion(200, ['ok' => true, 'resumen' => $resumen, 'detalle' => $detalle, 'estadisticas' => $estadisticas]);
