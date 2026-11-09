/**
 * agentes.js — Lógica del módulo de Agentes IA con avatares animados Bloub.
 */
document.addEventListener('DOMContentLoaded', () => {
  const base = document.body.dataset.base || '';
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const layout = document.querySelector('[data-agentes-layout]');
  if (!layout) return;

  const listaEl    = layout.querySelector('[data-agentes-lista]');
  const detalleEl  = layout.querySelector('[data-agentes-detalle]');
  const vacioEl    = layout.querySelector('[data-agentes-vacio]');
  const totalBadge = layout.querySelector('[data-agentes-total]');

  const sideBloubEl      = layout.querySelector('[data-bloub-sidebar]');
  const sideNombre       = layout.querySelector('[data-side-nombre]');
  const sideEstado       = layout.querySelector('[data-side-estado]');
  const emptyBloubEl     = layout.querySelector('[data-bloub-empty]');
  const chatHeaderBloubEl = layout.querySelector('[data-bloub-chat-header]');

  const dlg      = document.getElementById('dlg-agente');
  const form     = dlg ? dlg.querySelector('form') : null;
  const formaWrap = dlg ? dlg.querySelector('[data-forma-select]') : null;
  const colorWrap = dlg ? dlg.querySelector('[data-color-select]') : null;

  // Colores del avatar (paleta OpenBot)
  const COLORES = [
    { nombre: 'Azul',       hue: 215 },
    { nombre: 'Verde',      hue: 150 },
    { nombre: 'Turquesa',   hue: 185 },
    { nombre: 'Violeta',    hue: 280 },
    { nombre: 'Rosa',       hue: 320 },
    { nombre: 'Naranja',    hue: 30  },
    { nombre: 'Rojo',       hue: 0   },
    { nombre: 'Ámbar',      hue: 55  },
  ];

  const FORMAS = ['circle', 'squircle', 'hexagon', 'triangle', 'pebble', 'egg', 'cloud', 'droplet'];

  let selForma = 'circle';
  let selHue   = 215;
  let agentes  = [];
  let activo   = null;

  let sideBloubInstance   = null;
  let emptyBloubInstance  = null;
  let headerBloubInstance = null;
  // Mapa id → instancia mini
  const miniBloubs = new Map();

  const hdrs = { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' };

  /* ---- Función auxiliar escape HTML ---- */
  function escapeHtml(t) {
    return String(t ?? '').replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
    );
  }

  /* ---- Inicializar Bloub sidebar ---- */
  if (typeof BloubAvatar === 'function' && sideBloubEl) {
    sideBloubInstance = new BloubAvatar(sideBloubEl, {
      size: 80, shape: 'circle', hue: 215, state: 'idle', interactive: true
    });
  }

  /* ---- Inicializar Bloub pantalla vacía ---- */
  if (typeof BloubAvatar === 'function' && emptyBloubEl) {
    emptyBloubInstance = new BloubAvatar(emptyBloubEl, {
      size: 130, shape: 'circle', hue: 215, state: 'orbit', interactive: true
    });
  }

  /* ---- Controles de estado Bloub sidebar ---- */
  layout.querySelectorAll('[data-bloub-state]').forEach(btn => {
    btn.addEventListener('click', () => {
      layout.querySelectorAll('[data-bloub-state]').forEach(b => b.classList.remove('activo'));
      btn.classList.add('activo');
      const st = btn.dataset.bloubState;
      if (sideBloubInstance)  sideBloubInstance.setState(st);
      if (headerBloubInstance) headerBloubInstance.setState(st);
    });
  });

  /* ================================================================
     CARGAR lista de agentes
  ================================================================ */
  async function cargar() {
    try {
      const r = await fetch(`${base}/api/agentes/agentes.php`, { headers: hdrs });
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      const j = await r.json();
      agentes = j.agentes || [];
      if (totalBadge) totalBadge.textContent = agentes.length;

      if (agentes.length === 0) {
        listaEl.innerHTML = '<li class="agentes-item" style="cursor:default;opacity:.5;padding:10px 12px">Sin agentes — crea el primero</li>';
        vacioEl.hidden  = false;
        detalleEl.hidden = true;
        if (sideNombre) sideNombre.textContent = 'Sin agente';
        if (sideEstado) sideEstado.textContent = 'Crea uno nuevo';
        return;
      }

      // Destruir minis anteriores
      miniBloubs.forEach(b => b.stop && b.stop());
      miniBloubs.clear();

      listaEl.innerHTML = agentes.map(a => `
        <li class="agentes-item ${activo && activo.id === a.id ? 'activo' : ''}" data-id="${a.id}">
          <div class="agentes-item__mini" data-mini-bloub="${a.id}"></div>
          <div class="agentes-item__texto">
            <span class="agentes-item__nombre">${escapeHtml(a.nombre)}</span>
            <span class="agentes-item__sub">${escapeHtml(a.estado)} · ${escapeHtml(a.modelo || a.servicio || 'IA')}</span>
          </div>
        </li>
      `).join('');

      // Instanciar Bloub mini por agente
      agentes.forEach(a => {
        const el = listaEl.querySelector(`[data-mini-bloub="${a.id}"]`);
        if (el && typeof BloubAvatar === 'function') {
          const inst = new BloubAvatar(el, {
            size: 32,
            shape: a.shape || 'circle',
            hue: a.hue !== undefined ? +a.hue : 215,
            state: 'idle',
            interactive: false
          });
          miniBloubs.set(a.id, inst);
        }
      });

      listaEl.querySelectorAll('li[data-id]').forEach(li => {
        li.addEventListener('click', () => abrir(+li.dataset.id));
      });

      if (!activo || !agentes.find(x => x.id === activo.id)) {
        abrir(agentes[0].id);
      } else {
        abrir(activo.id);
      }
    } catch (e) {
      console.error('Agentes — error al cargar:', e);
    }
  }

  /* ================================================================
     ABRIR agente seleccionado
  ================================================================ */
  function abrir(id) {
    activo = agentes.find(a => a.id === id);
    if (!activo) return;

    listaEl.querySelectorAll('.agentes-item').forEach(li => {
      li.classList.toggle('activo', +li.dataset.id === id);
    });

    vacioEl.hidden   = true;
    detalleEl.hidden = false;

    // Sidebar
    if (sideNombre) sideNombre.textContent = activo.nombre;
    if (sideEstado) sideEstado.textContent = activo.estado || 'Activo';
    const hue   = activo.hue   !== undefined ? +activo.hue   : 215;
    const shape = activo.shape || 'circle';

    if (sideBloubInstance) {
      sideBloubInstance.setShape(shape);
      sideBloubInstance.setHue(hue);
      sideBloubInstance.setState('idle');
    }

    // Header del chat
    const hbEl = detalleEl.querySelector('[data-bloub-chat-header]');
    if (hbEl) {
      if (headerBloubInstance) { headerBloubInstance.stop && headerBloubInstance.stop(); hbEl.innerHTML = ''; }
      if (typeof BloubAvatar === 'function') {
        headerBloubInstance = new BloubAvatar(hbEl, { size: 44, shape, hue, state: 'idle', interactive: false });
      }
    }

    // Datos en el header
    const elNombre = detalleEl.querySelector('[data-agente-nombre]');
    const elEstado = detalleEl.querySelector('[data-agente-estado]');
    const elDesc   = detalleEl.querySelector('[data-agente-desc]');
    const elMeta   = detalleEl.querySelector('[data-agente-meta]');
    if (elNombre) elNombre.textContent = activo.nombre;
    if (elEstado) { elEstado.textContent = activo.estado || 'creado'; elEstado.className = `agentes-chip agentes-chip--${activo.estado || 'creado'}`; }
    if (elDesc)   elDesc.textContent   = activo.descripcion || '';
    if (elMeta)   elMeta.textContent   = `${activo.servicio || 'IA'} · ${activo.modelo || ''}`;

    // Limpiar conversación al cambiar de agente
    const conv = detalleEl.querySelector('[data-agente-conversacion]');
    if (conv) conv.innerHTML = '';
  }

  /* ================================================================
     MODAL — Selector de formas
  ================================================================ */
  function renderFormas() {
    if (!formaWrap) return;
    // Limpiar instancias Bloub del modal anteriores
    formaWrap.querySelectorAll('[data-forma-bloub]').forEach(el => {
      const inst = el._bloubInst;
      if (inst && inst.stop) inst.stop();
    });
    formaWrap.innerHTML = FORMAS.map(f => `
      <button type="button" class="forma-card ${f === selForma ? 'sel' : ''}" data-forma="${f}" title="${f}">
        <div data-forma-bloub="${f}" style="width:38px;height:38px;pointer-events:none"></div>
      </button>
    `).join('');

    formaWrap.querySelectorAll('[data-forma-bloub]').forEach(el => {
      const f = el.dataset.formaBloub;
      if (typeof BloubAvatar === 'function') {
        const inst = new BloubAvatar(el, { size: 38, shape: f, hue: selHue, state: 'idle', interactive: false });
        el._bloubInst = inst;
      }
    });

    formaWrap.querySelectorAll('[data-forma]').forEach(b => {
      b.addEventListener('click', () => {
        selForma = b.dataset.forma;
        renderFormas();
      });
    });
  }

  /* ================================================================
     MODAL — Selector de colores
  ================================================================ */
  function renderColores() {
    if (!colorWrap) return;
    colorWrap.innerHTML = COLORES.map(c => `
      <button type="button"
        class="color-swatch ${c.hue === selHue ? 'sel' : ''}"
        data-hue="${c.hue}"
        title="${c.nombre}"
        style="background: hsl(${c.hue}, 72%, 55%);"
        aria-label="${c.nombre}"
      ></button>
    `).join('');
    colorWrap.querySelectorAll('[data-hue]').forEach(b => {
      b.addEventListener('click', () => {
        selHue = +b.dataset.hue;
        renderColores();
        renderFormas(); // actualizar preview con nuevo color
      });
    });
  }

  /* ================================================================
     Abrir modal "Nuevo agente"
  ================================================================ */
  layout.querySelectorAll('[data-agente-nuevo]').forEach(b => {
    b.addEventListener('click', () => {
      selForma = 'circle';
      selHue   = 215;
      if (form) form.reset();
      renderFormas();
      renderColores();
      if (dlg) dlg.showModal();
    });
  });

  /* Cerrar modal */
  if (dlg) {
    dlg.querySelectorAll('[data-cerrar]').forEach(b => {
      b.addEventListener('click', () => dlg.close());
    });
    // Cierre con clic fuera del contenido
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
  }

  /* ================================================================
     SUBMIT — Crear agente
  ================================================================ */
  if (form) {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      e.stopPropagation();
      const fd = Object.fromEntries(new FormData(form));
      fd.accion       = 'crear';
      fd.shape        = selForma;
      fd.hue          = selHue;
      fd.herramientas = ['buscar_paginas', 'leer_pagina', 'crear_pagina', 'escribir_nota'];

      const btn = form.querySelector('[type="submit"]');
      if (btn) { btn.disabled = true; btn.textContent = 'Guardando…'; }

      try {
        const r = await fetch(`${base}/api/agentes/agentes.php`, {
          method: 'POST', headers: hdrs, body: JSON.stringify(fd)
        });
        const j = await r.json();
        if (j.ok) {
          dlg.close();
          form.reset();
          selForma = 'circle';
          selHue   = 215;
          await cargar();
          if (j.id) abrir(j.id);
        } else {
          alert(j.error || 'Error al guardar el agente.');
        }
      } catch (err) {
        alert('Error de conexión al guardar el agente.');
      } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Guardar Agente'; }
      }
    });
  }

  /* ================================================================
     Acciones: Iniciar / Pausar / Eliminar
  ================================================================ */
  const btnIniciar  = detalleEl.querySelector('[data-agente-iniciar]');
  const btnPausar   = detalleEl.querySelector('[data-agente-pausar]');
  const btnEliminar = detalleEl.querySelector('[data-agente-eliminar]');

  if (btnIniciar)  btnIniciar.addEventListener('click',  () => cambioEstado('iniciado'));
  if (btnPausar)   btnPausar.addEventListener('click',   () => cambioEstado('pausado'));
  if (btnEliminar) btnEliminar.addEventListener('click', () => {
    if (!activo) return;
    if (confirm(`¿Eliminar el agente "${activo.nombre}"? Esta acción no se puede deshacer.`)) {
      cambioEstado('eliminado');
    }
  });

  async function cambioEstado(estado) {
    if (!activo) return;
    try {
      await fetch(`${base}/api/agentes/agentes.php`, {
        method: 'POST', headers: hdrs,
        body: JSON.stringify({ accion: 'estado', id: activo.id, estado })
      });
      activo.estado = estado;
      await cargar();
      if (estado !== 'eliminado') abrir(activo.id);
    } catch (e) {
      console.error('cambioEstado error:', e);
    }
  }

  /* ================================================================
     Chat — Enviar mensaje (streaming)
  ================================================================ */
  function addMessage(rol, texto) {
    const conv = detalleEl.querySelector('[data-agente-conversacion]');
    if (!conv) return null;
    const wrap   = document.createElement('div');
    wrap.className = `chat-msg chat-msg--${rol}`;
    const bubble = document.createElement('div');
    bubble.className = 'chat-msg__burbuja';
    bubble.textContent = texto;
    wrap.appendChild(bubble);
    conv.appendChild(wrap);
    conv.scrollTop = conv.scrollHeight;
    return bubble;
  }

  const formChat = detalleEl.querySelector('[data-agente-form]');
  if (formChat) {
    formChat.addEventListener('submit', async e => {
      e.preventDefault();
      const ta    = formChat.querySelector('[data-agente-texto]');
      const texto = ta ? ta.value.trim() : '';
      if (!texto || !activo) return;

      addMessage('user', texto);
      if (ta) ta.value = '';

      if (sideBloubInstance)   sideBloubInstance.setState('thinking');
      if (headerBloubInstance) headerBloubInstance.setState('thinking');

      const bubble = addMessage('assistant', '…');
      let resp = '';

      try {
        const r = await fetch(`${base}/api/agentes/agentes.php`, {
          method: 'POST', headers: hdrs,
          body: JSON.stringify({ accion: 'conversar', id: activo.id, texto })
        });

        const reader = r.body.getReader();
        const dec    = new TextDecoder();
        let buf = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          buf += dec.decode(value, { stream: true });
          let idx;
          while ((idx = buf.indexOf('\n')) >= 0) {
            const line = buf.slice(0, idx).trim();
            buf = buf.slice(idx + 1);
            if (!line) continue;
            try {
              const j = JSON.parse(line);
              if (j.t === 'texto') {
                resp += j.d;
                if (bubble) { bubble.textContent = resp; }
                const conv = detalleEl.querySelector('[data-agente-conversacion]');
                if (conv) conv.scrollTop = conv.scrollHeight;
              } else if (j.t === 'error') {
                if (bubble) bubble.textContent = resp || `Error: ${j.d}`;
              }
            } catch {}
          }
        }

        if (!resp && bubble) bubble.textContent = 'Sin respuesta del agente.';
      } catch (err) {
        if (bubble) bubble.textContent = 'Error de comunicación.';
      } finally {
        if (sideBloubInstance)   sideBloubInstance.setState('idle');
        if (headerBloubInstance) headerBloubInstance.setState('idle');
      }
    });

    // Enter envía (Shift+Enter = nueva línea)
    const ta = formChat.querySelector('[data-agente-texto]');
    if (ta) {
      ta.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          formChat.requestSubmit();
        }
      });
      // Auto-resize textarea
      ta.addEventListener('input', () => {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
      });
    }
  }

  /* ================================================================
     Iniciar carga
  ================================================================ */
  cargar();
});
