<?php
/**
 * Actividad reciente del usuario en páginas y expedientes, tomada de la bitácora.
 * Los valores anteriores/nuevos no se exponen porque pueden contener información clínica.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../../app/servicios/espacio/secciones.php';

header('Content-Type: application/json; charset=UTF-8');
$usuarioId = (int) usuarioActual()['id'];

$c = db()->prepare(
    "SELECT tabla, registro_id, accion, campo, creado_en
       FROM bitacora
      WHERE usuario_id = ? AND institucion_id = ? AND tabla IN ('paginas', 'pacientes') AND registro_id > 0
        AND accion IN ('crear', 'actualizar', 'eliminar', 'acceso')
        AND creado_en > NOW() - INTERVAL 30 DAY
      ORDER BY creado_en DESC
      LIMIT 200"
);
$c->execute([$usuarioId, institucionId()]);
$filas = $c->fetchAll();

$espacio = paginasDelEspacio($usuarioId);
$idsPacientes = array_values(array_unique(array_map(
    fn ($f) => (int) $f['registro_id'],
    array_filter($filas, fn ($f) => $f['tabla'] === 'pacientes')
)));
$pacientes = [];
if ($idsPacientes && puede('ver_expediente')) {
    $marcas = implode(',', array_fill(0, count($idsPacientes), '?'));
    $p = db()->prepare("SELECT id, folio, nombre, primer_apellido, segundo_apellido FROM pacientes WHERE institucion_id = ? AND id IN ($marcas)");
    $p->execute([institucionId(), ...$idsPacientes]);
    foreach ($p->fetchAll() as $x) {
        $pacientes[(int) $x['id']] = $x;
    }
}

$elementos = [];
foreach ($filas as $f) {
    $id = (int) $f['registro_id'];
    $accion = (string) $f['accion'];
    $editado = $accion !== 'acceso';
    $accionTexto = match ($accion) {
        'crear' => 'Registro creado',
        'eliminar' => 'Eliminación registrada',
        'acceso' => 'Consultado',
        default => 'Modificación registrada',
    };
    if ($f['tabla'] === 'paginas') {
        $pg = $espacio[$id] ?? null;
        if (!$pg || !$pg['accesible']) {
            continue;
        }
        $enPapelera = (bool) $pg['en_papelera'];
        $titulo = $pg['titulo'] !== '' ? $pg['titulo'] : TIPOS_PAGINA[$pg['tipo']]['nueva'];
        $esNota = $pg['tipo'] === 'nota' && $pg['seccion'] === null;
        if ($accion === 'actualizar' && $f['campo'] === 'papelera') {
            $accionTexto = 'Página restaurada';
        } elseif ($accion === 'eliminar' && $f['campo'] === 'papelera') {
            $accionTexto = 'Movida a la papelera';
        } elseif ($accion === 'actualizar') {
            $accionTexto = 'Página modificada';
        }
        $deSeccion = $pg['seccion'] !== null;
        $elementos[] = [
            'tipo' => 'pagina', 'id' => $id,
            'titulo' => $titulo,
            'detalle' => ($deSeccion ? 'Nota de sección' : TIPOS_PAGINA[$pg['tipo']]['texto']) . ' · ' . $accionTexto,
            'icono' => TIPOS_PAGINA[$pg['tipo']]['icono'],
            'url' => $enPapelera ? '' : URL_BASE . ($deSeccion ? rutaDeSeccion($pg['seccion']) : '/paginas/ver.php?id=' . $id),
            'versionUrl' => !$enPapelera && $esNota && $editado ? URL_BASE . '/paginas/versiones.php?id=' . $id : '',
            'restaurable' => $enPapelera && $pg['nivel'] === 'completo' && $pg['eliminado_en'] !== null,
            'enPapelera' => $enPapelera,
            'ultima' => $f['creado_en'], 'editado' => $editado,
        ];
    } elseif (isset($pacientes[$id])) {
        $x = $pacientes[$id];
        $elementos[] = [
            'tipo' => 'paciente', 'id' => $id,
            'titulo' => trim("{$x['nombre']} {$x['primer_apellido']} {$x['segundo_apellido']}"),
            'detalle' => 'Expediente ' . $x['folio'] . ' · ' . $accionTexto, 'icono' => 'usuario',
            'url' => $accion === 'eliminar' ? '' : URL_BASE . '/pacientes/ver.php?id=' . $id,
            'versionUrl' => '', 'restaurable' => false, 'enPapelera' => false,
            'ultima' => $f['creado_en'], 'editado' => $editado,
        ];
    }
    if (count($elementos) >= 50) {
        break;
    }
}
echo json_encode(['ok' => true, 'elementos' => $elementos], JSON_UNESCAPED_UNICODE);
