    </div>
  </section>
  <!-- Imágenes y video del panel de acceso (assets/img/acceso, mejorados): pasan con un fundido suave -->
  <div class="acceso__imagen acceso__carrusel" data-carrusel aria-hidden="true">
    <img class="acceso__diapositiva activa" src="<?= recurso('img/acceso/pintura.webp') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-7.jpeg') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-8.jpeg') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-9.jpeg') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-11.jpeg') ?>" alt="">
    <img class="acceso__diapositiva" data-src="<?= recurso('img/acceso/diapositiva-12.jpeg') ?>" alt="">
    <video class="acceso__diapositiva" data-src="<?= recurso('img/acceso/video-acceso.mp4') ?>" muted playsinline loop preload="none"></video>
  </div>
</main>

<?php if (!empty($notificacion)): ?>
  <div class="notificaciones" aria-live="polite">
    <div class="notificacion notificacion--<?= e($notificacion['tipo']) ?>" role="<?= $notificacion['tipo'] === 'error' ? 'alert' : 'status' ?>">
      <?= icono($notificacion['tipo'] === 'error' ? 'info' : 'escudo') ?>
      <span><?= e($notificacion['texto']) ?></span>
    </div>
  </div>
<?php endif; ?>

<script src="<?= recurso("js/app.js") ?>"></script>
<script src="<?= recurso("js/modulos/controles.js") ?>"></script>
<script src="<?= recurso("js/carrusel_acceso.js") ?>"></script>
<?php foreach ($guionesAcceso ?? [] as $guion): ?>
<script src="<?= recurso($guion) ?>"></script>
<?php endforeach; ?>
</body>
</html>
