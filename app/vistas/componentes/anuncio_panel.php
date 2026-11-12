<?php
$pestanasAnuncio = $anuncio['pestanas'];
$primeraPestana = $pestanasAnuncio[0]['id'];
?>
<div class="anuncio-componente<?= !empty($anuncioEnDialogo) ? ' anuncio-componente--dialogo' : '' ?>" data-anuncio-tabs>
  <section class="anuncio-componente__principal">
    <div class="anuncio-componente__ilustracion">
      <svg viewBox="120 -72 880 605" role="img" aria-label="Computadora portátil mostrando un expediente clínico con un latido del corazón, un micrófono y una ficha de paciente">
        <circle cx="920" cy="420" r="210" fill="#FFFFFF" opacity="0.16"></circle>
        <circle cx="90" cy="50" r="150" fill="#FFFFFF" opacity="0.12"></circle>
        <rect x="250" y="60" width="540" height="310" rx="18" fill="#111111"></rect>
        <rect x="266" y="76" width="508" height="278" rx="8" fill="#1B1B1B"></rect>
        <circle cx="322" cy="130" r="26" fill="#3A1F1F"></circle>
        <rect x="317" y="114" width="10" height="32" fill="#FF8A80"></rect>
        <rect x="306" y="125" width="32" height="10" fill="#FF8A80"></rect>
        <rect x="368" y="116" width="170" height="10" rx="5" fill="#3A3A3A"></rect>
        <rect x="368" y="136" width="110" height="8" rx="4" fill="#2B2B2B"></rect>
        <polyline points="286,230 380,230 410,230 432,172 458,282 486,200 508,230 600,230 630,230 652,188 674,262 696,230 760,230" fill="none" stroke="#E5594F" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></polyline>
        <rect x="286" y="300" width="220" height="10" rx="5" fill="#2B2B2B"></rect>
        <rect x="286" y="320" width="150" height="8" rx="4" fill="#2B2B2B"></rect>
        <path d="M236 370 H804 L836 398 H204 Z" fill="#262626"></path>
        <rect x="470" y="370" width="100" height="8" rx="4" fill="#1A1A1A"></rect>
        <g transform="translate(800 100) rotate(6)">
          <rect width="190" height="160" rx="16" fill="#F5F5F0"></rect>
          <circle cx="38" cy="38" r="18" fill="#3A1F1F"></circle>
          <rect x="34" y="28" width="8" height="20" fill="#FF8A80"></rect>
          <rect x="28" y="34" width="20" height="8" fill="#FF8A80"></rect>
          <rect x="68" y="28" width="90" height="9" rx="4" fill="#C9C9C0"></rect>
          <rect x="68" y="46" width="60" height="8" rx="4" fill="#DDDDD5"></rect>
          <rect x="24" y="86" width="142" height="9" rx="4" fill="#DDDDD5"></rect>
          <rect x="24" y="106" width="118" height="9" rx="4" fill="#DDDDD5"></rect>
          <rect x="24" y="126" width="84" height="9" rx="4" fill="#DDDDD5"></rect>
        </g>
        <circle cx="170" cy="290" r="46" fill="#1B1B1B"></circle>
        <rect x="160" y="266" width="20" height="34" rx="10" fill="#EDEDED"></rect>
        <path d="M152 292 a18 18 0 0 0 36 0" fill="none" stroke="#EDEDED" stroke-width="4" stroke-linecap="round"></path>
        <line x1="170" y1="310" x2="170" y2="318" stroke="#EDEDED" stroke-width="4" stroke-linecap="round"></line>
      </svg>
    </div>
    <div class="anuncio-componente__presentacion">
      <h1 id="<?= e($idBase . '-titulo') ?>"><?= e($anuncio['titulo']) ?></h1>
      <p><?= e($anuncio['descripcion']) ?></p>
    </div>
    <?php if (!empty($anuncioEnDialogo)): ?>
      <button type="button" class="anuncio-componente__accion" data-anuncio-cerrar>Probar el sistema</button>
    <?php else: ?>
      <a class="anuncio-componente__accion" href="<?= URL_BASE ?>/">Probar el sistema</a>
    <?php endif; ?>
  </section>

  <section class="anuncio-componente__sistema" aria-labelledby="<?= e($idBase . '-sistema') ?>">
    <header class="anuncio-componente__cabecera">
      <h2 id="<?= e($idBase . '-sistema') ?>">Conoce el sistema</h2>
      <span><?= e($anuncio['periodo']) ?></span>
    </header>
    <div class="anuncio-componente__pestanas" role="tablist" aria-label="Conoce el sistema">
      <?php foreach ($pestanasAnuncio as $pestana): ?>
        <?php $activo = $pestana['id'] === $primeraPestana; ?>
        <button
          type="button"
          id="<?= e($idBase . '-tab-' . $pestana['id']) ?>"
          role="tab"
          aria-selected="<?= $activo ? 'true' : 'false' ?>"
          aria-controls="<?= e($idBase . '-panel-' . $pestana['id']) ?>"
          tabindex="<?= $activo ? '0' : '-1' ?>"
          data-anuncio-tab="<?= e($pestana['id']) ?>"
        ><?= e($pestana['etiqueta']) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="anuncio-componente__zona">
      <div class="anuncio-componente__desplazamiento">
        <?php foreach ($pestanasAnuncio as $pestana): ?>
          <?php $activo = $pestana['id'] === $primeraPestana; ?>
          <section
            id="<?= e($idBase . '-panel-' . $pestana['id']) ?>"
            role="tabpanel"
            aria-labelledby="<?= e($idBase . '-tab-' . $pestana['id']) ?>"
            tabindex="0"
            data-anuncio-panel="<?= e($pestana['id']) ?>"
            <?= $activo ? '' : 'hidden' ?>
          >
            <?php foreach ($pestana['elementos'] as $elemento): ?>
              <article class="anuncio-componente__elemento">
                <div>
                  <span class="anuncio-componente__etiqueta anuncio-componente__etiqueta--<?= e($elemento['clase']) ?> anuncio-componente__etiqueta--forma-<?= (int) $elemento['forma'] ?>" style="--anuncio-giro: <?= e($elemento['giro']) ?>"><?= e($elemento['tipo']) ?></span>
                </div>
                <div>
                  <h3><?= e($elemento['titulo']) ?></h3>
                  <p><?= e($elemento['descripcion']) ?></p>
                </div>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>
      <div class="anuncio-componente__difuminado" aria-hidden="true"></div>
    </div>
    <footer class="anuncio-componente__ayuda">DESLIZA PARA VER MÁS</footer>
  </section>
</div>
