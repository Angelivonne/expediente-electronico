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

$tituloPagina   = $m['nombre'] ?? 'Medicamento';
$seccionActiva  = 'medicamentos';
$migas          = [['texto' => 'Expediente'], ['texto' => 'Medicamentos', 'url' => '/medicamentos/'], ['texto' => $m['nombre'] ?? 'Medicamento']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

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
      <a class="boton boton--primario" href="<?= URL_BASE ?>/medicamentos/nuevo.php?id=<?= (int) $m['id'] ?>" title="Editar"><?= icono('editar') ?> Editar</a>
    </div>
  </header>

  <div class="pagina__cuerpo">
    <div class="ficha-perfil">
      <div class="ficha-perfil__foto">
        <?php if (!empty($m['foto'])): ?>
          <img src="<?= URL_BASE . '/' . e($m['foto']) ?>" alt="Foto de <?= e($m['nombre']) ?>">
        <?php else: ?>
          <div class="ficha-perfil__foto-vacia"><?= icono('pildora') ?></div>
        <?php endif; ?>
      </div>

      <div class="ficha-perfil__datos">
        <h1 class="ficha-perfil__titulo"><?= e($m['nombre']) ?></h1>
        <p class="ficha-perfil__subtitulo"><?= e($m['forma_farmaceutica'] ?? '') ?> · <?= e($m['presentacion'] ?? '') ?> · <?= e($m['concentracion'] ?? '') ?></p>

        <table class="ficha-perfil__tabla">
          <tr>
            <th>Clave</th>
            <td><?= e($m['clave']) ?></td>
          </tr>
          <?php if (!empty($m['clave_compendio'])): ?>
          <tr>
            <th>Clave Compendio Nacional de Insumos</th>
            <td><?= e($m['clave_compendio']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <th>Forma farmacéutica</th>
            <td><?= e($m['forma_farmaceutica'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Presentación</th>
            <td><?= e($m['presentacion'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Concentración</th>
            <td><?= e($m['concentracion'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Vía de administración</th>
            <td><?= e($m['via'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Laboratorio</th>
            <td><?= e($m['laboratorio'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Estado</th>
            <td><span class="estado <?= !$m['activo'] ? 'estado--desactivada' : '' ?>"><?= $m['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          </tr>
        </table>
      </div>
    </div>
  </div>
</main>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>

<style>
.ficha-perfil {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 24px;
  align-items: start;
}
.ficha-perfil__foto {
  display: flex;
  justify-content: center;
}
.ficha-perfil__foto img {
  width: 220px;
  height: 220px;
  object-fit: cover;
  border-radius: 16px;
  border: 2px solid var(--borde-fuerte);
  background: var(--bg-elevado);
}
.ficha-perfil__foto-vacia {
  width: 220px;
  height: 220px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 16px;
  border: 2px dashed var(--borde-fuerte);
  background: var(--bg-elevado);
  color: var(--texto-suave);
  font-size: 48px;
}
.ficha-perfil__datos h1 {
  margin: 0 0 6px 0;
  font-size: 1.75rem;
  color: var(--texto-fuerte);
}
.ficha-perfil__subtitulo {
  margin: 0 0 16px 0;
  color: var(--texto-suave);
}
.ficha-perfil__tabla {
  width: 100%;
  border-collapse: collapse;
}
.ficha-perfil__tabla th {
  text-align: left;
  width: 220px;
  padding: 8px 12px;
  color: var(--texto-suave);
  font-size: var(--fs-sm);
  border-bottom: 1px solid var(--borde);
}
.ficha-perfil__tabla td {
  padding: 8px 12px;
  color: var(--texto);
  border-bottom: 1px solid var(--borde);
}
</style>
