<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
exigirPermiso('ver_expediente');

$id   = (int) ($_GET['id'] ?? 0);
$m    = null;
if ($id) {
    $c = db()->prepare('SELECT * FROM medicamentos WHERE id = ? AND institucion_id = ?');
    $c->execute([$id, institucionId()]);
    $m = $c->fetch();
    if (!$m) {
        mensajeFlash('error', 'El medicamento no existe.');
        redirigir('/medicamentos/');
    }
}

$errores = [];
$datos   = ['clave' => '', 'clave_compendio' => '', 'nombre' => '', 'forma_farmaceutica' => '', 'presentacion' => '', 'concentracion' => '', 'via' => '', 'laboratorio' => '', 'activo' => 1, 'foto' => ''];

if ($m) {
    $datos = ['clave' => $m['clave'], 'clave_compendio' => $m['clave_compendio'] ?? '', 'nombre' => $m['nombre'],
              'forma_farmaceutica' => $m['forma_farmaceutica'] ?? '', 'presentacion' => $m['presentacion'] ?? '',
              'concentracion' => $m['concentracion'] ?? '', 'via' => $m['via'] ?? '', 'laboratorio' => $m['laboratorio'] ?? '',
              'activo' => (int) $m['activo'], 'foto' => $m['foto'] ?? ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $datos = [
            'clave'            => trim((string) ($_POST['clave'] ?? '')),
            'clave_compendio'  => trim((string) ($_POST['clave_compendio'] ?? '')),
            'nombre'           => trim((string) ($_POST['nombre'] ?? '')),
            'forma_farmaceutica'=> trim((string) ($_POST['forma_farmaceutica'] ?? '')),
            'presentacion'     => trim((string) ($_POST['presentacion'] ?? '')),
            'concentracion'    => trim((string) ($_POST['concentracion'] ?? '')),
            'via'              => trim((string) ($_POST['via'] ?? '')),
            'laboratorio'      => trim((string) ($_POST['laboratorio'] ?? '')),
            'activo'           => isset($_POST['activo']) ? 1 : 0,
            'foto'             => (string) ($m['foto'] ?? ''),
        ];

        if ($datos['clave'] === '')     $errores['clave'] = 'Escribe una clave.';
        if ($datos['nombre'] === '')    $errores['nombre'] = 'Escribe el nombre.';
        if (!$errores) {
            $yaExiste = db()->prepare('SELECT 1 FROM medicamentos WHERE clave = ? AND institucion_id = ? AND id <> ?');
            $yaExiste->execute([$datos['clave'], institucionId(), $id]);
            if ($yaExiste->fetchColumn()) {
                $errores['clave'] = 'Esa clave ya existe en esta institución.';
            }
        }
        if (!$errores && $datos['clave_compendio'] !== '') {
            $yaExiste = db()->prepare('SELECT 1 FROM medicamentos WHERE clave_compendio = ? AND institucion_id = ? AND id <> ?');
            $yaExiste->execute([$datos['clave_compendio'], institucionId(), $id]);
            if ($yaExiste->fetchColumn()) {
                $errores['clave_compendio'] = 'Esa clave del Compendio ya existe en esta institución.';
            }
        }
        if (!$errores) {
            $foto = $datos['foto']; // se conserva la existente si no se sube nueva
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $archivo = $_FILES['foto'];
                $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                    $errores['foto'] = 'La foto debe ser JPG, PNG o WEBP.';
                } elseif ($archivo['size'] > 2 * 1024 * 1024) {
                    $errores['foto'] = 'La foto no debe superar los 2 MB.';
                } else {
                    $carpeta = __DIR__ . '/../assets/medicamentos/';
                    if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
                    $nombreArchivo = 'med_' . (int) ($id ?: time()) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($archivo['tmp_name'], $carpeta . $nombreArchivo)) {
                        $foto = 'assets/medicamentos/' . $nombreArchivo;
                    } else {
                        $errores['foto'] = 'No se pudo guardar la foto.';
                    }
                }
            }
        }
        if (!$errores) {
            if ($id) {
                db()->prepare('UPDATE medicamentos SET clave = ?, clave_compendio = ?, nombre = ?, forma_farmaceutica = ?, presentacion = ?, concentracion = ?, via = ?, laboratorio = ?, activo = ?, foto = ?, actualizado_en = NOW() WHERE id = ? AND institucion_id = ?')
                    ->execute([$datos['clave'], $datos['clave_compendio'], $datos['nombre'], $datos['forma_farmaceutica'], $datos['presentacion'], $datos['concentracion'], $datos['via'], $datos['laboratorio'], $datos['activo'], $foto, $id, institucionId()]);
                registrarBitacora('medicamentos', $id, 'actualizar', 'medicamento', null, $datos['nombre']);
                mensajeFlash('exito', 'Medicamento actualizado.');
            } else {
                db()->prepare('INSERT INTO medicamentos (institucion_id, clave, clave_compendio, nombre, forma_farmaceutica, presentacion, concentracion, via, laboratorio, activo, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([institucionId(), $datos['clave'], $datos['clave_compendio'], $datos['nombre'], $datos['forma_farmaceutica'], $datos['presentacion'], $datos['concentracion'], $datos['via'], $datos['laboratorio'], $datos['activo'], $foto]);
                $nuevoId = (int) db()->lastInsertId();
                registrarBitacora('medicamentos', $nuevoId, 'crear', 'medicamento', null, $datos['nombre']);
                mensajeFlash('exito', 'Medicamento registrado.');
            }
            redirigir('/medicamentos/');
        }
    }
}

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$tituloPagina   = $id ? 'Editar medicamento' : 'Nuevo medicamento';
$seccionActiva  = 'medicamentos';
$migas          = [['texto' => 'Expediente'], ['texto' => 'Medicamentos', 'url' => '/medicamentos/'], ['texto' => $tituloPagina]];

