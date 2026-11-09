/**
 * agentes.js — Lógica del módulo de Agentes IA con avatares animados Bloub.
 */
document.addEventListener('DOMContentLoaded', () => {
  const base = document.body.dataset.base || '';
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const layout = document.querySelector('[data-agentes-layout]');
  if (!layout) return;

  const listaEl = layout.querySelector('[data-agentes-lista]');
  const detalleEl = layout.querySelector('[data-agentes-detalle]');
  const vacioEl = layout.querySelector('[data-agentes-vacio]');
  const totalBadge = layout.querySelector('[data-agentes-total]');

  const sideBloubEl = layout.querySelector('[data-bloub-sidebar]');
  const sideNombre = layout.querySelector('[data-side-nombre]');
  const sideEstado = layout.querySelector('[data-side-estado]');

  const emptyBloubEl = layout.querySelector('[data-bloub-empty]');
  const chatHeaderBloubEl = layout.querySelector('[data-bloub-chat-header]');

  const dlg = document.getElementById('dlg-agente');
  const form = dlg.querySelector('form');
  const formaWrap = dlg.querySelector('[data-forma-select]');

  const formas = ['circle', 'squircle', 'hexagon', 'triangle', 'pebble', 'egg', 'cloud', 'droplet'];
  let selForma = 'circle';
  let agentes = [];
  let activo = null;

  let sideBloubInstance = null;
  let emptyBloubInstance = null;
  let headerBloubInstance = null;

  const headers = { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' };

  // Inicializar Bloub en el sidebar
  if (typeof BloubAvatar === 'function' && sideBloubEl) {
    sideBloubInstance = new BloubAvatar(sideBloubEl, {
      size: 80,
      shape: 'circle',
      hue: 205,
      state: 'idle',
      interactive: true
    });
  }

  // Inicializar Bloub en la pantalla vacía
  if (typeof BloubAvatar === 'function' && emptyBloubEl) {
    emptyBloubInstance = new BloubAvatar(emptyBloubEl, {
      size: 130,
      shape: 'circle',
      hue: 205,
      state: 'orbit',
      interactive: true
    });
  }

  // Controles de estado de animación Bloub (Reposo, Órbita, Pensando, etc.)
  layout.querySelectorAll('[data-bloub-state]').forEach(btn => {
    btn.addEventListener('click', () => {
      layout.querySelectorAll('[data-bloub-state]').forEach(b => b.classList.remove('activo'));
      btn.classList.add('activo');
      const st = btn.dataset.bloubState;
      if (sideBloubInstance) sideBloubInstance.setState(st);
      if (headerBloubInstance) headerBloubInstance.setState(st);
    });
  });

  async function cargar() {
    try {
      const r = await fetch(`${base}/api/agentes/agentes.php`, { headers });
      const j = await r.json();
      agentes = j.agentes || [];
      if (totalBadge) totalBadge.textContent = agentes.length;

      if (agentes.length === 0) {
        listaEl.innerHTML = '<li class="agentes-item" style="cursor:default;opacity:.6">No hay agentes aún</li>';
        vacioEl.hidden = false;
        detalleEl.hidden = true;
        if (sideNombre) sideNombre.textContent = 'Sin Agente';
        if (sideEstado) sideEstado.textContent = 'Crea uno nuevo';
        return;
      }

      listaEl.innerHTML = agentes.map(a => `
        <li class="agentes-item ${activo && activo.id === a.id ? 'activo' : ''}" data-id="${a.id}">
          <div class="agentes-item__mini" data-mini-bloub="${a.id}"></div>
          <div class="agentes-item__texto">
            <span class="agentes-item__nombre">${escapeHtml(a.nombre)}</span>
            <span class="agentes-item__sub">${escapeHtml(a.estado)} · ${escapeHtml(a.modelo || a.servicio || 'IA')}</span>
          </div>
        </li>
      `).join('');

      listaEl.querySelectorAll('li[data-id]').forEach(li => {
        li.addEventListener('click', () => abrir(+li.dataset.id));
      });

      // Si no hay seleccionado o el seleccionado fue borrado, abrir el primero
      if (!activo || !agentes.find(x => x.id === activo.id)) {
        abrir(agentes[0].id);
      } else {
        abrir(activo.id);
      }
    } catch (e) {
      console.error('Error cargando agentes:', e);
    }
  }

  function abrir(id) {
    activo = agentes.find(a => a.id === id);
    if (!activo) return;

    // Actualizar clase activo en lista
    listaEl.querySelectorAll('.agentes-item').forEach(li => {
      li.classList.toggle('activo', +li.dataset.id === id);
    });

    vacioEl.hidden = true;
    detalleEl.hidden = false;

    // Actualizar Sidebar Card
    if (sideNombre) sideNombre.textContent = activo.nombre;
    if (sideEstado) sideEstado.textContent = activo.estado || 'Activo';

    const hue = activo.hue !== undefined ? activo.hue : 205;
    const shape = activo.shape || 'circle';

    if (sideBloubInstance) {
      sideBloubInstance.setShape(shape);
      sideBloubInstance.setHue(hue);
      sideBloubInstance.setState('idle');
    }

    // Actualizar Chat Header
    detalleEl.querySelector('[data-agente-nombre]').textContent = activo.nombre;
    detalleEl.querySelector('[data-agente-desc]').textContent = activo.description || '';
    detalleEl.querySelector('[data-agente-meta]').textContent = `${activo.servicio || 'IA'} · Modelo: ${activo.modelo || 'predeterminado'}`;
    detalleEl.querySelector('[data-agente-estado]').textContent = activo.estado || 'activo';

    // Header Bloub
    if (typeof BloubAvatar === 'function' && chatHeaderBloubEl) {
      if (headerBloubInstance) headerBloubInstance.stop();
      headerBloubInstance = new BloubAvatar(chatHeaderBloubEl, {
        size: 44,
        shape: shape,
        hue: hue,
        state: 'idle',
        interactive: false
      });
    }

    // Render historial de mensajes
    const conv = detalleEl.querySelector('[data-agente-conversacion]');
    conv.innerHTML = '';
    (activo.historial || []).forEach(m => addMessage(m.rol, m.contenido));
  }

  function addMessage(rol, txt) {
    const conv = detalleEl.querySelector('[data-agente-conversacion]');
    const wrap = document.createElement('div');
    wrap.className = `chat-msg chat-msg--${rol === 'user' ? 'user' : 'assistant'}`;

    const bubble = document.createElement('div');
    bubble.className = 'chat-msg__burbuja';
    bubble.textContent = txt;

    wrap.appendChild(bubble);
    conv.appendChild(wrap);
    conv.scrollTop = conv.scrollHeight;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function renderFormas() {
    formaWrap.innerHTML = formas.map(f => `
      <button type="button" class="forma-card ${f === selForma ? 'sel' : ''}" data-forma="${f}" title="${f}">
        <svg viewBox="-100 -100 200 200" width="34" height="34">
          <circle cx="0" cy="0" r="70" fill="hsl(205, 75%, 62%)" />
          <circle cx="-25" cy="-5" r="9" fill="#1e2022" />
          <circle cx="25" cy="-5" r="9" fill="#1e2022" />
        </svg>
      </button>
    `).join('');

    formaWrap.querySelectorAll('[data-forma]').forEach(b => {
      b.addEventListener('click', () => {
        selForma = b.dataset.forma;
        renderFormas();
      });
    });
  }

  layout.querySelectorAll('[data-agente-nuevo]').forEach(b => {
    b.addEventListener('click', () => {
      renderFormas();
      dlg.showModal();
    });
  });

  dlg.querySelectorAll('[data-cerrar]').forEach(b => {
    b.addEventListener('click', () => dlg.close());
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = Object.fromEntries(new FormData(form));
    fd.accion = 'crear';
    fd.shape = selForma;
    fd.herramientas = ['buscar_paginas', 'leer_pagina', 'crear_pagina', 'escribir_nota', 'buscar_en_internet'];

    try {
      const r = await fetch(`${base}/api/agentes/agentes.php`, {
        method: 'POST',
        headers,
        body: JSON.stringify(fd)
      });
      const j = await r.json();
      if (j.ok) {
        dlg.close();
        form.reset();
        selForma = 'circle';
        await cargar();
        abrir(j.id);
      } else {
        alert(j.error || 'Error al guardar agente');
      }
    } catch (err) {
      alert('Error de conexión');
    }
  });

  // Acciones de estado del agente
  detalleEl.querySelector('[data-agente-iniciar]')?.addEventListener('click', () => cambioEstado('iniciado'));
  detalleEl.querySelector('[data-agente-pausar]')?.addEventListener('click', () => cambioEstado('pausado'));
  detalleEl.querySelector('[data-agente-eliminar]')?.addEventListener('click', () => {
    if (confirm(`¿Eliminar al agente "${activo?.nombre}"?`)) {
      cambioEstado('eliminado');
    }
  });

  async function cambioEstado(estado) {
    if (!activo) return;
    try {
      await fetch(`${base}/api/agentes/agentes.php`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ accion: 'estado', id: activo.id, estado })
      });
      activo.estado = estado;
      await cargar();
      if (estado !== 'eliminado') abrir(activo.id);
    } catch (e) {
      console.error(e);
    }
  }

  // Envío del chat con streaming
  const formChat = detalleEl.querySelector('[data-agente-form]');
  formChat.addEventListener('submit', async (e) => {
    e.preventDefault();
    const ta = formChat.querySelector('[data-agente-texto]');
    const texto = ta.value.trim();
    if (!texto || !activo) return;

    addMessage('user', texto);
    ta.value = '';

    // Poner el Bloub en estado 'thinking' durante la respuesta
    if (sideBloubInstance) sideBloubInstance.setState('thinking');
    if (headerBloubInstance) headerBloubInstance.setState('thinking');

    try {
      const r = await fetch(`${base}/api/agentes/agentes.php`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ accion: 'conversar', id: activo.id, texto })
      });

      const reader = r.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      let resp = '';

      // Crear burbuja del asistente para streaming
      const conv = detalleEl.querySelector('[data-agente-conversacion]');
      const wrap = document.createElement('div');
      wrap.className = 'chat-msg chat-msg--assistant';
      const bubble = document.createElement('div');
      bubble.className = 'chat-msg__burbuja';
      wrap.appendChild(bubble);
      conv.appendChild(wrap);

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        let idx;
        while ((idx = buf.indexOf('\n')) >= 0) {
          const line = buf.slice(0, idx);
          buf = buf.slice(idx + 1);
          if (!line.trim()) continue;
          try {
            const j = JSON.parse(line);
            if (j.t === 'texto') {
              resp += j.d;
              bubble.textContent = resp;
              conv.scrollTop = conv.scrollHeight;
            } else if (j.t === 'error') {
              resp += '\n[Error: ' + j.d + ']';
              bubble.textContent = resp;
            }
          } catch {}
        }
      }

      if (!resp) bubble.textContent = 'Respuesta recibida sin contenido.';
    } catch (err) {
      addMessage('assistant', 'Error de comunicación con el agente.');
    } finally {
      // Regresar al estado idle
      if (sideBloubInstance) sideBloubInstance.setState('idle');
      if (headerBloubInstance) headerBloubInstance.setState('idle');
    }
  });

  cargar();
});
