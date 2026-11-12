(() => {
  const raiz = document.querySelector('[data-equipo-actividad]');
  if (!raiz) return;

  const contenido = raiz.querySelector('[data-equipo-actividad-contenido]');
  const buscar = raiz.querySelector('[data-equipo-actividad-buscar]');
  const periodo = raiz.querySelector('[data-equipo-actividad-periodo]');
  const pestañas = [...raiz.querySelectorAll('[data-equipo-vista]')];
  const colores = ['#dceaff', '#ffe0eb', '#e4f2dc', '#fff0d6', '#eee5ff', '#dff1f1', '#f7e6d5', '#e1e9fa'];
  const esc = (valor) => escaparHtml(String(valor ?? ''));
  const estado = { vista: 'notificaciones', detalle: [], cursor: null, hayMas: false, cargando: false, error: '', estadisticas: null, revision: 0 };
  let temporizador = null;

  function dibujarActividad() {
    if (estado.cargando && !estado.detalle.length) {
      contenido.innerHTML = '<p class="equipo__actividad-cargando">Cargando actividad…</p>';
      return;
    }
    if (estado.error && !estado.detalle.length) {
      contenido.innerHTML = `<p class="equipo__actividad-error">${esc(estado.error)}</p><button type="button" class="equipo__actividad-reintentar" data-equipo-reintentar>Reintentar</button>`;
      return;
    }
    if (!estado.detalle.length) {
      contenido.innerHTML = `<p class="equipo__actividad-vacia">${buscar.value.trim() ? 'No hay actividad de esa persona en el periodo elegido.' : 'No hay actividad registrada en el periodo elegido.'}</p>`;
      return;
    }

    const items = estado.detalle.map((evento) => {
      const id = Math.abs(Number(evento.usuario_id) || 0);
      const color = colores[id % colores.length];
      const foto = evento.foto ? `<img src="${esc(evento.foto)}" alt="" loading="lazy">` : '';
      const actor = evento.nombre || 'Sistema';
      const verbos = { registraste: 'registró', abriste: 'abrió', eliminaste: 'eliminó', actualizaste: 'actualizó', consultaste: 'consultó', cerraste: 'cerró', exportaste: 'exportó' };
      const descripcion = evento.tu
        ? String(evento.texto || '').replace(/^(registraste|abriste|eliminaste|actualizaste|consultaste|cerraste|exportaste)\b/, (verbo) => verbos[verbo])
        : (evento.texto || 'realizó un cambio');
      const hora = evento.hace || '';
      return `<a class="equipo__actividad-evento" href="${esc(evento.url || '#')}">
        <span class="equipo__actividad-avatar" style="--avatar:${color}">${esc(String(actor).trim().split(/\s+/).slice(0, 2).map((parte) => Array.from(parte)[0] || '').join('').toLocaleUpperCase('es-MX'))}${foto}</span>
        <span class="equipo__actividad-texto"><span><b>${esc(actor)}</b> ${esc(descripcion)}</span><time datetime="${esc(evento.fecha)}">${esc(hora)}</time></span>
      </a>`;
    }).join('');
    contenido.innerHTML = items + (estado.error
      ? `<p class="equipo__actividad-error">${esc(estado.error)}</p>`
      : estado.hayMas
        ? `<button type="button" class="equipo__actividad-mas" data-equipo-mas${estado.cargando ? ' disabled' : ''}>${estado.cargando ? 'Cargando…' : 'Cargar más'}</button>`
        : '');
  }

  function dibujarEstadisticas() {
    if (!estado.estadisticas) {
      contenido.innerHTML = '<p class="equipo__actividad-cargando">Cargando estadísticas…</p>';
      return;
    }
    const s = estado.estadisticas;
    const maximo = Math.max(1, ...s.dias.map((dia) => Number(dia.total) || 0));
    const barras = s.dias.map((dia) => {
      const total = Number(dia.total) || 0;
      const fecha = new Date(`${dia.dia}T12:00:00`);
      const titulo = `${fecha.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })}: ${total} vistas registradas`;
      return `<span class="equipo__actividad-barra" style="--altura:${Math.max(0, total / maximo * 100)}%" title="${esc(titulo)}" aria-label="${esc(titulo)}"></span>`;
    }).join('');
    contenido.innerHTML = `
      <p class="equipo__actividad-periodo-titulo">Actividad del equipo · últimos 28 días</p>
      <dl class="equipo__actividad-resumen">
        <div><dt>Vistas registradas</dt><dd>${Number(s.vistas) || 0}</dd></div>
        <div><dt>Personas activas</dt><dd>${Number(s.visitantes) || 0}</dd></div>
        <div><dt>Cambios</dt><dd>${Number(s.ediciones) || 0}</dd></div>
        <div><dt>Personas que editaron</dt><dd>${Number(s.editores) || 0}</dd></div>
      </dl>
      <div class="equipo__actividad-grafica" role="img" aria-label="Vistas registradas por día durante los últimos 28 días">${barras}</div>
      <div class="equipo__actividad-leyenda"><span>28 días</span><span>La actividad del sistema no demuestra asistencia clínica.</span></div>`;
  }

  async function cargar(reiniciar = true) {
    const revision = ++estado.revision;
    const anterior = estado;
    if (reiniciar) {
      estado.detalle = [];
      estado.cursor = null;
      estado.hayMas = false;
    }
    estado.cargando = true;
    estado.error = '';
    if (estado.vista === 'notificaciones') dibujarActividad();
    else dibujarEstadisticas();

    const consulta = new URLSearchParams({ seccion: 'equipo', periodo: periodo.value, cambios: '1' });
    const nombre = buscar.value.trim();
    if (nombre) consulta.set('persona', nombre);
    if (!reiniciar && anterior.cursor) consulta.set('antes', String(anterior.cursor));
    try {
      const respuesta = await fetch(`${BASE}/api/actividad_seccion.php?${consulta}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const datos = await respuesta.json();
      if (!respuesta.ok || datos.ok !== true) throw new Error(datos.error || 'No se pudo cargar la actividad del equipo.');
      if (revision !== estado.revision) return;
      estado.detalle = reiniciar ? datos.detalle : anterior.detalle.concat(datos.detalle);
      estado.cursor = datos.cursor;
      estado.hayMas = Boolean(datos.hay_mas);
      estado.estadisticas = datos.estadisticas;
      estado.cargando = false;
    } catch (error) {
      if (revision !== estado.revision) return;
      estado.cargando = false;
      estado.error = error.message || 'No se pudo cargar la actividad del equipo.';
    }
    if (estado.vista === 'notificaciones') dibujarActividad();
    else dibujarEstadisticas();
  }

  pestañas.forEach((boton) => boton.addEventListener('click', () => {
    estado.vista = boton.dataset.equipoVista;
    pestañas.forEach((pestaña) => pestaña.setAttribute('aria-selected', String(pestaña === boton)));
    raiz.querySelector('[data-equipo-filtros]').hidden = estado.vista !== 'notificaciones';
    if (estado.vista === 'notificaciones') dibujarActividad();
    else if (estado.estadisticas) dibujarEstadisticas();
    else cargar();
  }));
  buscar.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => cargar(), 250);
  });
  periodo.addEventListener('change', () => cargar());
  contenido.addEventListener('click', (evento) => {
    if (evento.target.closest('[data-equipo-reintentar]')) cargar();
    if (evento.target.closest('[data-equipo-mas]')) cargar(false);
  });

  cargar();
})();
