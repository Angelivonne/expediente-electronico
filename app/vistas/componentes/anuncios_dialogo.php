<?php
$anuncios = require __DIR__ . '/../../datos/anuncios.php';
$anuncio = $anuncios[0];
$idBase = 'anuncio-inicio';
$anuncioEnDialogo = true;
?>
<dialog
  class="anuncio-dialogo"
  data-anuncio-dialog
  data-anuncio-version="<?= e($anuncio['id']) ?>"
  data-anuncio-usuario="<?= (int) usuarioActual()['id'] ?>"
  data-anuncio-sesion="<?= e((string) ($_SESSION['sesion_anuncio'] ?? '')) ?>"
  aria-labelledby="anuncio-inicio-titulo"
>
  <button type="button" class="anuncio-dialogo__cerrar" data-anuncio-cerrar aria-label="Cerrar anuncio"><?= icono('cerrar') ?></button>
  <?php require __DIR__ . '/anuncio_panel.php'; ?>
</dialog>
