<?php
/**
 * Transcriptor por voz del formulario: barra flotante + panel con la transcripción.
 * Está oculto hasta que se pide con Alt+Shift+D o con el botón "Dictar" de la cabecera del formulario.
 * Usa Web Speech API cuando existe; en navegadores sin soporte usa el servicio STT local.
 */
?>
<div class="dictado" data-dictado data-estado="inactivo" hidden>
  <section class="dictado__panel" data-dictado-panel hidden aria-label="Transcripción del dictado">
    <header class="dictado__cabecera" data-dictado-asa title="Arrastrar el panel">
      <span class="dictado__asa"><?= icono('agarre') ?></span>
      <span class="dictado__cabecera-titulo">Traductor Cl�nico</span>
      <button type="button" class="boton-icono" data-dictado-minimizar title="Minimizar a bolita" aria-label="Minimizar a bolita"><?= icono('contraer') ?></button>
    </header>
    <div class="dictado__hoja">
      <p class="dictado__texto" data-dictado-texto aria-live="polite"></p>
      <div class="dictado__avisos" data-dictado-avisos hidden aria-live="polite"></div>
      <div class="dictado__estado">
        <span class="dictado__estado-texto"><?= icono('onda') ?><span data-dictado-estado>Escuchando</span></span>
        <span class="dictado__calificar" data-dictado-calificar hidden>
          <button type="button" class="boton-icono" data-calificacion="1" title="El dictado fue útil" aria-pressed="false"><?= icono('pulgar_arriba') ?></button>
          <button type="button" class="boton-icono" data-calificacion="0" title="El dictado no fue útil" aria-pressed="false"><?= icono('pulgar_abajo') ?></button>
        </span>
      </div>
    </div>
    <div class="dictado__pie-panel">
      <button type="button" class="dictado__nuevo" data-dictado-nuevo>Nuevo dictado <kbd class="tecla">Alt ⇧ D</kbd></button>
      <button type="button" class="boton-icono" data-dictado-cerrar title="Cerrar"><?= icono('cerrar') ?></button>
    </div>
    <span class="dictado__redimension" data-dictado-redimension title="Cambiar tamaño" aria-hidden="true"></span>
  </section>

  <div class="dictado__barra">
    <button type="button" class="boton-icono" data-dictado-expandir title="Ver transcripción"><?= icono('expandir') ?></button>
    <span class="dictado__puntos" data-dictado-puntos aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
    <span class="dictado__accion">
      <span class="dictado__globo" data-dictado-globo>Dictar</span>
      <button type="button" class="dictado__boton" data-dictado-boton aria-label="Iniciar dictado">
        <?= icono('microfono', 'dictado__icono-mic') ?><span class="dictado__cuadro" aria-hidden="true"></span>
      </button>
    </span>
  </div>
</div>

<div class="dictado-orbita" data-dictado-orbita role="group" aria-label="Dictado minimizado" hidden>
  <span class="dictado-orbita__burbuja" data-dictado-orbita-burbuja hidden>
    <span data-dictado-orbita-texto aria-live="polite">Escuchando…</span>
  </span>
  <span class="dictado-orbita__fila">
    <button type="button" class="dictado-orbita__accion" data-dictado-orbita-compactar title="Ocultar el mensaje y reducir el orbe" aria-label="Ocultar el mensaje y reducir el orbe">
      <span class="dictado-orbita__accion-icono"><?= icono('contraer') ?></span>
      <span class="dictado-orbita__accion-icono dictado-orbita__accion-icono--expandir"><?= icono('expandir') ?></span>
    </button>
    <button type="button" class="dictado-orbita__bolita" data-dictado-orbita-bolita title="Volver al dictado" aria-label="Volver al dictado"></button>
  </span>
</div>

