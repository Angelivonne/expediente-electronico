<?php
/**
 * Busca notas del historial clínico por texto, sin devolver su contenido.
 * GET q (texto). Responde JSON {total, notas: [...]}.
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../../app/nucleo/inicio.php';
require_once __DIR__ . '/../../app/datos/registros_clinicos.php';
exigirPermiso('ver_expediente');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$terminos = array_values(array_filter(
    terminosBusqueda(trim((string) ($_GET['q'] ?? ''))),
    static fn (string $termino): bool => mb_strlen($termino, 'UTF-8') >= 3
));
if (!$terminos) {
    echo json_encode(['total' => 0, 'notas' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$columnaBusqueda = db()->prepare(
    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$columnaBusqueda->execute(['historial_clinico_busqueda']);
if (!$columnaBusqueda->fetchColumn()) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Aplica database/migracion_busqueda_historial.sql para habilitar la búsqueda en notas clínicas.',
        'total' => 0,
        'notas' => [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$consultaFullText = implode(' ', array_map(static fn (string $termino): string => '+' . $termino . '*', $terminos));
$base = ' FROM historial_clinico_busqueda hb
          JOIN historial_clinico h ON h.id = hb.historial_id
          JOIN pacientes p ON p.id = h.paciente_id
                          AND p.institucion_id = h.institucion_id
                          AND p.eliminado_en IS NULL
         WHERE h.institucion_id = ?
           AND MATCH(hb.texto) AGAINST (? IN BOOLEAN MODE)';
$parametros = [institucionId(), $consultaFullText];

$total = db()->prepare('SELECT COUNT(*)' . $base);
$total->execute($parametros);

$consulta = db()->prepare(
    'SELECT h.id AS historial_id, h.fecha, p.id AS paciente_id, p.nombre,
            p.primer_apellido, p.segundo_apellido, h.tipo, h.titulo
       ' . $base . '
      ORDER BY h.fecha DESC, h.id DESC
      LIMIT 8'
);
$consulta->execute($parametros);

$notas = array_map(static function (array $fila): array {
    return [
        'id' => (int) $fila['historial_id'],
        'paciente_id' => (int) $fila['paciente_id'],
        'paciente' => trim($fila['nombre'] . ' ' . $fila['primer_apellido'] . ' ' . ($fila['segundo_apellido'] ?? '')),
        'tipo' => nombreTipoRegistro((string) $fila['tipo'], 'documento'),
        'titulo' => (string) $fila['titulo'],
        'fecha' => fechaCorta($fila['fecha']),
    ];
}, $consulta->fetchAll());

echo json_encode(['total' => (int) $total->fetchColumn(), 'notas' => $notas], JSON_UNESCAPED_UNICODE);
