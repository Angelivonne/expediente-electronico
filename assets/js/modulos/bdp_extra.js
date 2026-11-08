/* ==========================================================================
   Bases de datos (Bdp de espacio.js), segunda capa:
   - Fórmulas tipo Excel (columna «Fórmula», sin eval: analizador propio).
   - Columna «Paciente»: enlace al expediente y selector con buscador.
   - Cálculos al pie de cada columna (suma, promedio, conteo, % marcadas…).
   - Vistas: Tabla y Gráficas editables (tipo, agrupar por, valor, título) junto a la tabla.
   - Página de cada fila: panel lateral con todas sus propiedades y notas.
   - Galería de plantillas cuando la base está vacía.
   - Gráficas de las notas: editar títulos y datos a mano.
   ========================================================================== */

/* ---------------- Motor de fórmulas ---------------- */
const Formulas = (() => {
  const OPS = ['==', '!=', '<=', '>=', '&&', '||', '+', '-', '*', '/', '%', '^', '<', '>', '!', '(', ')', ',', '?', ':'];
  function tokens(texto) {
    const t = [];
    let i = 0;
    while (i < texto.length) {
      const c = texto[i];
      if (/\s/.test(c)) { i++; continue; }
      if (/[0-9.]/.test(c)) { const m = texto.slice(i).match(/^\d*\.?\d+(e[+-]?\d+)?/i); if (!m) throw new Error('Número no válido'); t.push({ k: 'num', v: Number(m[0]) }); i += m[0].length; continue; }
      if (c === '"' || c === "'") {
        let j = i + 1, s = '';
        while (j < texto.length && texto[j] !== c) { if (texto[j] === '\\' && j + 1 < texto.length) j++; s += texto[j++]; }
        if (j >= texto.length) throw new Error('Falta cerrar las comillas');
        t.push({ k: 'str', v: s }); i = j + 1; continue;
      }
      if (c === '{') { const j = texto.indexOf('}', i); if (j < 0) throw new Error('Falta cerrar }'); t.push({ k: 'prop', v: texto.slice(i + 1, j).trim() }); i = j + 1; continue; }
      if (/[A-Za-zÁÉÍÓÚáéíóúñÑ_]/.test(c)) { const m = texto.slice(i).match(/^[A-Za-zÁÉÍÓÚáéíóúñÑ_][\wÁÉÍÓÚáéíóúñÑ]*/); t.push({ k: 'id', v: m[0] }); i += m[0].length; continue; }
      const op = OPS.find((o) => texto.startsWith(o, i));
      if (!op) throw new Error(`Símbolo no válido: ${c}`);
      t.push({ k: 'op', v: op }); i += op.length;
    }
    return t;
  }
  // Analizador descendente: ternario > o > y > igualdad > comparación > suma > producto > potencia > unario > llamada
  function analizar(texto) {
    const t = tokens(texto);
    let p = 0;
    const ver = (v) => t[p] && t[p].k === 'op' && t[p].v === v;
    const tomar = (v) => { if (!ver(v)) throw new Error(`Se esperaba «${v}»`); p++; };
    const bin = (sig, ops) => () => { let a = sig(); while (t[p]?.k === 'op' && ops.includes(t[p].v)) { const op = t[p++].v; a = { op, a, b: sig() }; } return a; };
    let ternario;
    const primario = () => {
      const x = t[p++];
      if (!x) throw new Error('La fórmula está incompleta');
      if (x.k === 'num' || x.k === 'str') return { val: x.v };
      if (x.k === 'prop') return { prop: x.v };
      if (x.k === 'op' && x.v === '(') { const e = ternario(); tomar(')'); return e; }
      if (x.k === 'id') {
        const n = x.v.toLowerCase();
        if (n === 'true' || n === 'verdadero') return { val: true };
        if (n === 'false' || n === 'falso') return { val: false };
        if (ver('(')) {
          p++;
          const args = [];
          if (!ver(')')) { do { args.push(ternario()); } while (ver(',') && ++p); }
          tomar(')');
          return { fn: n, args };
        }
        throw new Error(`«${x.v}» no es una función; usa prop("${x.v}") para una columna`);
      }
      throw new Error(`No se esperaba «${x.v}»`);
    };
    const unario = () => (ver('!') || ver('-') ? (p++, { op: t[p - 1].v === '!' ? 'no' : 'neg', a: unario() }) : primario());
    const potencia = () => { const a = unario(); if (ver('^')) { p++; return { op: '^', a, b: potencia() }; } return a; };
    const producto = bin(potencia, ['*', '/', '%']);
    const suma = bin(producto, ['+', '-']);
    const comparacion = bin(suma, ['<', '>', '<=', '>=']);
    const igualdad = bin(comparacion, ['==', '!=']);
    const y = bin(igualdad, ['&&']);
    const o = bin(y, ['||']);
    ternario = () => { const c = o(); if (ver('?')) { p++; const a = ternario(); tomar(':'); return { si: c, a, b: ternario() }; } return c; };
    const arbol = ternario();
    if (p < t.length) throw new Error(`Sobra «${t[p].v}»`);
    return arbol;
  }

  const esFecha = (v) => v instanceof Date;
  const aFecha = (v) => (esFecha(v) ? v : typeof v === 'string' && /^\d{4}-\d{2}-\d{2}/.test(v) ? new Date(`${v.slice(0, 10)}T00:00:00`) : null);
  const vacio = (v) => v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));
  const num = (v) => (vacio(v) ? 0 : typeof v === 'boolean' ? (v ? 1 : 0) : esFecha(v) ? v.getTime() : Number(v));
  const txt = (v) => (vacio(v) ? '' : esFecha(v) ? v.toISOString().slice(0, 10) : typeof v === 'boolean' ? (v ? 'Sí' : 'No') : String(v));
  const DIA = 86400000;
  const unidad = (u) => ({ dias: DIA, días: DIA, days: DIA, semanas: 7 * DIA, weeks: 7 * DIA, horas: 3600000, hours: 3600000, meses: 30.4375 * DIA, months: 30.4375 * DIA, años: 365.25 * DIA, anios: 365.25 * DIA, years: 365.25 * DIA })[String(u || 'dias').toLowerCase()] || DIA;
  const FUNCIONES = {
    if: (c, a, b) => (c ? a : b),
    round: (x, d = 0) => { const f = 10 ** num(d); return Math.round(num(x) * f) / f; },
    abs: (x) => Math.abs(num(x)), floor: (x) => Math.floor(num(x)), ceil: (x) => Math.ceil(num(x)), sqrt: (x) => Math.sqrt(num(x)), pow: (x, y) => num(x) ** num(y),
    min: (...a) => Math.min(...a.map(num)), max: (...a) => Math.max(...a.map(num)),
    sum: (...a) => a.reduce((s, x) => s + num(x), 0), avg: (...a) => (a.length ? a.reduce((s, x) => s + num(x), 0) / a.length : 0),
    concat: (...a) => a.map(txt).join(''), length: (x) => txt(x).length, upper: (x) => txt(x).toUpperCase(), lower: (x) => txt(x).toLowerCase(),
    contains: (x, y) => txt(x).toLowerCase().includes(txt(y).toLowerCase()), replace: (x, a, b) => txt(x).split(txt(a)).join(txt(b)),
    empty: (x) => vacio(x), number: (x) => num(x), text: (x) => txt(x),
    and: (...a) => a.every(Boolean), or: (...a) => a.some(Boolean), not: (x) => !x,
    now: () => new Date(), today: () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; },
    year: (d) => aFecha(d)?.getFullYear() ?? null, month: (d) => (aFecha(d) ? aFecha(d).getMonth() + 1 : null), day: (d) => aFecha(d)?.getDate() ?? null,
    datediff: (a, b, u) => { const x = aFecha(a), y = aFecha(b); return x && y ? Math.floor((x - y) / unidad(u)) : null; },
    dateadd: (d, n, u) => { const x = aFecha(d); return x ? new Date(x.getTime() + num(n) * unidad(u)) : null; },
    formatdate: (d) => { const x = aFecha(d); return x ? x.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : ''; },
  };
  function evaluar(n, ctx) {
    if ('val' in n) return n.val;
    if ('prop' in n) return ctx.prop(n.prop);
    if ('si' in n) return evaluar(n.si, ctx) ? evaluar(n.a, ctx) : evaluar(n.b, ctx);
    if (n.fn) {
      if (n.fn === 'prop') return ctx.prop(txt(evaluar(n.args[0], ctx)));
      if (n.fn === 'if') return evaluar(n.args[0], ctx) ? evaluar(n.args[1], ctx) : (n.args[2] ? evaluar(n.args[2], ctx) : '');
      const f = FUNCIONES[n.fn];
      if (!f) throw new Error(`Función desconocida: ${n.fn}`);
      return f(...n.args.map((a) => evaluar(a, ctx)));
    }
    if (n.op === 'no') return !evaluar(n.a, ctx);
    if (n.op === 'neg') return -num(evaluar(n.a, ctx));
    const a = evaluar(n.a, ctx), b = evaluar(n.b, ctx);
    switch (n.op) {
      case '+': return typeof a === 'string' || typeof b === 'string' ? txt(a) + txt(b) : num(a) + num(b);
      case '-': return esFecha(a) && esFecha(b) ? (a - b) / DIA : num(a) - num(b);
      case '*': return num(a) * num(b);
      case '/': return num(b) === 0 ? null : num(a) / num(b);
      case '%': return num(a) % num(b);
      case '^': return num(a) ** num(b);
      case '<': return num(a) < num(b);
      case '>': return num(a) > num(b);
      case '<=': return num(a) <= num(b);
      case '>=': return num(a) >= num(b);
      case '==': return txt(a) === txt(b);
      case '!=': return txt(a) !== txt(b);
      case '&&': return Boolean(a) && Boolean(b);
      case '||': return Boolean(a) || Boolean(b);
      default: throw new Error('Operación no válida');
    }
  }
  const cache = new Map();
  return {
    analizar,
    /** Calcula la fórmula de una columna para una fila. Devuelve {valor} o {error}. */
    calcular(expresion, prop) {
      if (!expresion) return { valor: null };
      try {
        if (!cache.has(expresion)) cache.set(expresion, analizar(expresion));
        const v = evaluar(cache.get(expresion), { prop });
        return { valor: typeof v === 'number' && !Number.isFinite(v) ? null : v };
      } catch (e) { return { error: e.message }; }
    },
    texto: txt,
  };
})();

