/* ==========================================================================
   Recientes y Papelera de la barra lateral («Más»): paneles completos de la barra lateral.
   Recientes: lo que abriste o editaste en 30 días (bitácora), agrupado por día, con buscador y filtro
   «Editado por mí», más las pestañas cerradas de esta sesión. Papelera: buscador, ubicación de cada página,
   restaurar y eliminar definitivo (solo quien la creó; con confirmación).
   app.js (iniciarBarraLateral) llama a PanelLateral.abrir(nombre, popover).
   ========================================================================== */
window.PanelLateral = (() => {
  const esc = (t) => escaparHtml(String(t ?? ''));
  const sinAcentos = (t) => String(t ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es-MX').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  const coincide = (texto, consulta) => {
    const busqueda = sinAcentos(consulta);
    if (!busqueda) return true;
    const contenido = sinAcentos(texto);
    return busqueda.split(' ').every((termino) => contenido.includes(termino));
  };
  const icono = (n) => (typeof ico === 'function' ? ico(n) : '');
  const hora = (f) => f.slice(11, 16);
  const grupoDia = (f) => {
    const d = new Date(f.replace(' ', 'T'));
    const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
    const dias = Math.round((hoy - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
    if (dias <= 0) return 'Hoy';
    if (dias === 1) return 'Ayer';
    if (dias < 7) return 'Últimos 7 días';
    return 'Últimos 30 días';
  };
  const hace = (f) => {
    const min = Math.round((Date.now() - new Date(f.replace(' ', 'T'))) / 60000);
    if (min < 1) return 'ahora';
    if (min < 60) return `hace ${min} min`;
    if (min < 1440) return `hace ${Math.round(min / 60)} h`;
    return `hace ${Math.round(min / 1440)} d`;
  };

  function marco(popover, titulo, contenido) {
    popover.classList.add('lateral-popover--panel');
    popover.innerHTML = `
      <div class="panel-lateral__cabecera"><span class="panel-lateral__titulo">${titulo}</span></div>
      <div class="panel-lateral__buscar">${icono('buscar')}<input type="search" placeholder="Buscar…" aria-label="Buscar" data-panel-buscar></div>
      ${contenido}`;
    requestAnimationFrame(() => popover.querySelector('[data-panel-buscar]')?.focus({ preventScroll: true }));
  }

  /* ---------- Recientes ---------- */
  async function recientes(popover, { leerLista }) {
    marco(popover, 'Recientes', `
      <div class="panel-lateral__filtros" role="tablist">
        <button type="button" role="tab" aria-selected="true" data-filtro="todo">Todo</button>
        <button type="button" role="tab" aria-selected="false" data-filtro="editado">Editado por mí</button>
        <button type="button" role="tab" aria-selected="false" data-filtro="pacientes">Expedientes</button>
      </div>
      <div class="panel-lateral__lista" data-panel-lista><div class="panel-lateral__esqueleto"><span></span><span></span><span></span><span></span></div></div>`);
    let elementos = [];
    let errorCarga = '';
    try {
      const r = await fetch(`${BASE}/api/espacio/recientes.php`, { headers: { Accept: 'application/json' } });
      if (!r.ok) throw new Error('No se pudo cargar la actividad reciente.');
      const datos = await r.json();
      if (datos.ok !== true || !Array.isArray(datos.elementos)) throw new Error('La respuesta de actividad reciente no es válida.');
      elementos = datos.elementos;
    } catch (e) {
      errorCarga = e.message || 'No se pudo cargar la actividad reciente.';
    }
    const cerradas = (leerLista?.('cerradas') || []).slice(0, 6);
    let filtro = 'todo';
    const pintar = () => {
      const q = sinAcentos(popover.querySelector('[data-panel-buscar]').value.trim());
      const lista = elementos.filter((x) => (filtro !== 'editado' || x.editado) && (filtro !== 'pacientes' || x.tipo === 'paciente')
        && (!q || coincide(`${x.titulo} ${x.detalle}`, q)));
      let html = '';
      let grupo = '';
      lista.forEach((x, i) => {
        const g = grupoDia(x.ultima);
        if (g !== grupo) { grupo = g; html += `<div class="panel-lateral__grupo">${g}</div>`; }
        const contenido = `<span class="panel-lateral__icono">${icono(x.icono)}</span>
          <span class="panel-lateral__texto"><span>${esc(x.titulo)}</span><small>${esc(x.detalle)}</small></span>
          <time title="${esc(x.ultima)}">${g === 'Hoy' ? hora(x.ultima) : hace(x.ultima)}</time>`;
        html += `<div class="panel-lateral__fila panel-lateral__fila--papelera" style="--i:${i}">
          ${x.url ? `<a class="panel-lateral__texto panel-lateral__enlace" href="${esc(x.url)}">${contenido}</a>` : `<span class="panel-lateral__texto">${contenido}</span>`}
          <span class="panel-lateral__acciones">
            ${x.versionUrl ? `<a class="boton-icono" href="${esc(x.versionUrl)}" title="Ver historial de versiones">${icono('reloj')}</a>` : ''}
            ${x.restaurable ? `<button type="button" class="boton-icono" data-recientes-restaurar="${Number(x.id)}" title="Restaurar página">${icono('reabrir')}</button>` : ''}
          </span>
        </div>`;
      });
      if (filtro === 'todo' && cerradas.length && !q) {
        html += '<div class="panel-lateral__grupo">Pestañas cerradas</div>' + cerradas.map((x, i) => `
          <button type="button" class="panel-lateral__fila" role="menuitem" data-reabrir="${i}">
            <span class="panel-lateral__icono">${icono('reabrir')}</span>
            <span class="panel-lateral__texto"><span>${esc(x.titulo)}</span><small>Reabrir en una pestaña</small></span></button>`).join('');
      }
      popover.querySelector('[data-panel-lista]').innerHTML = html
        || `<div class="panel-lateral__vacio">${errorCarga ? esc(errorCarga) : q ? 'Nada coincide con la búsqueda.' : 'Aún no hay actividad reciente. Lo que abras o edites aparecerá aquí.'}</div>`;
    };
    popover.querySelector('[data-panel-buscar]').addEventListener('input', pintar);
    popover.querySelector('[data-panel-lista]').addEventListener('click', async (ev) => {
      const boton = ev.target.closest('[data-recientes-restaurar]');
      if (!boton) return;
      ev.preventDefault();
      ev.stopPropagation();
      const id = Number(boton.dataset.recientesRestaurar);
      try {
        await api('paginas/paginas.php', { accion: 'restaurar', id });
        mostrarNotificacion('exito', 'Página restaurada.');
        const respuesta = await fetch(`${BASE}/api/espacio/recientes.php`, { headers: { Accept: 'application/json' } });
        if (!respuesta.ok) throw new Error('La página se restauró, pero no se pudo actualizar la actividad reciente.');
        const datos = await respuesta.json();
        if (datos.ok !== true || !Array.isArray(datos.elementos)) throw new Error('La página se restauró, pero la respuesta de actividad reciente no es válida.');
        elementos = datos.elementos;
        if (typeof Arbol !== 'undefined') await Arbol.recargar();
        pintar();
      } catch (e) {
        mostrarNotificacion('error', e.message);
      }
    });
    popover.querySelectorAll('[data-filtro]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.stopPropagation();
      filtro = b.dataset.filtro;
      popover.querySelectorAll('[data-filtro]').forEach((o) => o.setAttribute('aria-selected', String(o === b)));
      pintar();
    }));
    pintar();
  }

  /* ---------- Papelera ---------- */
  async function papelera(popover) {
    marco(popover, 'Papelera', `
      <div class="panel-lateral__lista" data-panel-lista><div class="panel-lateral__esqueleto"><span></span><span></span><span></span></div></div>
      <div class="panel-lateral__pie">Las páginas en la papelera se pueden restaurar. Solo quien creó una página puede eliminarla para siempre.</div>`);
    let paginas = [];
    try { paginas = (await api('paginas/paginas.php')).papelera || []; } catch (e) {
      popover.querySelector('[data-panel-lista]').innerHTML = `<div class="panel-lateral__vacio">${esc(e.message)}</div>`;
      return;
    }
    const TIPO = { nota: ['documento', 'Nueva página'], carpeta: ['carpeta', 'Nueva carpeta'], base_datos: ['tabla', 'Nueva base de datos'] };
    const pintar = () => {
      const q = sinAcentos(popover.querySelector('[data-panel-buscar]').value.trim());
      const lista = paginas.filter((p) => !q || coincide(`${p.titulo} ${p.en}`, q));
      popover.querySelector('[data-panel-lista]').innerHTML = lista.length ? lista.map((p, i) => `
        <div class="panel-lateral__fila panel-lateral__fila--papelera" style="--i:${i}" data-fila-papelera="${p.id}">
          <span class="panel-lateral__icono">${icono((TIPO[p.tipo] || TIPO.nota)[0])}</span>
          <a class="panel-lateral__texto" href="${BASE}/paginas/ver.php?id=${p.id}"><span>${esc(p.titulo || (TIPO[p.tipo] || TIPO.nota)[1])}</span><small>en ${esc(p.en)} · ${hace(p.eliminado_en)}</small></a>
          <span class="panel-lateral__acciones">
            <button type="button" class="boton-icono" data-panel-restaurar="${p.id}" title="Restaurar">${icono('reabrir')}</button>
            ${p.propia ? `<button type="button" class="boton-icono boton-icono--peligro" data-panel-eliminar="${p.id}" title="Eliminar para siempre">${icono('papelera')}</button>` : ''}
          </span>
        </div>`).join('')
        : `<div class="panel-lateral__vacio">${q ? 'Nada coincide con la búsqueda.' : 'La papelera está vacía.'}</div>`;
    };
    popover.querySelector('[data-panel-buscar]').addEventListener('input', pintar);
    popover.querySelector('[data-panel-lista]').addEventListener('click', async (ev) => {
      const restaurar = ev.target.closest('[data-panel-restaurar]');
      const eliminar = ev.target.closest('[data-panel-eliminar]');
      if (!restaurar && !eliminar) return;
      ev.preventDefault();
      ev.stopPropagation();
      const id = Number((restaurar || eliminar).dataset[restaurar ? 'panelRestaurar' : 'panelEliminar']);
      try {
        if (eliminar) {
          const p = paginas.find((x) => x.id === id);
          const ok = typeof confirmar === 'function'
            ? await confirmar(eliminar, { titulo: `¿Eliminar «${p?.titulo || 'esta página'}» para siempre?`, texto: 'Se borra con todo lo que contiene y no se puede deshacer.', boton: 'Eliminar' })
            : false;
          if (!ok) return;
          await api('paginas/paginas.php', { accion: 'eliminar', id });
          mostrarNotificacion('exito', 'Página eliminada.');
        } else {
          await api('paginas/paginas.php', { accion: 'restaurar', id });
          mostrarNotificacion('exito', 'Página restaurada.');
          if (typeof PAGINA_ID !== 'undefined' && id === PAGINA_ID) { location.reload(); return; }
          if (typeof Arbol !== 'undefined') await Arbol.recargar();
        }
        const fila = popover.querySelector(`[data-fila-papelera="${id}"]`);
        fila?.classList.add('panel-lateral__fila--saliendo');
        setTimeout(() => { paginas = paginas.filter((x) => x.id !== id); pintar(); }, 200);
      } catch (e) { mostrarNotificacion('error', e.message); }
    });
    pintar();
  }

  return {
    abrir(nombre, popover, ayudas) {
      // Fuera de la barra lateral (que recorta lo que sobresale) y junto a ella, alineado con el botón
      if (popover.parentElement !== document.body) document.body.appendChild(popover);
      const boton = document.querySelector(`[data-lateral-lista="${nombre}"]`);
      const lateral = document.querySelector('.barra-lateral')?.getBoundingClientRect();
      const rb = boton?.getBoundingClientRect();
      if (lateral && rb && innerWidth > 760) {
        popover.style.left = `${lateral.right + 8}px`;
        popover.style.bottom = `${Math.max(12, innerHeight - rb.bottom - 8)}px`;
      } else {
        popover.style.left = '12px'; popover.style.right = '12px'; popover.style.bottom = '12px';
      }
      popover.onclick = (ev) => { if (!ev.target.closest('a, [data-reabrir]')) ev.stopPropagation(); };
      return nombre === 'papelera' ? papelera(popover) : recientes(popover, ayudas);
    },
  };
})();
