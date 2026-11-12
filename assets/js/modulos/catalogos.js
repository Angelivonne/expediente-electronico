(() => {
  const raiz = document.querySelector('[data-catalogos]');
  if (!raiz) return;

  const tabs = Array.from(raiz.querySelectorAll('[data-catalogos-tab]'));
  const paneles = Array.from(raiz.querySelectorAll('[data-catalogos-panel]'));
  const filtro = raiz.querySelector('[data-catalogos-filtro]');
  const estado = raiz.querySelector('[data-catalogos-estado]');
  let activo = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];

  const seleccionar = (tab) => {
    if (!tab) return;
    activo = tab;
    tabs.forEach((elemento) => {
      const seleccionado = elemento === tab;
      elemento.setAttribute('aria-selected', seleccionado ? 'true' : 'false');
      elemento.tabIndex = seleccionado ? 0 : -1;
    });
    paneles.forEach((panel) => {
      panel.hidden = panel.dataset.catalogosPanel !== tab.dataset.catalogosTab;
    });
  };

  const normalizar = (texto) => texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase('es')
    .trim();

  const filtrar = () => {
    const consulta = normalizar(filtro.value);
    let coincidencias = 0;
    const visiblesPorSeccion = new Map();

    raiz.querySelectorAll('[data-catalogos-fila]').forEach((fila) => {
      const visible = !consulta || normalizar(`${fila.dataset.busqueda} ${fila.textContent}`).includes(consulta);
      fila.hidden = !visible;
      if (visible) {
        coincidencias++;
        const panel = fila.closest('[data-catalogos-panel]');
        visiblesPorSeccion.set(panel.dataset.catalogosPanel, (visiblesPorSeccion.get(panel.dataset.catalogosPanel) || 0) + 1);
      }
    });

    tabs.forEach((tab) => {
      const cantidad = visiblesPorSeccion.get(tab.dataset.catalogosTab) || 0;
      tab.hidden = consulta !== '' && cantidad === 0;
      tab.querySelector('[data-catalogos-conteo]').textContent = String(consulta ? cantidad : paneles.find((panel) => panel.dataset.catalogosPanel === tab.dataset.catalogosTab).querySelectorAll('[data-catalogos-fila]').length);
    });

    if (consulta && (!activo || activo.hidden)) {
      seleccionar(tabs.find((tab) => !tab.hidden));
    }
    estado.textContent = consulta
      ? `${coincidencias} ${coincidencias === 1 ? 'catálogo coincide' : 'catálogos coinciden'} con «${filtro.value.trim()}».`
      : '';
  };

  tabs.forEach((tab, indice) => {
    tab.addEventListener('click', () => seleccionar(tab));
    tab.addEventListener('keydown', (evento) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(evento.key)) return;
      evento.preventDefault();
      const disponibles = tabs.filter((elemento) => !elemento.hidden);
      const posicion = disponibles.indexOf(tab);
      const destino = evento.key === 'Home' ? 0
        : evento.key === 'End' ? disponibles.length - 1
          : (posicion + (evento.key === 'ArrowRight' ? 1 : -1) + disponibles.length) % disponibles.length;
      disponibles[destino]?.focus();
      seleccionar(disponibles[destino]);
    });
  });

  filtro.addEventListener('input', filtrar);
  seleccionar(activo);
})();
