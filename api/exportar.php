<?php
/**
 * Exportar / convertir expedientes y notas a otros formatos.
 *   GET  ?tipo=paciente&id=…&formato=html|json   datos del paciente para exportar
 *   GET  ?tipo=nota&id=…&formato=html|json       datos de una nota para exportar
 *   POST mismo cuerpo JSON
 * Devuelve HTML imprimible (para abrir y guardar como PDF desde el navegador) o JSON.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../app/servicios/clinica/registros.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int) usuarioActual()['id'];

$tipo  = (string) ($_GET['tipo'] ?? ($_POST['tipo'] ?? ''));
$id    = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$formato = (string) ($_GET['formato'] ?? ($_POST['formato'] ?? 'html'));

if ($tipo === '' || $id <= 0) {
    responder(400, ['ok' => false, 'error' => 'Faltan parámetros: tipo e id.']);
}

if (!in_array($formato, ['html', 'json', 'pdf'], true)) {
    responder(400, ['ok' => false, 'error' => 'Formato no válido. Usa html, json o pdf.']);
}

if ($tipo === 'paciente') {
    if (!puedeVerExpediente($id)) {
        responder(403, ['ok' => false, 'error' => 'No tienes acceso a este expediente.']);
    }
    $c = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND eliminado_en IS NULL AND institucion_id <=> ?');
    $c->execute([$id, institucionId()]);
    $p = $c->fetch();
    if (!$p) {
        responder(404, ['ok' => false, 'error' => 'El paciente no existe.']);
    }
    if ($formato === 'json') {
        $institucionExportada = institucionActual();
        responder(200, ['ok' => true, 'tipo' => 'paciente', 'datos' => $p,
            'institucion' => $institucionExportada ? [
                'nombre' => $institucionExportada['nombre'] ?? null,
                'domicilio' => $institucionExportada['domicilio'] ?? null,
                'entidad' => ENTIDADES[$institucionExportada['entidad'] ?? ''] ?? null,
                'clues' => $institucionExportada['clues'] ?? null,
            ] : null,
        ]);
    }
    // HTML imprimible: reutiliza la vista de impresión
    $url = URL_BASE . '/pacientes/imprimir.php?id=' . $id . '&imprimir=1';
    responder(200, ['ok' => true, 'tipo' => 'paciente', 'formato' => 'html', 'url' => $url, 'titulo' => 'Expediente ' . e($p['folio'])]);
}

if ($tipo === 'nota') {
    $pagina = cargarPagina($id, $usuarioId);
    if (!$pagina || $pagina['tipo'] !== 'nota') {
        responder(404, ['ok' => false, 'error' => 'La nota no existe o no tienes acceso.']);
    }
    if (!nivelAlcanza($pagina, 'ver')) {
        responder(403, ['ok' => false, 'error' => 'No tienes permiso para ver esta nota.']);
    }
    if ($formato === 'json') {
        responder(200, ['ok' => true, 'tipo' => 'nota', 'datos' => [
            'id' => (int) $pagina['id'],
            'titulo' => $pagina['titulo'],
            'contenido' => $pagina['contenido'],
            'creado_en' => $pagina['creado_en'],
            'actualizado_en' => $pagina['actualizado_en'],
            'institucion' => (function (): ?array {
                $institucion = institucionActual();
                return $institucion ? [
                    'nombre' => $institucion['nombre'] ?? null,
                    'domicilio' => $institucion['domicilio'] ?? null,
                    'entidad' => ENTIDADES[$institucion['entidad'] ?? ''] ?? null,
                    'clues' => $institucion['clues'] ?? null,
                ] : null;
            })(),
        ]]);
    }
    // HTML imprimible de la nota
    $url = URL_BASE . '/paginas/ver.php?id=' . $id;
    responder(200, ['ok' => true, 'tipo' => 'nota', 'formato' => 'html', 'url' => $url, 'titulo' => $pagina['titulo']]);
}

responder(400, ['ok' => false, 'error' => 'Tipo no válido. Usa paciente o nota.']);
