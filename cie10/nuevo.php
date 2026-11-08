<?php
/**
 * Crear o editar un diagnóstico CIE-10.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

$id   = (int) ($_GET['id'] ?? 0);
$d    = null;
if ($id) {
    $c = db()->prepare('SELECT * FROM cie10 WHERE id = ? AND institucion_id = ?');
    $c->execute([$id, institucionId()]);
    $d = $c->fetch();
    if (!$d) {
        mensajeFlash('error', 'El diagnóstico no existe.');
        redirigir('/cie10/');
    }
}

$errores = [];
$datos   = ['codigo' => '', 'descripcion' => '', 'capitulo' => '', 'activo' => 1];

if ($d) {
    $datos = ['codigo' => $d['codigo'], 'descripcion' => $d['descripcion'], 'capitulo' => $d['capitulo'] ?? '', 'activo' => (int) $d['activo']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $datos = [
            'codigo'      => strtoupper(trim((string) ($_POST['codigo'] ?? ''))),
            'descripcion' => trim((string) ($_POST['descripcion'] ?? '')),
            'capitulo'    => trim((string) ($_POST['capitulo'] ?? '')),
            'activo'      => isset($_POST['activo']) ? 1 : 0,
        ];

        if ($datos['codigo'] === '')     $errores['codigo']      = 'Escribe el código CIE-10.';
        if ($datos['descripcion'] === '') $errores['descripcion'] = 'Escribe la descripción.';
        if (!$errores && !preg_match('/^[A-Z]\d{2}(\.?[0-9A-Z]{1,4})?$/', $datos['codigo'])) {
            $errores['codigo'] = 'Formato CIE-10 no válido (ej.: E11, I10, J45.9).';
        }
        if (!$errores) {
            $yaExiste = db()->prepare('SELECT 1 FROM cie10 WHERE codigo = ? AND institucion_id = ? AND id <> ?');
            $yaExiste->execute([$datos['codigo'], institucionId(), $id]);
            if ($yaExiste->fetchColumn()) {
                $errores['codigo'] = 'Ese código CIE-10 ya existe en esta institución.';
            }
        }
        if (!$errores) {
            if ($id) {
                db()->prepare('UPDATE cie10 SET codigo = ?, descripcion = ?, capitulo = ?, activo = ?, actualizado_en = NOW() WHERE id = ? AND institucion_id = ?')
                    ->execute([$datos['codigo'], $datos['descripcion'], $datos['capitulo'], $datos['activo'], $id, institucionId()]);
                registrarBitacora('cie10', $id, 'actualizar', 'diagnostico', null, $datos['codigo'] . ' · ' . $datos['descripcion']);
                mensajeFlash('exito', 'Diagnóstico actualizado.');
            } else {
                db()->prepare('INSERT INTO cie10 (institucion_id, codigo, descripcion, capitulo, activo) VALUES (?, ?, ?, ?, ?)')
                    ->execute([institucionId(), $datos['codigo'], $datos['descripcion'], $datos['capitulo'], $datos['activo']]);
                $nuevoId = (int) db()->lastInsertId();
                registrarBitacora('cie10', $nuevoId, 'crear', 'diagnostico', null, $datos['codigo'] . ' · ' . $datos['descripcion']);
                mensajeFlash('exito', 'Diagnóstico registrado.');
            }
            redirigir('/cie10/');
        }
    }
}

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tituloPagina   = $id ? 'Editar diagnóstico CIE-10' : 'Nuevo diagnóstico CIE-10';
$seccionActiva  = 'cie10';
$migas          = [['texto' => 'Expediente'], ['texto' => 'CIE-10', 'url' => '/cie10/'], ['texto' => $tituloPagina]];

?>
<main class="pagina">
  <header class="barra-superior">
    <span class="barra-superior__icono" aria-hidden="true"><?= icono('documento') ?></span>
    <nav class="migas" aria-label="Ruta">
      <?php foreach ($migas as $i => $miga): ?>
        <?php if ($i > 0): ?><span class="migas__separador">/</span><?php endif; ?>
        <?php if (!empty($miga['url'])): ?>
          <a class="migas__enlace" href="<?= URL_BASE . $miga['url'] ?>"><?= e($miga['texto']) ?></a>
        <?php else: ?>
          <span class="migas__actual"><?= e($miga['texto']) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="barra-superior__derecha">
      <a class="boton-icono" href="<?= URL_BASE ?>/cie10/" title="Volver al catálogo"><?= icono('cerrar') ?></a>
    </div>
  </header>

  <?php if (!empty($errores['general'])): ?>
    <div class="aviso aviso--error" role="status"><?= e($errores['general']) ?></div>
  <?php endif; ?>

  <form method="post" class="formulario">
    <?= campoCsrf() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <div class="campo">
      <label class="campo__etiqueta">Código CIE-10<?= isset($errores['codigo']) ? ' <span class="requerido">*</span>' : '' ?></label>
      <input type="text" name="codigo" value="<?= e($datos['codigo']) ?>" maxlength="7" required
             data-cie
             <?= isset($errores['codigo']) ? ' class="campo--error"' : '' ?>>
      <?php if (isset($errores['codigo'])): ?><span class="campo__ayuda"><?= e($errores['codigo']) ?></span><?php endif; ?>
    </div>

    <div class="campo">
      <label class="campo__etiqueta">Descripción<?= isset($errores['descripcion']) ? ' <span class="requerido">*</span>' : '' ?></label>
      <input type="text" name="descripcion" value="<?= e($datos['descripcion']) ?>" maxlength="200" required
             <?= isset($errores['descripcion']) ? ' class="campo--error"' : '' ?>>
      <?php if (isset($errores['descripcion'])): ?><span class="campo__ayuda"><?= e($errores['descripcion']) ?></span><?php endif; ?>
    </div>

    <div class="campo">
      <label class="campo__etiqueta">Capítulo</label>
      <input type="text" name="capitulo" value="<?= e($datos['capitulo']) ?>" maxlength="80" placeholder="Ej.: IV. Endocrino, nutricional y metabólico">
    </div>

    <div class="campo casilla">
      <label class="campo__etiqueta">Activo</label>
      <label class="casilla__etiqueta">
        <input type="checkbox" name="activo" value="1"<?= $datos['activo'] ? ' checked' : '' ?>> Sí, está disponible para usar en diagnósticos
      </label>
    </div>

    <div class="pie-formulario__acciones">
      <button type="button" class="boton boton--sutil" onclick="location.href='<?= URL_BASE ?>/cie10/'"><?= icono('cerrar') ?> Cancelar</button>
      <button type="submit" class="boton boton--primario"><?= icono('guardar') ?> Guardar</button>
    </div>
  </form>
</main>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>

<script>
(() => {
  const input = document.querySelector('[data-cie]');
  if (!input) return;
  input.addEventListener('input', () => {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9.]/g, '');
  });
})();
</script>
