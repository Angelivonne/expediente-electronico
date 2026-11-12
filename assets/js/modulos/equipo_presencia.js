(() => {
  const estados = [...document.querySelectorAll('[data-equipo-presencia]')];
  const vistos = [...document.querySelectorAll('[data-equipo-visto]')];
  const boton = document.querySelector('[data-equipo-abrir]');
  if (!estados.length && !boton) return;

  const base = document.body.dataset.base || '';
  const coloresAvatar = ['#dcd6ec', '#cddff3', '#d3e8dc', '#efdccb', '#ead3e0', '#e4dfc7', '#cfe6e6', '#e0d5f1'];
  const roles = { administrador: 'Administrador', medico: 'Médico', enfermeria: 'Enfermería', recepcion: 'Recepción' };
  const escapar = (valor) => String(valor ?? '').replace(/[&<>"']/g, (caracter) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[caracter]));
  const normalizar = (valor) => String(valor ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es-MX');
  const haceCuanto = (valor) => {
    if (!valor) return 'sin actividad';
    const fecha = new Date(String(valor).replace(' ', 'T'));
    if (Number.isNaN(fecha.getTime())) return 'sin actividad';
    const segundos = Math.max(0, Math.floor((Date.now() - fecha.getTime()) / 1000));
    if (segundos < 60) return 'ahora';
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) return `hace ${minutos} min`;
    const horas = Math.floor(minutos / 60);
    if (horas < 24) return `hace ${horas} h`;
    const dias = Math.floor(horas / 24);
    if (dias < 7) return dias === 1 ? 'ayer' : `hace ${dias} días`;
    const incluirAnio = fecha.getFullYear() !== new Date().getFullYear();
    return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', ...(incluirAnio ? { year: 'numeric' } : {}) }).format(fecha);
  };
  const textoEstado = (persona) => persona.en_linea
    ? 'En línea'
    : (persona.ultima_vez ? `Últ. vez ${haceCuanto(persona.ultima_vez)}` : 'Sin actividad');
  const iniciales = (nombre) => String(nombre || '?').trim().split(/\s+/).slice(0, 2).map((parte) => Array.from(parte)[0] || '').join('').toLocaleUpperCase('es-MX');

  let panel = null;
  let listaPanel = null;
  let personasPanel = [];
  if (boton) {
    panel = document.createElement('section');
    panel.id = 'equipo-panel';
    panel.className = 'equipo__panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Personas');
    panel.hidden = true;
    panel.innerHTML = '<header class="equipo__panel-cabecera"><span>Personas</span><a class="equipo__panel-todas" href="' + escapar(base) + '/equipo/">Ver todas</a><button type="button" class="equipo__panel-cerrar" data-equipo-cerrar aria-label="Cerrar">×</button></header><label class="equipo__panel-buscar"><span class="sr-only">Buscar persona</span><input type="search" data-equipo-buscar placeholder="Buscar persona" autocomplete="off"></label><div class="equipo__panel-lista" data-equipo-lista-panel><p class="equipo__panel-vacio">Cargando…</p></div>';
    document.body.append(panel);
    listaPanel = panel.querySelector('[data-equipo-lista-panel]');

    const posicionar = () => {
      const rect = boton.getBoundingClientRect();
      const movil = window.matchMedia('(max-width: 900px)').matches;
      const barra = document.querySelector('.barra-lateral');
      const izquierda = movil ? 8 : Math.max(8, (barra?.getBoundingClientRect().right || 0) + 8);
      const ancho = Math.max(0, Math.min(460, window.innerWidth - izquierda - 16));
      const alto = Math.min(560, window.innerHeight - 16);
      const arriba = Math.max(8, Math.min(rect.top, window.innerHeight - alto - 8));
      panel.style.left = `${izquierda}px`;
      panel.style.width = `${ancho}px`;
      panel.style.top = `${arriba}px`;
    };
    const cerrar = () => {
      panel.hidden = true;
      boton.setAttribute('aria-expanded', 'false');
    };
    boton.addEventListener('click', () => {
      if (!panel.hidden) {
        cerrar();
        return;
      }
      if (window.matchMedia('(max-width: 900px)').matches) {
        document.documentElement.classList.remove('lateral-abierta');
      }
      panel.hidden = false;
      boton.setAttribute('aria-expanded', 'true');
      posicionar();
      listaPanel.innerHTML = '<p class="equipo__panel-vacio">Cargando…</p>';
      actualizar();
    });
    panel.querySelector('[data-equipo-cerrar]').addEventListener('click', cerrar);
    panel.querySelector('[data-equipo-buscar]').addEventListener('input', () => pintarPanel(personasPanel));
    document.addEventListener('pointerdown', (evento) => {
      if (!panel.hidden && !panel.contains(evento.target) && !boton.contains(evento.target)) cerrar();
    });
    document.addEventListener('keydown', (evento) => {
      if (evento.key === 'Escape' && !panel.hidden) {
        cerrar();
        boton.focus();
      }
    });
    window.addEventListener('resize', () => { if (!panel.hidden) posicionar(); });
    window.addEventListener('scroll', () => { if (!panel.hidden) posicionar(); }, true);
    listaPanel.addEventListener('error', (evento) => {
      if (evento.target instanceof HTMLImageElement) evento.target.remove();
    }, true);
  }

  const pintarPanel = (personas) => {
    if (!listaPanel || panel.hidden) return;
    const consulta = normalizar(panel.querySelector('[data-equipo-buscar]').value.trim());
    const ordenadas = [...personas].sort((a, b) => {
      const enLinea = Number(Boolean(b.en_linea)) - Number(Boolean(a.en_linea));
      if (enLinea) return enLinea;
      const actividad = String(b.ultima_vez || '').localeCompare(String(a.ultima_vez || ''));
      if (actividad) return actividad;
      return String(a.nombre || '').localeCompare(String(b.nombre || ''), 'es-MX');
    }).filter((persona) => !consulta || normalizar(`${persona.nombre} ${roles[persona.rol] || persona.rol || ''}`).includes(consulta));
    const html = ordenadas.map((persona) => {
      const nombre = escapar(persona.nombre);
      const colorAvatar = coloresAvatar[Number(persona.id) % coloresAvatar.length] || coloresAvatar[0];
      const foto = persona.foto ? `<img src="${escapar(persona.foto)}" alt="" loading="lazy">` : '';
      const claseEstado = persona.en_linea ? ' equipo__panel-estado--en-linea' : '';
      const estado = escapar(textoEstado(persona));
      return `<a class="equipo__panel-persona" href="${escapar(base)}/equipo/persona.php?id=${Number(persona.id)}"><span class="equipo__panel-avatar" style="--avatar:${colorAvatar}">${escapar(iniciales(persona.nombre))}${foto}</span><span class="equipo__panel-nombre"><b>${nombre}</b><small>${escapar(roles[persona.rol] || persona.rol || 'Equipo')}</small></span><span class="equipo__panel-estado${claseEstado}"><i aria-hidden="true"></i>${estado}</span></a>`;
    }).join('');
    const contenido = html || `<p class="equipo__panel-vacio">${consulta ? 'No se encontró a esa persona.' : 'Sin personas.'}</p>`;
    if (listaPanel.innerHTML !== contenido) listaPanel.innerHTML = contenido;
  };

  const actualizar = async () => {
    if (document.visibilityState !== 'visible' || (!estados.length && (!panel || panel.hidden))) return;
    try {
      const respuesta = await fetch(`${base}/api/presencia.php?equipo=1`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!respuesta.ok) throw new Error('No se pudo cargar');
      const datos = await respuesta.json();
      const personas = datos.personas || [];
      personasPanel = personas;
      const porId = new Map(personas.map((persona) => [String(persona.id), persona]));
      estados.forEach((estado) => {
        const persona = porId.get(estado.dataset.id);
        if (!persona) return;
        const enLinea = Boolean(persona.en_linea);
        const etiqueta = textoEstado(persona);
        estado.classList.toggle('equipo__presencia--en-linea', enLinea);
        estado.setAttribute('aria-label', etiqueta);
        estado.title = etiqueta;
      });
      vistos.forEach((elemento) => {
        const persona = porId.get(elemento.dataset.id);
        if (persona) elemento.textContent = textoEstado(persona);
      });
      const lista = document.querySelector('[data-equipo-lista]');
      if (lista) {
        [...lista.children]
          .sort((a, b) => Number(Boolean(porId.get(b.dataset.id)?.en_linea)) - Number(Boolean(porId.get(a.dataset.id)?.en_linea)))
          .forEach((fila) => lista.append(fila));
      }
      pintarPanel(personas);
    } catch (error) {
      if (listaPanel && !panel.hidden) listaPanel.innerHTML = '<p class="equipo__panel-vacio">No se pudo cargar.</p>';
    }
  };

  if (estados.length) actualizar();
  window.setInterval(() => {
    if (estados.length || (panel && !panel.hidden)) actualizar();
  }, 30000);
  document.addEventListener('visibilitychange', actualizar);
})();