/* ---------------- Extensiones de la tabla ---------------- */
// Bdp se inicia en DOMContentLoaded (espacio.js): la extensión corre justo después
const extenderBdp = () => {
  if (typeof Bdp === 'undefined' || !Bdp.raiz) {
    extenderGraficasDeNotas();
    return;
  }
  TIPO_ICONO.formula = 'flujo';
  TIPO_ICONO.paciente = 'usuario';
  CONDICIONES.formula = CONDICIONES.texto;
  CONDICIONES.paciente = CONDICIONES.texto;
  const D = Bdp.datos;
  D.pacientes = D.pacientes || {};
  D.vistas = D.vistas?.length ? D.vistas : [{ id: 'v1', tipo: 'tabla', nombre: 'Tabla' }];
  const claveActiva = `expediente-bdp-vista-activa-${USUARIO_ID}-${PAGINA_ID}`;
  let activa = (() => { try { return localStorage.getItem(claveActiva); } catch (e) { return null; } })();
  if (!D.vistas.some((v) => v.id === activa)) activa = D.vistas[0].id;
  const vistaActiva = () => D.vistas.find((v) => v.id === activa) || D.vistas[0];
  const nombrePaciente = (id) => (D.pacientes[id] ? D.pacientes[id][0] : id ? 'Paciente sin acceso' : '');
  const errores = new Map();   // "fila:col" → mensaje de error de su fórmula

  // Valor de una columna por nombre (para prop()), con fórmulas encadenadas y freno a los ciclos
  function valorPorNombre(fila, nombre, pila = []) {
    const col = D.columnas.find((c) => normalizarTexto(c.nombre) === normalizarTexto(nombre));
    if (!col) throw new Error(`No hay una columna «${nombre}»`);
    if (col.tipo === 'formula') {
      if (pila.includes(col.id)) throw new Error('La fórmula se refiere a sí misma');
      const r = Formulas.calcular(col.expresion, (n) => valorPorNombre(fila, n, [...pila, col.id]));
      if (r.error) throw new Error(r.error);
      return r.valor;
    }
    const v = fila.valores[col.id];
    if (col.tipo === 'paciente') return nombrePaciente(v);
    return v ?? null;
  }
  function calcularFormulas() {
    errores.clear();
    const formulas = D.columnas.filter((c) => c.tipo === 'formula');
    D.filas.forEach((f) => formulas.forEach((c) => {
      const r = Formulas.calcular(c.expresion, (n) => valorPorNombre(f, n, [c.id]));
      if (r.error) errores.set(`${f.id}:${c.id}`, r.error);
      // Fecha → texto AAAA-MM-DD para ordenar y filtrar como las demás
      f.valores[c.id] = r.error ? null : r.valor instanceof Date ? r.valor.toISOString().slice(0, 10) : r.valor;
    }));
  }

  /* --- Formato y celdas --- */
  const formatoOriginal = Bdp.formato.bind(Bdp);
  Bdp.formato = function (col, v) {
    if (col.tipo === 'paciente') return nombrePaciente(v);
    if (col.tipo === 'formula') {
      if (v === null || v === undefined || v === '') return '';
      if (typeof v === 'boolean') return v ? 'Sí' : 'No';
      if (typeof v === 'number') return v.toLocaleString('es-MX', { maximumFractionDigits: 2 });
      if (/^\d{4}-\d{2}-\d{2}$/.test(String(v))) return formatoOriginal({ tipo: 'fecha' }, v);
      return String(v);
    }
    return formatoOriginal(col, v);
  };
  const celdaOriginal = Bdp.celdaHtml.bind(Bdp);
  Bdp.celdaHtml = function (col, fila, primera) {
    let html;
    if (col.tipo === 'paciente') {
      const id = fila.valores[col.id];
      html = `<td data-col="${col.id}" class="bdp__celda">${id ? `<a class="bdp__paciente" href="${BASE}/pacientes/ver.php?id=${Number(id)}" title="Abrir el expediente">${ico('usuario')}<span>${escaparHtml(nombrePaciente(id))}</span></a>` : ''}</td>`;
    } else if (col.tipo === 'formula') {
      const error = errores.get(`${fila.id}:${col.id}`);
      const v = fila.valores[col.id];
      html = `<td data-col="${col.id}" class="bdp__celda bdp__celda--formula${typeof v === 'number' ? ' bdp__celda--numero' : ''}"${error ? ` title="${escaparHtml(error)}"` : ''}>${error ? '<span class="bdp__error">#ERROR</span>' : escaparHtml(this.formato(col, v))}</td>`;
    } else {
      html = celdaOriginal(col, fila, primera);
    }
    // La primera celda abre la página de la fila
    if (primera) html = html.replace(/<\/td>$/, `<button type="button" class="bdp__abrir" data-fila-abrir title="Abrir como página">${ico('expandir') || ico('documento')}<span>Abrir</span></button></td>`);
    return html;
  };

  // Filtros y búsqueda sobre el nombre del paciente, no su id
  const cumpleOriginal = Bdp.cumpleFiltro.bind(Bdp);
  Bdp.cumpleFiltro = function (f, fila) {
    const col = this.columna(f.col);
    if (col?.tipo === 'paciente') return cumpleOriginal(f, { ...fila, valores: { ...fila.valores, [col.id]: nombrePaciente(fila.valores[col.id]) } });
    return cumpleOriginal(f, fila);
  };

  /* --- Edición: fórmula (editor de la columna) y paciente (selector) --- */
  const editarOriginal = Bdp.editarCelda.bind(Bdp);
  Bdp.editarCelda = function (celda) {
    const col = this.columna(celda.dataset.col);
    if (col.tipo === 'formula') { editorFormula(celda, col); return; }
    if (col.tipo === 'paciente') { selectorPaciente(celda, Number(celda.closest('tr').dataset.fila), col); return; }
    editarOriginal(celda);
  };
  const agregarOriginal = Bdp.agregarColumna.bind(Bdp);
  Bdp.agregarColumna = async function (tipo, texto, posicion = null) {
    await agregarOriginal(tipo, texto, posicion);
    if (tipo === 'formula') {
      const col = D.columnas[D.columnas.length - 1];
      cerrarPopover();
      const th = this.raiz.querySelector(`th[data-col="${col.id}"]`);
      if (th) editorFormula(th, col);
    }
  };

  function editorFormula(ancla, col) {
    if (!Bdp.editable) return;
    const caja = document.createElement('div');
    caja.className = 'formula-editor';
    const nombres = D.columnas.filter((c) => c.id !== col.id).map((c) => c.nombre);
    caja.innerHTML = `
      <div class="formula-editor__cabecera">${ico('flujo')}<span>Fórmula · ${escaparHtml(col.nombre)}</span></div>
      <textarea class="formula-editor__entrada" spellcheck="false" rows="3" placeholder='Ej. prop("Existencias") * prop("Costo unitario")'>${escaparHtml(col.expresion || '')}</textarea>
      <div class="formula-editor__estado" data-formula-estado></div>
      <div class="formula-editor__ayuda">
        <div class="formula-editor__grupo">Columnas</div>
        <div class="formula-editor__chips">${nombres.map((n) => `<button type="button" data-insertar='prop("${escaparHtml(n)}")'>${escaparHtml(n)}</button>`).join('')}</div>
        <div class="formula-editor__grupo">Funciones</div>
        <div class="formula-editor__chips">${['if( , , )', 'round( , 1)', 'sum( , )', 'avg( , )', 'min( , )', 'max( , )', 'concat( , )', 'datediff( , today(), "dias")', 'dateadd( , 30, "dias")', 'empty( )', 'formatdate( )', 'year( )', 'contains( , "")'].map((f) => `<button type="button" data-insertar="${escaparHtml(f)}">${escaparHtml(f.split('(')[0])}</button>`).join('')}</div>
        <p class="formula-editor__nota">Operadores: + − × ÷ (* /), ^, %, comparaciones (&lt; &gt; == !=), y (&&), o (||). Texto entre comillas.</p>
      </div>
      <div class="formula-editor__acciones"><button type="button" class="boton boton--secundario" data-formula-cancelar>Cancelar</button><button type="button" class="boton boton--primario" data-formula-guardar>Guardar</button></div>`;
    abrirPopover(ancla, caja, { clase: 'popover--formula' });
    const entrada = caja.querySelector('textarea');
    const estado = caja.querySelector('[data-formula-estado]');
    const probar = () => {
      const muestra = D.filas[0];
      if (!entrada.value.trim()) { estado.textContent = 'Escribe una fórmula.'; estado.className = 'formula-editor__estado'; return; }
      try {
        Formulas.analizar(entrada.value);
        const r = muestra ? Formulas.calcular(entrada.value, (n) => valorPorNombre(muestra, n, [col.id])) : { valor: null };
        if (r.error) throw new Error(r.error);
        estado.textContent = muestra ? `Primera fila: ${Formulas.texto(r.valor instanceof Date ? r.valor.toISOString().slice(0, 10) : r.valor) || '(vacío)'}` : 'Fórmula válida.';
        estado.className = 'formula-editor__estado formula-editor__estado--ok';
      } catch (e) { estado.textContent = e.message; estado.className = 'formula-editor__estado formula-editor__estado--error'; }
    };
    probar();
    entrada.addEventListener('input', probar);
    entrada.focus();
    caja.addEventListener('click', async (ev) => {
      const ins = ev.target.closest('[data-insertar]');
      if (ins) {
        const [a, b] = [entrada.selectionStart, entrada.selectionEnd];
        entrada.setRangeText(ins.dataset.insertar, a, b, 'end');
        entrada.focus();
        probar();
      }
      if (ev.target.closest('[data-formula-cancelar]')) cerrarPopover();
      if (ev.target.closest('[data-formula-guardar]')) {
        try {
          const r = await Bdp.accion({ accion: 'columna_cambiar', columna: col.id, expresion: entrada.value });
          Object.assign(col, r.columna);
          cerrarPopover();
          Bdp.pintar();
          marcarEdicionAhora();
        } catch (e) { mostrarNotificacion('error', e.message); }
      }
    });
    entrada.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) caja.querySelector('[data-formula-guardar]').click(); });
  }

  function selectorPaciente(ancla, filaId, col, alElegir = null) {
    if (!Bdp.editable) return;
    const lista = Object.entries(D.pacientes);
    const caja = document.createElement('div');
    caja.className = 'seleccion-editor';
    caja.innerHTML = '<input type="text" class="menu-flotante__entrada" placeholder="Busca por nombre o folio" aria-label="Paciente"><div class="menu-flotante seleccion-editor__lista"></div>';
    const entrada = caja.querySelector('input');
    const contenedor = caja.querySelector('.seleccion-editor__lista');
    const actual = Bdp.fila(filaId)?.valores[col.id];
    const pintar = () => {
      const q = normalizarTexto(entrada.value.trim());
      const vistos = lista.filter(([, [n, folio]]) => !q || coincideBusqueda(`${n} ${folio}`, q)).slice(0, 40);
      contenedor.innerHTML = (lista.length ? '<div class="menu-flotante__titulo">Paciente</div>' : '<div class="menu-flotante__titulo">No hay pacientes o no tienes acceso al expediente</div>')
        + vistos.map(([id, [n, folio]]) => `<button type="button" data-paciente="${id}">${ico('usuario')}<span>${escaparHtml(n)}</span><small>${escaparHtml(folio)}</small>${Number(id) === Number(actual) ? SVG_MARCA : ''}</button>`).join('')
        + (actual ? `<div class="menu-flotante__separador"></div><button type="button" data-paciente="">${ico('cerrar')}<span>Quitar</span></button>` : '');
    };
    pintar();
    abrirPopover(ancla, caja, { clase: 'popover--menu' });
    entrada.focus();
    entrada.addEventListener('input', pintar);
    entrada.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') { ev.preventDefault(); contenedor.querySelector('[data-paciente]')?.click(); } });
    contenedor.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-paciente]');
      if (!b) return;
      cerrarPopover();
      await Bdp.guardarCelda(filaId, col.id, b.dataset.paciente || null);
      alElegir?.();
    });
  }

  /* --- Cálculos al pie --- */
  const CALCULOS = {
    contar: ['Contar todas', () => true], llenos: ['Contar llenos', () => true], vacios: ['Contar vacíos', () => true], porcentaje_llenos: ['% llenos', () => true], unicos: ['Valores únicos', () => true],
    suma: ['Suma', (t) => t === 'numero' || t === 'formula'], promedio: ['Promedio', (t) => t === 'numero' || t === 'formula'], mediana: ['Mediana', (t) => t === 'numero' || t === 'formula'],
    minimo: ['Mínimo', (t) => ['numero', 'formula', 'fecha'].includes(t)], maximo: ['Máximo', (t) => ['numero', 'formula', 'fecha'].includes(t)], rango: ['Rango', (t) => t === 'numero' || t === 'formula'],
    marcadas: ['Marcadas', (t) => t === 'casilla'], sin_marcar: ['Sin marcar', (t) => t === 'casilla'], porcentaje_marcadas: ['% marcadas', (t) => t === 'casilla'],
  };
  function calcularPie(col, filas) {
    const vals = filas.map((f) => f.valores[col.id]);
    const lleno = (v) => !(v === null || v === undefined || v === '' || v === false);
    const nums = vals.filter((v) => typeof v === 'number' || (v !== '' && v !== null && !Number.isNaN(Number(v)) && typeof v !== 'boolean')).map(Number);
    const fmt = (n) => (Number.isFinite(n) ? n.toLocaleString('es-MX', { maximumFractionDigits: 2 }) : '—');
    switch (col.calculo) {
      case 'contar': return fmt(vals.length);
      case 'llenos': return fmt(vals.filter(lleno).length);
      case 'vacios': return fmt(vals.filter((v) => !lleno(v)).length);
      case 'porcentaje_llenos': return vals.length ? `${fmt((vals.filter(lleno).length / vals.length) * 100)} %` : '—';
      case 'unicos': return fmt(new Set(vals.filter(lleno).map(String)).size);
      case 'suma': return fmt(nums.reduce((s, n) => s + n, 0));
      case 'promedio': return nums.length ? fmt(nums.reduce((s, n) => s + n, 0) / nums.length) : '—';
      case 'mediana': { if (!nums.length) return '—'; const o = [...nums].sort((a, b) => a - b); const m = Math.floor(o.length / 2); return fmt(o.length % 2 ? o[m] : (o[m - 1] + o[m]) / 2); }
      case 'minimo': case 'maximo': {
        if (col.tipo === 'fecha') { const f = vals.filter(lleno).sort(); return f.length ? Bdp.formato(col, col.calculo === 'minimo' ? f[0] : f[f.length - 1]) : '—'; }
        return nums.length ? fmt(col.calculo === 'minimo' ? Math.min(...nums) : Math.max(...nums)) : '—';
      }
      case 'rango': return nums.length ? fmt(Math.max(...nums) - Math.min(...nums)) : '—';
      case 'marcadas': return fmt(vals.filter(Boolean).length);
      case 'sin_marcar': return fmt(vals.filter((v) => !v).length);
      case 'porcentaje_marcadas': return vals.length ? `${fmt((vals.filter(Boolean).length / vals.length) * 100)} %` : '—';
      default: return '';
    }
  }
  function pintarPie() {
    const tabla = Bdp.raiz.querySelector('.bdp-tabla');
    if (!tabla) return;
    const filas = Bdp.filasVisibles();
    const cols = Bdp.columnasVisibles();
    const pie = document.createElement('tfoot');
    pie.innerHTML = `<tr>${cols.map((c) => `<td data-col-calculo="${c.id}" class="bdp__calculo${c.calculo ? ' bdp__calculo--activo' : ''}"><button type="button" title="Calcular">${c.calculo ? `<small>${CALCULOS[c.calculo][0]}</small><span>${escaparHtml(calcularPie(c, filas))}</span>` : '<small>Calcular</small>'}</button></td>`).join('')}${Bdp.editable ? '<td></td><td></td>' : ''}</tr>`;
    tabla.appendChild(pie);
  }
  function menuCalculo(boton, col) {
    abrirMenu(boton, [
      { titulo: `Calcular · ${col.nombre}` },
      ...Object.entries(CALCULOS).filter(([, [, aplica]]) => aplica(col.tipo)).map(([k, [texto]]) => ({ texto, marcado: col.calculo === k, accion: () => guardarCalculo(col, k) })),
      'separador',
      { icono: 'cerrar', texto: 'Ninguno', accion: () => guardarCalculo(col, null) },
    ], { clase: 'menu-bdp' });
  }
  async function guardarCalculo(col, calculo) {
    col.calculo = calculo || undefined;
    Bdp.pintar();
    if (!Bdp.editable) return;
    try { await Bdp.accion({ accion: 'columna_cambiar', columna: col.id, calculo }); } catch (e) { mostrarNotificacion('error', e.message); }
  }

  /* --- Vistas: pestañas Tabla / Gráfica --- */
  async function guardarVistas() {
    if (!Bdp.editable) return;
    try { D.vistas = (await Bdp.accion({ accion: 'vistas', vistas: D.vistas })).vistas; } catch (e) { mostrarNotificacion('error', e.message); }
  }
  function pintarPestanas() {
    const lugar = Bdp.raiz.querySelector('.bdp__vista');
    if (!lugar) return;
    const pestanas = document.createElement('div');
    pestanas.className = 'bdp__vistas';
    pestanas.setAttribute('role', 'tablist');
    pestanas.innerHTML = D.vistas.map((v) => `<button type="button" role="tab" class="bdp__pestana" aria-selected="${v.id === activa}" data-vista-id="${v.id}">${ico(v.tipo === 'grafica' ? 'grafica' : 'tabla')}<span>${escaparHtml(v.nombre)}</span></button>`).join('')
      + (Bdp.editable ? `<button type="button" class="bdp__pestana bdp__pestana--nueva" data-vista-nueva title="Agregar una vista">${ico('agregar')}</button>` : '');
    lugar.replaceWith(pestanas);
  }
  function datosGrafica(g) {
    const agrupar = Bdp.columna(g.agrupar) || D.columnas[0];
    const valorCol = g.columna ? Bdp.columna(g.columna) : null;
    const grupos = new Map();
    Bdp.filasVisibles().forEach((f) => {
      let clave = agrupar.tipo === 'casilla' ? (f.valores[agrupar.id] ? 'Sí' : 'No') : Bdp.formato(agrupar, f.valores[agrupar.id]);
      if (!clave) { if (g.ocultar_vacios) return; clave = 'Sin valor'; }
      if (!grupos.has(clave)) grupos.set(clave, []);
      if (g.valor === 'conteo' || !valorCol) grupos.get(clave).push(1);
      else { const n = Number(f.valores[valorCol.id]); if (Number.isFinite(n)) grupos.get(clave).push(n); }
    });
    let pares = [...grupos.entries()].map(([k, l]) => [k, g.valor === 'conteo' || !valorCol ? l.length
      : g.valor === 'suma' ? l.reduce((s, n) => s + n, 0)
      : g.valor === 'promedio' ? (l.length ? Math.round((l.reduce((s, n) => s + n, 0) / l.length) * 100) / 100 : 0)
      : g.valor === 'minimo' ? (l.length ? Math.min(...l) : 0) : (l.length ? Math.max(...l) : 0)]);
    pares.sort(g.orden === 'nombre' ? (a, b) => String(a[0]).localeCompare(String(b[0]), 'es', { numeric: true }) : (a, b) => b[1] - a[1]);
    pares = pares.slice(0, 40);
    const nombreValor = g.valor === 'conteo' || !valorCol ? 'Filas' : `${{ suma: 'Suma', promedio: 'Promedio', minimo: 'Mínimo', maximo: 'Máximo' }[g.valor]} de ${valorCol.nombre}`;
    return { tipo: g.tipo, titulo: g.titulo || `${nombreValor} por ${agrupar.nombre}`, categorias: pares.map((p) => p[0]), series: [{ nombre: nombreValor, valores: pares.map((p) => p[1]) }] };
  }
  function pintarVistaGrafica(v) {
    const marco = Bdp.raiz.querySelector('.bdp__marco');
    Bdp.raiz.querySelectorAll('.bdp__nueva-fila, .bdp__vacio').forEach((x) => x.remove());
    const g = v.grafica || (v.grafica = { tipo: 'barras', agrupar: D.columnas[0].id, valor: 'conteo', columna: null, titulo: '', orden: 'valor' });
    const opcionesCol = (filtro, sel, vacia) => (vacia ? `<option value="">${vacia}</option>` : '') + D.columnas.filter(filtro).map((c) => `<option value="${c.id}"${c.id === sel ? ' selected' : ''}>${escaparHtml(c.nombre)}</option>`).join('');
    const zona = document.createElement('div');
    zona.className = 'bdp-grafica';
    zona.innerHTML = `
      <div class="bdp-grafica__lienzo" data-grafica-lienzo-bd></div>
      <aside class="bdp-grafica__ajustes"${Bdp.editable ? '' : ' hidden'}>
        <div class="bdp-grafica__titulo-ajustes">Ajustes de la gráfica</div>
        <label class="bdp-ajuste"><span>Título</span><input type="text" data-g="titulo" value="${escaparHtml(g.titulo || '')}" placeholder="Automático" maxlength="120"></label>
        <label class="bdp-ajuste"><span>Tipo</span><select data-g="tipo">${Object.entries(NOMBRES_GRAFICA).map(([k, n]) => `<option value="${k}"${k === g.tipo ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
        <label class="bdp-ajuste"><span>Agrupar por</span><select data-g="agrupar">${opcionesCol((c) => c.tipo !== 'formula' || true, g.agrupar)}</select></label>
        <label class="bdp-ajuste"><span>Valor</span><select data-g="valor">${[['conteo', 'Contar filas'], ['suma', 'Suma'], ['promedio', 'Promedio'], ['minimo', 'Mínimo'], ['maximo', 'Máximo']].map(([k, n]) => `<option value="${k}"${k === g.valor ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
        <label class="bdp-ajuste"${g.valor === 'conteo' ? ' hidden' : ''} data-ajuste-columna><span>De la columna</span><select data-g="columna">${opcionesCol((c) => c.tipo === 'numero' || c.tipo === 'formula', g.columna, 'Elige una columna numérica')}</select></label>
        <label class="bdp-ajuste"><span>Ordenar</span><select data-g="orden"><option value="valor"${g.orden !== 'nombre' ? ' selected' : ''}>De mayor a menor</option><option value="nombre"${g.orden === 'nombre' ? ' selected' : ''}>Por nombre</option></select></label>
        <label class="bdp-ajuste bdp-ajuste--casilla"><input type="checkbox" data-g="ocultar_vacios"${g.ocultar_vacios ? ' checked' : ''}><span>Ocultar «Sin valor»</span></label>
        <p class="bdp-grafica__nota">Usa los mismos filtros y búsqueda de la tabla.</p>
        <div class="bdp-grafica__acciones"><button type="button" class="boton boton--secundario" data-vista-renombrar>Renombrar</button><button type="button" class="boton boton--secundario" data-vista-quitar${D.vistas.length < 2 ? ' disabled' : ''}>Quitar vista</button></div>
      </aside>`;
    marco.replaceWith(zona);
    const lienzo = zona.querySelector('[data-grafica-lienzo-bd]');
    const montar = () => {
      lienzo.innerHTML = '';
      const def = datosGrafica(g);
      if (!def.categorias.length) { lienzo.innerHTML = '<p class="bdp__vacio">No hay datos que graficar con estos ajustes.</p>'; return; }
      if (typeof Graficas === 'undefined' || !Graficas.montar(lienzo, def)) lienzo.innerHTML = '<p class="bdp__vacio">No se pudo dibujar la gráfica.</p>';
    };
    montar();
    let temporizador = null;
    zona.addEventListener('change', (ev) => {
      const campo = ev.target.closest('[data-g]');
      if (!campo) return;
      g[campo.dataset.g] = campo.type === 'checkbox' ? campo.checked : (campo.value || null);
      zona.querySelector('[data-ajuste-columna]').hidden = g.valor === 'conteo';
      if (g.valor !== 'conteo' && !g.columna) g.columna = D.columnas.find((c) => c.tipo === 'numero' || c.tipo === 'formula')?.id || null;
      montar();
      clearTimeout(temporizador);
      temporizador = setTimeout(guardarVistas, 500);
    });
    zona.querySelector('[data-g="titulo"]')?.addEventListener('input', (ev) => { g.titulo = ev.target.value; montar(); clearTimeout(temporizador); temporizador = setTimeout(guardarVistas, 700); });
  }

  /* --- Página de la fila (panel lateral) --- */
  function abrirFila(filaId) {
    const fila = Bdp.fila(filaId);
    if (!fila) return;
    document.querySelector('.bdp-pagina')?.remove();
    const panel = document.createElement('aside');
    panel.className = 'bdp-pagina';
    panel.setAttribute('aria-label', 'Página de la fila');
    const titulo = D.columnas[0];
    const pintar = () => {
      calcularFormulas();
      const ed = Bdp.editable;
      const pacienteId = D.columnas.filter((c) => c.tipo === 'paciente').map((c) => fila.valores[c.id]).find(Boolean);
      panel.innerHTML = `
        <div class="bdp-pagina__barra">
          <button type="button" class="boton-icono" data-pagina-cerrar title="Cerrar (Esc)">${ico('doble_derecha') || ico('cerrar')}</button>
          <span class="bdp-pagina__ruta">${escaparHtml(document.body.dataset.titulo || 'Base de datos')}</span>
          ${pacienteId ? `<a class="boton boton--secundario bdp-pagina__expediente" href="${BASE}/pacientes/ver.php?id=${Number(pacienteId)}">${ico('usuario')} Abrir expediente</a>` : ''}
        </div>
        <div class="bdp-pagina__cuerpo">
          <input class="bdp-pagina__titulo" data-pagina-titulo value="${escaparHtml(fila.valores[titulo.id] ?? '')}" placeholder="Sin título" ${ed ? '' : 'readonly'}>
          <div class="bdp-pagina__propiedades">
            ${D.columnas.slice(1).map((c) => `
              <div class="bdp-propiedad" data-prop-col="${c.id}">
                <span class="bdp-propiedad__nombre">${ico(TIPO_ICONO[c.tipo])}<span>${escaparHtml(c.nombre)}</span></span>
                <span class="bdp-propiedad__valor">${valorPropiedad(c, fila, ed)}</span>
              </div>`).join('')}
          </div>
          <div class="bdp-pagina__separador"></div>
          <textarea class="bdp-pagina__notas" data-pagina-notas placeholder="${ed ? 'Escribe notas de esta fila…' : 'Sin notas.'}" ${ed ? '' : 'readonly'}>${escaparHtml(fila.notas || '')}</textarea>
        </div>`;
      const notas = panel.querySelector('[data-pagina-notas]');
      const ajustar = () => { notas.style.height = 'auto'; notas.style.height = `${Math.max(160, notas.scrollHeight)}px`; };
      ajustar();
      notas.addEventListener('input', ajustar);
    };
    const valorPropiedad = (c, f, ed) => {
      const v = f.valores[c.id];
      if (c.tipo === 'formula') { const e = errores.get(`${f.id}:${c.id}`); return `<span class="bdp-propiedad__fijo${e ? ' bdp__error' : ''}" title="${escaparHtml(e || c.expresion || '')}">${e ? '#ERROR' : escaparHtml(Bdp.formato(c, v)) || '<span class="bdp-propiedad__vacio">Vacío</span>'}</span>`; }
      if (c.tipo === 'paciente') return `<button type="button" class="bdp-propiedad__boton" data-prop-paciente ${ed ? '' : 'disabled'}>${v ? `${ico('usuario')}<span>${escaparHtml(nombrePaciente(v))}</span>` : '<span class="bdp-propiedad__vacio">Vacío</span>'}</button>`;
      if (c.tipo === 'casilla') return `<span class="bloque__casilla" role="checkbox" aria-checked="${v ? 'true' : 'false'}" data-prop-casilla></span>`;
      if (c.tipo === 'seleccion') { const o = (c.opciones || []).find((x) => x.nombre === v); return `<button type="button" class="bdp-propiedad__boton" data-prop-seleccion ${ed ? '' : 'disabled'}>${v ? `<span class="opcion opcion--${o?.color || 'gris'}">${escaparHtml(v)}</span>` : '<span class="bdp-propiedad__vacio">Vacío</span>'}</button>`; }
      const tipo = c.tipo === 'numero' ? 'number' : c.tipo === 'fecha' ? 'date' : 'text';
      return `<input class="bdp-propiedad__entrada" type="${tipo}" ${tipo === 'number' ? 'step="any"' : ''} value="${escaparHtml(v ?? '')}" placeholder="Vacío" data-prop-entrada ${ed ? '' : 'readonly'}>`;
    };
    pintar();
    document.body.appendChild(panel);
    requestAnimationFrame(() => panel.classList.add('abierta'));
    const guardar = async (colId, valor) => { await Bdp.guardarCelda(filaId, colId, valor); pintar(); };
    let tNotas = null;
    panel.addEventListener('input', (ev) => {
      if (ev.target.matches('[data-pagina-notas]')) {
        fila.notas = ev.target.value;
        clearTimeout(tNotas);
        tNotas = setTimeout(() => Bdp.accion({ accion: 'fila_notas', fila: filaId, notas: fila.notas }).then(marcarEdicionAhora).catch((e) => mostrarNotificacion('error', e.message)), 600);
      }
    });
    panel.addEventListener('change', (ev) => {
      if (ev.target.matches('[data-pagina-titulo]')) guardar(titulo.id, ev.target.value);
      const prop = ev.target.closest('[data-prop-col]');
      if (prop && ev.target.matches('[data-prop-entrada]')) guardar(prop.dataset.propCol, ev.target.value);
    });
    panel.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-pagina-cerrar]')) { cerrar(); return; }
      if (!Bdp.editable) return;
      const prop = ev.target.closest('[data-prop-col]');
      if (!prop) return;
      const col = Bdp.columna(prop.dataset.propCol);
      if (ev.target.closest('[data-prop-casilla]')) guardar(col.id, !fila.valores[col.id]);
      if (ev.target.closest('[data-prop-paciente]')) selectorPaciente(ev.target.closest('[data-prop-paciente]'), filaId, col, pintar);
      if (ev.target.closest('[data-prop-seleccion]')) {
        abrirMenu(ev.target.closest('[data-prop-seleccion]'), [
          ...(col.opciones || []).map((o) => ({ texto: o.nombre, marcado: fila.valores[col.id] === o.nombre, accion: () => guardar(col.id, o.nombre) })),
          'separador', { icono: 'cerrar', texto: 'Quitar', accion: () => guardar(col.id, '') },
        ], { clase: 'menu-bdp' });
      }
    });
    const cerrar = () => { panel.classList.remove('abierta'); setTimeout(() => panel.remove(), 220); document.removeEventListener('keydown', alTeclado); };
    const alTeclado = (ev) => { if (ev.key === 'Escape' && !document.querySelector('.popover')) cerrar(); };
    document.addEventListener('keydown', alTeclado);
  }

  /* --- Galería de plantillas (base vacía) --- */
  function pintarPlantillas() {
    if (!Bdp.editable || D.filas.length || D.columnas.length > 1 || !D.plantillas) return;
    const zona = document.createElement('div');
    zona.className = 'bdp-plantillas';
    const ICONOS = { expedientes: 'usuarios', pendientes: 'casilla', inventario: 'pildora', cobros: 'tarjeta', indicadores: 'tendencia' };
    zona.innerHTML = `<div class="bdp-plantillas__titulo">Empieza con una plantilla o arma tus propias columnas</div>
      <div class="bdp-plantillas__lista">
        ${Object.entries(D.plantillas).map(([k, [t, d]]) => `<button type="button" class="bdp-plantilla" data-plantilla="${k}"><span class="bdp-plantilla__icono">${ico(ICONOS[k]) || ico('tabla')}</span><span class="bdp-plantilla__texto"><strong>${escaparHtml(t)}</strong><small>${escaparHtml(d)}</small></span></button>`).join('')}
        <button type="button" class="bdp-plantilla bdp-plantilla--blanco" data-col-nueva-plantilla><span class="bdp-plantilla__icono">${ico('agregar')}</span><span class="bdp-plantilla__texto"><strong>En blanco</strong><small>Agrega tus propias propiedades: texto, número, fecha, selección, casilla, paciente o fórmula.</small></span></button>
      </div>`;
    Bdp.raiz.querySelector('.bdp__barra')?.after(zona);
  }

  /* --- Vista de formulario: una fila a la vez, con campos editables --- */
  let filaFormulario = null;
  function pintarVistaFormulario(v) {
    const marco = Bdp.raiz.querySelector('.bdp__marco');
    Bdp.raiz.querySelectorAll('.bdp__nueva-fila, .bdp__vacio').forEach((x) => x.remove());
    const filas = Bdp.filasVisibles();
    const primera = D.columnas[0];
    if (!filas.some((f) => f.id === filaFormulario)) filaFormulario = filas[0] ? filas[0].id : null;
    const fila = filas.find((f) => f.id === filaFormulario) || null;
    const etiqueta = (f) => (primera && Bdp.formato(primera, f.valores[primera.id])) || 'Sin título';
    const opcionesFila = filas.map((f) => `<option value="${f.id}"${fila && f.id === fila.id ? ' selected' : ''}>${escaparHtml(etiqueta(f))}</option>`).join('');
    const campo = (col) => {
      if (!fila) return '';
      const valor = fila.valores[col.id];
      if (col.tipo === 'formula') {
        return `<div class="bdp-form__campo"><span>${escaparHtml(col.nombre)}</span><output>${escaparHtml(Bdp.formato(col, valor) || '—')}</output></div>`;
      }
      if (col.tipo === 'paciente') {
        return `<div class="bdp-form__campo"><span>${escaparHtml(col.nombre)}</span><output>${escaparHtml(Bdp.formato(col, valor) || '—')}</output></div>`;
      }
      if (col.tipo === 'casilla') {
        return `<label class="bdp-form__campo"><span>${escaparHtml(col.nombre)}</span><input type="checkbox" data-form-col="${col.id}"${valor ? ' checked' : ''}></label>`;
      }
      if (col.tipo === 'seleccion') {
        const opciones = (col.opciones || []).map((o) => `<option value="${escaparHtml(o.nombre)}"${valor === o.nombre ? ' selected' : ''}>${escaparHtml(o.nombre)}</option>`).join('');
        return `<label class="bdp-form__campo"><span>${escaparHtml(col.nombre)}</span><select data-form-col="${col.id}"><option value="">—</option>${opciones}</select></label>`;
      }
      const tipo = col.tipo === 'fecha' ? 'date' : (col.tipo === 'numero' ? 'number' : 'text');
      const formato = col.tipo === 'fecha' ? String(valor || '').slice(0, 10) : (valor ?? '');
      return `<label class="bdp-form__campo"><span>${escaparHtml(col.nombre)}</span><input type="${tipo}" data-form-col="${col.id}" value="${escaparHtml(String(formato))}"></label>`;
    };
    const zona = document.createElement('div');
    zona.className = 'bdp-form';
    zona.innerHTML = `
      <div class="bdp-tablero__barra">
        ${filas.length ? `<label class="bdp-ajuste"><span>Fila</span><select data-form-fila>${opcionesFila}</select></label>` : ''}
        ${Bdp.editable ? '<button type="button" class="boton boton--sutil" data-form-nueva>Nueva fila</button>' : ''}
        <span class="bdp-grafica__acciones"><button type="button" class="boton boton--secundario" data-vista-renombrar>Renombrar</button><button type="button" class="boton boton--secundario" data-vista-quitar${D.vistas.length < 2 ? ' disabled' : ''}>Quitar vista</button></span>
      </div>
      ${fila ? `<div class="bdp-form__cuadricula">${D.columnas.map(campo).join('')}</div><p class="bdp-grafica__nota">Los cambios se guardan al salir de cada campo.</p>` : '<p class="bdp__vacio">Todavía no hay filas. Crea una para capturarla aquí.</p>'}`;
    marco.replaceWith(zona);
    zona.querySelector('[data-form-fila]')?.addEventListener('change', (ev) => {
      filaFormulario = Number(ev.target.value) || null;
      Bdp.pintar();
    });
    zona.querySelector('[data-form-nueva]')?.addEventListener('click', () => Bdp.nuevaFila());
    zona.querySelector('[data-vista-renombrar]')?.addEventListener('click', () => renombrarVista(zona.querySelector('[data-vista-renombrar]'), v));
    zona.querySelector('[data-vista-quitar]')?.addEventListener('click', () => quitarVista(v));
    if (!fila) return;
    zona.querySelectorAll('[data-form-col]').forEach((control) => {
      control.addEventListener('change', async () => {
        const col = Bdp.columna(control.dataset.formCol);
        if (!col) return;
        const valor = col.tipo === 'casilla' ? control.checked : (control.value === '' ? null : control.value);
        try {
          await Bdp.accion({ accion: 'celda', fila: fila.id, columna: col.id, valor });
          fila.valores[col.id] = valor;
          if (col.tipo === 'formula') Bdp.pintar();
        } catch (e) { mostrarNotificacion('error', e.message); }
      });
    });
  }
  /* --- Vista de calendario: filas por una propiedad de fecha --- */
  let mesCalendario = new Date();
  function pintarVistaCalendario(v) {
    const marco = Bdp.raiz.querySelector('.bdp__marco');
    Bdp.raiz.querySelectorAll('.bdp__nueva-fila, .bdp__vacio').forEach((x) => x.remove());
    const c = v.calendario || (v.calendario = { columna: null, titulo: '' });
    const fechas = D.columnas.filter((col) => col.tipo === 'fecha');
    const col = fechas.find((col) => col.id === c.columna) || fechas[0] || null;
    const base = new Date(mesCalendario.getFullYear(), mesCalendario.getMonth(), 1);
    const inicio = new Date(base);
    inicio.setDate(1 - ((base.getDay() + 6) % 7));   // la semana empieza en lunes
    const porDia = new Map();
    if (col) {
      Bdp.filasVisibles().forEach((fila) => {
        const valor = fila.valores[col.id];
        if (!valor) return;
        const d = new Date(String(valor).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return;
        const clave = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
        if (!porDia.has(clave)) porDia.set(clave, []);
        porDia.get(clave).push(fila);
      });
    }
    const primera = D.columnas[0];
    const nombreMes = base.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
    const hoy = new Date();
    let dias = '';
    for (let i = 0; i < 42; i++) {
      const d = new Date(inicio);
      d.setDate(inicio.getDate() + i);
      const clave = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
      const filas = porDia.get(clave) || [];
      const otraFecha = d.getMonth() !== base.getMonth();
      const esHoy = d.getFullYear() === hoy.getFullYear() && d.getMonth() === hoy.getMonth() && d.getDate() === hoy.getDate();
      dias += `<div class="bdp-cal__dia${otraFecha ? ' bdp-cal__dia--fuera' : ''}${esHoy ? ' bdp-cal__dia--hoy' : ''}">
        <span class="bdp-cal__numero">${d.getDate()}</span>
        ${filas.map((fila) => `<button type="button" class="bdp-cal__evento" data-cal-fila="${fila.id}">${escaparHtml(Bdp.formato(primera, fila.valores[primera.id]) || 'Sin título')}</button>`).join('')}
      </div>`;
    }
    const zona = document.createElement('div');
    zona.className = 'bdp-cal';
    zona.innerHTML = `
      <div class="bdp-tablero__barra">
        ${fechas.length ? `<label class="bdp-ajuste"><span>Fecha</span><select data-cal-col>${fechas.map((f2) => `<option value="${f2.id}"${col && f2.id === col.id ? ' selected' : ''}>${escaparHtml(f2.nombre)}</option>`).join('')}</select></label>` : ''}
        <button type="button" class="boton boton--sutil" data-cal-mes="-1" aria-label="Mes anterior">‹</button>
        <b class="bdp-cal__mes">${escaparHtml(nombreMes)}</b>
        <button type="button" class="boton boton--sutil" data-cal-mes="1" aria-label="Mes siguiente">›</button>
        <button type="button" class="boton boton--sutil" data-cal-hoy>Hoy</button>
        <span class="bdp-grafica__acciones"><button type="button" class="boton boton--secundario" data-vista-renombrar>Renombrar</button><button type="button" class="boton boton--secundario" data-vista-quitar${D.vistas.length < 2 ? ' disabled' : ''}>Quitar vista</button></span>
      </div>
      ${fechas.length ? `<div class="bdp-cal__semana">${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].map((d) => `<span>${d}</span>`).join('')}</div><div class="bdp-cal__cuadricula">${dias}</div>` : '<p class="bdp__vacio">Crea una propiedad de fecha para usar el calendario.</p>'}`;
    marco.replaceWith(zona);
    zona.querySelector('[data-cal-col]')?.addEventListener('change', (ev) => {
      c.columna = ev.target.value || null;
      Bdp.pintar();
      guardarVistas();
    });
    zona.querySelectorAll('[data-cal-mes]').forEach((b) => b.addEventListener('click', () => {
      mesCalendario = new Date(mesCalendario.getFullYear(), mesCalendario.getMonth() + Number(b.dataset.calMes), 1);
      Bdp.pintar();
    }));
    zona.querySelector('[data-cal-hoy]')?.addEventListener('click', () => { mesCalendario = new Date(); Bdp.pintar(); });
    zona.querySelector('[data-vista-renombrar]')?.addEventListener('click', () => renombrarVista(zona.querySelector('[data-vista-renombrar]'), v));
    zona.querySelector('[data-vista-quitar]')?.addEventListener('click', () => quitarVista(v));
    zona.querySelectorAll('[data-cal-fila]').forEach((b) => b.addEventListener('click', () => {
      const filaId = Number(b.dataset.calFila);
      if (Bdp.menuFila) Bdp.menuFila(b, filaId, {}, null);
    }));
  }
  /* --- Vista de tablero: columnas por una propiedad de selección --- */
  function pintarVistaTablero(v) {
    const marco = Bdp.raiz.querySelector('.bdp__marco');
    Bdp.raiz.querySelectorAll('.bdp__nueva-fila, .bdp__vacio').forEach((x) => x.remove());
    const t = v.tablero || (v.tablero = { columna: null, titulo: '' });
    const seleccionables = D.columnas.filter((c) => c.tipo === 'seleccion');
    const col = seleccionables.find((c) => c.id === t.columna) || seleccionables[0] || null;
    const opciones = col ? (col.opciones || []).map((o) => o.nombre) : [];
    const grupos = new Map(opciones.map((nombre) => [nombre, []]));
    const sinValor = [];
    Bdp.filasVisibles().forEach((fila) => {
      const valor = col ? String(fila.valores[col.id] || '') : '';
      if (valor && grupos.has(valor)) grupos.get(valor).push(fila); else sinValor.push(fila);
    });
    const tarjeta = (fila) => {
      const primera = D.columnas[0];
      const texto = (primera && Bdp.formato(primera, fila.valores[primera.id])) || 'Sin título';
      const fecha = D.columnas.find((c) => c.tipo === 'fecha');
      const fechaTexto = fecha && fila.valores[fecha.id] ? Bdp.formato(fecha, fila.valores[fecha.id]) : '';
      return `<article class="bdp-tarjeta" data-tablero-fila="${fila.id}"${Bdp.editable && col ? ' draggable="true"' : ''}>
        <b>${escaparHtml(texto)}</b>${fechaTexto ? `<small>${escaparHtml(fechaTexto)}</small>` : ''}</article>`;
    };
    const columnaHtml = (nombre, filas) => `<section class="bdp-tablero__columna">
        <header class="bdp-tablero__cabecera"><h3>${escaparHtml(nombre || 'Sin valor')}</h3><small>${filas.length}</small></header>
        <div class="bdp-tablero__tarjetas" data-tablero-zona="${escaparHtml(nombre)}">${filas.map(tarjeta).join('') || '<p class="bdp-tablero__vacio">Suelta aquí</p>'}</div>
      </section>`;
    const zona = document.createElement('div');
    zona.className = 'bdp-tablero';
    zona.innerHTML = `
      <div class="bdp-tablero__barra">
        ${seleccionables.length ? `<label class="bdp-ajuste"><span>Agrupar por</span><select data-tablero-col>${seleccionables.map((c) => `<option value="${c.id}"${col && c.id === col.id ? ' selected' : ''}>${escaparHtml(c.nombre)}</option>`).join('')}</select></label>` : '<p class="bdp__vacio">Crea una propiedad de selección para usar el tablero.</p>'}
        ${Bdp.editable && col ? '<span class="bdp-grafica__nota">Arrastra una tarjeta a otra columna para cambiar su valor.</span>' : ''}
        <span class="bdp-grafica__acciones"><button type="button" class="boton boton--secundario" data-vista-renombrar>Renombrar</button><button type="button" class="boton boton--secundario" data-vista-quitar${D.vistas.length < 2 ? ' disabled' : ''}>Quitar vista</button></span>
      </div>
      <div class="bdp-tablero__columnas">${[...grupos.entries()].map(([nombre, filas]) => columnaHtml(nombre, filas)).join('')}${sinValor.length ? columnaHtml('', sinValor) : ''}</div>`;
    marco.replaceWith(zona);
    zona.querySelector('[data-tablero-col]')?.addEventListener('change', (ev) => {
      t.columna = ev.target.value || null;
      Bdp.pintar();
      guardarVistas();
    });
    zona.querySelector('[data-vista-renombrar]')?.addEventListener('click', () => renombrarVista(zona.querySelector('[data-vista-renombrar]'), v));
    zona.querySelector('[data-vista-quitar]')?.addEventListener('click', () => quitarVista(v));
    if (!Bdp.editable || !col) return;
    zona.addEventListener('dragstart', (ev) => {
      const tarjeta = ev.target.closest('[data-tablero-fila]');
      if (!tarjeta) return;
      tarjeta.dataset.arrastrando = '1';
      ev.dataTransfer.setData('text/plain', tarjeta.dataset.tableroFila);
      ev.dataTransfer.effectAllowed = 'move';
    });
    zona.addEventListener('dragend', () => {
      zona.querySelectorAll('[data-arrastrando]').forEach((t) => t.removeAttribute('data-arrastrando'));
    });
    zona.querySelectorAll('[data-tablero-zona]').forEach((destino) => {
      destino.addEventListener('dragover', (ev) => { ev.preventDefault(); destino.classList.add('bdp-tablero__tarjetas--activa'); });
      destino.addEventListener('dragleave', () => destino.classList.remove('bdp-tablero__tarjetas--activa'));
      destino.addEventListener('drop', async (ev) => {
        ev.preventDefault();
        destino.classList.remove('bdp-tablero__tarjetas--activa');
        const filaId = Number(ev.dataTransfer.getData('text/plain'));
        if (!filaId) return;
        const valor = destino.dataset.tableroZona || null;
        try {
          await Bdp.accion({ accion: 'celda', fila: filaId, columna: col.id, valor });
          const fila = D.filas.find((f) => f.id === filaId);
          if (fila) fila.valores[col.id] = valor;
          Bdp.pintar();
        } catch (e) { mostrarNotificacion('error', e.message); }
      });
    });
  }
  /* --- Pintado completo --- */
  const pintarOriginal = Bdp.pintar.bind(Bdp);
  Bdp.pintar = function () {
    calcularFormulas();
    pintarOriginal();
    pintarPestanas();
    const v = vistaActiva();
    if (v.tipo === 'grafica') pintarVistaGrafica(v);
    else if (v.tipo === 'tablero') pintarVistaTablero(v);
    else if (v.tipo === 'calendario') pintarVistaCalendario(v);
    else if (v.tipo === 'formulario') pintarVistaFormulario(v);
    else { pintarPie(); pintarPlantillas(); }
  };

  Bdp.raiz.addEventListener('click', async (ev) => {
    // El enlace del paciente abre su expediente (sin entrar a editar la celda)
    if (ev.target.closest('a.bdp__paciente')) { ev.stopPropagation(); return; }
    const abrir = ev.target.closest('[data-fila-abrir]');
    if (abrir) { ev.stopPropagation(); abrirFila(Number(abrir.closest('tr').dataset.fila)); return; }
    const calculo = ev.target.closest('[data-col-calculo] button');
    if (calculo) { ev.stopPropagation(); menuCalculo(calculo, Bdp.columna(calculo.closest('td').dataset.colCalculo)); return; }
    const pestana = ev.target.closest('[data-vista-id]');
    if (pestana) {
      ev.stopPropagation();
      if (pestana.dataset.vistaId === activa && Bdp.editable) { menuVista(pestana, vistaActiva()); return; }
      activa = pestana.dataset.vistaId;
      try { localStorage.setItem(claveActiva, activa); } catch (e) { /* opcional */ }
      Bdp.pintar();
      return;
    }
    const nueva = ev.target.closest('[data-vista-nueva]');
    if (nueva) {
      ev.stopPropagation();
      abrirMenu(nueva, [{ titulo: 'Nueva vista' },
        { icono: 'tabla', texto: 'Tabla', accion: () => agregarVista('tabla') },
        { icono: 'grafica', texto: 'Gráfica', detalle: 'barras, líneas, área, dona', accion: () => agregarVista('grafica') },
        { icono: 'tabla', texto: 'Tablero', detalle: 'agrupa por una selección', accion: () => agregarVista('tablero') },
        { icono: 'calendario', texto: 'Calendario', detalle: 'por una fecha', accion: () => agregarVista('calendario') },
        { icono: 'documento', texto: 'Formulario', detalle: 'captura fila por fila', accion: () => agregarVista('formulario') }], { clase: 'menu-bdp' });
      return;
    }
    const plantilla = ev.target.closest('[data-plantilla]');
    if (plantilla) {
      ev.stopPropagation();
      plantilla.classList.add('bdp-plantilla--cargando');
      try { await Bdp.accion({ accion: 'plantilla', plantilla: plantilla.dataset.plantilla }); location.reload(); } catch (e) { mostrarNotificacion('error', e.message); plantilla.classList.remove('bdp-plantilla--cargando'); }
      return;
    }
    if (ev.target.closest('[data-col-nueva-plantilla]')) { ev.stopPropagation(); Bdp.menuNuevaColumna(ev.target.closest('[data-col-nueva-plantilla]')); return; }
    if (ev.target.closest('[data-vista-renombrar]')) { ev.stopPropagation(); renombrarVista(ev.target.closest('[data-vista-renombrar]'), vistaActiva()); return; }
    if (ev.target.closest('[data-vista-quitar]')) { ev.stopPropagation(); quitarVista(vistaActiva()); }
  }, true);

  function agregarVista(tipo) {
    const id = `v${Date.now().toString(36).slice(-6)}`;
    const numero = D.columnas.find((c) => c.tipo === 'numero' || c.tipo === 'formula');
    const agrupar = D.columnas.find((c) => c.tipo === 'seleccion') || D.columnas.find((c) => c.tipo === 'paciente' || c.tipo === 'fecha') || D.columnas[0];
    const vista = { id, tipo, nombre: tipo === 'grafica' ? 'Gráfica' : (tipo === 'tablero' ? 'Tablero' : (tipo === 'calendario' ? 'Calendario' : (tipo === 'formulario' ? 'Formulario' : 'Tabla'))) };
    if (tipo === 'tablero') {
      const sel = D.columnas.find((c) => c.tipo === 'seleccion');
      vista.tablero = { columna: sel ? sel.id : null, titulo: '' };
    }
    if (tipo === 'calendario') {
      const fecha = D.columnas.find((c) => c.tipo === 'fecha');
      vista.calendario = { columna: fecha ? fecha.id : null, titulo: '' };
    }
    if (tipo === 'grafica') vista.grafica = { tipo: 'barras', agrupar: agrupar.id, valor: numero && agrupar !== numero ? 'conteo' : 'conteo', columna: numero?.id || null, titulo: '', orden: 'valor' };
    D.vistas.push(vista);
    activa = id;
    try { localStorage.setItem(claveActiva, activa); } catch (e) { /* opcional */ }
    Bdp.pintar();
    guardarVistas();
  }
  function menuVista(ancla, v) {
    abrirMenu(ancla, [{ titulo: v.nombre },
      { icono: 'editar', texto: 'Renombrar', accion: () => renombrarVista(ancla, v) },
      D.vistas.length > 1 && { icono: 'papelera', texto: 'Quitar vista', accion: () => quitarVista(v) }], { clase: 'menu-bdp' });
  }
  function renombrarVista(ancla, v) {
    const caja = document.createElement('div');
    caja.className = 'menu-flotante';
    caja.innerHTML = `<input type="text" class="menu-flotante__entrada" value="${escaparHtml(v.nombre)}" maxlength="40" aria-label="Nombre de la vista">`;
    abrirPopover(ancla, caja, { clase: 'popover--menu' });
    const entrada = caja.querySelector('input');
    entrada.select();
    entrada.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') { v.nombre = entrada.value.trim() || v.nombre; cerrarPopover(); Bdp.pintar(); guardarVistas(); }
      if (ev.key === 'Escape') cerrarPopover();
    });
  }
  function quitarVista(v) {
    if (D.vistas.length < 2) return;
    D.vistas = D.vistas.filter((x) => x !== v);
    activa = D.vistas[0].id;
    Bdp.pintar();
    guardarVistas();
  }

  Bdp.pintar();
  extenderGraficasDeNotas();
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', extenderBdp);
else extenderBdp();

