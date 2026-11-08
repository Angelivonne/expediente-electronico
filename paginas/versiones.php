<?php
/**
 * Versiones de una nota: cada guardado deja el contenido anterior en registro_versiones.
 * Vista de solo lectura para consultar qué cambió y cuándo (la restauración de notas queda pendiente).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';
require_once __DIR__ . '/../app/servicios/espacio/firmas.php';

$id = (int) ($_GET['id'] ?? 0);
$pagina = $id > 0 ? cargarPagina($id, usuarioActual()['id']) : null;
if (!$pagina || !empty($pagina['en_papelera'])) {
    mensajeFlash('error', 'La nota no existe o está en la papelera.');
    redirigir('/paginas/');
}

$nivel = (string) ($pagina['nivel'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_restaurar_nota'] ?? '') === '1') {
    if ($nivel !== 'editar' && $nivel !== 'completo') {
        mensajeFlash('error', 'No tienes permiso para editar esta nota.');
    } elseif (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } else {
        $version = db()->prepare("SELECT id, datos FROM registro_versiones WHERE id = ? AND entidad = 'paginas' AND registro_id = ?");
        $version->execute([(int) ($_POST['version'] ?? 0), $id]);
        $version = $version->fetch();
        $datosVersion = $version ? (json_decode((string) $version['datos'], true) ?: []) : [];
        $bloques = bloquesValidos(json_decode((string) ($datosVersion['contenido'] ?? '[]'), true));
        if (!$version || $bloques === null) {
            mensajeFlash('error', 'Esa versión no se puede restaurar.');
        } elseif (notaFirmada($id)) {
            mensajeFlash('error', 'La nota está firmada: rompe el sello antes de restaurar.');
        } else {
            try {
                require_once __DIR__ . '/../app/servicios/motor/almacen.php';
                motorGuardarVersion('paginas', $id, $pagina);
            } catch (Throwable $e) {
                // Sin auditoría no se bloquea la restauración
            }
            db()->prepare('UPDATE paginas SET contenido = ?, version = version + 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
                ->execute([json_encode($bloques, JSON_UNESCAPED_UNICODE), usuarioActual()['id'], $id]);
            registrarBitacora('paginas', $id, 'actualizar', 'contenido_restaurado');
            mensajeFlash('exito', 'Versión restaurada.');
        }
    }
    redirigir('/paginas/versiones.php?id=' . $id);
}
$versiones = db()->prepare("SELECT v.id, v.usuario_id, v.datos, v.creado_en, u.nombre AS autor
                              FROM registro_versiones v LEFT JOIN usuarios u ON u.id = v.usuario_id
                             WHERE v.entidad = 'paginas' AND v.registro_id = ?
                             ORDER BY v.id DESC LIMIT 50");
$versiones->execute([$id]);
$versiones = $versiones->fetchAll();

$textoDeVersion = function (array $version): string {
    $datos = json_decode((string) $version['datos'], true) ?: [];
    $bloques = json_decode((string) ($datos['contenido'] ?? '[]'), true) ?: [];
    $partes = [];
    foreach ((array) $bloques as $bloque) {
        $texto = trim((string) ($bloque['x'] ?? ''));
        if ($texto !== '') {
            $partes[] = $texto;
        }
    }
    return mb_strimwidth(implode(' · ', $partes), 0, 240, '…');
};

$tituloPagina  = 'Versiones · ' . $pagina['titulo'];
$seccionActiva = 'espacio';
$claseContenido = 'pagina--ancha';
$migas = [['texto' => 'Espacio', 'url' => '/paginas/'], ['texto' => $pagina['titulo'], 'url' => '/paginas/ver.php?id=' . $id], ['texto' => 'Versiones']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <span class="pagina__meta"><?= icono('documento') ?> Historial de cambios</span>
  <h1 class="pagina__titulo">Versiones de la nota</h1>
  <p class="pagina__subtitulo">Cada guardado conserva el contenido anterior. Se muestran las últimas 50 versiones.</p>
</div>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Cambios registrados</h2>
    <span class="ficha__fuente"><?= count($versiones) ?> versión(es)</span>
  </div>

  <?php if (!$versiones): ?>
    <p class="ficha__vacio">Todavía no hay versiones de esta nota.</p>
  <?php else: ?>
    <ul class="versiones">
      <?php foreach ($versiones as $version): ?>
        <li class="versiones__item">
          <details>
            <summary class="versiones__resumen">
              <span class="versiones__fecha"><?= e(fechaCorta(substr((string) $version['creado_en'], 0, 10))) ?> · <?= e(hora12((string) $version['creado_en'])) ?></span>
              <span class="versiones__autor"><?= e($version['autor'] ?? 'Sistema') ?></span>
            </summary>
            <?php $resumenVersion = $textoDeVersion($version); ?>
            <?php if (($pagina['nivel'] ?? '') === 'editar' || ($pagina['nivel'] ?? '') === 'completo'): ?>
              <form method="post" class="versiones__restaurar">
                <?= campoCsrf() ?>
                <input type="hidden" name="accion_restaurar_nota" value="1">
                <input type="hidden" name="version" value="<?= (int) $version['id'] ?>">
                <button type="submit" class="boton boton--sutil">Restaurar esta versión</button>
              </form>
            <?php endif; ?>            <p class="versiones__previa"><?= $resumenVersion !== '' ? e($resumenVersion) : 'Sin texto guardado.' ?></p>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="documento__acciones">
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/paginas/ver.php?id=<?= $id ?>"><?= icono('atras') ?> Volver a la nota</a>
  </div>
</section>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
