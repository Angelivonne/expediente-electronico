document.addEventListener('DOMContentLoaded', () => {
  const base = document.body.dataset.base || '';
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const layout = document.querySelector('[data-agentes-layout]');
  if (!layout) return;
  const lista = layout.querySelector('[data-agentes-lista]');
  const detalle = layout.querySelector('[data-agentes-detalle]');
  const vacio = layout.querySelector('[data-agentes-vacio]');
  const dlg = document.getElementById('dlg-agente');
  const form = dlg.querySelector('form');
  const formaWrap = dlg.querySelector('[data-forma-select]');
  const formas = ['circle','pebble','squircle','capsule','triangle','hexagon','cloud','droplet'];
  let selForma = 'circle';
  let agentes = [];
  let activo = null;
  const headers = { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' };
  function fig(shape, hue, size=44) {
    return `<span class="agent-figure" style="width:${size}px;height:${size}px;--hue:${hue}"><span class="agent-figure__shape shape-${shape}"></span><span class="agent-figure__face"></span></span>`;
  }
  async function cargar() {
    const r = await fetch(`${base}/api/agentes/agentes.php`, { headers });
    const j = await r.json();
    agentes = j.agentes || [];
    lista.innerHTML = agentes.map(a => `
      <li data-id="${a.id}" class="${activo && activo.id===a.id ? 'activo':''}">
        ${fig(a.shape, a.hue, 40)}
        <span><b>${a.nombre}</b><small style="display:block">${a.estado} · ${a.modelo||a.proveedor}</small></span>
      </li>`).join('') || '<li style="cursor:default">Sin agentes</li>';
    lista.querySelectorAll('li[data-id]').forEach(li => li.addEventListener('click', () => abrir(+li.dataset.id)));
  }
  function abrir(id) {
    activo = agentes.find(a => a.id===id);
    if (!activo) return;
    vacio.hidden = true;
    detalle.hidden = false;
    detalle.querySelector('[data-agente-avatar]').innerHTML = fig(activo.shape, activo.hue, 56);
    detalle.querySelector('[data-agente-nombre]').textContent = activo.nombre;
    detalle.querySelector('[data-agente-desc]').textContent = activo.description || '';
    detalle.querySelector('[data-agente-meta]').textContent = `${activo.estado} · ${activo.modelo||activo.proveedor}`;
    const conv = detalle.querySelector('[data-agente-conversacion]');
    conv.innerHTML = '';
    (activo.historial||[]).forEach(m => addBubble(m.rol, m.contenido));
  }
  function addBubble(rol, txt) {
    const conv = detalle.querySelector('[data-agente-conversacion]');
    const d = document.createElement('div');
    d.style.whiteSpace='pre-wrap';
    d.textContent = (rol==='user' ? 'Tú: ' : (activo?.nombre||'Agente')+': ') + txt;
    conv.appendChild(d);
    conv.scrollTop = conv.scrollHeight;
  }
  function renderFormas() {
    formaWrap.innerHTML = formas.map(f => `<button type="button" class="forma-boton ${f===selForma?'sel':''}" data-forma="${f}" title="${f}"><span class="agent-figure" style="width:26px;height:26px;--hue:205"><span class="agent-figure__shape shape-${f}"></span></span></button>`).join('');
    formaWrap.querySelectorAll('[data-forma]').forEach(b => b.addEventListener('click', () => { selForma=b.dataset.forma; renderFormas(); }));
  }
  layout.querySelectorAll('[data-agente-nuevo]').forEach(b => b.addEventListener('click', () => { renderFormas(); dlg.showModal(); }));
  dlg.querySelector('[data-cerrar]').addEventListener('click', () => dlg.close());
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = Object.fromEntries(new FormData(form));
    fd.accion='crear'; fd.shape=selForma; fd.herramientas=['buscar_paginas','leer_pagina','crear_pagina','escribir_nota','buscar_en_internet'];
    const r = await fetch(`${base}/api/agentes/agentes.php`, { method:'POST', headers, body: JSON.stringify(fd) });
    const j = await r.json();
    if (j.ok) { dlg.close(); form.reset(); selForma='circle'; await cargar(); abrir(j.id); }
  });
  detalle.querySelector('[data-agente-iniciar]').addEventListener('click', () => cambioEstado('iniciado'));
  detalle.querySelector('[data-agente-pausar]').addEventListener('click', () => cambioEstado('pausado'));
  detalle.querySelector('[data-agente-eliminar]').addEventListener('click', () => cambioEstado('eliminado'));
  async function cambioEstado(estado) {
    if (!activo) return;
    await fetch(`${base}/api/agentes/agentes.php`, { method:'POST', headers, body: JSON.stringify({accion:'estado', id: activo.id, estado}) });
    activo.estado=estado; await cargar(); abrir(activo.id);
  }
  const formChat = detalle.querySelector('[data-agente-form]');
  formChat.addEventListener('submit', async (e) => {
    e.preventDefault();
    const ta = formChat.querySelector('[data-agente-texto]');
    const texto = ta.value.trim(); if (!texto || !activo) return;
    addBubble('user', texto); ta.value='';
    const r = await fetch(`${base}/api/agentes/agentes.php`, { method:'POST', headers, body: JSON.stringify({accion:'conversar', id: activo.id, texto}) });
    const reader = r.body.getReader(); const dec = new TextDecoder(); let buf='';
    let resp='';
    while (true) {
      const {done, value} = await reader.read(); if (done) break;
      buf += dec.decode(value,{stream:true});
      let idx; while ((idx=buf.indexOf('\n'))>=0) {
        const line=buf.slice(0,idx); buf=buf.slice(idx+1); if(!line.trim()) continue;
        try { const j=JSON.parse(line); if (j.t==='texto') resp+=j.d; if (j.t==='error') resp+=j.d; } catch {}
      }
    }
    if (resp) addBubble('assistant', resp);
  });
  cargar();
});
