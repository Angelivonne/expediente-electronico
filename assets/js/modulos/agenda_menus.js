/* Agenda: el calendario queda limpio y las acciones viven en un único menú. */
(() => {
  const raiz = document.querySelector('[data-agenda]');
  if (!raiz || typeof abrirMenu !== 'function') return;
  raiz.classList.add('calendario--sereno');

  const herramientas = raiz.querySelector('.calendario__herramientas');
  const buscar = raiz.querySelector('[data-agenda-buscar]');
  const icono = (n) => (typeof ico === 'function' ? ico(n) : '');
  const VISTAS = [['dia', 'Día', 'D'], ['semana', 'Semana', 'W'], ['mes', 'Mes', 'M'], ['lista', 'Lista', 'L']];
  const boton = document.createElement('button');
  boton.type = 'button';
  boton.className = 'cal-menu-boton cal-menu-boton--hamburguesa';
  boton.setAttribute('aria-label', 'Opciones de agenda');
  boton.setAttribute('aria-haspopup', 'menu');
  boton.setAttribute('aria-expanded', 'false');
  boton.title = 'Opciones de agenda';
  boton.innerHTML = '<span class="cal-menu-boton__linea"></span><span class="cal-menu-boton__linea"></span><span class="cal-menu-boton__linea"></span><i class="cal-menu-boton__punto" aria-hidden="true"></i>';
  herramientas.append(boton);

  const vistaActual = () => raiz.querySelector('.calendario__vistas [aria-pressed="true"]')?.dataset.vista || 'semana';
  const profesionales = () => [...raiz.querySelectorAll('[data-agenda-profesionales] [data-prof-alternar]')];
  const canceladas = () => raiz.querySelector('[data-ver-canceladas]');
  const filtrosActivos = () => profesionales().some((b) => b.getAttribute('aria-checked') === 'false')
    || canceladas()?.getAttribute('aria-checked') === 'true'
    || !!buscar?.value.trim();
  const pintarEstado = () => boton.classList.toggle('cal-menu-boton--activo', filtrosActivos());

  const opciones = () => {
    const listaProfesionales = profesionales();
    const mostrarCanceladas = canceladas();
    return [
      { titulo: 'Acciones' },
      { icono: 'agregar', texto: 'Nueva cita', detalle: 'N', accion: () => raiz.querySelector('[data-agenda-nueva]')?.click() },
      'separador',
      { titulo: 'Calendario' },
      { texto: 'Ir a hoy', detalle: 'T', accion: () => raiz.querySelector('[data-agenda-hoy]')?.click() },
      { texto: 'Periodo anterior', detalle: '←', accion: () => raiz.querySelector('[data-agenda-anterior]')?.click() },
      { texto: 'Periodo siguiente', detalle: '→', accion: () => raiz.querySelector('[data-agenda-siguiente]')?.click() },
      {
        icono: 'calendario',
        texto: `Vista: ${VISTAS.find(([clave]) => clave === vistaActual())?.[1] || 'Semana'}`,
        submenu: () => VISTAS.map(([clave, texto, atajo]) => ({
          texto,
          detalle: atajo,
          marcado: vistaActual() === clave,
          accion: () => raiz.querySelector(`.calendario__vistas [data-vista="${clave}"]`)?.click(),
        })),
      },
      'separador',
      { campo: true, tipo: 'search', valor: buscar?.value || '', placeholder: 'Buscar pacientes o citas', etiqueta: 'Buscar en la agenda', alCambiar: (valor) => {
        if (!buscar) return;
        buscar.value = valor;
        buscar.dispatchEvent(new Event('input', { bubbles: true }));
        pintarEstado();
      } },
      { titulo: 'Profesionales' },
      ...listaProfesionales.map((b) => ({
        texto: b.querySelector('span')?.textContent || 'Profesional',
        detalle: b.querySelector('small')?.textContent || '',
        marcado: b.getAttribute('aria-checked') === 'true',
        mantener: true,
        accion: () => b.click(),
      })),
      listaProfesionales.length > 1 && { icono: 'usuarios', texto: 'Ver a todos', mantener: true, accion: () => raiz.querySelector('[data-profesionales-todos]')?.click() },
      'separador',
      mostrarCanceladas && { texto: 'Mostrar citas canceladas', marcado: mostrarCanceladas.getAttribute('aria-checked') === 'true', mantener: true, accion: () => mostrarCanceladas.click() },
      'separador',
      { icono: 'info', texto: 'Atajos de teclado', detalle: '?', accion: () => raiz.querySelector('[data-agenda-atajos]')?.click() },
    ];
  };

  boton.addEventListener('click', () => {
    boton.setAttribute('aria-expanded', 'true');
    abrirMenu(boton, opciones(), {
      alinear: 'derecha',
      alCerrar: () => boton.setAttribute('aria-expanded', 'false'),
    });
  });

  const observador = new MutationObserver(pintarEstado);
  observador.observe(raiz.querySelector('[data-agenda-rail]'), { subtree: true, attributes: true, attributeFilter: ['aria-checked'], childList: true });
  observador.observe(raiz.querySelector('.calendario__vistas'), { subtree: true, attributes: true, attributeFilter: ['aria-pressed'] });
  buscar?.addEventListener('input', pintarEstado);
  pintarEstado();
})();
