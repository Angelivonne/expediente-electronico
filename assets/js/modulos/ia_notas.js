/* IA dentro de la nota: tarjeta en línea (/ia, Ctrl+J o Espacio en renglón vacío).
   Pide consentimiento para compartir y revisión antes de aplicar; nunca firma.
   Usa Editor (espacio.js), markdownSeguro, BASE y TOKEN_CSRF (app.js). */
(() => {
  if (typeof Editor === 'undefined' || typeof markdownSeguro !== 'function') return;
  // [texto del botón, petición, icono]; las de «renglón» solo aparecen si el renglón tiene texto
  const GRUPOS = [
    ['Escribir', [
      ['Continuar escribiendo', 'Continúa escribiendo a partir del renglón actual, con el mismo tono y formato. Devuelve solo el texto nuevo.', 'editar'],
      ['Resumir la nota', 'Resume esta nota en pocas líneas, con lo más importante primero.', 'texto'],
      ['Sacar pendientes', 'Haz una lista de tareas con los pendientes que aparecen en esta nota (formato - [ ] tarea).', 'casilla'],
      ['Agregar una imagen', 'Busca una imagen de licencia libre que ilustre el renglón actual (o el tema de la nota) e insértala justo debajo de ese renglón, a 60 % de ancho, con su fuente en el pie.', 'imagen'],
      ['Ordenar por apartados', 'Propón esta nota ordenada en apartados con encabezados claros, sin perder información.', 'titulo'],
    ]],
    ['Editar el renglón', [
      ['Mejorar la redacción', 'Mejora la redacción del renglón actual sin cambiar su sentido. Devuelve solo el texto mejorado.', 'chispa'],
      ['Hacerlo más corto', 'Reescribe el renglón actual más corto y directo. Devuelve solo el texto.', 'menos'],
      ['Lenguaje clínico', 'Reescribe el renglón actual con terminología clínica correcta y formal. Devuelve solo el texto.', 'clinico'],
      ['Explicar', 'Explica con claridad el renglón actual.', 'info'],
    ], true],
  ];
  const REFINAR = [
    ['Más corto', 'Hazlo más corto.'],
    ['Más detallado', 'Hazlo más detallado.'],
    ['En lista', 'Ponlo como lista con viñetas.'],
    ['Más formal', 'Usa un tono más formal y clínico.'],
  ];
  let abierto = null;   // {caja, bloque, historial, controlador, respuesta, ultimaPeticion, editor, rango, fragmento}

  const titulo = (editor) => (typeof editor?.tituloActual === 'function' ? editor.tituloActual() : '')
    || editor.raiz.closest('[data-hoja], [data-hoja-seccion]')?.querySelector('[data-hoja-titulo]')?.textContent.trim()
    || editor.raiz.closest('[data-hoja-seccion]')?.getAttribute('aria-label') || 'esta nota';

  let estadoIaCache = null;
  let estadoIaTs = 0;
  async function estadoIa(forzar = false) {
    if (!forzar && estadoIaCache && Date.now() - estadoIaTs < 30000) return estadoIaCache;
    try {
      const r = await fetch(`${BASE}/api/asistente/configuracion.php`, { credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      estadoIaCache = { ok: r.ok && j && j.configurado === true, datos: j || {}, http: r.status };
    } catch (e) {
      estadoIaCache = { ok: false, datos: {}, http: 0, error: e?.message || 'sin conexión' };
    }
    estadoIaTs = Date.now();
    return estadoIaCache;
  }

  function cerrar() {
    if (!abierto) return;
    const { caja, colocar, bloque } = abierto;
    bloque.classList.remove('bloque--con-ia');
    abierto.controlador?.abort();
    window.removeEventListener('resize', colocar);
    abierto = null;
    caja.classList.add('ia-linea--saliendo');
    caja.addEventListener('animationend', () => caja.remove(), { once: true });
    setTimeout(() => caja.remove(), 260);
  }

  // Markdown de la respuesta → bloques de la nota (encabezados, listas, tareas, citas y párrafos)
  function bloquesDesde(texto) {
    return texto.replace(/```[\s\S]*?```/g, '').split(/\r?\n/).map((l) => l.trimEnd()).filter((l, i, a) => l || (a[i - 1] && a[i + 1])).map((l) => {
      let m;
      if ((m = l.match(/^###\s+(.*)/))) return { t: 'h3', x: m[1] };
      if ((m = l.match(/^##\s+(.*)/))) return { t: 'h2', x: m[1] };
      if ((m = l.match(/^#\s+(.*)/))) return { t: 'h1', x: m[1] };
      if ((m = l.match(/^[-*]\s+\[( |x|X)\]\s+(.*)/))) return { t: 'tarea', x: m[2], c: m[1].toLowerCase() === 'x' };
      if ((m = l.match(/^[-*•]\s+(.*)/))) return { t: 'lista', x: m[1] };
      if ((m = l.match(/^\d+[.)]\s+(.*)/))) return { t: 'numerada', x: m[1] };
      if ((m = l.match(/^>\s?(.*)/))) return { t: 'cita', x: m[1] };
      return { t: 'p', x: l };
    }).map((b) => ({ ...b, x: b.x.replace(/\*\*(.+?)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1') }));
  }

  function estado(nombre) {
    const a = abierto;
    a.caja.dataset.estado = nombre;   // inicio | pensando | listo
    a.caja.querySelector('[data-ia-sugerencias]').hidden = nombre !== 'inicio';
    a.caja.querySelector('[data-ia-resultado]').hidden = nombre === 'inicio';
    a.caja.querySelector('[data-ia-acciones]').hidden = nombre !== 'listo';
  }

  async function preguntar(texto, { repetir = false } = {}) {
    const a = abierto;
    if (!a || !texto.trim() || a.caja.dataset.estado === 'pensando') return;
    const compartir = a.caja.querySelector('[data-ia-compartir]');
    if (!compartir.checked) {
      compartir.closest('label')?.classList.add('ia-linea__confirmar--atencion');
      compartir.focus({ preventScroll: true });
      mostrarNotificacion('error', 'Paso 1: autoriza compartir el texto con el proveedor de IA para continuar.');
      return;
    }
    a.revision = a.editor.serializar();
    a.caja.querySelector('[data-ia-revision]').checked = false;
    const renglon = a.fragmento || a.editor.texto(a.bloque).trim();
    const contexto = renglon ? `\n\n(Renglón actual de la nota: «${renglon.slice(0, 600)}»)` : '';
    if (!repetir) a.historial.push({ rol: 'usuario', texto: texto + contexto });
    a.ultimaPeticion = texto;
    const resultado = a.caja.querySelector('[data-ia-resultado]');
    const pasos = a.caja.querySelector('[data-ia-pasos]');
    const md = a.caja.querySelector('[data-ia-respuesta]');
    a.caja.querySelector('[data-ia-pregunta]').textContent = texto;
    md.innerHTML = '';
    pasos.innerHTML = '<span class="ia-linea__paso ia-linea__paso--activo trShimmer" data-ia-pensando>Verificando conexión con la IA…</span>';
    estado('pensando');
    let respuesta = '';
    a.controlador = new AbortController();
    try {
      const ver = await estadoIa(!repetir);
      if (!ver.ok) {
        const msg = ver.http === 403 ? 'No tienes permiso para usar la IA o tu sesión expiró. Recarga la página.'
          : ver.datos?.error || ver.error || 'La IA no está configurada o no se pudo verificar la conexión. Revisa Configuración → Asistente.';
        throw new Error(msg);
      }
      pasos.innerHTML = '<span class="ia-linea__paso ia-linea__paso--activo trShimmer" data-ia-pensando>Pensando…</span>';
      const paginaId = a.editor.formulario ? null : a.editor.paginaId;
      const borrador = typeof a.editor.textoPlano === 'function' ? a.editor.textoPlano() : a.editor.serializar().map((b) => b.x || '').join('\n');
      const r = await fetch(`${BASE}/api/asistente/conversar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({
          mensajes: a.historial, acceso: 'expediente', herramienta: 'ninguna', esfuerzo: 'medium',
          modo: 'borrador_nota', compartir_texto: true, pagina_id: paginaId || null,
          contexto_nota: { seccion: a.editor.contextoClinico?.seccion || 'nota', titulo: titulo(a.editor), texto: borrador },
        }),
        signal: a.controlador.signal,
      });
      if (!r.ok && !r.body) {
        let j = null; try { j = await r.json(); } catch {}
        throw new Error(j?.d || j?.error || `No se pudo consultar al asistente (HTTP ${r.status}).`);
      }
      if (!r.body) throw new Error('El asistente no devolvió respuesta.');
      const lector = r.body.getReader();
      const dec = new TextDecoder();
      let pendiente = '';
      for (;;) {
        const { value, done } = await lector.read();
        if (done) break;
        pendiente += dec.decode(value, { stream: true });
        let fin;
        while ((fin = pendiente.indexOf('\n')) >= 0) {
          const linea = pendiente.slice(0, fin).trim();
          pendiente = pendiente.slice(fin + 1);
          if (!linea) continue;
          const ev = JSON.parse(linea);
          if (abierto !== a) return;
          if (ev.t === 'texto') {
            if (!respuesta) { pasos.querySelector('[data-ia-pensando]')?.remove(); pasos.querySelectorAll('.ia-linea__paso--activo').forEach((p) => p.classList.remove('ia-linea__paso--activo', 'trShimmer')); }
            respuesta += ev.d;
            md.innerHTML = markdownSeguro(respuesta);
            resultado.scrollTop = resultado.scrollHeight;
          } else if (ev.t === 'error') {
            throw new Error(ev.d);
          } else if (ev.t === 'aviso') {
            pasos.innerHTML = `<span class="ia-linea__paso ia-linea__paso--activo">${escaparHtml(ev.d)}</span>`;
          } else if (ev.t === 'pensamiento') {
            pasos.innerHTML = '<span class="ia-linea__paso ia-linea__paso--activo trShimmer">Analizando…</span>';
          }
        }
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      if (abierto !== a) return;
      respuesta = '';
      pasos.innerHTML = '';
      md.innerHTML = `<div class="ia-linea__error">${ico('alerta') || ''}<span>${escaparHtml(e.message || 'No se pudo consultar al asistente. Revisa la conexión o los ajustes de la IA.')}</span></div>`;
    }
    if (abierto !== a) return;
    pasos.querySelectorAll('.ia-linea__paso--activo').forEach((p) => p.remove());
    a.historial.push({ rol: 'asistente', texto: respuesta });
    a.respuesta = respuesta;
    estado('listo');
    const conTexto = !!respuesta.trim();
    a.caja.querySelector('[data-ia-insertar]').hidden = !conTexto;
    a.caja.querySelector('[data-ia-reemplazar]').hidden = !conTexto || !a.editor.texto(a.bloque).trim();
    a.caja.querySelector('[data-ia-reintentar]').hidden = conTexto || !a.modoSeleccion;
    a.caja.querySelector('[data-ia-revision]').closest('label').hidden = a.modoSeleccion && !conTexto;
    a.caja.querySelector('[data-ia-turnos]').textContent = a.historial.length > 2 ? `${a.historial.length / 2} intercambios` : '';
    const entrada = a.caja.querySelector('[data-ia-entrada]');
    entrada.value = '';
    entrada.placeholder = 'Pide un cambio o haz otra pregunta…';
    if (conTexto) a.caja.querySelector('[data-ia-revision]')?.focus({ preventScroll: true });
    else if (!a.modoSeleccion) entrada.focus();
  }

  function insertar(reemplazar) {
    const a = abierto;
    if (!a || !a.respuesta?.trim()) return;
    const rev = a.caja.querySelector('[data-ia-revision]');
    if (!rev.checked) {
      rev.closest('label')?.classList.add('ia-linea__confirmar--atencion');
      rev.focus({ preventScroll: true });
      mostrarNotificacion('error', 'Paso 2: revisa la propuesta y confirma que puedes aplicarla.');
      return;
    }
    if (JSON.stringify(a.revision) !== JSON.stringify(a.editor.serializar()) || !a.editor.raiz.contains(a.bloque)) {
      mostrarNotificacion('error', 'La nota cambió durante la consulta. Solicita una propuesta nueva para no sobrescribir tus cambios.');
      return;
    }
    if (reemplazar && a.rango) {
      if (a.rango.toString() !== a.fragmento) {
        mostrarNotificacion('error', 'La selección cambió durante la consulta. Selecciona de nuevo el texto para evitar reemplazar contenido distinto.');
        return;
      }
      a.rango.deleteContents();
      // La selección es texto en línea: inserta la respuesta sin marcadores de markdown.
      const nodo = document.createTextNode(bloquesDesde(a.respuesta).map((b) => b.x).join(' '));
      a.rango.insertNode(nodo);
      a.editor.programarGuardado();
      mostrarNotificacion('exito', 'Texto mejorado con IA.');
      cerrar();
      return;
    }
    let ultimo = a.bloque;
    const vacio = !a.editor.texto(a.bloque).trim() && a.bloque.dataset.t === 'p';
    const nuevos = bloquesDesde(a.respuesta || '');
    nuevos.forEach((b) => { ultimo = a.editor.insertarDespues(ultimo, b); ultimo.classList.add('bloque--nuevo-ia'); });
    if ((vacio || reemplazar) && ultimo !== a.bloque) a.bloque.remove();
    a.editor.programarGuardado();
    mostrarNotificacion('exito', reemplazar ? 'Renglón reemplazado con IA.' : 'Texto insertado con IA.');
    cerrar();
    a.editor.enfocar(ultimo);
  }

  Editor.cerrarIaEnLinea = function () { if (abierto?.editor === this) cerrar(); };

  Editor.abrirIaEnLinea = function (bloque, opciones = {}) {
    cerrar();
    const contenedor = this.raiz.parentElement;
    if (getComputedStyle(contenedor).position === 'static') contenedor.style.position = 'relative';
    const conTexto = !!this.texto(bloque).trim();
    const caja = document.createElement('div');
    caja.className = 'ia-linea';
    caja.dataset.estado = 'inicio';
    caja.setAttribute('contenteditable', 'false');
    caja.innerHTML = `
      <div class="ia-linea__tarjeta">
        <div class="ia-linea__cabecera">
          <span class="ia-linea__marca">${ico('chispa')}<span>IA</span></span>
          <span class="ia-linea__contexto">sobre «${escaparHtml(titulo(this).slice(0, 60))}»</span>
          <span class="ia-linea__turnos" data-ia-turnos></span>
          <kbd class="ia-linea__tecla">Esc</kbd>
        </div>
        <div class="ia-linea__resultado" data-ia-resultado hidden>
          <p class="ia-linea__pregunta" data-ia-pregunta></p>
          <p class="ia-linea__fragmento" data-ia-fragmento hidden></p>
          <div class="ia-linea__pasos" data-ia-pasos></div>
          <div class="ia-linea__respuesta md" data-ia-respuesta></div>
        </div>
        <label class="ia-linea__confirmar"><input type="checkbox" data-ia-compartir> <span><b>Paso 1.</b> Autorizo compartir el texto con el proveedor configurado de IA.</span></label>
        <div class="ia-linea__barra">
          <textarea data-ia-entrada rows="1" placeholder="Pregunta a la IA sobre esta nota o pídele que escriba…" aria-label="Pregunta a la IA"></textarea>
          <button type="button" class="ia-linea__enviar" data-ia-enviar aria-label="Enviar">${ico('flecha_arriba') || '↑'}</button>
        </div>
        <div class="ia-linea__acciones" data-ia-acciones hidden>
          <label class="ia-linea__confirmar ia-linea__confirmar--aplicar"><input type="checkbox" data-ia-revision> <span><b>Paso 2.</b> Revisé la propuesta y confirmo aplicarla. No firma la nota.</span></label>
          <div class="ia-linea__principales">
            <button type="button" class="ia-linea__boton ia-linea__boton--primario" data-ia-reemplazar>${ico('editar')}<span>Reemplazar renglón</span></button>
            <button type="button" class="ia-linea__boton" data-ia-insertar>${ico('agregar')}<span>Insertar debajo</span></button>
            <button type="button" class="ia-linea__boton" data-ia-otra>${ico('reabrir') || ''}<span>Otra versión</span></button>
            <button type="button" class="ia-linea__boton" data-ia-copiar>${ico('documento')}<span>Copiar</span></button>
            <button type="button" class="ia-linea__boton" data-ia-reintentar hidden>${ico('reabrir') || ''}<span>Intentar de nuevo</span></button>
            <button type="button" class="ia-linea__boton ia-linea__boton--tenue" data-ia-descartar>${ico('cerrar')}<span>Descartar</span></button>
          </div>
          <div class="ia-linea__refinar">${REFINAR.map(([t], i) => `<button type="button" data-ia-refinar="${i}">${t}</button>`).join('')}</div>
        </div>
      </div>
      <div class="menu-flotante ia-linea__sugerencias" data-ia-sugerencias>
        ${GRUPOS.filter(([, , soloConTexto]) => !soloConTexto || conTexto).map(([nombre, opciones]) => `
          <div class="menu-flotante__titulo">${nombre}</div>
          ${opciones.map(([t, , icono]) => `<button type="button" data-ia-sugerencia="${escaparHtml(t)}">${ico(icono) || ico('chispa')}<span>${t}</span></button>`).join('')}`).join('')}
      </div>`;
    contenedor.appendChild(caja);
    const colocar = () => {
      const cb = contenedor.getBoundingClientRect(), bb = bloque.getBoundingClientRect(), rb = this.raiz.getBoundingClientRect();
      caja.style.top = `${bb.bottom - cb.top + 6}px`;
      caja.style.left = `${rb.left - cb.left}px`;
      caja.style.width = `${rb.width}px`;
    };
    colocar();
    window.addEventListener('resize', colocar);
    const seleccionDirecta = !!(opciones.rango && opciones.texto?.trim() && opciones.instruccion);
    abierto = { caja, bloque, editor: this, historial: [], colocar, rango: opciones.rango || null, fragmento: opciones.texto || '', revision: this.serializar(), modoSeleccion: seleccionDirecta };
    if (seleccionDirecta) {
      caja.classList.add('ia-linea--seleccion');
      const fragmento = caja.querySelector('[data-ia-fragmento]');
      fragmento.textContent = `Texto seleccionado: “${opciones.texto.trim().slice(0, 360)}${opciones.texto.trim().length > 360 ? '…' : ''}”`;
      fragmento.hidden = false;
      caja.querySelector('[data-ia-reemplazar] span').textContent = 'Aceptar cambios';
    }
    if (opciones.instruccion) caja.querySelector('[data-ia-entrada]').value = opciones.instruccion;
    bloque.classList.add('bloque--con-ia');
    const entrada = caja.querySelector('[data-ia-entrada]');
    entrada.addEventListener('input', () => { entrada.style.height = 'auto'; entrada.style.height = `${Math.min(entrada.scrollHeight, 160)}px`; });
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); preguntar(entrada.value); }
    });
    // Escape cierra la tarjeta desde cualquier control, no solo desde la entrada.
    caja.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') { ev.preventDefault(); cerrar(); bloque.classList.remove('bloque--con-ia'); this.enfocar(bloque); }
    });
    caja.querySelector('[data-ia-enviar]').addEventListener('click', () => preguntar(entrada.value));
    caja.querySelector('[data-ia-compartir]').addEventListener('change', (ev) => {
      ev.target.closest('label')?.classList.remove('ia-linea__confirmar--atencion');
      if (ev.target.checked && abierto?.modoSeleccion && caja.dataset.estado === 'inicio') preguntar(entrada.value);
    });
    caja.querySelector('[data-ia-revision]').addEventListener('change', (ev) => ev.target.closest('label')?.classList.remove('ia-linea__confirmar--atencion'));
    caja.addEventListener('click', (ev) => {
      const s = ev.target.closest('[data-ia-sugerencia]');
      if (s) {
        const opcion = GRUPOS.flatMap(([, o]) => o).find(([t]) => t === s.dataset.iaSugerencia);
        if (opcion) preguntar(opcion[1]);
      }
      const r = ev.target.closest('[data-ia-refinar]');
      if (r) preguntar(REFINAR[Number(r.dataset.iaRefinar)][1]);
      if (ev.target.closest('[data-ia-otra]')) {
        // Otra versión: quita la última respuesta y repite la misma petición
        abierto.historial.pop();
        preguntar(abierto.ultimaPeticion, { repetir: true });
      }
      if (ev.target.closest('[data-ia-reintentar]')) preguntar(abierto.ultimaPeticion, { repetir: true });
      if (ev.target.closest('[data-ia-descartar]')) { bloque.classList.remove('bloque--con-ia'); cerrar(); this.enfocar(bloque); }
      if (ev.target.closest('[data-ia-copiar]')) navigator.clipboard?.writeText(abierto.respuesta || '').then(() => window.mostrarNotificacion?.('exito', 'Respuesta copiada.'));
      if (ev.target.closest('[data-ia-insertar]')) { bloque.classList.remove('bloque--con-ia'); insertar(false); }
      if (ev.target.closest('[data-ia-reemplazar]')) { bloque.classList.remove('bloque--con-ia'); insertar(true); }
    });
    if (opciones.instruccion && opciones.texto) {
      // Acción directa (ej. “Mejorar con IA”): pide consentimiento visible y luego ejecuta.
      caja.querySelector('[data-ia-compartir]')?.focus({ preventScroll: true });
      mostrarNotificacion('exito', 'Revisa el texto seleccionado y marca “Paso 1” para mejorar con IA.');
    } else {
      entrada.focus();
    }
  };

  // Cerrar con un clic fuera (sin perder una respuesta en curso: solo si ya terminó o no se ha preguntado)
  document.addEventListener('mousedown', (ev) => {
    if (abierto && !abierto.caja.contains(ev.target) && abierto.caja.dataset.estado !== 'pensando') {
      abierto.bloque.classList.remove('bloque--con-ia');
      cerrar();
    }
  });
})();