/* ---------------- Gráficas de las notas: editar títulos y datos ---------------- */
function extenderGraficasDeNotas() {
  if (typeof Editor === 'undefined' || !Editor.itemsGrafica || Editor.__graficasEditables) return;
  Editor.__graficasEditables = true;
  const itemsOriginal = Editor.itemsGrafica.bind(Editor);
  Editor.itemsGrafica = function (bloque) {
    const items = itemsOriginal(bloque);
    if (!this.editable) return items;
    return [{ icono: 'editar', texto: 'Editar título y datos', accion: () => editorGrafica(bloque) }, ...items];
  };
  function editorGrafica(bloque) {
    let g = {};
    try { g = JSON.parse(bloque.dataset.g || '{}'); } catch (e) { /* sin datos */ }
    const series = g.series?.length ? g.series : [{ nombre: 'Serie 1', valores: [] }];
    const categorias = g.categorias?.length ? g.categorias : ['A'];
    const caja = document.createElement('div');
    caja.className = 'grafica-editor';
    const filaHtml = (c, i) => `<tr><td><input type="text" value="${escaparHtml(c)}" data-cat="${i}"></td>${series.map((s, j) => `<td><input type="number" step="any" value="${s.valores[i] ?? ''}" data-val="${i}:${j}"></td>`).join('')}<td><button type="button" class="boton-icono" data-quitar-fila="${i}" title="Quitar">${ico('cerrar')}</button></td></tr>`;
    caja.innerHTML = `
      <div class="grafica-editor__cabecera">${ico('grafica')}<span>Editar gráfica</span></div>
      <label class="bdp-ajuste"><span>Título</span><input type="text" data-titulo value="${escaparHtml(g.titulo || '')}" maxlength="120"></label>
      <label class="bdp-ajuste"><span>Subtítulo</span><input type="text" data-subtitulo value="${escaparHtml(g.subtitulo || '')}" maxlength="200"></label>
      <div class="grafica-editor__tabla"><table>
        <thead><tr><th>Categoría</th>${series.map((s, j) => `<th><input type="text" value="${escaparHtml(s.nombre)}" data-serie="${j}"></th>`).join('')}<th></th></tr></thead>
        <tbody>${categorias.map(filaHtml).join('')}</tbody>
      </table></div>
      <div class="grafica-editor__acciones">
        <button type="button" class="boton boton--secundario" data-agregar-fila>${ico('agregar')} Fila</button>
        <button type="button" class="boton boton--secundario" data-agregar-serie>${ico('agregar')} Serie</button>
        <span class="bdp__espacio"></span>
        <button type="button" class="boton boton--primario" data-guardar-grafica>Aplicar</button>
      </div>`;
    abrirPopover(bloque.querySelector('.bloque__grafica') || bloque, caja, { clase: 'popover--grafica-editor' });
    const leer = () => {
      const cats = [...caja.querySelectorAll('[data-cat]')].map((x) => x.value.trim() || '—');
      const nombres = [...caja.querySelectorAll('[data-serie]')].map((x) => x.value.trim() || 'Serie');
      return { titulo: caja.querySelector('[data-titulo]').value, subtitulo: caja.querySelector('[data-subtitulo]').value, categorias: cats,
        series: nombres.map((nombre, j) => ({ nombre, valores: cats.map((_, i) => { const v = caja.querySelector(`[data-val="${i}:${j}"]`)?.value; return v === '' || v === undefined ? null : Number(v); }) })) };
    };
    caja.addEventListener('click', (ev) => {
      if (ev.target.closest('[data-guardar-grafica]')) { Editor.cambiarGrafica(bloque, leer()); cerrarPopover(); return; }
      const actual = leer();
      if (ev.target.closest('[data-agregar-fila]')) { actual.categorias.push(`Categoría ${actual.categorias.length + 1}`); actual.series.forEach((s) => s.valores.push(null)); }
      else if (ev.target.closest('[data-agregar-serie]')) { actual.series.push({ nombre: `Serie ${actual.series.length + 1}`, valores: actual.categorias.map(() => null) }); }
      else if (ev.target.closest('[data-quitar-fila]')) { const i = Number(ev.target.closest('[data-quitar-fila]').dataset.quitarFila); if (actual.categorias.length > 1) { actual.categorias.splice(i, 1); actual.series.forEach((s) => s.valores.splice(i, 1)); } }
      else return;
      bloque.dataset.g = JSON.stringify({ ...g, ...actual });
      cerrarPopover();
      editorGrafica(bloque);
    });
  }
}
