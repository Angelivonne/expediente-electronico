<?php
/**
 * Historial técnico de cambios de filas en una base de datos del espacio de trabajo.
 * Solo muestra metadatos: nunca consulta ni expone valores de las celdas.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

$usuarioId = (int) usuarioActual()['id'];
$paginaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$pagina = $paginaId && $paginaId > 0 ? cargarPagina((int) $paginaId, $usuarioId) : null;
if (!$pagina || $pagina['tipo'] !== 'base_datos') {
    http_response_code(404);
    exit('La base de datos no existe o no tienes acceso.');
}

$id = (int) $pagina['id'];
$institucion = $pagina['institucion_id'] ?? institucionId();
$patron = 'pagina:' . $id . ':%';
$columnas = [];
foreach (columnasDe($pagina) as $columna) {
    $columnas[$columna['id']] = $columna['nombre'];
}

$nombreCampo = static function (?string $campo) use ($id, $columnas): string {
    $prefijo = 'pagina:' . $id . ':';
    if ($campo === null || !str_starts_with($campo, $prefijo)) {
        return 'Fila';
    }
    $detalle = substr($campo, strlen($prefijo));
    if (str_starts_with($detalle, 'celda:')) {
        $columnaId = substr($detalle, strlen('celda:'));
        return 'Celda · ' . ($columnas[$columnaId] ?? $columnaId);
    }
    return match ($detalle) {
        'notas' => 'Notas de fila',
        'orden' => 'Orden de la fila',
        default => 'Fila',
    };
};
$accionLegible = static fn (string $accion): string => match ($accion) {
    'crear' => 'Fila creada',
    'actualizar' => 'Fila actualizada',
    'eliminar' => 'Fila eliminada',
    default => 'Cambio',
};

$consultaBase = "SELECT b.id, b.registro_id, b.accion, b.campo, b.usuario_id, b.creado_en, u.nombre, u.rol
                   FROM bitacora b
                   LEFT JOIN usuarios u ON u.id = b.usuario_id
                  WHERE b.tabla = 'pagina_filas' AND b.campo LIKE ? AND b.institucion_id <=> ?";

if (($_GET['formato'] ?? '') === 'csv') {
    $consulta = db()->prepare($consultaBase . ' ORDER BY b.id');
    $consulta->execute([$patron, $institucion]);
    $nombre = 'evidencia-base-datos-' . $id . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Cache-Control: no-store, max-age=0');
    echo "\xEF\xBB\xBF";
    $salida = fopen('php://output', 'wb');
    if ($salida === false) {
        throw new RuntimeException('No se pudo generar la descarga del historial.');
    }
    fputcsv($salida, ['Evento', 'Base de datos', 'Fila', 'Campo', 'Acción', 'Usuario ID', 'Usuario', 'Rol', 'Fecha del servidor'], ';', '"', '\\', "\r\n");
    while ($evento = $consulta->fetch(PDO::FETCH_ASSOC)) {
        $datos = [
            (int) $evento['id'],
            $pagina['titulo'] !== '' ? $pagina['titulo'] : 'Base de datos',
            (int) $evento['registro_id'],
            $nombreCampo($evento['campo']),
            $accionLegible((string) $evento['accion']),
            $evento['usuario_id'] !== null ? (int) $evento['usuario_id'] : '',
            (string) ($evento['nombre'] ?? 'Cuenta no disponible'),
            (string) ($evento['rol'] ?? ''),
            (string) $evento['creado_en'],
        ];
        $datos = array_map(static function (mixed $valor): string {
            $texto = (string) $valor;
            return preg_match('/^[\s=+\-@]/u', $texto) ? "'" . $texto : $texto;
        }, $datos);
        fputcsv($salida, $datos, ';', '"', '\\', "\r\n");
    }
    fclose($salida);
    exit;
}

$antes = filter_var($_GET['antes'] ?? null, FILTER_VALIDATE_INT);
$sql = $consultaBase;
$parametros = [$patron, $institucion];
if ($antes !== false && $antes !== null && $antes > 0) {
    $sql .= ' AND b.id < ?';
    $parametros[] = $antes;
}
$consulta = db()->prepare($sql . ' ORDER BY b.id DESC LIMIT 201');
$consulta->execute($parametros);
$eventos = $consulta->fetchAll(PDO::FETCH_ASSOC);
$hayMas = count($eventos) > 200;
if ($hayMas) {
    array_pop($eventos);
}
$siguiente = $eventos ? (int) $eventos[array_key_last($eventos)]['id'] : null;

$tituloPagina = 'Evidencia de cambios · ' . ($pagina['titulo'] !== '' ? $pagina['titulo'] : 'Base de datos');
$seccionActiva = 'espacio';
$claseContenido = 'pagina--ancha';
$migas = [
    ['texto' => 'Espacio', 'url' => '/paginas/'],
    ['texto' => $pagina['titulo'] !== '' ? $pagina['titulo'] : 'Base de datos', 'url' => '/paginas/ver.php?id=' . $id],
    ['texto' => 'Historial de cambios'],
];
$paginaActual = $pagina;
$clavePresencia = 'pagina:' . $id;
$mostrarBanner = false;
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<section class="hoja">
  <h1 class="hoja__titulo">Historial de cambios</h1>
  <p>Base de datos: <strong><?= e($pagina['titulo'] !== '' ? $pagina['titulo'] : 'Sin título') ?></strong></p>
  <p>El registro muestra quién, cuándo, fila y campo. Por privacidad no conserva ni presenta el contenido de las celdas.</p>
  <p>Es evidencia técnica de actividad; no es una firma digital ni certifica por sí sola la identidad de la persona.</p>
  <p>
    <a href="<?= e(URL_BASE . '/paginas/ver.php?id=' . $id) ?>">Volver a la base de datos</a>
    · <a href="<?= e(URL_BASE . '/paginas/auditoria.php?id=' . $id . '&formato=csv') ?>">Descargar historial CSV</a>
  </p>

  <?php if (!$eventos): ?>
    <p>No hay cambios de filas registrados para esta base de datos. El seguimiento aplica a cambios realizados después de instalar esta actualización.</p>
  <?php else: ?>
    <div class="bd-tabla-contenedor">
      <table class="bd-tabla">
        <thead>
          <tr><th>Evento</th><th>Fecha del servidor</th><th>Usuario</th><th>Acción</th><th>Fila</th><th>Campo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($eventos as $evento): ?>
            <tr>
              <td><?= (int) $evento['id'] ?></td>
              <td><time datetime="<?= e((string) $evento['creado_en']) ?>"><?= e((string) $evento['creado_en']) ?></time></td>
              <td><?= e($evento['nombre'] ?? 'Cuenta no disponible') ?><?= $evento['usuario_id'] !== null ? ' · #' . (int) $evento['usuario_id'] : '' ?></td>
              <td><?= e($accionLegible((string) $evento['accion'])) ?></td>
              <td><?= (int) $evento['registro_id'] ?></td>
              <td><?= e($nombreCampo($evento['campo'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($hayMas && $siguiente !== null): ?>
      <p><a href="<?= e(URL_BASE . '/paginas/auditoria.php?id=' . $id . '&antes=' . $siguiente) ?>">Ver cambios anteriores</a></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
