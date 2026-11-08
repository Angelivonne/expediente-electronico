/* Filtro por tipo documental del historial del paciente. */
(() => {
  const seccion = document.querySelector('[data-historial]');
  const tabla = seccion?.querySelector('.linea-tiempo');
  const filtro = seccion?.querySelector('[data-historial-tipo]');
  if (!tabla || !filtro) return;
  const eventos = [...tabla.querySelectorAll('.linea-tiempo__evento')];
  filtro.addEventListener('change', () => {
    eventos.forEach((evento) => {
      evento.hidden = Boolean(filtro.value) && evento.dataset.tipo !== filtro.value;
    });
  });
})();
