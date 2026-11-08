(() => {
  const panel = document.querySelector('[data-conexiones-panel]');
  const formulario = document.querySelector('#solicitar-conexion');
  if (!panel || !formulario) return;

  const abrirSolicitud = (enfocarClinica = false) => {
    panel.open = true;
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (enfocarClinica) {
      const selector = formulario.elements.namedItem('institucion_destino');
      window.setTimeout(() => selector?.focus({ preventScroll: true }), 180);
    }
  };

  document.addEventListener('click', (evento) => {
    const nueva = evento.target.closest('[data-conexiones-abrir]');
    if (nueva) {
      abrirSolicitud(true);
      return;
    }

    const boton = evento.target.closest('[data-conexion-destino]');
    if (!boton) return;

    const selector = formulario.elements.namedItem('institucion_destino');
    if (!(selector instanceof HTMLSelectElement)) return;

    evento.preventDefault();
    selector.value = boton.dataset.conexionDestino || '';
    abrirSolicitud(true);
  });
})();
