<?php
/** Catálogo de servicios clínicos de la institución (sin precios precargados). */
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('configurar');

$tablas = db()->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$tablas->execute(['servicios_clinicos']);
$disponible = (bool) $tablas->fetchColumn();
$errores = [];
$idEditar = max(0, (int) ($_GET['id'] ?? 0));
$datos = ['nombre' => '', 'descripcion' => '', 'precio' => ''];

if ($disponible && $idEditar > 0) {
    $buscar = db()->prepare('SELECT id, nombre, descripcion, precio FROM servicios_clinicos WHERE id = ? AND institucion_id = ? AND activo = 1');
    $buscar->execute([$idEditar, institucionId()]);
    $existente = $buscar->fetch();
    if ($existente) {
        $datos = ['nombre' => $existente['nombre'], 'descripcion' => $existente['descripcion'] ?? '', 'precio' => number_format((float) $existente['precio'], 2, '.', '')];
    } else {
        $idEditar = 0;
    }
}

if ($disponible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Recarga la página.';
    } elseif (in_array($accion, ['desactivar', 'reactivar'], true)) {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        $activoNuevo = $accion === 'reactivar' ? 1 : 0;
        $activoAnterior = 1 - $activoNuevo;
        $q = $pdo->prepare('UPDATE servicios_clinicos SET activo = ?, actualizado_por = ? WHERE id = ? AND institucion_id = ? AND activo = ?');
        $q->execute([$activoNuevo, usuarioActual()['id'], $id, institucionId(), $activoAnterior]);
        if ($q->rowCount()) {
            registrarBitacora('servicios_clinicos', $id, 'actualizar', 'activo', (string) $activoAnterior, (string) $activoNuevo);
        }
        $pdo->commit();
        mensajeFlash('exito', $q->rowCount() ? ($activoNuevo ? 'Servicio reactivado.' : 'Servicio desactivado.') : 'El servicio ya no está disponible.');
        redirigir('/finanzas/servicios.php');
    } elseif ($accion === 'guardar') {
        $idEditar = max(0, (int) ($_POST['id'] ?? 0));
        $datos = [
            'nombre' => trim((string) ($_POST['nombre'] ?? '')),
            'descripcion' => trim((string) ($_POST['descripcion'] ?? '')),
            'precio' => trim((string) ($_POST['precio'] ?? '')),
        ];
        if ($datos['nombre'] === '' || mb_strlen($datos['nombre']) > 120) {
            $errores['nombre'] = 'Escribe un nombre de hasta 120 caracteres.';
        }
        if (mb_strlen($datos['descripcion']) > 255) {
            $errores['descripcion'] = 'Máximo 255 caracteres.';
        }
        if ($datos['precio'] === '' || !is_numeric($datos['precio']) || !is_finite((float) $datos['precio']) || (float) $datos['precio'] < 0 || (float) $datos['precio'] > 9999999999.99) {
            $errores['precio'] = 'Escribe un precio válido igual o mayor que cero.';
        }
        if (!$errores) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ($idEditar > 0) {
                    $q = $pdo->prepare('UPDATE servicios_clinicos SET nombre = ?, descripcion = ?, precio = ?, actualizado_por = ? WHERE id = ? AND institucion_id = ? AND activo = 1');
                    $q->execute([$datos['nombre'], $datos['descripcion'] ?: null, $datos['precio'], usuarioActual()['id'], $idEditar, institucionId()]);
                    if (!$q->rowCount()) {
                        $verificar = db()->prepare('SELECT 1 FROM servicios_clinicos WHERE id = ? AND institucion_id = ? AND activo = 1');
                        $verificar->execute([$idEditar, institucionId()]);
                        if (!$verificar->fetchColumn()) {
                            $errores['general'] = 'El servicio ya no está disponible.';
                        }
                    }
                } else {
                    $q = $pdo->prepare('INSERT INTO servicios_clinicos (institucion_id, nombre, descripcion, precio, creado_por, actualizado_por) VALUES (?, ?, ?, ?, ?, ?)');
                    $q->execute([institucionId(), $datos['nombre'], $datos['descripcion'] ?: null, $datos['precio'], usuarioActual()['id'], usuarioActual()['id']]);
                }
                if (!$errores) {
                    registrarBitacora('servicios_clinicos', $idEditar ?: (int) db()->lastInsertId(), $idEditar ? 'actualizar' : 'crear', 'servicio', null, $datos['nombre'] . ' · $' . number_format((float) $datos['precio'], 2, '.', ''));
                    $pdo->commit();
                    mensajeFlash('exito', $idEditar ? 'Servicio actualizado.' : 'Servicio agregado.');
                    redirigir('/finanzas/servicios.php');
                }
                $pdo->rollBack();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof PDOException && (string) ($e->errorInfo[1] ?? '') === '1062') {
                    $errores['nombre'] = 'Ya existe un servicio con ese nombre en esta clínica.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$servicios = [];
$serviciosInactivos = [];
if ($disponible) {
    $q = db()->prepare('SELECT id, nombre, descripcion, precio, actualizado_en FROM servicios_clinicos WHERE institucion_id = ? AND activo = 1 ORDER BY nombre LIMIT 500');
    $q->execute([institucionId()]);
    $servicios = $q->fetchAll();
    $q = db()->prepare('SELECT id, nombre, precio FROM servicios_clinicos WHERE institucion_id = ? AND activo = 0 ORDER BY nombre LIMIT 200');
    $q->execute([institucionId()]);
    $serviciosInactivos = $q->fetchAll();
}

$tituloPagina = 'Servicios';
$seccionActiva = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas = [['texto' => 'Finanzas', 'url' => '/finanzas/'], ['texto' => 'Servicios']];
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera"><h1 class="pagina__titulo">Servicios</h1></div>
<?php require __DIR__ . '/_nav.php'; ?>

<?php if (!$disponible): ?>
  <p class="pagina__descripcion" role="status">Falta aplicar <code>database/migracion_servicios_clinicos.sql</code> para activar este catálogo.</p>
<?php else: ?>
  <?php if (!empty($errores['general'])): ?><p class="pagina__descripcion pagina__descripcion--error" role="alert"><?= e($errores['general']) ?></p><?php endif; ?>
  <section class="seccion">
    <h2 class="seccion__titulo"><?= $idEditar ? 'Editar servicio' : 'Agregar servicio' ?></h2>
    <div class="seccion__cuerpo">
      <form method="post" action="<?= URL_BASE ?>/finanzas/servicios.php<?= $idEditar ? '?id=' . $idEditar : '' ?>">
        <?= campoCsrf() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= $idEditar ?>">
        <div class="rejilla">
          <div class="campo col-6<?= isset($errores['nombre']) ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="servicio-nombre">Nombre</label>
            <div class="campo__control"><input id="servicio-nombre" name="nombre" maxlength="120" required value="<?= e($datos['nombre']) ?>"></div>
            <?php if (isset($errores['nombre'])): ?><span class="campo__ayuda"><?= e($errores['nombre']) ?></span><?php endif; ?>
          </div>
          <div class="campo col-3<?= isset($errores['precio']) ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="servicio-precio">Precio</label>
            <div class="campo__control"><input id="servicio-precio" name="precio" type="number" min="0" max="9999999999.99" step="0.01" required value="<?= e($datos['precio']) ?>"></div>
            <?php if (isset($errores['precio'])): ?><span class="campo__ayuda"><?= e($errores['precio']) ?></span><?php endif; ?>
          </div>
          <div class="campo col-12<?= isset($errores['descripcion']) ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="servicio-descripcion">Descripción</label>
            <div class="campo__control"><input id="servicio-descripcion" name="descripcion" maxlength="255" value="<?= e($datos['descripcion']) ?>"></div>
            <?php if (isset($errores['descripcion'])): ?><span class="campo__ayuda"><?= e($errores['descripcion']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="seccion__acciones">
          <?php if ($idEditar): ?><a class="boton boton--fantasma" href="<?= URL_BASE ?>/finanzas/servicios.php">Cancelar</a><?php endif; ?>
          <button class="boton boton--primario" type="submit"><?= $idEditar ? 'Guardar cambios' : 'Agregar servicio' ?></button>
        </div>
      </form>
    </div>
  </section>

  <section class="bd" data-bd>
    <div class="bd__cabecera"><h2 class="bd__titulo">Servicios activos</h2><span class="bd__contador"><?= count($servicios) ?></span></div>
    <?php if ($servicios): ?>
      <div class="finanzas__plano"><table class="bd-tabla">
        <thead><tr><th>Servicio</th><th>Descripción</th><th>Precio</th><th>Acciones</th></tr></thead>
        <tbody><?php foreach ($servicios as $servicio): ?>
          <tr>
            <td><?= e($servicio['nombre']) ?></td><td><?= e($servicio['descripcion'] ?? '—') ?></td>
            <td>$<?= number_format((float) $servicio['precio'], 2, '.', ',') ?></td>
            <td><a href="<?= URL_BASE ?>/finanzas/servicios.php?id=<?= (int) $servicio['id'] ?>">Editar</a>
              <form method="post" action="<?= URL_BASE ?>/finanzas/servicios.php" style="display:inline">
                <?= campoCsrf() ?><input type="hidden" name="accion" value="desactivar"><input type="hidden" name="id" value="<?= (int) $servicio['id'] ?>">
                <button class="boton boton--fantasma" type="submit">Desactivar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php else: ?><p class="bd__vacio">El catálogo está vacío. Agrega los servicios y precios de esta clínica.</p><?php endif; ?>
  </section>
  <?php if ($serviciosInactivos): ?>
    <section class="bd">
      <div class="bd__cabecera"><h2 class="bd__titulo">Desactivados</h2><span class="bd__contador"><?= count($serviciosInactivos) ?></span></div>
      <div class="finanzas__plano"><table class="bd-tabla"><thead><tr><th>Servicio</th><th>Precio</th><th>Acción</th></tr></thead><tbody>
        <?php foreach ($serviciosInactivos as $servicio): ?><tr>
          <td><?= e($servicio['nombre']) ?></td><td>$<?= number_format((float) $servicio['precio'], 2, '.', ',') ?></td>
          <td><form method="post" action="<?= URL_BASE ?>/finanzas/servicios.php">
            <?= campoCsrf() ?><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?= (int) $servicio['id'] ?>">
            <button class="boton boton--fantasma" type="submit">Reactivar</button>
          </form></td>
        </tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
