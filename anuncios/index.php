<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';

$anuncios = require __DIR__ . '/../app/datos/anuncios.php';
$tituloPagina = 'Anuncios';
$seccionActiva = 'anuncios';
$mostrarBanner = false;
$claseContenido = 'pagina--ancha';
$migas = [['texto' => 'Anuncios']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<section class="anuncios-pagina" aria-label="Novedades del sistema">
  <?php foreach ($anuncios as $indice => $anuncio): ?>
    <?php $idBase = 'anuncio-pagina-' . $indice; ?>
    <?php $anuncioEnDialogo = false; ?>
    <?php require __DIR__ . '/../app/vistas/componentes/anuncio_panel.php'; ?>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
