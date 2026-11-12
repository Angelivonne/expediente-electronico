(() => {
  document.querySelectorAll('[data-anuncio-tabs]').forEach((root) => {
    const tabs = Array.from(root.querySelectorAll('[data-anuncio-tab]'));

    const activar = (tab, moverFoco = false) => {
      tabs.forEach((opcion) => {
        const activo = opcion === tab;
        opcion.setAttribute('aria-selected', String(activo));
        opcion.tabIndex = activo ? 0 : -1;

        const panel = root.querySelector(`[data-anuncio-panel="${CSS.escape(opcion.dataset.anuncioTab)}"]`);
        if (panel) panel.hidden = !activo;
      });

      if (moverFoco) tab.focus();
    };

    root.addEventListener('click', (evento) => {
      const tab = evento.target.closest('[data-anuncio-tab]');
      if (tab && root.contains(tab)) activar(tab);
    });

    root.addEventListener('keydown', (evento) => {
      const tabActual = evento.target.closest('[data-anuncio-tab]');
      const indice = tabs.indexOf(tabActual);
      if (indice < 0) return;

      let siguiente = indice;
      if (evento.key === 'ArrowRight') siguiente = (indice + 1) % tabs.length;
      else if (evento.key === 'ArrowLeft') siguiente = (indice - 1 + tabs.length) % tabs.length;
      else if (evento.key === 'Home') siguiente = 0;
      else if (evento.key === 'End') siguiente = tabs.length - 1;
      else return;

      evento.preventDefault();
      activar(tabs[siguiente], true);
    });
  });

  const dialogo = document.querySelector('[data-anuncio-dialog]');
  if (!(dialogo instanceof HTMLDialogElement)) return;

  const sesion = dialogo.dataset.anuncioSesion;
  if (!sesion) {
    console.error('No se pudo determinar la sesión autenticada para mostrar el anuncio.');
    return;
  }
  const claveVisto = `anuncio-visto:${dialogo.dataset.anuncioVersion}:usuario:${dialogo.dataset.anuncioUsuario}`;
  let yaVisto = false;
  let almacenamiento = null;
  try {
    almacenamiento = window.localStorage;
    yaVisto = almacenamiento.getItem(claveVisto) === sesion;
  } catch (error) {
    try {
      almacenamiento = window.sessionStorage;
      yaVisto = almacenamiento.getItem(claveVisto) === sesion;
    } catch (errorSesion) {
      console.error('No se pudo consultar el estado del anuncio en esta sesión autenticada.', error, errorSesion);
    }
  }

  if (!yaVisto) dialogo.showModal();

  dialogo.querySelectorAll('[data-anuncio-cerrar]').forEach((boton) => {
    boton.addEventListener('click', () => dialogo.close());
  });

  dialogo.addEventListener('close', () => {
    if (!almacenamiento) {
      console.error('No se pudo guardar el cierre del anuncio: el navegador bloqueó el almacenamiento local y de sesión.');
      return;
    }
    try {
      almacenamiento.setItem(claveVisto, sesion);
    } catch (error) {
      console.error('No se pudo guardar el cierre del anuncio en esta sesión autenticada.', error);
    }
  });
})();
