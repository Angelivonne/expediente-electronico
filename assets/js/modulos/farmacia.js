(() => {
  document.querySelectorAll('[data-farmacia-abrir]').forEach((boton) => {
    boton.addEventListener('click', () => {
      const tipo = boton.dataset.farmaciaAbrir;
      const panel = [...document.querySelectorAll('[data-farmacia-operacion]')]
        .find((elemento) => elemento.dataset.farmaciaOperacion === tipo);
      if (!(panel instanceof HTMLDetailsElement)) return;
      document.querySelectorAll('[data-farmacia-operacion]').forEach((otro) => {
        if (otro !== panel) otro.open = false;
      });
      panel.open = true;
      panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
      panel.querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
    });
  });

  const formulario = document.querySelector('[data-farmacia-dispensacion]');
  if (!formulario) return;

  const prescripcion = formulario.querySelector('[data-farmacia-receta]');
  const lote = formulario.querySelector('[data-farmacia-lote]');
  if (!prescripcion || !lote) return;

  const filtrarLotes = () => {
    const medicamentoId = prescripcion.selectedOptions[0]?.dataset.medicamentoId || '';
    lote.value = '';
    [...lote.options].forEach((opcion, indice) => {
      opcion.hidden = indice > 0 && (!medicamentoId || opcion.dataset.medicamentoId !== medicamentoId);
    });
  };

  prescripcion.addEventListener('change', filtrarLotes);
  filtrarLotes();
})();
