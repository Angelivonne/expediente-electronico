(() => {
  const servicio = document.querySelector('[data-finanzas-servicio]');
  if (!servicio) return;
  const concepto = document.querySelector('[data-finanzas-concepto]');
  const monto = document.querySelector('[data-finanzas-monto]');
  servicio.addEventListener('change', () => {
    const opcion = servicio.selectedOptions[0];
    if (!opcion || !opcion.value) return;
    if (concepto) concepto.value = opcion.dataset.nombre || '';
    if (monto) monto.value = opcion.dataset.precio || '';
  });
})();
