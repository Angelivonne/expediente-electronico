<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('importar_exportar');

$institucionId = institucionId() ?: 1;
$areasValidas = ['urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'];
$estadosValidos = ['disponible', 'ocupada', 'limpieza', 'mantenimiento', 'reservada'];
$area = in_array($_GET['area'] ?? '', $areasValidas, true) ? (string) $_GET['area'] : '';
$estadoSolicitado = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';
$estado = in_array($estadoSolicitado, $estadosValidos, true) ? $estadoSolicitado : '';
$busqueda = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

$sql = "SELECT c.codigo, c.area, c.piso, c.tipo, c.estado, c.diagnostico_ingreso, c.ingreso_en,
               p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
          FROM camillas c
          LEFT JOIN pacientes p ON p.id = c.paciente_id AND p.institucion_id = c.institucion_id
         WHERE c.institucion_id = ?";
$parametros = [$institucionId];
if ($area !== '') {
    $sql .= ' AND c.area = ?';
    $parametros[] = $area;
}
if ($estado !== '') {
    $sql .= ' AND c.estado = ?';
    $parametros[] = $estado;
}
if ($busqueda !== '') {
    [$filtroBusqueda, $parametrosBusqueda] = condicionBusqueda([
        'c.codigo',
        'c.piso',
        'c.area',
        'c.tipo',
        'c.estado',
        'c.diagnostico_ingreso',
        'c.notas',
        "CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido)",
        'p.folio',
        'p.curp',
        'p.alergias',
    ], $busqueda);
    $sql .= " AND $filtroBusqueda";
    array_push($parametros, ...$parametrosBusqueda);
}
$sql .= " ORDER BY FIELD(c.area, 'urgencias', 'hospitalizacion', 'cirugia', 'terapia_intensiva'), c.codigo";
$consulta = db()->prepare($sql);
$consulta->execute($parametros);

$archivo = 'censo-camas-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Cache-Control: no-store');
$salida = fopen('php://output', 'wb');
if ($salida === false) {
    throw new RuntimeException('No se pudo preparar la descarga del censo.');
}
fwrite($salida, "\xEF\xBB\xBF");
fputcsv($salida, ['Código', 'Área', 'Piso', 'Tipo', 'Estado', 'Folio', 'Paciente', 'Diagnóstico / motivo', 'Ingreso'], ';', '"', '\\');
while ($fila = $consulta->fetch(PDO::FETCH_ASSOC)) {
    $paciente = trim(implode(' ', array_filter([
        $fila['nombre'], $fila['primer_apellido'], $fila['segundo_apellido'],
    ], static fn ($parte): bool => $parte !== null && $parte !== '')));
    $valores = [
        $fila['codigo'], $fila['area'], $fila['piso'], $fila['tipo'], $fila['estado'],
        $fila['folio'], $paciente, $fila['diagnostico_ingreso'], $fila['ingreso_en'],
    ];
    $valores = array_map(static function ($valor): string {
        $texto = (string) ($valor ?? '');
        return preg_match('/^[=+\-@]/u', $texto) ? "'" . $texto : $texto;
    }, $valores);
    fputcsv($salida, $valores, ';', '"', '\\');
}
fclose($salida);
