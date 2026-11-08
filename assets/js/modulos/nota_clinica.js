(() => {
  if (typeof Editor === 'undefined') return;
  let panel = null;
  let editor = null;
  let campo = null;
  let sincronizando = false;
  const estados = new WeakMap();

  const PATRONES = [
    [/^###\s/, 'h3'], [/^##\s/, 'h2'], [/^#\s/, 'h1'],
    [/^-\s\[( |x|X)\]\s/, 'tarea'], [/^[-*]\s/, 'lista'], [/^\d+[.)]\s/, 'numerada'], [/^[>"]\s/, 'cita'],
  ];

  // Texto plano → bloques, reconociendo los marcadores del editor.
  function desdeTexto(valor) {
    return String(valor).replace(/\r\n?/g, '\n').split('\n').map((linea) => {
      if (linea.trim() === '---') return { t: 'separador', x: '' };
      for (const [patron, t] of PATRONES) {
        const m = linea.match(patron);
        if (m) return { t, x: linea.replace(patron, ''), ...(t === 'tarea' ? { c: m[1].toLowerCase() === 'x' } : {}) };
      }
      return { t: 'p', x: linea };
    });
  }

  // Mismo formato que Editor.textoPlano(), para verificar que los bloques corresponden al texto.
  function textoDeBloques(bloques) {
    let n = 0;
    return bloques.map((b) => {
      n = b.t === 'numerada' ? n + 1 : 0;
      if (b.t === 'separador') return '---';
      if (b.t === 'lista') return `- ${b.x}`;
      if (b.t === 'numerada') return `${n}. ${b.x}`;
      if (b.t === 'tarea') return `- [${b.c ? 'x' : ' '}] ${b.x}`;
      if (b.t === 'cita') return `> ${b.x}`;
      return b.x;
    }).join('\n');
  }

  const sinVacias = (t) => t.split(/\r?\n/).filter((l) => l.trim()).join('\n');

  function cargar(valor) {
    editor.cerrarMenuComandos();
    editor.raiz.replaceChildren(...(valor.length ? valor : [{ t: 'p', x: '' }]).map((b) => editor.crearBloque(b)));
    editor.numerar();
  }

  function contador() {
    const texto = editor.textoPlano();
    const palabras = texto.trim() ? texto.trim().split(/\s+/).length : 0;
    panel.querySelector('[data-nota-contador]').textContent = `${palabras} palabras · ${texto.length} caracteres`;
  }

  function sincronizar(bloques) {
    if (!campo) return;
    const estado = estados.get(campo);
    estado.bloques = bloques;
    sincronizando = true;
    campo.value = editor.textoPlano();
    estado.texto = campo.value;
    if (estado.oculto) estado.oculto.value = JSON.stringify(bloques);
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    sincronizando = false;
    contador();
  }

  function crearPanel() {
    if (panel) return;
    panel = document.createElement('aside');
    panel.className = 'nota-clinica';
    panel.hidden = true;
    panel.setAttribute('aria-label', 'Nota clínica');
    panel.innerHTML = `
      <header class="nota-clinica__cabecera">
        <span class="nota-clinica__titulos">Nota clínica</span>
        <button type="button" class="nota-clinica__cerrar" data-nota-opciones aria-label="Opciones de la nota" title="Opciones">${ico('agarre')}</button>
        <button type="button" class="nota-clinica__cerrar" data-nota-voz aria-label="Dictar por voz" title="Dictar por voz">${ico('microfono')}</button>
        <button type="button" class="nota-clinica__cerrar" data-nota-cerrar aria-label="Cerrar nota clínica" title="Cerrar">${ico('doble_derecha')}</button>
      </header>
      <div class="nota-clinica__marco">
        <article class="hoja" data-hoja-seccion data-editable="1" data-version="0">
          <h1 class="hoja__titulo" data-nota-titulo></h1>
          <div class="editor" data-editor-bloques data-editor-formulario="1" aria-label="Contenido de la nota"></div>
        </article>
      </div>
      <footer class="nota-clinica__pie">
        <span class="nota-clinica__contador" data-nota-contador></span>
      </footer>`;
    document.body.appendChild(panel);
    editor = Editor.crearInstancia(panel.querySelector('[data-editor-bloques]'), { alSincronizar: sincronizar });
    panel.querySelector('[data-nota-cerrar]').addEventListener('click', () => cerrarPanel(true));
    panel.querySelector('[data-nota-voz]').addEventListener('click', () => editor.dictar());
    panel.querySelector('[data-nota-opciones]').addEventListener('click', (ev) => {
      const bloque = editor.raiz.querySelector('.bloque:focus-within') || editor.raiz.firstElementChild;
      editor.menuBloque(ev.currentTarget, bloque);
    });
    panel.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !ev.defaultPrevented && !ev.target.closest('.ia-linea')) cerrarPanel(true);
    });
  }

  function abrir(nuevo) {
    crearPanel();
    if (campo === nuevo) return;
    if (campo) editor.sincronizarFormulario();
    editor.cerrarIaEnLinea?.();
    editor.detenerDictado?.();
    Buscador.cerrar();
    campo = nuevo;
    let estado = estados.get(nuevo);
    if (!estado) {
      let bloques = desdeTexto(nuevo.value);
      try {
        const guardados = JSON.parse(nuevo.dataset.notaBloques || 'null');
        // Los bloques solo mandan si corresponden al texto actual (evita resucitar formato viejo).
        if (Array.isArray(guardados) && guardados.length && sinVacias(textoDeBloques(guardados)) === sinVacias(nuevo.value)) bloques = guardados;
      } catch (e) { bloques = desdeTexto(nuevo.value); }
      let oculto = null;
      if (nuevo.form && nuevo.name && !nuevo.dataset.notaInline) {
        oculto = document.createElement('input');
        oculto.type = 'hidden';
        oculto.name = `nota_bloques[${nuevo.name}]`;
        nuevo.form.appendChild(oculto);
      }
      estado = { bloques, texto: nuevo.value, oculto };
      estados.set(nuevo, estado);
    } else if (estado.texto !== nuevo.value) {
      estado.bloques = desdeTexto(nuevo.value);
      estado.texto = nuevo.value;
    }
    cargar(estado.bloques);
    editor.contextoClinico = {
      seccion: nuevo.name || nuevo.id || 'nota',
      titulo: (nuevo.dataset.notaClinica || nuevo.getAttribute('aria-label') || 'Nota clínica').trim(),
    };
    panel.querySelector('[data-nota-titulo]').textContent = editor.contextoClinico.titulo;
    editor.raiz.closest('[data-hoja-seccion]').setAttribute('aria-label', editor.contextoClinico.titulo);
    if (estado.oculto) estado.oculto.value = JSON.stringify(estado.bloques);
    panel.hidden = false;
    document.body.classList.add('nota-clinica-abierta');
    void panel.offsetHeight;   // fuerza el estilo inicial para que la transición anime aun con rAF suspendido
    panel.classList.add('abierta');
    contador();
    editor.enfocarInicio();
  }

  function cerrarPanel(guardar) {
    if (!panel || panel.hidden || !campo) return;
    const objetivo = campo;
    if (guardar) editor.sincronizarFormulario();
    editor.cerrarMenuComandos();
    editor.cerrarIaEnLinea?.();
    editor.detenerDictado?.();
    Buscador.cerrar();
    // Guardar antes de soltar el campo: el guardado en línea lee qué campo está abierto.
    if (guardar) {
      objetivo.dispatchEvent(new Event('change', { bubbles: true }));
      objetivo._cerrarNota?.(true);
    }
    campo = null;
    panel.classList.remove('abierta');
    document.body.classList.remove('nota-clinica-abierta');
    setTimeout(() => { if (!campo) panel.hidden = true; }, 220);
  }

  document.addEventListener('focusin', (ev) => {
    const t = ev.target.closest?.('textarea[data-nota-clinica]');
    if (t) abrir(t);
  });
  document.addEventListener('click', (ev) => {
    const t = ev.target.closest?.('textarea[data-nota-clinica]');
    if (t) abrir(t);
  });
  document.addEventListener('input', (ev) => {
    const t = ev.target.closest?.('textarea[data-nota-clinica]');
    if (!t || sincronizando) return;
    const estado = estados.get(t);
    if (estado) {
      estado.texto = t.value;
      estado.bloques = desdeTexto(t.value);
      if (estado.oculto) estado.oculto.value = JSON.stringify(estado.bloques);
    }
    if (t === campo) {
      editor.cerrarIaEnLinea?.();
      cargar(desdeTexto(t.value));
      contador();
    }
  });
  window.abrirNotaClinica = abrir;
  window.cerrarNotaClinica = () => cerrarPanel(false);
  window.notaClinicaCampo = () => campo;
  window.notaClinicaBloques = () => (campo && editor ? editor.serializar() : null);
})();
