/* ==========================================================================
   Expedientes Clínicos — comportamiento en pantallas táctiles y angostas
   - Barra lateral deslizable: se cierra con Esc, al deslizar a la izquierda,
     al elegir un enlace o al pasar a pantalla ancha (la abre el botón de
     la barra de pestañas, alternarLateral en app.js).
   - Toque largo = clic derecho: abre los mismos menús contextuales del árbol,
     las bases de datos, la lista de páginas y la barra superior (iOS no
     dispara "contextmenu"; en Android se evita abrirlos dos veces).
   - Tarjeta de acciones del paciente: se cierra al tocar fuera.
   ========================================================================== */
(() => {
  const raiz = document.documentElement;
  const angosta = window.matchMedia('(max-width: 900px)');

  /* ---------- Barra lateral deslizable ---------- */
  const cerrarLateral = () => raiz.classList.remove('lateral-abierta', 'ia-abierta');
  const lateral = document.getElementById('barra-lateral');

  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') {
      raiz.classList.remove('lateral-abierta', 'ia-abierta');
      if (document.querySelector('.popover, .paleta')) return;
    }
  });
  angosta.addEventListener('change', (ev) => { if (!ev.matches) cerrarLateral(); });

  if (lateral) {
    // Elegir un destino cierra el panel (la página nueva carga sin él abierto)
    lateral.addEventListener('click', (ev) => {
      if (!angosta.matches) return;
      const enlace = ev.target.closest('a[href]');
      if (enlace && !ev.target.closest('button') && !enlace.matches('[href="#"]')) cerrarLateral();
    });
    // Deslizar hacia la izquierda lo cierra
    let inicio = null;
    lateral.addEventListener('touchstart', (ev) => {
      if (!raiz.classList.contains('lateral-abierta') || ev.touches.length !== 1) { inicio = null; return; }
      inicio = { x: ev.touches[0].clientX, y: ev.touches[0].clientY };
    }, { passive: true });
    lateral.addEventListener('touchend', (ev) => {
      if (!inicio) return;
      const t = ev.changedTouches[0];
      const dx = t.clientX - inicio.x;
      const dy = Math.abs(t.clientY - inicio.y);
      inicio = null;
      if (dx < -60 && dy < 50) cerrarLateral();
    }, { passive: true });
  }

  // Cerrar IA al hacer clic fuera de ella en móvil
  document.addEventListener('click', (ev) => {
    if (!angosta.matches) return;
    const iaPanel = document.querySelector('.ia-panel, .ia-panel--abierta, .ia-cajon');
    if (!iaPanel) return;
    if (ev.target.closest('.ia-panel, .ia-cajon, .btn-abrir-ia')) return;
    raiz.classList.remove('ia-abierta');
    lateral?.classList.remove('ia-abierta');
  }, { passive: true });

  // Botón para cerrar IA desde el panel
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('.btn-cerrar-ia')) {
      raiz.classList.remove('ia-abierta');
      ev.preventDefault();
    }
  });

  // Toque largo = clic derecho: iOS no dispara "contextmenu", así que el puente
  // sintetiza el evento tras 550 ms; en Android llega nativo y no se duplica.
  if (/iP(hone|ad|od)/.test(navigator.userAgent)) {
    let toqueLargo = null;
    document.addEventListener('touchstart', (ev) => {
      if (ev.touches.length !== 1) { toqueLargo = null; return; }
      const t = ev.touches[0];
      const destino = ev.target;
      toqueLargo = setTimeout(() => {
        toqueLargo = null;
        destino.dispatchEvent(new MouseEvent('contextmenu', {
          bubbles: true, cancelable: true, clientX: t.clientX, clientY: t.clientY,
        }));
      }, 550);
    }, { passive: true });
    const cancelar = () => { if (toqueLargo) { clearTimeout(toqueLargo); toqueLargo = null; } };
    document.addEventListener('touchmove', cancelar, { passive: true });
    document.addEventListener('touchend', cancelar, { passive: true });
    document.addEventListener('touchcancel', cancelar, { passive: true });
  }
})();
