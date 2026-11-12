document.addEventListener('click', (evento) => {
  if (!(evento.target instanceof Element)) return;
  const imprimir = evento.target.closest('[data-morbilidad-imprimir]');
  if (!imprimir) return;

  imprimir.closest('.morbilidad__acciones')?.removeAttribute('open');
  window.print();
});