?>
<main class="pagina">
  <header class="barra-superior">
    <span class="barra-superior__icono" aria-hidden="true"><?= icono('pildora') ?></span>
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
      <a class="boton-icono" href="<?= URL_BASE ?>/medicamentos/" title="Volver al catálogo"><?= icono('cerrar') ?></a>
    </div>
  </header>

  <?php if (!empty($errores['general'])): ?>
    <div class="aviso aviso--error" role="status"><?= e($errores['general']) ?></div>
  <?php endif; ?>

  <form method="post" class="formulario" data-validar-formulario enctype="multipart/form-data">
    <?= campoCsrf() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">

    <div class="rejilla" style="grid-template-columns: 1fr 1fr; gap: 16px;">
      <div class="campo">
        <label class="campo__etiqueta">Clave<?= isset($errores['clave']) ? ' <span class="requerido">*</span>' : '' ?></label>
        <input type="text" name="clave" value="<?= e($datos['clave']) ?>" maxlength="30" required
               <?= isset($errores['clave']) ? ' class="campo--error"' : '' ?>>
        <?php if (isset($errores['clave'])): ?><span class="campo__ayuda"><?= e($errores['clave']) ?></span><?php endif; ?>
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Clave Compendio Nacional de Insumos<?= isset($errores['clave_compendio']) ? ' <span class="requerido">*</span>' : '' ?></label>
        <input type="text" name="clave_compendio" value="<?= e($datos['clave_compendio']) ?>" maxlength="20"
               <?= isset($errores['clave_compendio']) ? ' class="campo--error"' : '' ?>>
        <?php if (isset($errores['clave_compendio'])): ?><span class="campo__ayuda"><?= e($errores['clave_compendio']) ?></span><?php endif; ?>
      </div>

      <div class="campo" style="grid-column: 1 / -1;">
        <label class="campo__etiqueta">Nombre<?= isset($errores['nombre']) ? ' <span class="requerido">*</span>' : '' ?></label>
        <input type="text" name="nombre" value="<?= e($datos['nombre']) ?>" maxlength="160" required
               <?= isset($errores['nombre']) ? ' class="campo--error"' : '' ?>>
        <?php if (isset($errores['nombre'])): ?><span class="campo__ayuda"><?= e($errores['nombre']) ?></span><?php endif; ?>
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Forma farmacéutica</label>
        <input type="text" name="forma_farmaceutica" value="<?= e($datos['forma_farmaceutica']) ?>" maxlength="60" placeholder="Ej.: comprimido, cápsula, jarabe">
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Presentación</label>
        <input type="text" name="presentacion" value="<?= e($datos['presentacion']) ?>" maxlength="80" placeholder="Ej.: tableta, jarabe, ampolla">
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Concentración</label>
        <input type="text" name="concentracion" value="<?= e($datos['concentracion']) ?>" maxlength="40" placeholder="Ej.: 500 mg, 10 ml">
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Vía de administración</label>
        <input type="text" name="via" value="<?= e($datos['via']) ?>" maxlength="40" placeholder="Ej.: Oral, Intravenosa">
      </div>

      <div class="campo">
        <label class="campo__etiqueta">Laboratorio</label>
        <input type="text" name="laboratorio" value="<?= e($datos['laboratorio']) ?>" maxlength="120">
      </div>

      <div class="campo" style="grid-column: 1 / -1;">
        <label class="campo__etiqueta">Foto del medicamento</label>
        <input type="file" name="foto" accept=".jpg,.jpeg,.png,.webp"
               <?= isset($errores['foto']) ? ' class="campo--error"' : '' ?>>
        <?php if (isset($errores['foto'])): ?><span class="campo__ayuda"><?= e($errores['foto']) ?></span><?php endif; ?>
        <?php if ($datos['foto']): ?>
          <div style="margin-top: 8px;">
            <img src="<?= URL_BASE . '/' . e($datos['foto']) ?>" alt="Foto actual" style="max-height: 120px; border-radius: 8px; border: 1px solid var(--borde);">
          </div>
        <?php endif; ?>
      </div>

      <div class="campo casilla" style="grid-column: 1 / -1;">
        <label class="campo__etiqueta">Activo</label>
        <label class="casilla__etiqueta">
          <input type="checkbox" name="activo" value="1"<?= $datos['activo'] ? ' checked' : '' ?>> Sí, está disponible para recetar
        </label>
      </div>
    </div>

    <div class="pie-formulario__acciones" style="margin-top: 24px;">
      <button type="button" class="boton boton--sutil" onclick="location.href='<?= URL_BASE ?>/medicamentos/'"><?= icono('cerrar') ?> Cancelar</button>
      <button type="submit" class="boton boton--primario"><?= icono('guardar') ?> Guardar</button>
    </div>
  </form>
</main>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
