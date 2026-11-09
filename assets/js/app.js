/* ==========================================================================
   Expedientes Clínicos — comportamiento común
   ========================================================================== */

/* ---------- CURP ---------- */
const CURP_REGEX = /^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HMX](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/;

/** Calcula el dígito verificador (posición 18) según RENAPO. */
function digitoVerificadorCurp(curp17) {
  const diccionario = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
  let suma = 0;
  for (let i = 0; i < 17; i++) {
    suma += diccionario.indexOf(curp17.charAt(i)) * (18 - i);
  }
  return (10 - (suma % 10)) % 10;
}

function curpValida(curp) {
  if (!CURP_REGEX.test(curp)) return false;
  return digitoVerificadorCurp(curp.slice(0, 17)) === Number(curp.charAt(17));
}

/** Extrae fecha de nacimiento, sexo y entidad de una CURP válida. */
function datosDesdeCurp(curp) {
  const aa = curp.slice(4, 6);
  const siglo = /\d/.test(curp.charAt(16)) ? '19' : '20';
  return {
    fecha: `${siglo}${aa}-${curp.slice(6, 8)}-${curp.slice(8, 10)}`,
    sexo: curp.charAt(10),
    entidad: curp.slice(11, 13),
  };
}

/* ---------- Utilidades de formulario ---------- */
function marcarError(campoEl, mensaje) {
  const campo = campoEl.closest('.campo');
  if (!campo) return;
  campo.classList.toggle('campo--error', Boolean(mensaje));
  let ayuda = campo.querySelector('.campo__ayuda');
  if (mensaje) {
    if (!ayuda) {
      ayuda = document.createElement('span');
      ayuda.className = 'campo__ayuda';
      campo.appendChild(ayuda);
    }
    if (!ayuda.dataset.original) ayuda.dataset.original = ayuda.textContent;
    ayuda.textContent = mensaje;
  } else if (ayuda && ayuda.dataset.original !== undefined) {
    ayuda.textContent = ayuda.dataset.original;
  }
}

function rellenarSiVacio(el, valor) {
  if (el && !el.value) {
    el.value = valor;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

/* Fechas vacías con color de placeholder */
function actualizarFechasVacias() {
  document.querySelectorAll('input[type="date"]').forEach((el) => {
    el.classList.toggle('vacio', !el.value);
  });
}

/* ---------- Barra lateral ---------- */
const PANTALLA_CHICA = window.matchMedia('(max-width: 900px)');

function alternarLateral() {
  const raiz = document.documentElement;
  if (PANTALLA_CHICA.matches) {
    raiz.classList.toggle('lateral-abierta');
    return;
  }
  const oculta = raiz.classList.toggle('lateral-oculta');
  try { localStorage.setItem('lateral-oculta', oculta ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
}

document.addEventListener('click', (ev) => {
  const accion = ev.target.closest('[data-accion]')?.dataset.accion;
  if (accion === 'alternar-lateral') alternarLateral();
  if (accion === 'cerrar-lateral') document.documentElement.classList.remove('lateral-abierta');
});

/* ---------- Tabla de base de datos: filtro inmediato y orden por columna ---------- */
function iniciarBaseDatos(bd) {
  const tabla = bd.querySelector('.bd-tabla');
  const cuerpo = tabla.tBodies[0];
  const buscar = bd.querySelector('[data-bd-buscar]');
  const contador = bd.querySelector('[data-bd-contador]');
  const vacio = bd.querySelector('[data-bd-vacio]');
  const filas = () => Array.from(cuerpo.rows);

  // Filtra mientras se escribe; el botón Buscar consulta la base de datos completa.
  const normalizar = (t) => String(t ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es-MX').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  const coincideTexto = (texto, consulta) => {
    const base = normalizar(texto);
    return !consulta || consulta.split(/\s+/u).filter(Boolean).every((token) => base.includes(token));
  };
  buscar?.addEventListener('input', () => {
    const termino = normalizar(buscar.value.trim());
    let visibles = 0;
    filas().forEach((fila) => {
      const coincide = !termino || coincideTexto(fila.textContent, termino);
      fila.hidden = !coincide;
      if (coincide) visibles++;
    });
    if (contador) contador.textContent = visibles;
    vacio.hidden = visibles > 0 || filas().length === 0;
  });

  tabla.querySelectorAll('thead tr:last-child th').forEach((th, indice) => {
    // Clic en el encabezado: menú de la propiedad (ordenar, renombrar, ocultar) si está disponible
    const btnTh = th.querySelector('button'); if (btnTh) btnTh.addEventListener('click', () => {
      if (window.menuPropiedadPacientes) window.menuPropiedadPacientes(th, (asc) => ordenar(asc));
      else ordenar(th.getAttribute('aria-sort') !== 'ascending');
    });
    const ordenar = (ascendente) => {
      tabla.querySelectorAll('thead tr:last-child th').forEach((otro) => otro.removeAttribute('aria-sort'));
      th.setAttribute('aria-sort', ascendente ? 'ascending' : 'descending');

      const valor = (fila) => {
        const celda = fila.cells[indice];
        const texto = celda.dataset.valor ?? celda.textContent.trim();
        if (th.dataset.orden === 'numero') return Number(texto) || 0;
        if (th.dataset.orden === 'fecha') {
          const fecha = Date.parse(texto);
          return Number.isNaN(fecha) ? null : fecha;
        }
        return texto;
      };
      const ordenadas = filas().sort((a, b) => {
        const x = valor(a);
        const y = valor(b);
        const r = x === null ? (y === null ? 0 : 1) : y === null ? -1
          : typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { sensitivity: 'base', numeric: true });
        return ascendente ? r : -r;
      });
      cuerpo.append(...ordenadas);
    };
  });

  cuerpo.querySelector('tr.nuevo')?.scrollIntoView({ block: 'nearest' });
}

/** Aplica la clase de salida y quita el elemento al terminar (o enseguida si no hay animación). */
function quitarConAnimacion(el, claseSalida) {
  el.classList.add(claseSalida);
  if (el.getAnimations().length === 0) { el.remove(); return; }
  el.addEventListener('animationend', () => el.remove(), { once: true });
  setTimeout(() => el.remove(), 400);
}

/* ---------- Menú de clic izquierdo: tarjeta del paciente ---------- */
const BASE = document.body.dataset.base ?? '';
// Versión de app.js (?v=…): se reutiliza para los scripts que se cargan después
const VERSION_APP = (document.currentScript?.src.match(/[?&]v=(\d+)/) || [])[1] || String(Date.now());
const tarjetasEnCache = new Map();
let menuAbierto = null;

function cerrarMenuFlotante() {
  if (!menuAbierto) return;
  const { tarjeta, fila } = menuAbierto;
  menuAbierto = null;
  fila?.classList.remove('seleccionado');
  quitarConAnimacion(tarjeta, 'cerrando');
}

/** Coloca la tarjeta junto al cursor sin salirse de la ventana. */
function posicionar(tarjeta, x, y) {
  const margen = 12;
  const { width, height } = tarjeta.getBoundingClientRect();
  const izquierda = x + width + margen > innerWidth ? Math.max(margen, x - width) : x;
  const arriba = Math.min(Math.max(margen, y), Math.max(margen, innerHeight - height - margen));
  tarjeta.style.left = `${izquierda}px`;
  tarjeta.style.top = `${arriba}px`;
  tarjeta.style.setProperty('--origen', `${x - izquierda}px ${y - arriba}px`);
}

async function abrirMenuPaciente(fila, x, y) {
  const id = fila.dataset.paciente;
  if (menuAbierto?.fila === fila) { cerrarMenuFlotante(); return; }
  cerrarMenuFlotante();

  let html = tarjetasEnCache.get(id);
  if (!html) {
    const respuesta = await fetch(`${BASE}/api/pacientes/tarjeta.php?id=${encodeURIComponent(id)}`);
    if (!respuesta.ok) return;
    html = await respuesta.text();
    tarjetasEnCache.set(id, html);
  }

  const plantilla = document.createElement('template');
  plantilla.innerHTML = html.trim();
  const tarjeta = plantilla.content.firstElementChild;
  tarjeta.setAttribute('role', 'dialog');
  document.body.appendChild(tarjeta);
  posicionar(tarjeta, x, y);

  fila.classList.add('seleccionado');
  menuAbierto = { tarjeta, fila };
  tarjeta.querySelector('.menu__opcion')?.focus({ preventScroll: true });
}

document.addEventListener('click', (ev) => {
  // Opciones aún no disponibles: no navegan
  const deshabilitada = ev.target.closest('.menu__opcion[aria-disabled="true"]');
  if (deshabilitada) { ev.preventDefault(); return; }

  // Clic en el nombre: abre el menú del paciente (Ctrl/⌘ + clic abre la ficha en otra pestaña)
  const celdaTitulo = ev.target.closest('.bd-tabla td.bd-tabla__celda-titulo');
  if (celdaTitulo && !(ev.ctrlKey || ev.metaKey || ev.shiftKey)) {
    ev.preventDefault();
    abrirMenuPaciente(celdaTitulo.closest('tr'), ev.clientX, ev.clientY);
    return;
  }

  // Clic en una celda editable: se edita en línea
  const editable = ev.target.closest('.bd-tabla td[data-campo]');
  if (editable) {
    cerrarMenuFlotante();
    if (!editable.classList.contains('editando')) editarCelda(editable);
    return;
  }

  // Los menús que salen de la tarjeta (foto, acciones) viven fuera de ella:
  // usarlos no debe cerrarla ni cortar la acción en curso.
  if (menuAbierto && !menuAbierto.tarjeta.contains(ev.target) && !ev.target.closest('.popover')
      && !ev.target.matches('[data-portada-archivo-global]')) cerrarMenuFlotante();
});

/* ---------- Edición en línea de la tabla (edición en línea) ---------- */
const OPCIONES_EDITOR = JSON.parse(document.getElementById('opciones-editor')?.textContent || '{}');
const TOKEN_CSRF = document.querySelector('meta[name="csrf"]')?.content ?? '';
let celdaEditando = null;

function editarCelda(td) {
  if (celdaEditando) terminarEdicion(true);

  const campo = td.dataset.campo;
  const opciones = OPCIONES_EDITOR[campo];
  const original = td.dataset.valor ?? '';
  let control;

  if (opciones) {
    control = document.createElement('select');
    control.add(new Option('—', ''));
    Object.entries(opciones).forEach(([valor, texto]) => control.add(new Option(texto, valor)));
    control.value = original;
    control.addEventListener('change', () => terminarEdicion(true));
  } else {
    control = document.createElement('input');
    control.type = 'text';
    control.value = original;
    if (campo === 'medicamentos_actuales') {
      control.dataset.catalogo = 'medicamentos';
      control.setAttribute('role', 'combobox');
      control.setAttribute('aria-expanded', 'false');
      control.setAttribute('aria-autocomplete', 'list');
    }
    if (campo === 'curp') {
      control.maxLength = 18;
      control.spellcheck = false;
      control.addEventListener('input', () => { control.value = control.value.toUpperCase(); });
    }
  }

  control.className = 'bd-tabla__editor';
  control.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter') {
      if (control.dataset.catalogo && control.getAttribute('aria-expanded') === 'true') { ev.preventDefault(); return; }
      ev.preventDefault(); terminarEdicion(true);
    }
    if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); terminarEdicion(false); }
    if (ev.key === 'Tab') {
      ev.preventDefault();
      const siguiente = celdaVecina(td, ev.shiftKey ? -1 : 1);
      terminarEdicion(true);
      if (siguiente) editarCelda(siguiente);
    }
  });
  control.addEventListener('blur', () => { if (celdaEditando === td) terminarEdicion(true); });

  td._edicion = { original, textoOriginal: td.textContent, control };
  td.classList.add('editando');
  td.textContent = '';
  td.appendChild(control);
  if (control.dataset.catalogo) window.inicializarCatalogoClinico?.(control);
  control.focus();
  if (control.select) control.select();
  celdaEditando = td;
}

/** Celda editable anterior/siguiente en orden de lectura (para Tab). */
function celdaVecina(td, paso) {
  const celdas = Array.from(td.closest('tbody').querySelectorAll('tr:not([hidden]) td[data-campo]'));
  return celdas[celdas.indexOf(td) + paso] ?? null;
}

async function terminarEdicion(guardar) {
  const td = celdaEditando;
  if (!td) return;
  celdaEditando = null;

  const { original, textoOriginal, control } = td._edicion;
  const campo = td.dataset.campo;
  const valor = control.value.trim();
  td.classList.remove('editando');

  if (!guardar || valor === original) {
    td.textContent = textoOriginal;
    return;
  }

  // Muestra el valor mientras se guarda; si falla, se restaura el anterior
  td.textContent = OPCIONES_EDITOR[campo] ? (OPCIONES_EDITOR[campo][valor] ?? '') : valor;
  td.classList.add('guardando');

  try {
    // La tabla indica a qué API pertenece: pacientes por omisión; los catálogos del sistema usan la suya
    const tabla = td.closest('table');
    const fila = td.closest('tr');
    const cuerpo = { id: fila.dataset.id ?? fila.dataset.paciente, campo, valor };
    if (tabla?.dataset.entidad) cuerpo.entidad = tabla.dataset.entidad;
    else if (tabla?.dataset.catalogo) cuerpo.catalogo = tabla.dataset.catalogo;
    const respuesta = await fetch(tabla?.dataset.actualizar || `${BASE}/api/pacientes/actualizar.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
      body: JSON.stringify(cuerpo),
    });
    const datos = await respuesta.json();
    if (!datos.ok) throw new Error(datos.error);

    td.dataset.valor = datos.valor;
    td.textContent = datos.texto;
    td.title = datos.texto;
    td.classList.remove('guardado');
    void td.offsetWidth; // reinicia la animación
    td.classList.add('guardado');
  } catch (error) {
    td.textContent = textoOriginal;
    mostrarNotificacion('error', error.message || 'No se pudo guardar el cambio.');
  } finally {
    td.classList.remove('guardando');
  }
}

/** Muestra un aviso con el mismo estilo que los del servidor. */
function mostrarNotificacion(tipo, texto) {
  let contenedor = document.querySelector('.notificaciones');
  if (!contenedor) {
    contenedor = document.createElement('div');
    contenedor.className = 'notificaciones';
    contenedor.setAttribute('aria-live', 'polite');
    document.body.appendChild(contenedor);
  }
  const aviso = document.createElement('div');
  aviso.className = `notificacion notificacion--${tipo}`;
  aviso.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
  aviso.innerHTML = '<span></span>';
  aviso.querySelector('span').textContent = texto;
  contenedor.appendChild(aviso);
  programarCierre(aviso);
}

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape') cerrarMenuFlotante();
});
window.addEventListener('resize', cerrarMenuFlotante);
document.addEventListener('scroll', (ev) => {
  if (menuAbierto && !menuAbierto.tarjeta.contains(ev.target)) cerrarMenuFlotante();
}, true);

/* ---------- Paleta de búsqueda (lupa, botón Buscar o Ctrl K) ---------- */
const ICONOS_PALETA = {
  buscar: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
  pagina: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  usuarioMas: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M19 8v6M16 11h6"/>',
  filtro: '<path d="M4 5h16l-6 8v6l-4-2v-4z"/>',
  pildora: '<path d="M10.5 20.5a5 5 0 0 1-7-7l7-7a5 5 0 0 1 7 7z"/><path d="m8.5 8.5 7 7"/>',
  documento: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 13h6M9 17h4"/>',
};
const svgPaleta = (nombre) => `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${ICONOS_PALETA[nombre]}</svg>`;
const escaparHtml = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

let paleta = null;

function abrirPaleta() {
  if (paleta) { paleta.entrada.focus(); return; }
  cerrarMenuFlotante();

  const velo = document.createElement('div');
  velo.className = 'paleta-velo';
  const caja = document.createElement('div');
  caja.className = 'paleta';
  caja.setAttribute('role', 'dialog');
  caja.setAttribute('aria-modal', 'true');
  caja.setAttribute('aria-label', 'Buscar pacientes');
  caja.innerHTML = `
    <div class="paleta__entrada">
      <input type="text" placeholder="Buscar por nombre, CURP o folio…" aria-label="Buscar" autocomplete="off" spellcheck="false"
             role="combobox" aria-expanded="true" aria-controls="paleta-lista">
      <span class="paleta__circulo">${svgPaleta('buscar')}</span>
    </div>
    <div class="paleta__meta">
      <span data-total></span>
      <button type="button" class="paleta__chip" data-orden="recientes"><span>Ordenar por</span><span data-orden-texto>Más recientes</span></button>
    </div>
    <div class="paleta__lista" id="paleta-lista" role="listbox"></div>
    <div class="paleta__pie">
      <span><kbd class="tecla">esc</kbd> Cerrar</span>
    </div>`;
  document.body.append(velo, caja);

  paleta = {
    velo, caja,
    entrada: caja.querySelector('input'),
    lista: caja.querySelector('.paleta__lista'),
    total: caja.querySelector('[data-total]'),
    chip: caja.querySelector('.paleta__chip'),
    elementos: [],
    activo: 0,
    peticion: null,
    temporizador: null,
  };

  paleta.entrada.addEventListener('input', () => {
    clearTimeout(paleta.temporizador);
    paleta.temporizador = setTimeout(buscarEnPaleta, 140);
  });
  paleta.entrada.addEventListener('keydown', teclasPaleta);
  paleta.chip.addEventListener('click', () => {
    const porNombre = paleta.chip.dataset.orden === 'recientes';
    paleta.chip.dataset.orden = porNombre ? 'nombre' : 'recientes';
    paleta.chip.querySelector('[data-orden-texto]').textContent = porNombre ? 'Nombre A–Z' : 'Más recientes';
    buscarEnPaleta();
    paleta.entrada.focus();
  });
  velo.addEventListener('click', cerrarPaleta);
  paleta.lista.addEventListener('mousemove', (ev) => {
    const item = ev.target.closest('.paleta__item');
    if (item) marcarActivo(Number(item.dataset.indice));
  });
  paleta.lista.addEventListener('click', (ev) => {
    const item = ev.target.closest('.paleta__item');
    if (item) { ev.preventDefault(); ejecutarElemento(Number(item.dataset.indice)); }
  });

  paleta.entrada.focus();
  buscarEnPaleta();
}

function cerrarPaleta() {
  if (!paleta) return;
  paleta.peticion?.abort();
  quitarConAnimacion(paleta.velo, 'cerrando');
  quitarConAnimacion(paleta.caja, 'cerrando');
  paleta = null;
}

async function buscarEnPaleta() {
  const termino = paleta.entrada.value.trim();
  paleta.peticion?.abort();
  const control = new AbortController();
  paleta.peticion = control;

  let datosPacientes, datosMedicamentos, datosDiagnosticos;
  try {
    const [r1, r2, r3] = await Promise.all([
      fetch(`${BASE}/api/pacientes/buscar.php?q=${encodeURIComponent(termino)}&orden=${paleta.chip.dataset.orden}`, { signal: control.signal }),
      fetch(`${BASE}/api/medicamentos/buscar.php?q=${encodeURIComponent(termino)}`, { signal: control.signal }),
      fetch(`${BASE}/api/cie10/buscar.php?q=${encodeURIComponent(termino)}`, { signal: control.signal }),
    ]);
    datosPacientes = await r1.json();
    datosMedicamentos = await r2.json();
    datosDiagnosticos = await r3.json();
    if (!r1.ok) throw new Error(datosPacientes.error || 'No se pudo buscar pacientes.');
    if (!r2.ok) throw new Error(datosMedicamentos.error || 'No se pudo buscar medicamentos.');
    if (!r3.ok) throw new Error(datosDiagnosticos.error || 'No se pudo buscar diagnósticos.');
  } catch (error) {
    if (error.name === 'AbortError' || !paleta) return;
    paleta.total.textContent = '';
    paleta.lista.innerHTML = `<p class="paleta__vacio">${escaparHtml(error.message || 'No se pudo buscar.')}</p>`;
    return;
  }
  if (!paleta) return;

  const total = (datosPacientes.total || 0) + (datosMedicamentos.total || 0) + (datosDiagnosticos.total || 0);

  // Acciones y resultados reales, en el orden en que se muestran
  const acciones = [{ tipo: 'accion', icono: 'usuarioMas', titulo: 'Registrar paciente…', url: `${BASE}/pacientes/registro.php` }];
  if (termino) {
    acciones.push({ tipo: 'accion', icono: 'filtro', titulo: `Filtrar la tabla por «${termino}»`, url: `${BASE}/pacientes/?q=${encodeURIComponent(termino)}` });
  }
  const pacientesLocales = (datosPacientes.pacientes || []).map((p) => ({ tipo: 'paciente', ...p, url: `${BASE}/pacientes/ver.php?id=${p.id}` }));
  const pacientesCompartidos = (datosPacientes.compartidos || []).map((p) => ({
    tipo: 'paciente', compartido: true, ...p,
    hace: 'Compartido',
    url: `${BASE}/conexiones/paciente.php?autorizacion=${encodeURIComponent(p.autorizacion)}`,
  }));
  const pacientes = [...pacientesLocales, ...pacientesCompartidos];
  const paginas = (datosPacientes.paginas || []).map((p) => ({ tipo: 'pagina', ...p, url: `${BASE}/paginas/ver.php?id=${p.id}` }));
  const medicamentos = (datosMedicamentos.medicamentos || []).map((m) => ({ tipo: 'medicamento', ...m }));
  const diagnosticos = (datosDiagnosticos.diagnosticos || []).map((d) => ({ tipo: 'diagnostico', ...d }));

  paleta.elementos = [...acciones, ...pacientes, ...medicamentos, ...diagnosticos, ...paginas];
  paleta.activo = 0;

  paleta.total.textContent = total === 1 ? '1 resultado' : `${total} resultados`;

  let html = '<div class="paleta__grupo">Navegación y acciones</div>';
  html += acciones.map((a, i) => `
    <a class="paleta__item" role="option" data-indice="${i}" href="${a.url}">
      ${svgPaleta(a.icono)}<span class="paleta__titulo">${escaparHtml(a.titulo)}</span>
    </a>`).join('');
  html += '<div class="paleta__grupo">Pacientes</div>';
  html += pacientes.length
    ? pacientes.map((p, k) => `
      <a class="paleta__item" role="option" data-indice="${acciones.length + k}" href="${p.url}">
        ${svgPaleta('pagina')}
        <span class="paleta__titulo">${escaparHtml(p.nombre)}</span>
        <span class="paleta__lado"><span class="paleta__iniciales" aria-hidden="true">${escaparHtml(p.iniciales)}</span>${escaparHtml(p.hace)}</span>
        <span class="paleta__detalle">${escaparHtml(p.detalle)}</span>
      </a>`).join('')
    : `<p class="paleta__vacio">${termino ? `Ningún paciente coincide con «${escaparHtml(termino)}».` : 'Aún no hay pacientes registrados.'}</p>`;
  const idxMedicamentos = acciones.length + pacientes.length;
  html += '<div class="paleta__grupo">Medicamentos</div>';
  html += medicamentos.length
    ? medicamentos.map((m, k) => `
      <a class="paleta__item" role="option" data-indice="${idxMedicamentos + k}" href="${m.url}">
        ${svgPaleta('pildora')}
        <span class="paleta__titulo">${escaparHtml(m.nombre)}</span>
        <span class="paleta__detalle">${escaparHtml(m.detalle)}</span>
      </a>`).join('')
    : `<p class="paleta__vacio">${termino ? `Ningún medicamento coincide con «${escaparHtml(termino)}».` : 'No hay medicamentos registrados.'}</p>`;
  const idxDiagnosticos = idxMedicamentos + medicamentos.length;
  html += '<div class="paleta__grupo">Diagnósticos CIE-10</div>';
  html += diagnosticos.length
    ? diagnosticos.map((d, k) => `
      <a class="paleta__item" role="option" data-indice="${idxDiagnosticos + k}" href="${d.url}">
        ${svgPaleta('documento')}
        <span class="paleta__titulo">${escaparHtml(d.nombre)}</span>
        <span class="paleta__detalle">${escaparHtml(d.detalle)}</span>
      </a>`).join('')
    : `<p class="paleta__vacio">${termino ? `Ningún diagnóstico coincide con «${escaparHtml(termino)}».` : 'No hay diagnósticos registrados.'}</p>`;
  if (paginas.length) {
    html += '<div class="paleta__grupo">Páginas</div>';
    html += paginas.map((p, k) => `
      <a class="paleta__item" role="option" data-indice="${acciones.length + pacientes.length + medicamentos.length + diagnosticos.length + k}" href="${p.url}">
        ${typeof ico === 'function' ? ico(p.icono) : svgPaleta('pagina')}
        <span class="paleta__titulo">${escaparHtml(p.titulo)}</span>
        <span class="paleta__lado">${escaparHtml(p.hace)}</span>
        <span class="paleta__detalle">${escaparHtml(p.ruta)}</span>
      </a>`).join('');
  }
  paleta.lista.innerHTML = html;
  marcarActivo(0);
}

function marcarActivo(indice) {
  if (!paleta || !paleta.elementos.length) return;
  paleta.activo = (indice + paleta.elementos.length) % paleta.elementos.length;
  paleta.lista.querySelectorAll('.paleta__item').forEach((item) => {
    const activo = Number(item.dataset.indice) === paleta.activo;
    item.classList.toggle('activo', activo);
    item.setAttribute('aria-selected', activo);
    if (activo) item.scrollIntoView({ block: 'nearest' });
  });
}

function ejecutarElemento(indice) {
  const elemento = paleta?.elementos[indice];
  if (elemento) window.location.href = elemento.url;
}

function teclasPaleta(ev) {
  if (ev.key === 'ArrowDown') { ev.preventDefault(); marcarActivo(paleta.activo + 1); }
  if (ev.key === 'ArrowUp') { ev.preventDefault(); marcarActivo(paleta.activo - 1); }
  if (ev.key === 'Enter') { ev.preventDefault(); ejecutarElemento(paleta.activo); }
  if (ev.key === 'Escape') { ev.preventDefault(); cerrarPaleta(); }
}

document.addEventListener('click', (ev) => {
  if (ev.target.closest('[data-abrir-busqueda]')) { ev.preventDefault(); abrirPaleta(); }
});
document.addEventListener('keydown', (ev) => {
  if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k' && document.body.dataset.base !== undefined
      && !document.body.classList.contains('acceso')) {
    ev.preventDefault();
    paleta ? cerrarPaleta() : abrirPaleta();
  }
});

/* ---------- Acceso: primero el correo, luego se despliega la contraseña ---------- */
function iniciarAccesoEnPasos(form) {
  if (!form) return;
  const desplegable = form.querySelector('[data-acceso-clave]');
  const boton = form.querySelector('button[type="submit"]');
  const correo = form.querySelector('#correo');
  const clave = form.querySelector('#clave');

  (desplegable.classList.contains('abierto') ? clave : correo).focus();

  form.addEventListener('submit', (ev) => {
    if (!desplegable.classList.contains('abierto')) {
      ev.preventDefault();
      if (!correo.value.trim() || !correo.checkValidity()) {
        mostrarNotificacion('error', 'Escribe un correo válido.');
        correo.focus();
        return;
      }
      desplegable.classList.add('abierto');
      boton.textContent = boton.dataset.textoFinal;
      setTimeout(() => clave.focus(), 120);
    } else if (!clave.value) {
      ev.preventDefault();
      clave.focus();
    }
  });
}

/* ---------- Código de verificación: casillas de un dígito ---------- */
function iniciarCodigo(form) {
  if (!form) return;
  const casillas = Array.from(form.querySelectorAll('.codigo__digito'));
  let enviado = false;
  casillas[0].focus();

  const marcar = (c) => c.classList.toggle('lleno', c.value !== '');
  const enviarSiCompleto = () => {
    if (!enviado && casillas.every((c) => /^\d$/.test(c.value))) {
      enviado = true;
      form.requestSubmit();
    }
  };
  // Reparte varios dígitos (pegar o autocompletar) desde la casilla indicada
  const repartir = (desde, texto) => {
    const digitos = texto.replace(/\D/g, '').split('');
    let i = desde;
    digitos.forEach((d) => { if (i < casillas.length) { casillas[i].value = d; marcar(casillas[i]); i++; } });
    casillas[Math.min(i, casillas.length - 1)].focus();
    enviarSiCompleto();
  };

  casillas.forEach((casilla, i) => {
    casilla.addEventListener('input', () => {
      const valor = casilla.value.replace(/\D/g, '');
      if (valor.length > 1) { repartir(i, valor); return; }
      casilla.value = valor;
      marcar(casilla);
      if (valor && i < casillas.length - 1) casillas[i + 1].focus();
      enviarSiCompleto();
    });
    casilla.addEventListener('keydown', (ev) => {
      if (ev.key === 'Backspace' && !casilla.value && i > 0) {
        casillas[i - 1].value = '';
        marcar(casillas[i - 1]);
        casillas[i - 1].focus();
        ev.preventDefault();
      }
      if (ev.key === 'ArrowLeft' && i > 0) casillas[i - 1].focus();
      if (ev.key === 'ArrowRight' && i < casillas.length - 1) casillas[i + 1].focus();
    });
    casilla.addEventListener('paste', (ev) => {
      ev.preventDefault();
      repartir(i, ev.clipboardData.getData('text'));
    });
    casilla.addEventListener('focus', () => casilla.select());
  });

  form.addEventListener('submit', (ev) => {
    if (!casillas.every((c) => /^\d$/.test(c.value))) {
      ev.preventDefault();
      enviado = false;
      mostrarNotificacion('error', 'Escribe los 10 dígitos del código.');
      casillas.find((c) => !c.value)?.focus();
    }
  });
}

/* ---------- Gráfica de actividad: globo con el detalle y recorrido con flechas ---------- */
function iniciarActividad(contenedor) {
  const rejilla = contenedor.querySelector('.actividad__rejilla');
  const globo = contenedor.querySelector('.actividad__globo');
  const semanas = Array.from(rejilla.querySelectorAll('.actividad__semana'));
  const dias = semanas.map((s) => Array.from(s.children));
  let posicion = null; // [semana, día]

  const mostrar = (celda) => {
    if (!celda?.dataset.texto) { globo.hidden = true; return; }
    const caja = contenedor.getBoundingClientRect();
    const r = celda.getBoundingClientRect();
    globo.textContent = celda.dataset.texto;
    globo.style.left = `${r.left - caja.left + r.width / 2}px`;
    globo.style.top = `${r.top - caja.top}px`;
    globo.hidden = false;
  };

  const enfocar = (s, d) => {
    rejilla.querySelector('.enfocado')?.classList.remove('enfocado');
    posicion = [s, d];
    const celda = dias[s][d];
    celda.classList.add('enfocado');
    rejilla.setAttribute('aria-activedescendant', celda.id || (celda.id = `dia-${s}-${d}`));
    mostrar(celda);
  };

  rejilla.addEventListener('mouseover', (ev) => {
    const celda = ev.target.closest('.actividad__dia');
    if (celda) mostrar(celda);
  });
  rejilla.addEventListener('mouseleave', () => { if (!posicion) globo.hidden = true; });

  rejilla.addEventListener('focus', () => {
    // Empieza en el día más reciente con datos (hoy)
    if (!posicion) {
      const s = semanas.length - 1;
      const d = dias[s].findLastIndex((c) => c.dataset.texto);
      enfocar(s, Math.max(0, d));
    } else {
      enfocar(...posicion);
    }
  });
  rejilla.addEventListener('blur', () => {
    rejilla.querySelector('.enfocado')?.classList.remove('enfocado');
    globo.hidden = true;
  });
  rejilla.addEventListener('keydown', (ev) => {
    if (!posicion) return;
    let [s, d] = posicion;
    if (ev.key === 'ArrowRight') s++;
    else if (ev.key === 'ArrowLeft') s--;
    else if (ev.key === 'ArrowDown') d++;
    else if (ev.key === 'ArrowUp') d--;
    else return;
    ev.preventDefault();
    // Solo días existentes (no futuros)
    if (dias[s]?.[d]?.dataset.texto) enfocar(s, d);
  });
}

/* ---------- Barra de pestañas ----------
   Las pestañas viven en sessionStorage (se borran al cerrar el navegador o la sesión),
   porque sus títulos contienen nombres de pacientes. */
const CLAVE_PESTANAS = `expediente-pestanas-${document.body.dataset.usuario ?? ''}`;
const MAX_PESTANAS = 10;

function leerPestanas() {
  try { return JSON.parse(sessionStorage.getItem(CLAVE_PESTANAS)) ?? null; } catch (e) { return null; }
}
function guardarPestanas(estado) {
  try { sessionStorage.setItem(CLAVE_PESTANAS, JSON.stringify(estado)); } catch (e) { /* sin almacenamiento */ }
}
const nuevoId = () => Math.random().toString(36).slice(2, 10);

function iniciarPestanas() {
  const lista = document.querySelector('[data-pestanas-lista]');
  if (!lista) return;

  const actual = { url: location.pathname + location.search, titulo: document.body.dataset.titulo || document.title };
  let estado = leerPestanas();
  if (!estado?.pestanas?.length) {
    estado = { pestanas: [{ id: nuevoId(), ...actual }], activa: null };
    estado.activa = estado.pestanas[0].id;
  } else {
    // La navegación dentro de una pestaña actualiza esa pestaña
    const activa = estado.pestanas.find((p) => p.id === estado.activa) ?? estado.pestanas[0];
    Object.assign(activa, actual);
    estado.activa = activa.id;
  }
  guardarPestanas(estado);

  const ir = (pestana) => {
    estado.activa = pestana.id;
    guardarPestanas(estado);
    if (pestana.url !== location.pathname + location.search) location.href = pestana.url;
    else pintar();
  };

  const pintar = () => {
    lista.innerHTML = '';
    estado.pestanas.forEach((p) => {
      const el = document.createElement('div');
      el.className = 'pestana' + (p.id === estado.activa ? ' activa' : '');
      el.setAttribute('role', 'tab');
      el.setAttribute('aria-selected', p.id === estado.activa);
      el.tabIndex = 0;
      el.title = p.titulo;
      el.innerHTML = `<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>
        <span class="pestana__titulo"></span>
        <button type="button" class="pestana__cerrar" aria-label="Cerrar pestaña"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>`;
      el.querySelector('.pestana__titulo').textContent = p.titulo;
      el.addEventListener('click', (ev) => {
        if (ev.target.closest('.pestana__cerrar')) { cerrarPestana(p.id); return; }
        ir(p);
      });
      el.addEventListener('auxclick', (ev) => { if (ev.button === 1) cerrarPestana(p.id); }); // clic central cierra
      el.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); ir(p); } });
      lista.appendChild(el);
    });
    lista.querySelector('.activa')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    pintarLateral();
  };

  // Las mismas pestañas, listadas en la barra lateral ("Pestañas abiertas")
  const lateral = document.querySelector('[data-lateral-pestanas]');
  const pintarLateral = () => {
    if (!lateral) return;
    lateral.innerHTML = '';
    estado.pestanas.forEach((p) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'lateral-pestana' + (p.id === estado.activa ? ' activa' : '');
      b.title = p.titulo;
      b.innerHTML = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span></span>';
      b.querySelector('span').textContent = p.titulo;
      b.addEventListener('click', () => ir(p));
      lateral.appendChild(b);
    });
  };

  const cerrarPestana = (id) => {
    const i = estado.pestanas.findIndex((p) => p.id === id);
    if (i < 0) return;
    const eraActiva = estado.activa === id;
    recordarEnLista('cerradas', estado.pestanas[i], 10);
    estado.pestanas.splice(i, 1);
    if (!estado.pestanas.length) {
      estado.pestanas.push({ id: nuevoId(), url: `${BASE}/pacientes/`, titulo: 'Pacientes' });
    }
    if (eraActiva) {
      ir(estado.pestanas[Math.min(i, estado.pestanas.length - 1)]);
    } else {
      guardarPestanas(estado);
      pintar();
    }
  };

  const abrirNueva = () => {
    if (estado.pestanas.length >= MAX_PESTANAS) {
      mostrarNotificacion('error', `Puedes tener hasta ${MAX_PESTANAS} pestañas abiertas.`);
      return;
    }
    const p = { id: nuevoId(), url: `${BASE}/pacientes/`, titulo: 'Pacientes' };
    estado.pestanas.push(p);
    ir(p);
  };

  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-pestanas-nueva]')) { ev.preventDefault(); abrirNueva(); }
  });

  // Atrás / adelante con el historial real del navegador
  const atras = document.querySelector('[data-pestanas-atras]');
  const adelante = document.querySelector('[data-pestanas-adelante]');
  atras.addEventListener('click', () => history.back());
  adelante.addEventListener('click', () => history.forward());
  if (window.navigation && 'canGoBack' in window.navigation) {
    atras.disabled = !navigation.canGoBack;
    adelante.disabled = !navigation.canGoForward;
  }

  // Al cerrar sesión se olvidan las pestañas
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { sessionStorage.removeItem(CLAVE_PESTANAS); } catch (e) { /* sin almacenamiento */ }
  });

  // Al renombrar la página abierta, su pestaña cambia de título
  window.renombrarPestanaActual = (titulo) => {
    const activa = estado.pestanas.find((p) => p.id === estado.activa);
    if (!activa) return;
    activa.titulo = titulo;
    guardarPestanas(estado);
    pintar();
  };

  pintar();
}

/* ---------- Barra lateral: grupos, historial y pestañas cerradas recientemente ----------
   Historial y cerradas viven en sessionStorage (como las pestañas) y se borran al cerrar sesión. */
const claveLista = (nombre) => `expediente-${nombre}-${document.body.dataset.usuario ?? ''}`;
function leerLista(nombre) {
  try { return JSON.parse(sessionStorage.getItem(claveLista(nombre))) || []; } catch (e) { return []; }
}
function recordarEnLista(nombre, entrada, maximo) {
  if (!entrada) return;
  const lista = leerLista(nombre).filter((x) => x.url !== entrada.url);
  lista.unshift({ url: entrada.url, titulo: entrada.titulo, cuando: Date.now() });
  try { sessionStorage.setItem(claveLista(nombre), JSON.stringify(lista.slice(0, maximo))); } catch (e) { /* lleno */ }
}

function iniciarBarraLateral() {
  if (!document.querySelector('.barra-lateral__desplazable')) return;
  // Cada página visitada entra al historial de la sesión
  recordarEnLista('historial', { url: location.pathname + location.search, titulo: document.body.dataset.titulo || document.title }, 20);

  document.querySelectorAll('.lateral-grupo__titulo').forEach((b) => b.addEventListener('click', () => {
    b.setAttribute('aria-expanded', String(b.getAttribute('aria-expanded') !== 'true'));
  }));

  const popover = document.querySelector('[data-lateral-popover]');
  const botones = document.querySelectorAll('[data-lateral-lista]');
  const cerrar = () => { popover.hidden = true; botones.forEach((b) => b.setAttribute('aria-expanded', 'false')); };
  const hace = (ms) => {
    const min = Math.round((Date.now() - ms) / 60000);
    return min < 1 ? 'ahora' : min < 60 ? `hace ${min} min` : `hace ${Math.round(min / 60)} h`;
  };
  botones.forEach((b) => b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const nombre = b.dataset.lateralLista;
    if (!popover.hidden && popover.dataset.lista === nombre) { cerrar(); return; }
    cerrar();
    popover.dataset.lista = nombre;
    // Paneles completos (assets/js/modulos/paneles_laterales.js); lo de abajo queda como respaldo
    if (window.PanelLateral) {
      popover.hidden = false;
      b.setAttribute('aria-expanded', 'true');
      window.PanelLateral.abrir(nombre, popover, { leerLista });
      return;
    }
    if (nombre === 'papelera') {
      popover.hidden = false;
      b.setAttribute('aria-expanded', 'true');
      window.pintarPapelera?.(popover);
      return;
    }
    // "Recientes": lo visitado en la sesión y, debajo, las pestañas cerradas que se pueden reabrir
    const visitadas = leerLista('historial').filter((x) => x.url !== location.pathname + location.search).slice(0, 8);
    const cerradas = leerLista('cerradas').slice(0, 6);
    popover.dataset.lista = nombre;
    popover.innerHTML = (!visitadas.length && !cerradas.length)
      ? '<div class="lateral-popover__vacio">Aún no hay páginas recientes.</div>'
      : (visitadas.length ? '<div class="lateral-popover__titulo">Visitado en esta sesión</div>'
          + visitadas.map((x) => `<a role="menuitem" href="${escaparHtml(x.url)}"><span>${escaparHtml(x.titulo)}</span><small>${hace(x.cuando)}</small></a>`).join('') : '')
        + (cerradas.length ? '<div class="lateral-popover__titulo">Pestañas cerradas</div>'
          + cerradas.map((x, i) => `<button type="button" role="menuitem" data-reabrir="${i}"><span>${escaparHtml(x.titulo)}</span><small>${hace(x.cuando)}</small></button>`).join('') : '');
    popover.hidden = false;
    b.setAttribute('aria-expanded', 'true');
  }));
  popover.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-reabrir]');
    if (!b) return;
    // Reabrir: vuelve como pestaña nueva y sale de la lista de cerradas
    const lista = leerLista('cerradas');
    const [p] = lista.splice(Number(b.dataset.reabrir), 1);
    try { sessionStorage.setItem(claveLista('cerradas'), JSON.stringify(lista)); } catch (e) { /* lleno */ }
    const estado = leerPestanas();
    if (estado && p) {
      const nueva = { id: nuevoId(), url: p.url, titulo: p.titulo };
      estado.pestanas.push(nueva);
      estado.activa = nueva.id;
      guardarPestanas(estado);
    }
    if (p) location.href = p.url;
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.lateral-desplegable, [data-lateral-popover]')) cerrar(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrar(); });
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { ['historial', 'cerradas'].forEach((n) => sessionStorage.removeItem(claveLista(n))); } catch (e) { /* opcional */ }
  });
}

/* ---------- Barra de ruta: Compartir (copiar enlace), menú "…", imprimir ---------- */
async function exportarPacienteJSON(pacienteId) {
  try {
    const respuesta = await fetch(`${BASE}/api/exportar.php?tipo=paciente&id=${encodeURIComponent(pacienteId)}&formato=json`);
    const resultado = await respuesta.json();
    if (!respuesta.ok || !resultado.ok) throw new Error(resultado.error || 'No se pudo exportar.');
    const archivo = new Blob([JSON.stringify(resultado.datos, null, 2)], { type: 'application/json;charset=utf-8' });
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(archivo);
    enlace.download = `paciente-${pacienteId}.json`;
    enlace.click();
    setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);
    mostrarNotificacion('exito', 'Se descargaron los datos del paciente en JSON.');
  } catch (error) {
    mostrarNotificacion('error', error.message || 'No se pudo exportar el paciente.');
  }
}

function exportarRegistroJSON(registroId) {
  const fuente = document.getElementById('registro-exportacion');
  if (!fuente || String(JSON.parse(fuente.textContent).id) !== String(registroId)) {
    mostrarNotificacion('error', 'No se encontraron los datos de este documento para exportar.');
    return;
  }
  const datos = JSON.parse(fuente.textContent);
  const archivo = new Blob([JSON.stringify(datos, null, 2)], { type: 'application/json;charset=utf-8' });
  const enlace = document.createElement('a');
  enlace.href = URL.createObjectURL(archivo);
  enlace.download = `documento-clinico-${datos.id}.json`;
  enlace.click();
  setTimeout(() => URL.revokeObjectURL(enlace.href), 1000);
  mostrarNotificacion('exito', 'Se descargó el documento clínico en JSON.');
}

function iniciarBarraRuta() {
  document.querySelector('[data-compartir]')?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(location.href);
      mostrarNotificacion('exito', 'Enlace copiado. Solo lo abren usuarios con sesión iniciada.');
    } catch (e) {
      mostrarNotificacion('error', 'No se pudo copiar el enlace.');
    }
  });

  const boton = document.querySelector('[data-menu-mas]');
  const menu = document.querySelector('.menu-mas__lista');
  if (!boton || !menu) return;
  const cerrar = () => { menu.hidden = true; boton.setAttribute('aria-expanded', 'false'); };
  boton.addEventListener('click', (ev) => {
    ev.stopPropagation();
    menu.hidden = !menu.hidden;
    boton.setAttribute('aria-expanded', String(!menu.hidden));
    if (!menu.hidden) menu.querySelector('button')?.focus();
  });
  menu.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-imprimir]')) {
      const botonImprimir = ev.target.closest('[data-imprimir]');
      if (document.body.dataset.paciente && window.abrirImpresion) window.abrirImpresion(botonImprimir);
      else window.print();
    }
    if (ev.target.closest('[data-exportar]')) {
      const paginaId = document.body.dataset.pagina;
      const registroId = document.body.dataset.registro;
      const pacienteId = document.body.dataset.paciente;
      if (registroId) exportarRegistroJSON(registroId);
      else if (pacienteId) exportarPacienteJSON(pacienteId);
      else if (paginaId) {
        fetch(`${BASE}/api/exportar.php?tipo=nota&id=${paginaId}&formato=html`)
          .then(r => r.json())
          .then(d => { if (d.ok) window.open(d.url, '_blank', 'noopener'); })
          .catch(() => {});
      }
    }
    if (!ev.target.closest('[data-imprimir]')) cerrar();
    else if (!document.body.dataset.paciente) cerrar();
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.menu-mas')) cerrar(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrar(); });
}

/* ---------- Mapa de México: selector de entidad, globo, ranking y lista de pacientes ---------- */
function iniciarMapa(seccion) {
  let datos = null;
  let cargando = false;
  const boton = seccion.querySelector('[data-geo-boton]');
  const opciones = seccion.querySelector('[data-geo-opciones]');
  const nombre = seccion.querySelector('[data-geo-nombre]');
  const numero = seccion.querySelector('[data-geo-numero]');
  const nota = seccion.querySelector('[data-geo-nota]');
  const contenedorMapa = seccion.querySelector('[data-geo-mapa]');
  const lista = seccion.querySelector('[data-geo-lista]');
  const verTodos = seccion.querySelector('[data-geo-todos]');
  const bloquePacientes = seccion.querySelector('[data-geo-pacientes]');
  // Variantes de la sección sin el mapa completo (u ocultas): no hay nada que iniciar
  if (!boton || !opciones || !nombre || !numero || !nota || !contenedorMapa || !lista || !verTodos || !bloquePacientes) return;
  let globo = contenedorMapa.querySelector('.mapa__globo');
  let estados = contenedorMapa.querySelectorAll('.mapa__estado');
  let notaGeneral = '';

  const cargarDatos = async () => {
    if (datos || cargando) return;
    cargando = true;
    nota.textContent = 'Cargando datos geográficos…';
    contenedorMapa.innerHTML = '<p class="geo__vacio">Cargando mapa…</p>';
    try {
      const respuesta = await fetch(`${BASE}/api/pacientes/geografia.php`, { headers: { Accept: 'application/json' } });
      const resultado = await respuesta.json();
      if (!respuesta.ok || !resultado.ok) throw new Error(resultado.error || 'No se pudo cargar el mapa.');
      datos = resultado;
      notaGeneral = datos.sinEntidad ? `(${datos.sinEntidad} sin entidad identificada)` : (datos.total ? '(con entidad identificada)' : '(aún no hay domicilios registrados)');
      numero.textContent = datos.total;
      nota.textContent = notaGeneral;
      opciones.innerHTML = `<li role="option" data-entidad="" aria-selected="true"><span>México</span><span>${datos.total}</span></li>${Object.entries(datos.conteos).map(([clave, total]) => `<li role="option" data-entidad="${clave}"><span>${escaparHtml(datos.nombres[clave])}</span><span>${total}</span></li>`).join('')}`;
      lista.innerHTML = datos.ranking.map((estado, i) => `<li class="${i >= 5 || !estado.total ? 'extra' : ''}" data-entidad="${estado.clave}"><span class="geo__rango">${i + 1}</span><div><span class="geo__estado">${escaparHtml(estado.nombre)}</span><span class="geo__conteo">${estado.total === 1 ? '1 paciente' : `${estado.total} pacientes`}</span><span class="geo__barra"><i style="width:${estado.porcentaje}%"></i></span></div></li>`).join('');
      lista.hidden = !datos.total;
      verTodos.hidden = !datos.total;
      contenedorMapa.innerHTML = `${datos.mapa}<div class="mapa__globo" hidden></div><div class="geo__leyenda" aria-hidden="true"><div class="geo__escala"><i class="nivel-1"></i><i class="nivel-2"></i><i class="nivel-3"></i><i class="nivel-4"></i><i class="nivel-5"></i></div><div class="geo__cortes">${datos.cortes.map((corte) => `<span>${corte}</span>`).join('')}</div></div>`;
      globo = contenedorMapa.querySelector('.mapa__globo');
      estados = contenedorMapa.querySelectorAll('.mapa__estado');
    } catch (error) {
      nota.textContent = 'No se pudo cargar el mapa. Inténtalo de nuevo.';
      contenedorMapa.innerHTML = '<p class="geo__vacio">No se pudo cargar el mapa.</p>';
      mostrarNotificacion('error', error.message || 'No se pudo cargar el mapa.');
    } finally {
      cargando = false;
    }
  };
  seccion.addEventListener('geo:open', cargarDatos);

  const seleccionar = (clave) => {
    if (!datos) return;
    estados.forEach((e) => {
      e.classList.toggle('resaltada', !!clave && e.dataset.entidad === clave);
      e.classList.toggle('atenuada', !!clave && e.dataset.entidad !== clave);
    });
    opciones.querySelectorAll('li').forEach((li) => li.setAttribute('aria-selected', String(li.dataset.entidad === (clave || ''))));
    if (!clave) {
      nombre.textContent = 'México';
      numero.textContent = datos.total;
      nota.textContent = notaGeneral;
      bloquePacientes.hidden = true;
      return;
    }
    const pacientes = datos.pacientes[clave] || [];
    nombre.textContent = datos.nombres[clave];
    numero.textContent = pacientes.length;
    nota.textContent = `(en ${datos.nombres[clave]})`;
    bloquePacientes.hidden = false;
    bloquePacientes.querySelector('[data-geo-pacientes-titulo]').textContent = `Pacientes en ${datos.nombres[clave]}`;
    const ul = bloquePacientes.querySelector('[data-geo-pacientes-lista]');
    ul.innerHTML = pacientes.length
      ? pacientes.map((p) => `<li><a href="${BASE}/pacientes/ver.php?id=${p.id}"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true">${ICONOS_PALETA.pagina}</svg>${escaparHtml(p.nombre)}</a></li>`).join('')
      : '<li class="geo__vacio">Ningún paciente registrado con domicilio en esta entidad.</li>';
  };

  const cerrarOpciones = () => { opciones.hidden = true; boton.setAttribute('aria-expanded', 'false'); };
  boton.addEventListener('click', (ev) => {
    ev.stopPropagation();
    opciones.hidden = !opciones.hidden;
    boton.setAttribute('aria-expanded', String(!opciones.hidden));
    if (!opciones.hidden) opciones.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
  });
  opciones.addEventListener('click', (ev) => {
    const li = ev.target.closest('li[data-entidad]');
    if (!li) return;
    seleccionar(li.dataset.entidad || null);
    cerrarOpciones();
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.geo__selector')) cerrarOpciones(); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') cerrarOpciones(); });

  contenedorMapa.addEventListener('mousemove', (ev) => {
    const estado = ev.target.closest('.mapa__estado');
    if (!estado) { if (globo) globo.hidden = true; return; }
    if (!globo) return;
    const caja = contenedorMapa.getBoundingClientRect();
    globo.textContent = estado.dataset.texto;
    globo.style.left = `${ev.clientX - caja.left}px`;
    globo.style.top = `${ev.clientY - caja.top}px`;
    globo.hidden = false;
  });
  contenedorMapa.addEventListener('mouseleave', () => { if (globo) globo.hidden = true; });
  contenedorMapa.addEventListener('click', (ev) => {
    const estado = ev.target.closest('.mapa__estado');
    if (!estado) return;
    seleccionar(estado.classList.contains('resaltada') ? null : estado.dataset.entidad);
  });

  lista.addEventListener('click', (ev) => {
    const li = ev.target.closest('li[data-entidad]');
    if (li) seleccionar(li.dataset.entidad);
  });
  verTodos.addEventListener('click', () => {
    const todos = lista.classList.toggle('todos');
    verTodos.setAttribute('aria-expanded', String(todos));
    verTodos.childNodes.forEach((n) => { if (n.nodeType === 3 && n.textContent.trim()) n.textContent = todos ? ' Ver los 5 principales ' : ' Ver todos los estados '; });
  });
}

/* ---------- Dictado por voz ----------
   Usa Web Speech cuando está disponible y el STT local para navegadores sin esa API.
   Nunca sobrescribe lo que el usuario escribió a mano. */
function iniciarDictado(raiz) {
  const form = document.getElementById('form-paciente') || document.querySelector('[data-registro-clinico]');
  if (!form) return;
  const panel = raiz.querySelector('[data-dictado-panel]');
  const texto = raiz.querySelector('[data-dictado-texto]');
  const estadoTexto = raiz.querySelector('[data-dictado-estado]');
  const calificar = raiz.querySelector('[data-dictado-calificar]');
  const boton = raiz.querySelector('[data-dictado-boton]');
  const globo = raiz.querySelector('[data-dictado-globo]');
  const puntos = Array.from(raiz.querySelectorAll('[data-dictado-puntos] i'));
  const asa = raiz.querySelector('[data-dictado-asa]');
  const redimension = raiz.querySelector('[data-dictado-redimension]');
  const barra = raiz.querySelector('.dictado__barra');
  const orbita = document.querySelector('[data-dictado-orbita]');
  const orbitaBolita = orbita?.querySelector('[data-dictado-orbita-bolita]');
  const orbitaBurbuja = orbita?.querySelector('[data-dictado-orbita-burbuja]');
  const orbitaTexto = orbita?.querySelector('[data-dictado-orbita-texto]');
  const orbitaCompactar = orbita?.querySelector('[data-dictado-orbita-compactar]');
  // Una consulta completa puede durar mucho más que unos minutos: el dictado no se corta solo.
  const MAX_SEGUNDOS = 3600;
  const CLAVE_PANEL = 'expediente-dictado-panel';
  const CLAVE_TAMANO = 'expediente-dictado-tamano';
  const CLAVE_ORBITA = 'expediente-dictado-orbita';

  let reconocedor = null, paroManual = false, reintentosVoz = 0, reinicioVoz = null;
  let textoFinal = '', textoParcial = '', temporizadorEnvio = null;
  let inicio = 0, reloj = null, enVuelo = false, finalPendiente = false;
  let descartar = false, duracion = 0;
  let modoVoz = null;                        // 'web' o 'local'
  let flujoLocal = null, ctxLocal = null, analizadorLocal = null;
  let muestrasVivo = null, silencioMs = 0, hablando = false, pisoRuido = 0, umbralVoz = 0.012;
  let colaVivo = [], enviandoVivo = false, paroLocal = false, localActivo = false, vivoEnviando = false;
  let bolita = null, orbitaLista = false, clicArrastrado = false;
  const llenados = new Set();

  const ponerEstado = (estado) => {
    raiz.dataset.estado = estado;
    const etiqueta = { inactivo: 'Dictar', grabando: 'Terminar', procesando: 'Procesando…' }[estado];
    globo.textContent = etiqueta;
    boton.setAttribute('aria-label', etiqueta);
    boton.disabled = estado === 'procesando';
  };
  const mostrarPanel = () => { panel.hidden = false; panel.classList.remove('saliendo'); };
  const ocultarPanel = () => { if (!panel.hidden) { panel.classList.add('saliendo'); setTimeout(() => { panel.hidden = true; }, 160); } };
  const segundos = () => Math.round((Date.now() - inicio) / 1000);

  /* ---------- Posición y tamaño del panel ---------- */
  const guardarPos = (clave, datos) => { try { localStorage.setItem(clave, JSON.stringify(datos)); } catch (e) { /* sin almacenamiento */ } };
  const leerPos = (clave) => { try { return JSON.parse(localStorage.getItem(clave) || 'null'); } catch (e) { return null; } };
  const acotar = (valor, minimo, maximo) => Math.min(Math.max(minimo, valor), Math.max(minimo, maximo));

  const guardarPosicion = (el, pos, clave) => {
    if (!pos) return;
    const ancho = el.offsetWidth || 320;
    const alto = el.offsetHeight || 80;
    const x = acotar(Number(pos.x) || 0, 0, window.innerWidth - ancho);
    const y = acotar(Number(pos.y) || 0, 0, window.innerHeight - Math.min(alto, 80));
    el.style.left = `${x}px`;
    el.style.top = `${y}px`;
    el.style.right = 'auto';
    el.style.bottom = 'auto';
    el.style.transform = 'none';
    if (clave && (x !== Number(pos.x) || y !== Number(pos.y))) guardarPos(clave, { x, y });
  };

  const posicionInicial = leerPos(CLAVE_PANEL);
  if (posicionInicial) guardarPosicion(raiz, posicionInicial, CLAVE_PANEL);

  const tamanoInicial = leerPos(CLAVE_TAMANO);
  if (tamanoInicial) {
    const ancho = acotar(Number(tamanoInicial.w) || 680, 320, window.innerWidth - 24);
    const alto = acotar(Number(tamanoInicial.h) || 420, 210, window.innerHeight - 40);
    panel.style.width = `${ancho}px`;
    panel.style.height = `${alto}px`;
    panel.classList.add('redimensionado');
  }

  if (asa && panel) {
    let arrastre = null;
    asa.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0 || ev.target.closest('button')) return;
      const caja = raiz.getBoundingClientRect();
      arrastre = { x: ev.clientX, y: ev.clientY, left: caja.left, top: caja.top, ancho: caja.width, alto: caja.height };
      asa.setPointerCapture?.(ev.pointerId);
      ev.preventDefault();
    });
    asa.addEventListener('pointermove', (ev) => {
      if (!arrastre) return;
      const dx = ev.clientX - arrastre.x;
      const dy = ev.clientY - arrastre.y;
      if (Math.abs(dx) + Math.abs(dy) < 3) return;
      guardarPosicion(raiz, { x: arrastre.left + dx, y: arrastre.top + dy }, null);
    });
    const terminarArrastre = (ev) => {
      if (!arrastre) return;
      arrastre = null;
      asa.releasePointerCapture?.(ev.pointerId);
      guardarPos(CLAVE_PANEL, { x: raiz.offsetLeft, y: raiz.offsetTop });
    };
    asa.addEventListener('pointerup', terminarArrastre);
    asa.addEventListener('pointercancel', terminarArrastre);
  }

  if (redimension && panel) {
    let medida = null;
    redimension.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0) return;
      const caja = panel.getBoundingClientRect();
      medida = { x: ev.clientX, y: ev.clientY, ancho: caja.width, alto: caja.height };
      redimension.setPointerCapture?.(ev.pointerId);
      panel.classList.add('redimensionado');
      ev.preventDefault();
      ev.stopPropagation();
    });
    redimension.addEventListener('pointermove', (ev) => {
      if (!medida) return;
      const ancho = acotar(medida.ancho + ev.clientX - medida.x, 320, window.innerWidth - 24);
      const alto = acotar(medida.alto + ev.clientY - medida.y, 210, window.innerHeight - 40);
      panel.style.width = `${ancho}px`;
      panel.style.height = `${alto}px`;
    });
    const terminarMedida = (ev) => {
      if (!medida) return;
      medida = null;
      redimension.releasePointerCapture?.(ev.pointerId);
      guardarPos(CLAVE_TAMANO, { w: panel.offsetWidth, h: panel.offsetHeight });
    };
    redimension.addEventListener('pointerup', terminarMedida);
    redimension.addEventListener('pointercancel', terminarMedida);
  }

  /* ---------- Bolita minimizada (motor aurora de la herramienta de pensamiento) ---------- */
  const prepararBolita = async () => {
    if (!orbita || orbitaLista) return;
    orbitaLista = true;
    try { await cargarAurora(); } catch (e) { /* sin motor: bolita CSS */ }
    if (window.Razonamiento?.bolita) {
      try {
        bolita = window.Razonamiento.bolita();
        orbitaBolita.appendChild(bolita.el);
        return;
      } catch (e) { /* usa la bolita CSS */ }
    }
    orbitaBolita.classList.add('dictado-orbita__bolita--css');
  };

  const quitarBolita = () => {
    if (bolita) { try { bolita.apagar(); } catch (e) { /* ya liberada */ } bolita = null; }
    orbitaBolita?.replaceChildren();
    orbitaBolita?.classList.remove('dictado-orbita__bolita--css');
    orbitaLista = false;
  };

  const actualizarBurbuja = (valor) => {
    if (!orbitaBurbuja || !orbitaTexto) return;
    let t = String(valor || '').replace(/\s+/g, ' ').trim();
    if (t.length > 300) t = '…' + t.slice(-300);
    orbitaTexto.textContent = t || (raiz.dataset.estado === 'grabando' ? 'Escuchando…' : 'Dictado listo');
    orbitaBurbuja.hidden = false;
  };

  // Segundo nivel de minimización: la burbuja desaparece y el orbe queda como un punto.
  const ponerCompacto = (valor) => {
    if (!orbita) return;
    orbita.dataset.compacto = valor ? '1' : '';
    const etiqueta = valor
      ? 'Mostrar el mensaje y ampliar el orbe'
      : 'Ocultar el mensaje y reducir el orbe';
    orbitaCompactar?.setAttribute('aria-label', etiqueta);
    orbitaCompactar?.setAttribute('title', etiqueta);
  };
  orbitaCompactar?.addEventListener('click', (ev) => {
    ev.stopPropagation();
    ponerCompacto(orbita?.dataset.compacto !== '1');
  });

  const minimizar = () => {
    if (!orbita) { ocultarPanel(); return; }
    panel.hidden = true;
    panel.classList.remove('saliendo');
    barra.hidden = true;
    raiz.dataset.minimizado = '1';
    guardarPosicion(orbita, leerPos(CLAVE_ORBITA), CLAVE_ORBITA);
    orbita.hidden = false;
    prepararBolita();
    actualizarBurbuja(texto.textContent);
  };

  const restaurar = () => {
    if (!orbita) return;
    orbita.hidden = true;
    raiz.dataset.minimizado = '';
    barra.hidden = false;
    mostrarPanel();
    if (posicionInicial) guardarPosicion(raiz, { x: raiz.offsetLeft, y: raiz.offsetTop }, CLAVE_PANEL);
  };

  if (orbita) {
    const posOrbita = leerPos(CLAVE_ORBITA);
    if (posOrbita) guardarPosicion(orbita, posOrbita, CLAVE_ORBITA);
    let arrastre = null, movido = false;
    orbita.addEventListener('pointerdown', (ev) => {
      if (ev.button !== 0 || ev.target.closest('[data-dictado-orbita-compactar]')) return;
      const caja = orbita.getBoundingClientRect();
      arrastre = { x: ev.clientX, y: ev.clientY, left: caja.left, top: caja.top, ancho: caja.width, alto: caja.height };
      movido = false;
      clicArrastrado = false;
      orbita.classList.add('arrastrando');
      orbita.setPointerCapture?.(ev.pointerId);
    });
    orbita.addEventListener('pointermove', (ev) => {
      if (!arrastre) return;
      const dx = ev.clientX - arrastre.x;
      const dy = ev.clientY - arrastre.y;
      if (!movido && Math.abs(dx) + Math.abs(dy) < 5) return;
      movido = true;
      guardarPosicion(orbita, { x: arrastre.left + dx, y: arrastre.top + dy }, null);
    });
    const terminarArrastre = (ev) => {
      if (!arrastre) return;
      arrastre = null;
      orbita.classList.remove('arrastrando');
      orbita.releasePointerCapture?.(ev.pointerId);
      if (movido) {
        clicArrastrado = true;
        guardarPos(CLAVE_ORBITA, { x: orbita.offsetLeft, y: orbita.offsetTop });
      }
    };
    orbita.addEventListener('pointerup', terminarArrastre);
    orbita.addEventListener('pointercancel', terminarArrastre);
    orbita.addEventListener('click', () => {
      if (clicArrastrado) { clicArrastrado = false; return; }
      restaurar();
    });
  }

  // Lo que el usuario escribe a mano ya no lo toca el dictado
  form.addEventListener('input', (ev) => { if (ev.isTrusted) delete ev.target.dataset.dictado; });
  form.addEventListener('change', (ev) => { if (ev.isTrusted) delete ev.target.dataset.dictado; });

  // En modo navegador no hay analizador: la animación sigue el ritmo del habla de forma suave
  let cuadro = null;
  const nivelesSuaves = () => {
    const pintar = () => {
      puntos.forEach((p, i) => {
        const forma = [0.55, 0.85, 1, 0.85, 0.55][i];
        p.style.height = `${3 + Math.random() * 11 * forma}px`;
      });
      cuadro = requestAnimationFrame(pintar);
    };
    pintar();
  };
  const pararNiveles = () => {
    cancelAnimationFrame(cuadro);
    puntos.forEach((p) => { p.style.height = ''; });
  };

  const ponerEnCampo = (campo, valor) => {
    if (!campo) return false;
    if (campo.tagName === 'SELECT' && ![...campo.options].some((o) => o.value === valor)) return false;
    // Respeta el límite del campo: mejor recortar aquí que perder el guardado completo después.
    if (typeof campo.maxLength === 'number' && campo.maxLength > 0 && valor.length > campo.maxLength) {
      valor = valor.slice(0, campo.maxLength);
    }
    if (campo.value === valor) return false;
    campo.value = valor;
    campo.dataset.dictado = '1';
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    campo.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };

  // El dictado usa nombres clínicos; cada formulario los guarda con otro nombre. Este mapa
  // lleva cada dato a la primera casilla que exista en la página actual (registro de
  // pacientes, nota de consulta, urgencias, referencia, egreso, incapacidad…).
  const ALIAS_CAMPOS = {
    motivo_consulta: ['motivo_consulta', 'motivo'],
    padecimiento_actual: ['padecimiento_actual', 'padecimiento', 'interrogatorio', 'resumen'],
    exploracion_fisica: ['exploracion_fisica', 'exploracion'],
    nota_evolucion: ['nota_evolucion', 'nota', 'resumen', 'observaciones'],
    plan_tratamiento: ['plan_tratamiento', 'plan', 'manejo', 'sugerencias', 'terapeutica'],
    estado_mental: ['estado_mental'],
    resultados: ['resultados'],
    pronostico: ['pronostico'],
    // Signos vitales: la nota los nombra como en la clínica y el formulario los guarda así.
    ta_sistolica: ['signos[sistolica]'],
    ta_diastolica: ['signos[diastolica]'],
    frecuencia_cardiaca: ['signos[fc]'],
    frecuencia_respiratoria: ['signos[fr]'],
    temperatura: ['signos[temperatura]'],
    saturacion_o2: ['signos[spo2]'],
    glucosa: ['signos[glucosa]'],
    peso: ['signos[peso]'],
    talla: ['signos[talla]'],
    // Dictado sin etiquetas: va al área clínica principal que tenga el formulario.
    nota_libre: ['padecimiento', 'interrogatorio', 'resumen', 'motivo_consulta', 'motivo', 'exploracion', 'nota', 'observaciones'],
  };
  const campoDe = (nombre) => {
    for (const clave of [nombre, ...(ALIAS_CAMPOS[nombre] || [])]) {
      const campo = form.elements[clave];
      if (campo) return campo;
    }
    return null;
  };

  // El diagnóstico de la nota se guarda en renglones diagnostico[]; se llena el primero
  // vacío (o el que ya puso el dictado) sin pisar lo que el médico escribió a mano.
  const llenarDiagnostico = (valor) => {
    if (!valor) return false;
    if (form.elements['diagnostico']) return false;   // el formulario tiene un campo simple
    const entrada = [...form.querySelectorAll('[name="diagnostico[]"]')]
      .find((campo) => !campo.value || campo.dataset.dictado === '1');
    if (!entrada || !ponerEnCampo(entrada, valor)) return false;
    const fila = entrada.closest('[data-fila]') || entrada.closest('.campo');
    fila?.classList.remove('campo--dictado');
    void fila?.offsetWidth;
    fila?.classList.add('campo--dictado');
    llenados.add('diagnostico');
    return true;
  };

  // Avisos del dictado: dosis incompletas, medicamento no reconocido, contradicciones…
  const avisosCaja = panel.querySelector('[data-dictado-avisos]');
  const TIPOS_AVISO = {
    dosis_incompleta: 'Dosis', dosis_fuera_de_rango: 'Dosis',
    frecuencia_incompleta: 'Frecuencia', frecuencia_fuera_de_rango: 'Frecuencia',
    medicamento_no_reconocido: 'Medicamento', contradiccion: 'Contradicción',
    signo_fuera_de_rango: 'Signo vital', telefono_incompleto: 'Teléfono',
    nombre_dudoso: 'Nombre', curp_invalida: 'CURP', falta_motivo: 'Falta motivo',
    falta_diagnostico: 'Falta diagnóstico', texto_repetido: 'Transcripción',
    texto_muy_corto: 'Transcripción',
  };
  const pintarAvisos = (avisos) => {
    if (!avisosCaja) return;
    const lista = Array.isArray(avisos) ? avisos.slice(0, 5) : [];
    if (!lista.length) {
      avisosCaja.hidden = true;
      avisosCaja.replaceChildren();
      return;
    }
    avisosCaja.replaceChildren(...lista.map((aviso) => {
      const fila = document.createElement('p');
      fila.className = `dictado__aviso dictado__aviso--${aviso.gravedad || 'media'}`;
      const etiqueta = document.createElement('strong');
      etiqueta.textContent = (TIPOS_AVISO[aviso.tipo] || 'Aviso') + ': ';
      fila.append(etiqueta, document.createTextNode(aviso.mensaje || ''));
      if (aviso.tipo) fila.dataset.tipo = aviso.tipo;
      return fila;
    }));
    avisosCaja.hidden = false;
  };

  const mostrarErrorDictado = (mensaje, detener = true) => {
    if (detener) {
      paroManual = true;
      clearInterval(reloj);
      clearTimeout(temporizadorEnvio);
      clearTimeout(reinicioVoz);
      pararNiveles();
      const rec = reconocedor;
      reconocedor = null;
      try { rec?.abort?.(); } catch (e) { /* el reconocedor ya se detuvo */ }
      if (localActivo || flujoLocal || ctxLocal) cerrarAudioLocal();
      colaVivo = [];
      paroLocal = false;
      vivoEnviando = false;
      ponerEstado('inactivo');
    }
    if (raiz.dataset.minimizado === '1') restaurar();
    const fila = document.createElement('p');
    fila.className = 'dictado__aviso dictado__aviso--error';
    fila.setAttribute('role', 'alert');
    fila.textContent = String(mensaje || 'No se pudo completar el dictado.');
    avisosCaja.replaceChildren(fila);
    avisosCaja.hidden = false;
    estadoTexto.textContent = 'Revisa el aviso y vuelve a intentar';
    actualizarBurbuja(fila.textContent);
  };

  /* ---------- Renglones de la receta: medicamentos reconocidos por el catálogo ---------- */
  const llenarMedicamentos = (medicamentos) => {
    const caja = form.querySelector('[data-filas="medicamentos"]');
    const plantilla = form.querySelector('template[data-plantilla="medicamentos"]');
    if (!caja || !plantilla) {
      const actuales = form.elements['medicamentos_actuales'];
      if (actuales && (!actuales.value || actuales.dataset.dictado)) {
        const nombres = medicamentos
          .map((m) => [m.nombre, m.dosis, m.periodicidad, m.duracion].filter(Boolean).join(' '))
          .filter(Boolean);
        const texto = nombres.join('; ').slice(0, 255);
        if (texto && ponerEnCampo(actuales, texto)) llenados.add('medicamentos_actuales');
      }
      return;
    }
    // Solo se reutilizan los renglones vacíos o los que llenó el propio dictado: lo que el
    // médico escribió a mano no se sobrescribe al seguir hablando.
    const disponibles = [...caja.querySelectorAll(':scope > [data-fila]')].filter((fila) => {
      const entrada = fila.querySelector('[name="medicamento[]"]');
      return !entrada || !entrada.value || entrada.dataset.dictado === '1';
    });
    medicamentos.forEach((medicamento, indice) => {
      let fila = disponibles[indice];
      if (!fila) {
        fila = plantilla.content.firstElementChild.cloneNode(true);
        caja.appendChild(fila);
        disponibles.push(fila);
        window.inicializarCatalogoClinico?.(fila);
      }
      const campo = (selector) => fila.querySelector(selector);
      const valores = {
        '[data-medicamento-id]': medicamento.id ? String(medicamento.id) : '',
        '[name="medicamento[]"]': medicamento.nombre || '',
        '[name="presentacion[]"]': (medicamento.presentacion || '').slice(0, 120),
        '[name="dosis[]"]': medicamento.dosis || '',
        '[name="via[]"]': medicamento.via || '',
        '[name="periodicidad[]"]': medicamento.periodicidad || '',
        '[name="duracion[]"]': medicamento.duracion || '',
        '[name="cantidad[]"]': medicamento.cantidad || '',
        '[name="fecha_inicio[]"]': new Date().toISOString().slice(0, 10),
      };
      Object.entries(valores).forEach(([selector, valor]) => {
        if (valor) ponerEnCampo(campo(selector), valor);
      });
      const entrada = campo('[name="medicamento[]"]');
      if (entrada?.value) {
        entrada.dataset.seleccionCatalogo = entrada.value;
        llenados.add(`medicamento[${indice}]`);
      }
      fila.classList.remove('campo--dictado');
      void fila.offsetWidth;
      fila.classList.add('campo--dictado');
    });
    [...caja.querySelectorAll(':scope > [data-fila]')].forEach((fila, i) => {
      const numero = fila.querySelector('[data-fila-numero]');
      if (numero) numero.textContent = String(i + 1);
    });
  };

  const aplicar = (datos) => {
    texto.textContent = datos.texto || '';
    texto.scrollTop = texto.scrollHeight;
    const usados = new Set();
    Object.entries(datos.campos || {}).forEach(([nombre, valor]) => {
      if (nombre === 'diagnostico' && !form.elements['diagnostico']) {
        llenarDiagnostico(String(valor || ''));
        return;
      }
      const campo = campoDe(nombre);
      if (!campo || usados.has(campo)) return;                          // una casilla, un dato
      if (campo.value && !campo.dataset.dictado) return;                // respeta lo escrito a mano
      if (!ponerEnCampo(campo, String(valor ?? ''))) return;
      usados.add(campo);
      const contenedor = campo.closest('.campo');
      contenedor?.classList.remove('campo--dictado');
      void contenedor?.offsetWidth;
      contenedor?.classList.add('campo--dictado');
      llenados.add(nombre);
    });
    if (Array.isArray(datos.medicamentos) && datos.medicamentos.length) llenarMedicamentos(datos.medicamentos);
    const claves = Object.keys(datos.campos || {});
    const hayCasilla = claves.some((nombre) => nombre === 'diagnostico'
      ? !!form.querySelector('[name="diagnostico[]"]')
      : !!campoDe(nombre));
    pintarAvisos(datos.avisos);
    if (claves.length && !hayCasilla) {
      mostrarErrorDictado('Se transcribió el audio, pero este formulario no tiene una casilla compatible. Revisa el texto.', false);
    }
    actualizarBurbuja(texto.textContent);
  };

  const enviarTexto = async (final) => {
    if (!textoFinal.trim()) { if (final) terminar(); return; }
    if (enVuelo) { if (final) finalPendiente = true; return; }
    enVuelo = true;
    try {
      const respuesta = await fetch(`${BASE}/api/pacientes/dictado.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ modo: 'texto', texto: textoFinal }),
      });
      const resultado = await respuesta.json();
      if (!resultado.ok) throw new Error(resultado.error);
      if (!descartar) aplicar(resultado);
    } catch (error) {
      if (final || !texto.textContent) mostrarErrorDictado(error.message || 'No se pudo procesar el dictado.');
    } finally {
      enVuelo = false;
      if (finalPendiente) { finalPendiente = false; enviarTexto(true); } else if (final) terminar();
    }
  };

  const programarEnvio = () => {
    clearTimeout(temporizadorEnvio);
    // Cada 1.5 s: suficiente para ver el llenado en vivo sin agotar el límite del servidor
    // en dictados largos (antes se enviaba cada 0.9 s y los dictados de varios minutos se cortaban).
    temporizadorEnvio = setTimeout(() => enviarTexto(false), 1500);
  };

  /* ---------- Reconocimiento del navegador (Web Speech API) ---------- */
  const iniciarVoz = () => {
    const Reconocedor = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Reconocedor) return false;
    let rec;
    try {
      rec = new Reconocedor();
    } catch (e) {
      return false;
    }
    rec.lang = 'es-MX';
    rec.continuous = true;
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    rec.onresult = (ev) => {
      reintentosVoz = 0;
      let parcial = '';
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        const resultado = ev.results[i];
        if (resultado.isFinal) textoFinal += resultado[0].transcript.trim() + ' ';
        else parcial += resultado[0].transcript;
      }
      textoParcial = parcial;
      // Las palabras aparecen EN VIVO mientras se habla (final + parcial)
      texto.textContent = (textoFinal + textoParcial).trim();
      texto.scrollTop = texto.scrollHeight;
      actualizarBurbuja(texto.textContent);
      if (textoFinal.trim()) programarEnvio();
    };
    rec.onerror = (ev) => {
      if (ev.error === 'aborted' || ev.error === 'no-speech') return;
      // Un fallo de red suele ser pasajero (Chrome reconecta solo): se reintenta sin
      // detener el dictado. Tras varios intentos sí se avisa y se termina.
      if (ev.error === 'network' && reintentosVoz < 8) {
        reintentosVoz++;
        estadoTexto.textContent = 'Reconectando…';
        return;
      }
      reconocedor = null;
      const avisos = {
        'not-allowed': 'El navegador no tiene permiso para usar el micrófono. Habilítalo y vuelve a intentarlo.',
        'service-not-allowed': 'El servicio de voz del navegador está deshabilitado en este equipo.',
        'audio-capture': 'No se encontró un micrófono disponible.',
        network: 'El servicio de voz del navegador no está disponible. Revisa la conexión a internet.',
      };
      mostrarErrorDictado(avisos[ev.error] || 'El reconocimiento de voz del navegador se detuvo.');
    };
    rec.onend = () => {
      // Chrome corta tras un silencio largo: se relanza mientras el usuario no haya parado.
      // Al parar a propósito, los últimos resultados finales ya llegaron: se envían aquí.
      if (reconocedor === rec && paroManual) {
        enviarTexto(true);
        return;
      }
      if (reconocedor === rec && raiz.dataset.estado === 'grabando' && !paroManual) {
        const espera = reintentosVoz ? Math.min(800 * reintentosVoz, 4000) : 220;
        clearTimeout(reinicioVoz);
        reinicioVoz = setTimeout(() => {
          if (reconocedor !== rec || paroManual || raiz.dataset.estado !== 'grabando') return;
          try {
            rec.start();
            estadoTexto.textContent = `Escuchando ${segundos()} s`;
          } catch (e) {
            // Si el motor aún no termina de cerrar, se reintenta una vez más.
            reinicioVoz = setTimeout(() => {
              try { rec.start(); } catch (e2) { /* se recupera con el siguiente onend */ }
            }, 900);
          }
        }, espera);
      }
    };
    try {
      rec.start();
    } catch (e) {
      return false;
    }
    reconocedor = rec;
    return true;
  };

  /* ---------- Alternativa local en vivo para navegadores sin Web Speech (Firefox/Zen) ---------- */
  const MUESTRA_VIVO = 16000;          // el modelo local espera 16 kHz mono
  const UMBRAL_SILENCIO_MS = 700;      // pausa que cierra una frase
  const FRASE_MAX_MS = 12000;          // una frase larguísima se envía de todos modos

  const wavDeMuestras = (muestras) => {
    const largo = 44 + muestras.length * 2;
    const buffer = new ArrayBuffer(largo);
    const vista = new DataView(buffer);
    const cadena = (pos, texto) => { for (let i = 0; i < texto.length; i++) vista.setUint8(pos + i, texto.charCodeAt(i)); };
    cadena(0, 'RIFF');
    vista.setUint32(4, largo - 8, true);
    cadena(8, 'WAVEfmt ');
    vista.setUint32(16, 16, true);
    vista.setUint16(20, 1, true);
    vista.setUint16(22, 1, true);
    vista.setUint32(24, MUESTRA_VIVO, true);
    vista.setUint32(28, MUESTRA_VIVO * 2, true);
    vista.setUint16(32, 2, true);
    vista.setUint16(34, 16, true);
    cadena(36, 'data');
    vista.setUint32(40, muestras.length * 2, true);
    let pos = 44;
    for (let i = 0; i < muestras.length; i++, pos += 2) {
      const v = Math.max(-1, Math.min(1, muestras[i]));
      vista.setInt16(pos, v < 0 ? v * 0x8000 : v * 0x7fff, true);
    }
    return new Blob([buffer], { type: 'audio/wav' });
  };

  // Reentremezcla y remuestrea (lineal) el audio del navegador a 16 kHz mono.
  const procesarBloqueVivo = (entrada) => {
    const canales = entrada.numberOfChannels;
    const largo = entrada.length;
    const mono = new Float32Array(largo);
    for (let c = 0; c < canales; c++) {
      const datos = entrada.getChannelData(c);
      for (let i = 0; i < largo; i++) mono[i] += datos[i] / canales;
    }
    // La tasa real del buffer (p. ej. 48 kHz), no un contador de muestras: evita división entre cero.
    const relacion = entrada.sampleRate / MUESTRA_VIVO;
    const salida = Math.max(0, Math.floor(largo / relacion));
    const remuestreado = new Float32Array(salida);
    for (let i = 0; i < salida; i++) {
      const pos = i * relacion;
      const i0 = Math.floor(pos);
      const i1 = Math.min(i0 + 1, largo - 1);
      remuestreado[i] = mono[i0] + (mono[i1] - mono[i0]) * (pos - i0);
    }
    return remuestreado;
  };

  // Un solo envío a la vez: el modelo local transcribe de uno en uno y el texto llega en orden.
  const bombaVivo = async () => {
    if (enviandoVivo) return;
    enviandoVivo = true;
    while (colaVivo.length && !descartar) {
      const trozo = colaVivo.shift();
      try {
        const respuesta = await fetch(`${BASE}/api/pacientes/dictado_vivo.php`, {
          method: 'POST', headers: { 'Content-Type': 'audio/wav', 'X-CSRF-Token': TOKEN_CSRF }, body: trozo,
        });
        const resultado = await respuesta.json();
        if (!respuesta.ok || !resultado.ok) throw new Error(resultado.error || 'El transcriptor local no respondió.');
        const frase = (resultado.texto || '').trim();
        if (frase && !descartar) {
          textoFinal += frase + ' ';
          texto.textContent = textoFinal.trim() + (colaVivo.length ? ' …' : '');
          texto.scrollTop = texto.scrollHeight;
          actualizarBurbuja(texto.textContent);
          programarEnvio();
        }
      } catch (error) {
        colaVivo = [];
        if (!descartar) {
          mostrarErrorDictado(error.message || 'No se pudo transcribir en este equipo.');
        }
        break;
      }
    }
    enviandoVivo = false;
    // El médico ya paró y llegó la última frase: extraer campos y medicamentos del texto completo.
    if (paroLocal && !colaVivo.length && !descartar && !vivoEnviando) {
      vivoEnviando = true;
      enviarTexto(true);
    }
  };

  const enviarTrozoVivo = (muestras) => {
    if (!muestras || muestras.length < MUESTRA_VIVO * 0.25) return;   // <0.25 s: ruido
    colaVivo.push(wavDeMuestras(muestras));
    bombaVivo();
  };

  const cerrarAudioLocal = () => {
    localActivo = false;
    try { analizadorLocal?.disconnect(); } catch (e) { /* ya desconectado */ }
    analizadorLocal = null;
    if (ctxLocal && ctxLocal.state !== 'closed') ctxLocal.close().catch(() => {});
    ctxLocal = null;
    flujoLocal?.getTracks().forEach((pista) => pista.stop());
    flujoLocal = null;
    muestrasVivo = null;
  };

  const finalizarLocal = () => {
    if (!localActivo) return;
    cerrarAudioLocal();
    if (descartar) { colaVivo = []; paroLocal = false; return; }
    // La última frase (aún sin pausa que la cierre) también se transcribe antes de llenar campos.
    if (!colaVivo.length && !enviandoVivo && !vivoEnviando) {
      vivoEnviando = true;
      enviarTexto(true);
    }
  };

  const iniciarVozLocal = async () => {
    if (!navigator.mediaDevices?.getUserMedia || !window.AudioContext) {
      throw new Error('Este navegador no permite grabar desde el micrófono. Revisa que uses localhost y habilita el permiso de micrófono.');
    }
    flujoLocal = await navigator.mediaDevices.getUserMedia({
      audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    });
    if (paroManual || descartar) {
      flujoLocal.getTracks().forEach((pista) => pista.stop());
      flujoLocal = null;
      if (!descartar) terminar();
      return;
    }
    ctxLocal = new AudioContext();
    await ctxLocal.resume?.();
    const fuente = ctxLocal.createMediaStreamSource(flujoLocal);
    analizadorLocal = ctxLocal.createScriptProcessor(4096, 1, 1);
    analizadorLocal.onaudioprocess = (evento) => {
      if (!localActivo) return;
      const entrada = evento.inputBuffer;
      const remuestreado = procesarBloqueVivo(entrada);

      // Umbral adaptativo: el ruido de fondo del consultorio no debe dictar.
      let suma = 0;
      for (let i = 0; i < remuestreado.length; i++) suma += remuestreado[i] * remuestreado[i];
      const rms = Math.sqrt(suma / remuestreado.length);
      if (!hablando) {
        pisoRuido = pisoRuido ? pisoRuido * 0.95 + rms * 0.05 : rms;
        umbralVoz = Math.max(0.008, Math.min(0.06, pisoRuido * 3));
      }
      const ms = remuestreado.length * 1000 / MUESTRA_VIVO;
      if (rms >= umbralVoz) {
        if (!hablando) {
          hablando = true;
          muestrasVivo = [];
        }
        silencioMs = 0;
        muestrasVivo.push(remuestreado);
      } else if (hablando) {
        muestrasVivo.push(remuestreado);
        silencioMs += ms;
        const largoMs = muestrasVivo.reduce((n, m) => n + m.length, 0) * 1000 / MUESTRA_VIVO;
        if (silencioMs >= UMBRAL_SILENCIO_MS || largoMs >= FRASE_MAX_MS) {
          hablando = false;
          silencioMs = 0;
          const frase = new Float32Array(muestrasVivo.reduce((n, m) => n + m.length, 0));
          let pos = 0;
          for (const m of muestrasVivo) { frase.set(m, pos); pos += m.length; }
          muestrasVivo = null;
          enviarTrozoVivo(frase);
        }
      }
    };
    fuente.connect(analizadorLocal);
    analizadorLocal.connect(ctxLocal.destination);
    localActivo = true;
    modoVoz = 'local';
    estadoTexto.textContent = 'Grabando · el texto aparece mientras hablas';
  };

  const terminar = () => {
    ponerEstado('inactivo');
    if (descartar) return;
    if (!texto.textContent.trim()) {
      estadoTexto.textContent = 'No se detectó voz';
      const fila = document.createElement('p');
      fila.className = 'dictado__aviso dictado__aviso--sin-voz';
      fila.textContent = 'No se detectó voz clara. Revisa el micrófono y vuelve a intentar.';
      avisosCaja.replaceChildren(fila);
      avisosCaja.hidden = false;
      actualizarBurbuja(fila.textContent);
      return;
    }
    estadoTexto.textContent = `Dictado terminado ${Math.round(duracion || segundos())} s`;
    calificar.hidden = false;
    calificar.querySelectorAll('button').forEach((b) => { b.disabled = false; b.setAttribute('aria-pressed', 'false'); });
    actualizarBurbuja(texto.textContent || 'Dictado terminado');
    if (raiz.dataset.minimizado == '1') { try { bolita?.apagar(); } catch (e) { /* sin motor */ } }
    if (llenados.size) mostrarNotificacion('exito', `Se llenaron ${llenados.size} campos. Revísalos antes de guardar.`);
  };

  const iniciar = async () => {
    descartar = false;
    duracion = 0;
    llenados.clear();
    textoFinal = '';
    textoParcial = '';
    paroManual = false;
    modoVoz = null;
    paroLocal = false;
    vivoEnviando = false;
    colaVivo = [];
    hablando = false;
    muestrasVivo = null;
    reintentosVoz = 0;
    clearTimeout(reinicioVoz);
    inicio = Date.now();
    texto.textContent = '';
    pintarAvisos([]);
    calificar.hidden = true;
    estadoTexto.textContent = 'Escuchando 0 s';
    ponerEstado('grabando');
    mostrarPanel();
    if (raiz.dataset.minimizado == '1') { quitarBolita(); prepararBolita(); }
    clearInterval(reloj);
    reloj = setInterval(() => {
      const s = segundos();
      estadoTexto.textContent = modoVoz === 'local' ? `Grabando ${s} s · transcripción en vivo` : `Escuchando ${s} s`;
      if (s >= MAX_SEGUNDOS) detener();
    }, 1000);
    // Zen/Firefox suelen carecer de Web Speech; en ese caso el audio se procesa en el STT local.
    if (iniciarVoz()) {
      modoVoz = 'web';
      nivelesSuaves();
      return;
    }
    try {
      modoVoz = 'local-pending';
      await iniciarVozLocal();
      nivelesSuaves();
    } catch (error) {
      modoVoz = null;
      cerrarAudioLocal();
      mostrarErrorDictado(error.name === 'NotAllowedError'
        ? 'Permite el micrófono para este sitio en Zen y vuelve a intentarlo.'
        : error.message || 'No se pudo iniciar la transcripción local.');
    }
  };

  const detener = () => {
    paroManual = true;
    if (modoVoz === 'local-pending') return;
    if (modoVoz === 'local' && localActivo) {
      // La frase que se está diciendo también cuenta: se transcribe antes de llenar campos.
      if (muestrasVivo?.length) {
        const frase = new Float32Array(muestrasVivo.reduce((n, m) => n + m.length, 0));
        let pos = 0;
        for (const m of muestrasVivo) { frase.set(m, pos); pos += m.length; }
        muestrasVivo = null;
        hablando = false;
        colaVivo.push(wavDeMuestras(frase));
        bombaVivo();
      }
      paroLocal = true;
      ponerEstado('procesando');
      clearInterval(reloj);
      pararNiveles();
      estadoTexto.textContent = 'Transcribiendo en este equipo…';
      finalizarLocal();
      return;
    }
    if (!reconocedor) { terminar(); return; }
    ponerEstado('procesando');
    clearTimeout(temporizadorEnvio);
    clearTimeout(reinicioVoz);
    try { reconocedor.stop(); } catch (e) { reconocedor = null; enviarTexto(true); }
  };

  const cerrar = () => {
    descartar = true;
    paroManual = true;
    clearTimeout(temporizadorEnvio);
    clearTimeout(reinicioVoz);
    clearInterval(reloj);
    pararNiveles();
    if (reconocedor) {
      try { if (reconocedor.abort) reconocedor.abort(); else reconocedor.stop(); } catch (e) { /* ya parado */ }
      reconocedor = null;
    }
    if (localActivo || flujoLocal || ctxLocal) cerrarAudioLocal();
    colaVivo = [];
    paroLocal = false;
    vivoEnviando = false;
    if (orbita) orbita.hidden = true;
    raiz.dataset.minimizado = '';
    quitarBolita();
    ponerEstado('inactivo');
    ocultarPanel();
    barra.hidden = false;
    if (raiz.dataset.estado === 'inactivo') setTimeout(() => { raiz.hidden = true; }, 170);
  };

  boton.addEventListener('click', () => {
    if (raiz.dataset.estado === 'inactivo') iniciar();
    else if (raiz.dataset.estado === 'grabando') detener();
  });
  raiz.querySelector('[data-dictado-expandir]').addEventListener('click', () => (panel.hidden ? mostrarPanel() : ocultarPanel()));
  raiz.querySelector('[data-dictado-minimizar]').addEventListener('click', minimizar);
  raiz.querySelector('[data-dictado-cerrar]').addEventListener('click', cerrar);
  raiz.querySelector('[data-dictado-nuevo]').addEventListener('click', () => { if (raiz.dataset.estado === 'inactivo') iniciar(); });

  calificar.addEventListener('click', async (ev) => {
    const b = ev.target.closest('[data-calificacion]');
    if (!b) return;
    calificar.querySelectorAll('button').forEach((x) => { x.disabled = true; x.setAttribute('aria-pressed', String(x === b)); });
    try {
      await fetch(`${BASE}/api/pacientes/dictado_calificar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ util: b.dataset.calificacion === '1', duracion, campos: llenados.size }),
      });
      mostrarNotificacion('exito', 'Gracias, tu calificación ayuda a mejorar el dictado.');
    } catch (e) {
      mostrarNotificacion('error', 'No se pudo guardar la calificación.');
    }
  });

  // Mostrar y empezar a dictar con Alt+Shift+D (o el botón "Dictar"); otra vez lo termina
  const mostrarYDictar = () => {
    if (raiz.hidden) {
      raiz.hidden = false;
      raiz.classList.add('apareciendo');
    }
    if (posicionInicial) guardarPosicion(raiz, leerPos(CLAVE_PANEL), CLAVE_PANEL);
    if (orbita && raiz.dataset.minimizado == '1') restaurar();
    boton.click();
  };
  document.addEventListener('keydown', (ev) => {
    if (ev.altKey && ev.shiftKey && ev.key.toLowerCase() === 'd') {
      ev.preventDefault();
      mostrarYDictar();
    }
  });
  document.querySelector('[data-dictado-mostrar]')?.addEventListener('click', mostrarYDictar);
  window.addEventListener('beforeunload', () => { descartar = true; paroManual = true; });
  window.addEventListener('resize', () => {
    if (posicionInicial && !raiz.hidden) guardarPosicion(raiz, { x: raiz.offsetLeft, y: raiz.offsetTop }, CLAVE_PANEL);
    if (orbita && !orbita.hidden) guardarPosicion(orbita, { x: orbita.offsetLeft, y: orbita.offsetTop }, CLAVE_ORBITA);
  });
}

/* ---------- Historial clínico del perfil: filtros y formulario para agregar ---------- */
function iniciarHistorial(seccion) {
  // Filtro por tipo
  const filtros = seccion.querySelectorAll('[data-filtro]');
  filtros.forEach((b) => b.addEventListener('click', () => {
    filtros.forEach((x) => { x.classList.toggle('activo', x === b); x.setAttribute('aria-selected', String(x === b)); });
    seccion.querySelectorAll('.historial__evento').forEach((ev) => { ev.hidden = !!b.dataset.filtro && ev.dataset.tipo !== b.dataset.filtro; });
  }));

  const abrir = seccion.querySelector('[data-historial-abrir]');
  const form = seccion.querySelector('[data-historial-form]');
  if (!form) return;
  const mostrar = (visible) => {
    form.hidden = !visible;
    abrir.setAttribute('aria-expanded', String(visible));
    if (visible) form.querySelector('fieldset:not([disabled]) input, fieldset:not([disabled]) textarea')?.focus();
  };
  abrir.addEventListener('click', () => mostrar(true));
  seccion.querySelector('[data-historial-cancelar]').addEventListener('click', () => mostrar(false));

  // Cambiar el tipo: solo el grupo visible participa en la validación y en el envío
  const tipo = form.querySelector('[data-historial-tipo]');
  form.querySelectorAll('[data-tipo-form]').forEach((b) => b.addEventListener('click', () => {
    tipo.value = b.dataset.tipoForm;
    form.querySelectorAll('[data-tipo-form]').forEach((x) => { x.classList.toggle('activo', x === b); x.setAttribute('aria-selected', String(x === b)); });
    form.querySelectorAll('[data-grupo]').forEach((g) => {
      const activo = g.dataset.grupo === tipo.value;
      g.hidden = !activo;
      g.disabled = !activo;
    });
    actualizarFechasVacias();
  }));

  // Otro renglón de medicamento (vacío; los renglones vacíos se ignoran al guardar)
  seccion.querySelector('[data-medicamento-otro]')?.addEventListener('click', () => {
    const caja = form.querySelector('[data-medicamentos]');
    const nuevo = caja.firstElementChild.cloneNode(true);
    nuevo.querySelectorAll('input').forEach((i) => { i.value = ''; i.required = false; });
    nuevo.querySelectorAll('select').forEach((s) => { s.value = ''; s.required = false; });
    caja.appendChild(nuevo);
    nuevo.querySelector('input').focus();
  });

  form.addEventListener('submit', (ev) => {
    if (!form.checkValidity()) {
      ev.preventDefault();
      form.reportValidity();
    }
  });
  if (location.hash === '#historial' && !document.querySelector('.notificacion--error')) seccion.scrollIntoView({ block: 'start' });
  if (document.querySelector('.notificacion--error') && location.hash === '#historial') mostrar(true);
}

/* ---------- Foto de la clínica (barra lateral; solo administrador) ---------- */
function iniciarFotoClinica() {
  const entrada = document.querySelector('[data-clinica-archivo]');
  if (!entrada) return;
  entrada.addEventListener('change', async () => {
    const archivo = entrada.files[0];
    if (!archivo) return;
    const caja = entrada.closest('[data-clinica]');
    caja.classList.add('subiendo');
    const datos = new FormData();
    datos.append('foto', archivo);
    try {
      const respuesta = await fetch(`${BASE}/api/configuracion/foto_clinica.php`, {
        method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos,
      });
      const resultado = await respuesta.json();
      if (!resultado.ok) throw new Error(resultado.error);
      let img = caja.querySelector('[data-clinica-imagen]');
      if (!img) {
        img = document.createElement('img');
        img.alt = 'Foto de la clínica';
        img.dataset.clinicaImagen = '';
        caja.prepend(img);
      }
      img.src = resultado.url;
      caja.classList.remove('clinica--vacia');
      caja.querySelector('.clinica__editar span').textContent = 'Cambiar foto';
      mostrarNotificacion('exito', 'Foto de la clínica actualizada.');
    } catch (error) {
      mostrarNotificacion('error', error.message || 'No se pudo subir la foto.');
    } finally {
      caja.classList.remove('subiendo');
      entrada.value = '';
    }
  });
}

/* ---------- Asistente de IA ---------- */
// Markdown mínimo y seguro: primero se escapa el HTML y luego se dan formatos
/** Respuestas del asistente: Markdown seguro (assets/js/markdown.js). */
function markdownSeguro(texto) {
  return Markdown.render(texto);
}

// Las gráficas ya dibujadas se reutilizan mientras llega el texto (no se repite la animación en cada fragmento)
const graficasDibujadas = new Map();
/** Convierte los bloques ```grafica``` ya renderizados en gráficas interactivas. */
function montarGraficas(raiz) {
  if (typeof Graficas === 'undefined') return;
  raiz.querySelectorAll('[data-md-grafica]').forEach((caja) => {
    const clave = caja.dataset.mdGrafica;
    let seccion = graficasDibujadas.get(clave);
    if (!seccion || (seccion.isConnected && !raiz.contains(seccion))) {
      let def = null;
      try { def = JSON.parse(clave); } catch (e) { return; }
      seccion = Graficas.montar(caja, def);
      if (!seccion) { caja.textContent = 'La gráfica no tiene datos válidos.'; return; }
      graficasDibujadas.set(clave, seccion);
      if (graficasDibujadas.size > 60) graficasDibujadas.delete(graficasDibujadas.keys().next().value);
    } else {
      caja.appendChild(seccion);
    }
  });
}

// Carga el motor de la bolita aurora solo cuando se abre el asistente
let promesaAurora = null;
function cargarAurora() {
  if (promesaAurora) return promesaAurora;
  const scripts = ['vendor/aurora/react-minimo.js', 'vendor/aurora/paper-shaders.js', 'vendor/aurora/disenos.js', 'js/razonamiento.js'];
  promesaAurora = scripts.reduce((cadena, ruta) => cadena.then(() => new Promise((ok) => {
    const s = document.createElement('script');
    s.src = `${BASE}/assets/${ruta}${VERSION_APP ? `?v=${VERSION_APP}` : ""}`;
    s.onload = ok;
    s.onerror = ok;   // sin el motor, razonamiento.js usa la bolita CSS
    document.head.appendChild(s);
  })), Promise.resolve());
  return promesaAurora;
}

function iniciarAsistente() {
  const panel = document.querySelector('[data-asistente]');
  if (!panel) return;
  const enPagina = panel.dataset.modo === 'pagina';
  const boton = document.querySelector('[data-asistente-abrir]');
  const conversacion = panel.querySelector('[data-asistente-conversacion]');
  const marco = panel.querySelector('[data-asistente-marco]');
  const vacio = panel.querySelector('[data-asistente-vacio]');
  const form = panel.querySelector('[data-asistente-form]');
  const texto = panel.querySelector('[data-entrada-texto]');
  const enviar = panel.querySelector('[data-entrada-enviar]');
  const info = panel.querySelector('[data-asistente-info]');
  const ajustes = panel.querySelector('[data-asistente-panel-ajustes]');
  const adjuntosCaja = panel.querySelector('[data-entrada-adjuntos]');
  const chipHerramienta = panel.querySelector('[data-herramienta-actual]');
  const raizHtml = document.documentElement;
  const CLAVE = `expediente-asistente-${document.body.dataset.usuario ?? ''}`;
  const pacienteId = document.body.dataset.paciente ? Number(document.body.dataset.paciente) : null;
  const paginaId = document.body.dataset.pagina ? Number(document.body.dataset.pagina) : null;

  let estado = { configurado: false };
  let chats = [];
  let chatActual = null;
  let adjuntos = [];
  // El acceso de arranque lo marca el servidor en el panel (data-acceso-defecto):
  // «expediente» solo si el rol puede ver pacientes, «restringido» en cualquier otro caso.
  // Nunca se guarda en la conversación: cada página decide de nuevo y siempre se puede cambiar con el escudo.
  const opciones = {
    acceso: panel.dataset.accesoDefecto === 'expediente' ? 'expediente' : 'restringido',
    herramienta: 'ninguna',
    esfuerzo: 'medium',
  };
  let peticion = null;

  /* --- Conversaciones guardadas en la sesión del navegador (se borran al cerrar sesión) --- */
  try {
    const guardado = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
    if (guardado?.chats) { chats = guardado.chats; chatActual = guardado.actual; opciones.esfuerzo = guardado.esfuerzo || 'medium'; }
  } catch (e) { /* sin almacenamiento */ }
  const chat = () => chats.find((c) => c.id === chatActual) || null;
  const historial = () => chat()?.historial ?? [];
  const guardar = () => {
    chats = chats.filter((c) => c.historial.length).slice(0, 20);
    try { sessionStorage.setItem(CLAVE, JSON.stringify({ chats, actual: chatActual, esfuerzo: opciones.esfuerzo })); } catch (e) { /* lleno */ }
  };
  const tituloChat = panel.querySelector('[data-chat-titulo]');
  const pintarTitulo = () => { tituloChat.textContent = chat()?.titulo || 'Nuevo chat con la IA'; };

  /* --- Abrir, cerrar y cambiar el ancho (panel) --- */
  try {
    const ancho = Number(localStorage.getItem('asistente-ancho'));
    if (ancho >= 360) raizHtml.style.setProperty('--ancho-asistente', `${ancho}px`);
  } catch (e) { /* preferencia opcional */ }

  let iniciado = false;
  const preparar = async () => {
    await cargarAurora();
    if (iniciado) return;
    iniciado = true;
    await cargarEstado();
    pintarHistorial();
    // Aviso informativo una sola vez por sesión: el acceso al expediente nace activado para
    // roles que pueden ver expedientes, para que las preguntas sobre pacientes funcionen de una vez.
    if (opciones.acceso === 'expediente' && !sessionStorage.getItem(`${CLAVE}-aviso-acceso`)) {
      try { sessionStorage.setItem(`${CLAVE}-aviso-acceso`, '1'); } catch (e) { /* opcional */ }
      avisarAcceso();
    }
  };
  const abrir = async () => {
    if (!document.querySelector('[data-panel-actividad]')?.hidden) document.querySelector('[data-panel-cerrar]')?.click();
    panel.hidden = false;
    boton?.setAttribute('aria-expanded', 'true');
    raizHtml.classList.add('asistente-abierto');
    try { sessionStorage.setItem(`${CLAVE}-abierto`, '1'); } catch (e) { /* opcional */ }
    texto.focus();
    try {
      await preparar();
    } catch (error) {
      if (typeof mostrarNotificacion === 'function') mostrarNotificacion('error', 'No se pudo iniciar el asistente.');
    }
  };
  const cerrar = () => {
    panel.hidden = true;
    boton?.setAttribute('aria-expanded', 'false');
    raizHtml.classList.remove('asistente-abierto');
    try { sessionStorage.removeItem(`${CLAVE}-abierto`); } catch (e) { /* opcional */ }
  };
  if (enPagina) {
    preparar();
    texto.focus();
  } else {
    boton.addEventListener('click', (ev) => {
      ev.preventDefault();
      abrir();
    });
    panel.querySelector('[data-asistente-cerrar]').addEventListener('click', cerrar);
    try { if (sessionStorage.getItem(`${CLAVE}-abierto`)) abrir(); } catch (e) { /* opcional */ }

    const tirador = panel.querySelector('[data-asistente-tirador]');
    const fijarAncho = (px) => {
      const ancho = Math.round(Math.min(Math.max(px, 360), window.innerWidth * 0.6));
      raizHtml.style.setProperty('--ancho-asistente', `${ancho}px`);
      try { localStorage.setItem('asistente-ancho', String(ancho)); } catch (e) { /* opcional */ }
    };
    tirador.addEventListener('pointerdown', (ev) => {
      ev.preventDefault();
      tirador.setPointerCapture(ev.pointerId);
      raizHtml.classList.add('asistente-redimensionando');
      const derecha = panel.getBoundingClientRect().right;
      const mover = (e) => fijarAncho(derecha - e.clientX);
      const soltar = () => {
        raizHtml.classList.remove('asistente-redimensionando');
        tirador.removeEventListener('pointermove', mover);
        tirador.removeEventListener('pointerup', soltar);
      };
      tirador.addEventListener('pointermove', mover);
      tirador.addEventListener('pointerup', soltar);
    });
    tirador.addEventListener('keydown', (ev) => {
      const actual = panel.getBoundingClientRect().width;
      if (ev.key === 'ArrowLeft') { ev.preventDefault(); fijarAncho(actual + 24); }
      if (ev.key === 'ArrowRight') { ev.preventDefault(); fijarAncho(actual - 24); }
    });
  }

  /* --- Estado y configuración del proveedor --- */
  async function cargarEstado() {
    try {
      estado = await (await fetch(`${BASE}/api/asistente/configuracion.php`)).json();
    } catch (e) { estado = { configurado: false }; }
    panel.querySelector('[data-entrada-modelo]').textContent = estado.configurado ? `Modelo: ${estado.modelo}` : 'Sin modelo configurado';
    info.textContent = estado.configurado
      ? (estado.local ? 'Modelo local · nada sale del equipo' : '')
      : (estado.es_admin ? 'Sin configurar · abre la configuración' : 'Sin configurar');
    panel.querySelector('[data-asistente-ajustes]').hidden = !estado.es_admin;
    if (!iniciado && estado.esfuerzo_defecto && !sessionStorage.getItem(CLAVE)) opciones.esfuerzo = estado.esfuerzo_defecto;
    if (!estado.configurado && estado.es_admin && !historial().length) mostrarAjustes();
  }

  /* --- Configuración: la clave detecta el proveedor y carga sus modelos --- */
  const formAjustes = panel.querySelector('[data-ajustes-form]');
  const selectServicio = panel.querySelector('[data-ajuste-servicio]');
  const campoModelo = panel.querySelector('[data-ajuste-modelo]');
  const listaModelos = panel.querySelector('[data-lista-modelos]');
  const detectado = panel.querySelector('[data-ajuste-detectado]');
  const resultado = panel.querySelector('[data-ajustes-resultado]');
  const botonServicio = panel.querySelector('[data-ajuste-servicio-boton]');
  const listaServicios = panel.querySelector('[data-ajuste-servicios]');
  const cuentaModelos = panel.querySelector('[data-ajuste-cuenta]');
  let modelos = [];
  let esfuerzoAjuste = 'medium';
  // Si el administrador elige el proveedor a mano, pegar la clave no debe cambiarle la elección
  // (las claves de pasarelas compatibles suelen empezar por «sk-» y se confundirían con OpenAI).
  let servicioManual = false;

  // Proveedor: lista propia (sin <select> del navegador) con monograma de cada servicio
  const monograma = (nombre) => escaparHtml((nombre || '?').replace(/[^A-Za-zÁÉÍÓÚÑ0-9 ]/g, '').split(/\s+/).map((p) => p[0]).join('').slice(0, 2).toUpperCase());
  const pintarServicio = () => {
    const s = estado.servicios?.[selectServicio.value] || {};
    panel.querySelector('[data-ajuste-servicio-monograma]').innerHTML = monograma(s.nombre);
    panel.querySelector('[data-ajuste-servicio-nombre]').textContent = s.nombre ? `${s.nombre}${s.local ? ' · en este equipo' : ''}` : 'Elige un proveedor';
    listaServicios.querySelectorAll('[data-servicio]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.servicio === selectServicio.value)));
  };
  const cerrarServicios = () => { listaServicios.hidden = true; botonServicio.setAttribute('aria-expanded', 'false'); };
  const elegirServicio = (valor) => {
    if (valor === selectServicio.value) { cerrarServicios(); return; }
    servicioManual = true;
    selectServicio.value = valor;
    formAjustes.url.value = '';
    campoModelo.value = '';
    modelos = [];
    listaModelos.hidden = true;
    cuentaModelos.textContent = '';
    pintarServicio();
    actualizarServicio();
    const sugerido = estado.servicios?.[valor]?.modelo;
    if (sugerido) campoModelo.value = sugerido;
    cerrarServicios();
    if (estado.servicios?.[valor]?.local) cargarModelos();
  };
  botonServicio.addEventListener('click', () => {
    const abrir = listaServicios.hidden;
    listaServicios.hidden = !abrir;
    botonServicio.setAttribute('aria-expanded', String(abrir));
  });
  listaServicios.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-servicio]');
    if (b) elegirServicio(b.dataset.servicio);
  });
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.desplegable-ia')) cerrarServicios(); });
  panel.querySelector('[data-ajuste-ver]').addEventListener('click', (ev) => {
    const ver = formAjustes.clave.type === 'password';
    formAjustes.clave.type = ver ? 'text' : 'password';
    ev.currentTarget.classList.toggle('activo', ver);
  });
  // Límite: contador con pasos de 5 (de 5 a 200)
  const fijarLimite = (n) => { formAjustes.limite.value = String(Math.min(200, Math.max(5, Math.round(Number(n) || 30)))); };
  panel.querySelectorAll('[data-ajuste-limite] [data-paso]').forEach((b) => b.addEventListener('click', () => fijarLimite(Number(formAjustes.limite.value) + Number(b.dataset.paso))));
  formAjustes.limite.addEventListener('change', () => fijarLimite(formAjustes.limite.value));
  formAjustes.limite.addEventListener('input', () => { formAjustes.limite.value = formAjustes.limite.value.replace(/\D/g, '').slice(0, 3); });

  // Prefijos conocidos de claves de API
  const detectarServicio = (clave) => {
    const c = clave.trim();
    if (c.startsWith('sk-or-')) return 'openrouter';
    if (c.startsWith('sk-ant-')) return 'anthropic';
    if (c.startsWith('gsk_')) return 'groq';
    if (c.startsWith('AIza')) return 'gemini';
    if (c.startsWith('sk-')) return 'openai';
    return null;
  };
  const datosAjustes = (accion) => ({
    accion, servicio: selectServicio.value, url: formAjustes.url.value, modelo: campoModelo.value.trim(), clave: formAjustes.clave.value,
    esfuerzo: esfuerzoAjuste, herramientas: formAjustes.herramientas.checked, limite: Number(formAjustes.limite.value) || 30,
    instrucciones: formAjustes.instrucciones.value,
  });
  const enviarAjustes = async (accion) => {
    const r = await fetch(`${BASE}/api/asistente/configuracion.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF }, body: JSON.stringify(datosAjustes(accion)),
    });
    return r.json();
  };
  const actualizarServicio = () => {
    const s = estado.servicios?.[selectServicio.value];
    if (!s) return;
    const anthropic = s.api === 'anthropic';
    panel.querySelector('[data-ajuste-url-caja]').hidden = anthropic || (selectServicio.value !== 'personalizado' && !s.local);
    if (selectServicio.value !== estado.servicio || !formAjustes.url.value) formAjustes.url.value = s.url;
    panel.querySelector('[data-ajuste-destino]').textContent = s.local
      ? 'Modelo local: las preguntas no salen de este equipo.'
      : `Las preguntas se envían a ${anthropic ? 'api.anthropic.com' : (s.url ? new URL(s.url).hostname : 'el servidor que indiques')}. Los datos de pacientes solo se envían con «Acceso al expediente» y cada envío queda en la bitácora.`;
  };
  const pintarModelos = () => {
    const termino = normalizarTexto(campoModelo.value.trim());
    const visibles = modelos.filter((m) => !termino || coincideBusqueda(`${m.id} ${m.nombre}`, termino)).slice(0, 80);
    listaModelos.hidden = !modelos.length;
    listaModelos.innerHTML = visibles.length
      ? visibles.map((m) => `<li role="option" data-modelo="${escaparHtml(m.id)}" aria-selected="${m.id === campoModelo.value}"><b>${escaparHtml(m.nombre || m.id)}</b><small>${escaparHtml(m.id)}${m.contexto ? ` · ${Math.round(m.contexto / 1000)}k de contexto` : ''}</small></li>`).join('')
      : '<li class="ajustes-ia__vacio">Ningún modelo coincide.</li>';
  };
  const cargarModelos = async () => {
    const boton = panel.querySelector('[data-ajustes-modelos]');
    boton.disabled = true;
    boton.classList.add('girando');
    cuentaModelos.textContent = 'Cargando…';
    try {
      const r = await enviarAjustes('modelos');
      if (!r.ok) throw new Error(r.error);
      modelos = r.modelos;
      pintarModelos();
      cuentaModelos.textContent = `${modelos.length} modelos`;
    } catch (e) {
      cuentaModelos.textContent = '';
      mostrarNotificacion('error', e.message || 'No se pudo obtener la lista de modelos.');
    } finally { boton.disabled = false; boton.classList.remove('girando'); }
  };

  function mostrarAjustes() {
    const servicios = Object.entries(estado.servicios || {});
    listaServicios.innerHTML = [['En la nube', servicios.filter(([, s]) => !s.local && s.url !== '')], ['En este equipo y otros', servicios.filter(([, s]) => s.local || s.url === '')]]
      .filter(([, l]) => l.length)
      .map(([titulo, l]) => `<div class="desplegable-ia__titulo">${titulo}</div>` + l.map(([v, s]) => `<button type="button" role="option" data-servicio="${v}"><span class="desplegable-ia__monograma">${monograma(s.nombre)}</span><span>${escaparHtml(s.nombre)}</span><svg class="desplegable-ia__marca" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 12.5 4 4 8-9"/></svg></button>`).join(''))
      .join('');
    selectServicio.value = estado.servicio || 'openrouter';
    servicioManual = false;
    pintarServicio();
    cerrarServicios();
    formAjustes.url.value = estado.url || '';
    campoModelo.value = estado.modelo || '';
    formAjustes.clave.value = '';
    formAjustes.clave.type = 'password';
    panel.querySelector('[data-ajuste-clave-ayuda]').textContent = estado.clave_ajena
      ? `La clave guardada es de ${estado.clave_ajena}. Pega la de ${estado.servicios?.[selectServicio.value]?.nombre || 'este proveedor'}.`
      : (estado.clave_final
        ? `Guardada y cifrada (termina en ${estado.clave_final}). Pega otra solo si quieres cambiarla.`
        : 'Al pegarla se reconoce el proveedor y se cargan sus modelos.');
    formAjustes.herramientas.checked = estado.herramientas !== false;
    fijarLimite(estado.limite || 30);
    formAjustes.instrucciones.value = estado.instrucciones || '';
    esfuerzoAjuste = estado.esfuerzo_defecto || 'medium';
    panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.valor === esfuerzoAjuste)));
    panel.querySelector('[data-ajustes-estado]').textContent = estado.clave_ajena
      ? 'Clave de otro proveedor'
      : (estado.configurado ? `Conectado · ${estado.modelo}` : 'Sin configurar');
    panel.querySelector('[data-ajustes-estado]').classList.toggle('activo', !!estado.configurado && !estado.clave_ajena);
    const uso = estado.uso || {};
    panel.querySelector('[data-ajuste-uso]').innerHTML = [
      ['Preguntas', uso.preguntas], ['Personas', uso.personas], ['Con datos del expediente', uso.expediente], ['Acciones de la IA', uso.acciones],
    ].map(([t, n]) => `<div><dt>${t}</dt><dd>${n ?? 0}</dd></div>`).join('');
    detectado.textContent = '';
    resultado.textContent = '';
    modelos = [];
    listaModelos.hidden = true;
    cuentaModelos.textContent = '';
    actualizarServicio();
    ajustes.hidden = false;
    if (estado.configurado || estado.servicios?.[selectServicio.value]?.local) cargarModelos();
  }

  // Al pegar la clave: se detecta el proveedor y aparecen sus modelos
  formAjustes.clave.addEventListener('input', () => {
    const servicio = servicioManual ? null : detectarServicio(formAjustes.clave.value);
    if (servicio && servicio !== selectServicio.value) {
      selectServicio.value = servicio;
      formAjustes.url.value = estado.servicios[servicio].url;
      campoModelo.value = '';
      pintarServicio();
      actualizarServicio();
    }
    detectado.textContent = servicio ? estado.servicios[servicio].nombre : '';
    clearTimeout(formAjustes._modelos);
    if (formAjustes.clave.value.trim().length > 20) formAjustes._modelos = setTimeout(cargarModelos, 350);
  });
  campoModelo.addEventListener('input', pintarModelos);
  campoModelo.addEventListener('focus', () => { if (modelos.length) pintarModelos(); });
  listaModelos.addEventListener('click', (ev) => {
    const li = ev.target.closest('[data-modelo]');
    if (!li) return;
    campoModelo.value = li.dataset.modelo;
    pintarModelos();
  });
  panel.querySelector('[data-ajustes-modelos]').addEventListener('click', cargarModelos);
  panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((b) => b.addEventListener('click', () => {
    esfuerzoAjuste = b.dataset.valor;
    panel.querySelectorAll('[data-ajuste-esfuerzo] button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
  }));
  panel.querySelector('[data-asistente-ajustes]').addEventListener('click', () => (ajustes.hidden ? mostrarAjustes() : (ajustes.hidden = true)));
  panel.querySelector('[data-ajustes-cerrar]').addEventListener('click', () => { ajustes.hidden = true; });

  formAjustes.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const r = await enviarAjustes('guardar');
    if (!r.ok) { mostrarNotificacion('error', r.error); return; }
    mostrarNotificacion('exito', 'Configuración guardada. La clave quedó cifrada en el servidor.');
    ajustes.hidden = true;
    await cargarEstado();
  });
  panel.querySelector('[data-ajustes-probar]').addEventListener('click', async (ev) => {
    const b = ev.currentTarget;
    b.disabled = true; b.textContent = 'Probando…';
    resultado.textContent = '';
    resultado.className = 'ajustes-ia__prueba';
    try {
      const g = await enviarAjustes('guardar');
      if (!g.ok) throw new Error(g.error);
      estado = { ...estado, ...g };
      const r = await enviarAjustes('probar');
      if (!r.ok) throw new Error(r.error);
      resultado.textContent = `Conectado · respondió en ${(r.ms / 1000).toFixed(1)} s`;
      resultado.classList.add('ok');
      await cargarEstado();
    } catch (e) {
      resultado.textContent = e.message || 'No se pudo conectar.';
      resultado.classList.add('error');
    } finally {
      b.disabled = false; b.textContent = 'Probar';
    }
  });

  /* --- Menús de la barra de entrada: "+" (adjuntar, dictar, herramienta), acceso y esfuerzo --- */
  const HERRAMIENTAS = { normativa: 'Normativa', pacientes: 'Lista de pacientes', paciente: 'Paciente abierto', pagina: 'Página abierta' };
  const ESFUERZOS = { low: 'Rápido', medium: 'Auto', high: 'Profundo' };
  const cerrarMenus = () => panel.querySelectorAll('[data-menu]').forEach((m) => {
    m.hidden = true;
    panel.querySelector(`[data-menu-boton="${m.dataset.menu}"]`).setAttribute('aria-expanded', 'false');
  });
  const avisarAcceso = () => {
    const destino = estado.local ? 'tu modelo local (no sale del equipo)' : (estado.proveedor === 'anthropic' ? 'Anthropic' : (estado.url || 'el proveedor'));
    agregarAviso(`Con acceso al expediente, los datos de pacientes y páginas que elijas en la herramienta se enviarán a ${destino}. Cada envío queda en la bitácora. Puedes cambiarlo con el botón de escudo de la caja de pregunta.`);
  };
  const fijarAcceso = (valor) => {
    if (opciones.acceso === valor) return;
    opciones.acceso = valor;
    if (valor === 'restringido' && ['pacientes', 'paciente', 'pagina'].includes(opciones.herramienta)) opciones.herramienta = 'ninguna';
    if (valor === 'expediente') avisarAcceso();
    pintarOpciones();
  };
  const pintarOpciones = () => {
    panel.querySelector('[data-menu-texto="esfuerzo"]').textContent = ESFUERZOS[opciones.esfuerzo];
    panel.querySelectorAll('[data-menu="esfuerzo"] [data-valor]').forEach((li) => li.setAttribute('aria-checked', String(li.dataset.valor === opciones.esfuerzo)));
    panel.querySelectorAll('[data-menu="acceso"] [data-valor]').forEach((li) => li.setAttribute('aria-checked', String(li.dataset.valor === opciones.acceso)));
    panel.querySelectorAll('[data-herramienta]').forEach((li) => {
      li.setAttribute('aria-checked', String(li.dataset.herramienta === opciones.herramienta));
      const bloqueado = ('requiereExpediente' in li.dataset && opciones.acceso !== 'expediente')
        || ('requierePaciente' in li.dataset && !pacienteId) || ('requierePagina' in li.dataset && !paginaId);
      li.setAttribute('aria-disabled', String(bloqueado));
    });
    const acceso = panel.querySelector('[data-menu-boton="acceso"]');
    acceso.classList.toggle('entrada__icono--expediente', opciones.acceso === 'expediente');
    acceso.title = opciones.acceso === 'expediente' ? 'Acceso al expediente: se pueden compartir datos con el modelo' : 'Acceso restringido: no se envía ningún dato de pacientes';
    acceso.innerHTML = panel.querySelector(`[data-menu="acceso"] [data-valor="${opciones.acceso}"] svg`).outerHTML;
    chipHerramienta.hidden = opciones.herramienta === 'ninguna';
    chipHerramienta.innerHTML = opciones.herramienta === 'ninguna' ? ''
      : `${panel.querySelector(`[data-herramienta="${opciones.herramienta}"] svg`).outerHTML}<span>${HERRAMIENTAS[opciones.herramienta]}</span><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>`;
    chipHerramienta.title = 'Quitar la herramienta';
  };
  chipHerramienta.addEventListener('click', () => { opciones.herramienta = 'ninguna'; pintarOpciones(); texto.focus(); });
  // Otras partes de la app (sugerencias del expediente, IA en notas) le pasan una petición lista para enviar.
  // El acceso al expediente solo se activa por un clic explícito del usuario en esa acción, y se avisa como siempre.
  window.preguntarAsistente = async (pregunta, { acceso = null, herramienta = null } = {}) => {
    if (panel.hidden) await abrir();
    if (acceso) fijarAcceso(acceso);
    if (herramienta && !(herramienta === 'paciente' && !pacienteId)) opciones.herramienta = herramienta;
    pintarOpciones();
    texto.value = pregunta;
    texto.dispatchEvent(new Event('input', { bubbles: true }));
    requestAnimationFrame(() => form.requestSubmit());
  };
  panel.querySelectorAll('[data-menu-boton]').forEach((b) => b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const menu = panel.querySelector(`[data-menu="${b.dataset.menuBoton}"]`);
    const abrirlo = menu.hidden;
    cerrarMenus();
    menu.hidden = !abrirlo;
    b.setAttribute('aria-expanded', String(abrirlo));
  }));
  panel.querySelectorAll('[data-menu] li[role^="menuitem"]').forEach((li) => li.addEventListener('click', () => {
    if (li.getAttribute('aria-disabled') === 'true') return;
    const menu = li.parentElement.dataset.menu;
    if (li.dataset.herramienta) opciones.herramienta = li.dataset.herramienta;
    if (menu === 'acceso') fijarAcceso(li.dataset.valor);
    if (menu === 'esfuerzo') { opciones.esfuerzo = li.dataset.valor; guardar(); }
    if (li.dataset.accionEntrada === 'adjuntar') archivo.click();
    if (li.dataset.accionEntrada === 'dictar') dictar();
    pintarOpciones();
    cerrarMenus();
  }));
  document.addEventListener('click', (ev) => { if (!ev.target.closest('.entrada__menu')) cerrarMenus(); });

  /* --- Adjuntar archivos de texto --- */
  const archivo = panel.querySelector('[data-entrada-archivo]');
  archivo.addEventListener('change', async () => {
    const f = archivo.files[0];
    archivo.value = '';
    if (!f) return;
    if (f.size > 200 * 1024) { mostrarNotificacion('error', 'El archivo es muy grande (máximo 200 KB de texto).'); return; }
    adjuntos.push({ nombre: f.name, contenido: await f.text() });
    pintarAdjuntos();
  });
  const pintarAdjuntos = () => {
    adjuntosCaja.hidden = !adjuntos.length;
    adjuntosCaja.innerHTML = adjuntos.map((a, i) => `<span class="entrada__adjunto">${escaparHtml(a.nombre)}<button type="button" data-quitar="${i}" aria-label="Quitar"><svg class="icono" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button></span>`).join('');
    actualizarEnviar();
  };
  adjuntosCaja.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-quitar]');
    if (b) { adjuntos.splice(Number(b.dataset.quitar), 1); pintarAdjuntos(); }
  });

  /* --- Dictar la pregunta con el servicio local de voz --- */
  const mic = panel.querySelector('[data-entrada-mic]');
  let grabadora = null;
  async function dictar() {
    if (grabadora?.state === 'recording') { grabadora.stop(); return; }
    let flujo;
    try { flujo = await navigator.mediaDevices.getUserMedia({ audio: true }); }
    catch (e) { mostrarNotificacion('error', 'No se pudo usar el micrófono. Revisa el permiso del navegador.'); return; }
    const trozos = [];
    grabadora = new MediaRecorder(flujo);
    grabadora.ondataavailable = (e) => { if (e.data.size) trozos.push(e.data); };
    grabadora.onstop = async () => {
      flujo.getTracks().forEach((t) => t.stop());
      mic.hidden = true;
      const datos = new FormData();
      datos.append('audio', new Blob(trozos, { type: grabadora.mimeType }), 'pregunta.webm');
      try {
        const r = await (await fetch(`${BASE}/api/pacientes/dictado.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos })).json();
        if (!r.ok) throw new Error(r.error);
        texto.value = (texto.value ? `${texto.value} ` : '') + r.texto;
        ajustarAltura();
        texto.focus();
      } catch (e) { mostrarNotificacion('error', e.message || 'No se pudo transcribir.'); }
    };
    grabadora.start();
    mic.hidden = false;
  }
  mic.addEventListener('click', () => grabadora?.stop());

  /* --- Sugerencias del estado vacío --- */
  const preparar_pregunta = (t) => { texto.value = t; ajustarAltura(); texto.focus(); texto.setSelectionRange(t.length, t.length); };
  panel.addEventListener('click', (ev) => {
    const s = ev.target.closest('[data-sugerencia]')?.dataset.sugerencia;
    if (!s) return;
    if (s === 'buscar') { abrirPaleta(); return; }
    if (s === 'archivo') { archivo.click(); return; }
    if (s === 'normativa') { opciones.herramienta = 'normativa'; preparar_pregunta('¿Qué debe contener una nota de evolución según la NOM-004? '); }
    if (s === 'nota_clinica') { opciones.herramienta = 'normativa'; preparar_pregunta('Redacta una nota de evolución con los apartados de la NOM-004 y créala como página a partir de estos datos: '); }
    if (s === 'base_datos_nueva') { preparar_pregunta('Crea una base de datos llamada «» con las columnas: '); }
    if (s === 'pacientes') { fijarAcceso('expediente'); opciones.herramienta = 'pacientes'; preparar_pregunta('¿Cuántos pacientes hay registrados y qué padecimientos son más frecuentes?'); }
    if (s === 'paciente') { fijarAcceso('expediente'); opciones.herramienta = 'paciente'; preparar_pregunta('Resume el expediente de este paciente.'); }
    if (s === 'resumen_paciente') {
      fijarAcceso('expediente'); opciones.herramienta = 'paciente';
      preparar_pregunta('Revisa el expediente de este paciente y resúmelo: primero las alertas importantes, luego antecedentes, tratamiento actual, evolución reciente (con gráfica de signos vitales si hay datos) y pendientes.');
      pintarOpciones(); actualizarEnviar();
      setTimeout(() => enviar.click(), 60);
      return;
    }
    if (s === 'pagina') { fijarAcceso('expediente'); opciones.herramienta = 'pagina'; preparar_pregunta('Resume esta página.'); }
    if (s === 'base_datos') { fijarAcceso('expediente'); opciones.herramienta = 'pagina'; preparar_pregunta('Ayúdame a configurar esta base de datos: ¿qué propiedades me recomiendas agregar?'); }
    pintarOpciones();
    actualizarEnviar();
  });

  /* --- Lista de chats ("Nuevo chat con la IA ▾") --- */
  const botonChats = panel.querySelector('[data-asistente-chats]');
  botonChats.addEventListener('click', () => {
    const hace = (ms) => {
      const min = Math.round((Date.now() - ms) / 60000);
      return min < 1 ? 'ahora' : min < 60 ? `hace ${min} min` : `hace ${Math.round(min / 60)} h`;
    };
    abrirMenu(botonChats, [
      { icono: 'agregar', texto: 'Nuevo chat', accion: nuevoChat },
      ...(chats.length ? ['separador', { titulo: 'En esta sesión' }] : []),
      ...chats.map((c) => ({ texto: c.titulo, detalle: hace(c.actualizado), marcado: c.id === chatActual, accion: () => {
        peticion?.abort();
        chatActual = c.id;
        guardar();
        pintarHistorial();
      } })),
    ]);
  });
  function nuevoChat() {
    peticion?.abort();
    chatActual = null;
    guardar();
    pintarHistorial();
    texto.focus();
  }
  panel.querySelector('[data-asistente-nueva]').addEventListener('click', nuevoChat);

  /* --- Conversación --- */
  const actualizarEnviar = () => { enviar.disabled = !peticion && !texto.value.trim() && !adjuntos.length; };
  const ajustarAltura = () => { texto.style.height = 'auto'; texto.style.height = `${Math.min(texto.scrollHeight, 200)}px`; actualizarEnviar(); };
  texto.addEventListener('input', ajustarAltura);
  texto.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); form.requestSubmit(); }
  });

  const alFinal = () => { conversacion.scrollTop = conversacion.scrollHeight; };
  const conversando = () => panel.classList.toggle('asistente--conversando', historial().length > 0 || !!conversacion.querySelector('.msj'));
  const agregarAviso = (t) => {
    vacio.hidden = true;
    const d = document.createElement('div');
    d.className = 'msj msj__aviso';
    d.textContent = t;
    conversacion.appendChild(d);
    conversando();
    alFinal();
  };
  const nodoUsuario = (m) => {
    const d = document.createElement('div');
    d.className = 'msj msj--usuario';
    d.textContent = m.visible ?? m.texto;
    (m.adjuntos || []).forEach((n) => {
      const a = document.createElement('div');
      a.className = 'msj__adjunto';
      a.innerHTML = '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg><span></span>';
      a.querySelector('span').textContent = n;
      d.appendChild(a);
    });
    return d;
  };
  // Icono de cada acción según la herramienta que la hizo
  const TEMA_HERRAMIENTA = {
    buscar_paginas: 'buscar', buscar_pacientes: 'buscar', leer_pagina: 'leer', leer_paciente: 'paciente',
    crear_pagina: 'escribir', escribir_nota: 'escribir', renombrar_pagina: 'escribir', mover_pagina: 'planear', mover_a_papelera: 'planear',
    agregar_columnas: 'datos', agregar_filas: 'datos', actualizar_filas: 'datos', eliminar_filas: 'datos', editar_columna: 'datos',
    registrar_paciente: 'paciente', actualizar_paciente: 'paciente', agregar_historial: 'clinica', revisar_expediente: 'clinica', crear_grafica: 'grafica',
    buscar_en_internet: 'web', leer_sitio_web: 'web', insertar_imagen: 'imagen', ver_agenda: 'agenda', agendar_cita: 'agenda',
  };
  const ICONO_IMAGEN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.6-4.6a1.2 1.2 0 0 0-1.7 0L6 19.5"/></svg>';
  const iconoAccion = (nombre) => {
    const tema = TEMA_HERRAMIENTA[nombre] || 'idea';
    if (tema === 'grafica') return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v16h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/></svg>';
    return tema === 'imagen' ? ICONO_IMAGEN : (window.Razonamiento?.icono(tema) || '');
  };
  const nodoAccion = (a) => {
    // Con enlace, toda la acción abre lo que se creó o consultó (página, cita, expediente)
    const enlaceSeguro = typeof a.url === 'string' && (a.url.startsWith(`${BASE}/`) || a.url.startsWith('/')) && !a.url.startsWith('//');
    const n = document.createElement(enlaceSeguro ? 'a' : 'div');
    if (enlaceSeguro) { n.href = a.url; n.title = 'Abrir'; }
    n.className = `msj__accion${a.ok ? '' : ' msj__accion--error'}${enlaceSeguro ? ' msj__accion--enlace' : ''}`;
    const estado = a.ok
      ? '<path d="m6.5 12.5 3.5 3.5 7.5-8"/>'
      : '<path d="M12 7.5v5.5M12 16.5h.01"/>';
    n.innerHTML = `<span class="msj__accion-icono" aria-hidden="true">${iconoAccion(a.nombre)}</span><span class="msj__accion-texto"></span>`
      + `<svg class="msj__accion-estado" viewBox="0 0 24 24" aria-hidden="true">${estado}</svg>`;
    n.querySelector('.msj__accion-texto').textContent = a.d;
    if (a.vista) {
      // Lo que encontró la investigación: fuentes con su dominio o una galería de imágenes
      const caja = document.createElement('div');
      caja.className = 'msj__vista';
      const fuentes = (a.vista.fuentes || []).filter((f) => /^https?:\/\//.test(f.url));
      const imagenes = (a.vista.imagenes || []).filter((i) => /^https?:\/\//.test(i.url));
      if (fuentes.length) {
        caja.innerHTML = `<div class="msj__fuentes">${fuentes.map((f) => {
          let host = '';
          try { host = new URL(f.url).hostname.replace(/^www\./, ''); } catch (e) { /* sin dominio */ }
          return `<a class="msj__fuente" href="${escaparHtml(f.url)}" target="_blank" rel="noopener noreferrer" title="${escaparHtml(f.titulo || '')}">${iconoAccion('buscar_en_internet')}<span>${escaparHtml(host)}</span></a>`;
        }).join('')}</div>`;
      }
      if (imagenes.length) {
        caja.innerHTML += `<div class="msj__galeria">${imagenes.map((i) => `<a href="${escaparHtml(i.pagina || i.url)}" target="_blank" rel="noopener noreferrer"><img alt="${escaparHtml(i.titulo || '')}" loading="lazy" src="${BASE}/api/asistente/imagen.php?u=${encodeURIComponent(i.url)}"><small>${escaparHtml(i.titulo || '')}</small></a>`).join('')}</div>`;
        caja.querySelectorAll('img').forEach((img) => {
          img.addEventListener('load', () => img.classList.add('cargada'));
          img.addEventListener('error', () => img.closest('a').remove());
        });
      }
      n._vista = caja;
    }
    if (enlaceSeguro) n.insertAdjacentHTML('beforeend', '<svg class="msj__accion-ir" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>');
    return n;
  };
  const nodoAsistente = (contenido, acciones = []) => {
    const d = document.createElement('div');
    d.className = 'msj msj--asistente';
    acciones.forEach((a) => { const n = nodoAccion(a); d.appendChild(n); if (n._vista) d.appendChild(n._vista); });
    const md = document.createElement('div');
    md.className = 'md';
    md.innerHTML = markdownSeguro(contenido || '');
    montarGraficas(md);
    d.appendChild(md);
    return d;
  };
  function pintarHistorial() {
    conversacion.querySelectorAll('.msj').forEach((n) => n.remove());
    vacio.hidden = historial().length > 0;
    historial().forEach((m) => conversacion.appendChild(m.rol === 'usuario' ? nodoUsuario(m) : nodoAsistente(m.visible ?? m.texto, m.acciones)));
    pintarTitulo();
    conversando();
    alFinal();
  }

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (peticion) { peticion.abort(); return; }          // el botón también detiene la respuesta
    const pregunta = texto.value.trim();
    if (!pregunta && !adjuntos.length) return;
    if (!estado.configurado) {
      mostrarNotificacion('error', estado.es_admin ? 'Configura un proveedor y modelo en el asistente: usa una clave válida para una API externa o inicia y configura la URL de un modelo local.' : 'El asistente aún no está configurado. Pide al administrador que configure un proveedor y modelo con una credencial válida o un servicio local activo.');
      return;
    }
    if (!chat()) {
      chatActual = Math.random().toString(36).slice(2, 10);
      chats.unshift({ id: chatActual, titulo: (pregunta || adjuntos[0].nombre).slice(0, 60), historial: [], actualizado: Date.now() });
    }
    const actual = chat();
    // Los archivos adjuntos viajan como texto dentro de la pregunta
    const completo = pregunta + adjuntos.map((a) => `\n\n--- Archivo adjunto: ${a.nombre} ---\n${a.contenido}`).join('');
    const mensaje = { rol: 'usuario', texto: completo, visible: pregunta || '(archivo adjunto)', adjuntos: adjuntos.map((a) => a.nombre) };
    actual.historial.push(mensaje);
    actual.actualizado = Date.now();
    vacio.hidden = true;
    conversacion.appendChild(nodoUsuario(mensaje));
    pintarTitulo();
    conversando();
    texto.value = '';
    adjuntos = [];
    pintarAdjuntos();
    ajustarAltura();

    const contenedor = document.createElement('div');
    contenedor.className = 'msj msj--asistente';
    const zonaPensamiento = document.createElement('div');
    let md = document.createElement('div');
    md.className = 'md msj__escribiendo';
    contenedor.append(zonaPensamiento, md);
    conversacion.appendChild(contenedor);
    alFinal();

    let respuesta = '';
    let segmento = '';          // texto desde la última acción de herramienta
    const acciones = [];
    let cambioEspacio = false;
    let cambioPaginaActual = false;
    let razonamiento = null;
    const esperaPensamiento = document.createElement('div');
    esperaPensamiento.className = 'cot cot--espera';
    esperaPensamiento.setAttribute('role', 'status');
    esperaPensamiento.setAttribute('aria-live', 'polite');
    esperaPensamiento.innerHTML = '<div class="cot-cabecera"><span class="cot-bolita cot-bolita-aurora-css" aria-hidden="true"></span><span class="cot-label"><span class="trShimmer">Pensando…</span></span></div>';
    if (!window.Razonamiento) zonaPensamiento.appendChild(esperaPensamiento);
    const crearRazonamiento = () => {
      if (!razonamiento && window.Razonamiento) {
        esperaPensamiento.remove();
        razonamiento = window.Razonamiento.crear(zonaPensamiento, marco);
      }
    };
    crearRazonamiento();
    let enCurso = null;
    peticion = new AbortController();
    enviar.disabled = false;
    enviar.classList.add('detener');
    enviar.setAttribute('aria-label', 'Detener');

    try {
      if (!window.Razonamiento) await cargarAurora();
      crearRazonamiento();
      const r = await fetch(`${BASE}/api/asistente/conversar.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({
          mensajes: actual.historial.map((m) => ({ rol: m.rol, texto: m.texto })),
          acceso: opciones.acceso, herramienta: opciones.herramienta, esfuerzo: opciones.esfuerzo,
          paciente_id: pacienteId, pagina_id: paginaId,
        }),
        signal: peticion.signal,
      });
      const lector = r.body.getReader();
      const decodificador = new TextDecoder();
      let pendiente = '';
      for (;;) {
        const { value, done } = await lector.read();
        if (done) break;
        pendiente += decodificador.decode(value, { stream: true });
        let fin;
        while ((fin = pendiente.indexOf('\n')) >= 0) {
          const linea = pendiente.slice(0, fin).trim();
          pendiente = pendiente.slice(fin + 1);
          if (!linea) continue;
          const evento = JSON.parse(linea);
          if (evento.t === 'pensamiento') {
            crearRazonamiento();
            razonamiento?.agregar(evento.d);
          } else if (evento.t === 'texto') {
            razonamiento?.terminar();
            esperaPensamiento.remove();
            respuesta += evento.d;
            segmento += evento.d;
            md.innerHTML = markdownSeguro(segmento);
            montarGraficas(md);
            if (conversacion.scrollHeight - conversacion.scrollTop - conversacion.clientHeight < 140) alFinal();
          } else if (evento.t === 'herramienta_inicio') {
            // Paso en curso: brillo sobre el texto hasta que llega el resultado
            razonamiento?.terminar();
            esperaPensamiento.remove();
            enCurso?.remove();
            enCurso = document.createElement('div');
            enCurso.className = 'msj__accion msj__accion--curso';
            enCurso.innerHTML = `<span class="msj__accion-icono" aria-hidden="true">${iconoAccion(evento.nombre)}</span><span class="msj__accion-texto trShimmer"></span>`;
            enCurso.querySelector('.msj__accion-texto').textContent = evento.d;
            md.classList.remove('msj__escribiendo');
            contenedor.insertBefore(enCurso, md);
            alFinal();
          } else if (evento.t === 'herramienta') {
            // La IA hizo algo en el espacio de trabajo: se muestra la acción y el texto siguiente va debajo
            razonamiento?.terminar();
            esperaPensamiento.remove();
            enCurso?.remove();
            enCurso = null;
            const accion = { d: evento.d, ok: evento.ok, url: evento.url, nombre: evento.nombre, vista: evento.vista };
            acciones.push(accion);
            md.classList.remove('msj__escribiendo');
            if (!segmento) md.remove();
            const nodo = nodoAccion(accion);
            contenedor.appendChild(nodo);
            if (nodo._vista) contenedor.appendChild(nodo._vista);
            md = document.createElement('div');
            md.className = 'md msj__escribiendo';
            contenedor.appendChild(md);
            if (segmento) respuesta += '\n\n';
            segmento = '';
            if (evento.cambio) {
              cambioEspacio = true;
              if (evento.url && paginaId && evento.url.endsWith(`?id=${paginaId}`)) cambioPaginaActual = true;
            }
            alFinal();
          } else if (evento.t === 'aviso' || evento.t === 'error') {
            const n = document.createElement('div');
            n.className = evento.t === 'error' ? 'msj__error' : 'msj__aviso';
            n.textContent = evento.d;
            contenedor.appendChild(n);
          }
        }
      }
    } catch (error) {
      if (error.name !== 'AbortError') {
        const n = document.createElement('div');
        n.className = 'msj__error';
        n.textContent = 'Se perdió la conexión con el servidor.';
        contenedor.appendChild(n);
      }
    } finally {
      razonamiento?.terminar();
      esperaPensamiento.remove();
      enCurso?.remove();
      md.classList.remove('msj__escribiendo');
      if (!segmento && acciones.length) md.remove();
      // Las acciones viajan con el texto en el historial que se reenvía, para que el modelo sepa lo que ya hizo
      const resumenAcciones = acciones.map((a) => `- ${a.ok ? 'Hecho' : 'Falló'}: ${a.d}`).join('\n');
      const textoGuardado = respuesta.trim() || (acciones.length ? 'Listo.' : '');
      if (textoGuardado) {
        actual.historial.push({ rol: 'asistente', texto: textoGuardado, acciones,
          ...(acciones.length ? { texto: `${textoGuardado}\n\n(Acciones realizadas:\n${resumenAcciones})`, visible: textoGuardado } : {}) });
      }
      else actual.historial.pop();     // sin respuesta: la pregunta no queda en el historial que se reenvía
      guardar();
      peticion = null;
      enviar.classList.remove('detener');
      enviar.setAttribute('aria-label', 'Enviar');
      actualizarEnviar();
      alFinal();
      // Lo que la IA creó o editó aparece en la barra lateral; si cambió la página abierta, se vuelve a cargar
      if (cambioEspacio && typeof Arbol !== 'undefined') Arbol.recargar();
      if (cambioPaginaActual) {
        mostrarNotificacion('exito', 'El asistente actualizó esta página.');
        setTimeout(() => location.reload(), 900);
      }
    }
  });

  // Al cerrar sesión se olvida la conversación
  document.querySelector('.barra-lateral__usuario')?.addEventListener('submit', () => {
    try { sessionStorage.removeItem(CLAVE); sessionStorage.removeItem(`${CLAVE}-abierto`); } catch (e) { /* opcional */ }
  });

  pintarOpciones();
  pintarTitulo();
  actualizarEnviar();
}

/* ---------- Notificaciones: se ocultan solas ---------- */
function programarCierre(notificacion) {
  const espera = notificacion.classList.contains('notificacion--error') ? 6000 : 4000;
  setTimeout(() => quitarConAnimacion(notificacion, 'saliendo'), espera);
}

/* ---------- Inicialización ---------- */
document.addEventListener('DOMContentLoaded', () => {
  const iniciarSeguro = (nombre, iniciar) => {
    try { iniciar(); } catch (error) { console.error(`[Expediente Expediente] No se pudo iniciar ${nombre}.`, error); }
  };
  iniciarSeguro('fechas', actualizarFechasVacias);
  document.addEventListener('change', actualizarFechasVacias);
  document.querySelectorAll('.notificacion').forEach((elemento) => iniciarSeguro('notificaciones', () => programarCierre(elemento)));

  iniciarSeguro('asistente', iniciarAsistente);
  iniciarSeguro('acceso', () => iniciarAccesoEnPasos(document.querySelector('[data-acceso-pasos]')));
  iniciarSeguro('codigo', () => iniciarCodigo(document.querySelector('[data-codigo]')));
  document.querySelectorAll('[data-actividad]').forEach((elemento) => iniciarSeguro('actividad', () => iniciarActividad(elemento)));
  iniciarSeguro('barra lateral', iniciarBarraLateral);
  iniciarSeguro('pestanas', iniciarPestanas);
  iniciarSeguro('ruta', iniciarBarraRuta);
  document.querySelectorAll('[data-geo]').forEach((elemento) => iniciarSeguro('mapa', () => iniciarMapa(elemento)));
  document.querySelectorAll('[data-dictado]').forEach((elemento) => iniciarSeguro('dictado', () => iniciarDictado(elemento)));
  iniciarSeguro('foto de clinica', iniciarFotoClinica);
  document.querySelectorAll('[data-historial]').forEach((elemento) => iniciarSeguro('historial', () => iniciarHistorial(elemento)));
  document.querySelectorAll('[data-bd]').forEach((elemento) => iniciarSeguro('base de datos', () => iniciarBaseDatos(elemento)));

  const form = document.getElementById('form-paciente');
  if (!form) return;

  const curp = form.querySelector('#curp');
  curp.addEventListener('input', () => {
    curp.value = curp.value.toUpperCase().replace(/[^A-ZÑ0-9]/g, '');
    if (curp.value.length < 18) {
      marcarError(curp, '');
      return;
    }
    if (!curpValida(curp.value)) {
      marcarError(curp, 'La CURP no es válida. Revisa el formato y el dígito verificador.');
      return;
    }
    marcarError(curp, '');
    const datos = datosDesdeCurp(curp.value);
    rellenarSiVacio(form.querySelector('#fecha_nacimiento'), datos.fecha);
    rellenarSiVacio(form.querySelector('#sexo'), datos.sexo);
    rellenarSiVacio(form.querySelector('#entidad_nacimiento'), datos.entidad);
  });

  // Quita el error en cuanto el usuario corrige el campo
  form.addEventListener('input', (ev) => {
    if (ev.target !== curp && ev.target.value) marcarError(ev.target, '');
  });
  form.addEventListener('change', (ev) => {
    if (ev.target.matches('select, input[type="checkbox"], input[type="date"]')) marcarError(ev.target, '');
  });

  form.addEventListener('submit', (ev) => {
    let primero = null;

    form.querySelectorAll('[required]').forEach((el) => {
      const vacio = el.type === 'checkbox' ? !el.checked : !el.value.trim();
      if (vacio && el.type !== 'checkbox') {
        marcarError(el, 'Campo obligatorio');
        primero = primero || el;
      }
    });

    if (curp.value && !curpValida(curp.value)) {
      marcarError(curp, 'La CURP no es válida. Revisa el formato y el dígito verificador.');
      primero = primero || curp;
    }

    const correo = form.querySelector('#correo');
    if (correo.value && !correo.checkValidity()) {
      marcarError(correo, 'Correo electrónico no válido');
      primero = primero || correo;
    }

    const confirmacion = form.querySelector('#confirmacion');
    confirmacion.closest('.casilla').style.color = confirmacion.checked ? '' : 'var(--peligro)';
    if (!confirmacion.checked) primero = primero || confirmacion;

    if (primero) {
      ev.preventDefault();
      primero.focus();
    }
  });

  form.querySelector('#confirmacion').addEventListener('change', (ev) => {
    if (ev.target.checked) ev.target.closest('.casilla').style.color = '';
  });
});

/* ---------- Historial clínico desplegable (cada evento abre su detalle) ---------- */
function iniciarAcordeon(lista) {
  lista.addEventListener('click', (ev) => {
    const cabecera = ev.target.closest('.acordeon__cabecera');
    if (!cabecera) return;
    const abierto = cabecera.getAttribute('aria-expanded') === 'true';
    cabecera.setAttribute('aria-expanded', String(!abierto));
    cabecera.closest('.acordeon__item').classList.toggle('abierto', !abierto);
  });
}

/* ---------- Barra de acciones que despliega sus nombres ----------
   Con ratón: se despliega al pasar por encima. Con teclado: al enfocarla.
   En pantallas táctiles el primer toque despliega y el segundo ejecuta. */
function iniciarBarraAcciones(barra) {
  const resalte = barra.querySelector('.barra-acciones__resalte');
  const items = [...barra.querySelectorAll('.barra-acciones__item')];
  let temporizador = null;
  let activo = null;
  let tocada = false;

  const moverResalte = () => {
    if (!activo) { resalte.style.opacity = '0'; return; }
    resalte.style.left = `${activo.offsetLeft}px`;
    resalte.style.width = `${activo.offsetWidth}px`;
    resalte.style.opacity = '1';
  };
  // Mientras los nombres se despliegan, el resalte sigue al elemento
  const seguir = () => {
    const fin = performance.now() + 420;
    const paso = () => { moverResalte(); if (performance.now() < fin) requestAnimationFrame(paso); };
    requestAnimationFrame(paso);
  };
  const expandir = () => { clearTimeout(temporizador); barra.classList.add('expandida'); seguir(); };
  const contraer = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
      barra.classList.remove('expandida');
      activo = null;
      tocada = false;
      items.forEach((i) => i.classList.remove('resaltado'));
      seguir();
    }, 90);
  };
  const resaltar = (item) => {
    activo = item;
    items.forEach((i) => i.classList.toggle('resaltado', i === item));
    moverResalte();
  };

  barra.addEventListener('pointerenter', (ev) => { if (ev.pointerType === 'mouse') expandir(); });
  barra.addEventListener('pointerleave', (ev) => { if (ev.pointerType === 'mouse') contraer(); });
  barra.addEventListener('transitionend', moverResalte);   // al terminar de desplegarse, el resalte se ajusta al elemento
  barra.addEventListener('focusin', () => expandir());
  barra.addEventListener('focusout', (ev) => { if (!barra.contains(ev.relatedTarget)) contraer(); });
  items.forEach((item) => {
    item.addEventListener('pointerenter', () => { clearTimeout(temporizador); resaltar(item); });
    item.addEventListener('focus', () => resaltar(item));
    item.addEventListener('pointerdown', (ev) => {
      if (ev.pointerType !== 'mouse' && !barra.classList.contains('expandida')) item.dataset.primerToque = '1';
    });
    item.addEventListener('click', (ev) => {
      if (item.dataset.primerToque && !tocada) {
        delete item.dataset.primerToque;
        ev.preventDefault();
        ev.stopImmediatePropagation();
        tocada = true;
        expandir();
        resaltar(item);
        return;
      }
      delete item.dataset.primerToque;
      const accion = item.dataset.barraAccion;
      if (accion === 'imprimir') (window.abrirImpresion ? window.abrirImpresion(item) : window.print());
      if (accion === 'exportar') {
        const pacienteId = document.body.dataset.paciente;
        if (pacienteId) {
          fetch(`${BASE}/api/exportar.php?tipo=paciente&id=${pacienteId}&formato=html`)
            .then(r => r.json())
            .then(d => { if (d.ok) window.open(d.url, '_blank', 'noopener'); })
            .catch(() => {});
        }
      }
      if (accion === 'preguntar-ia') {
        // Abre el asistente y le pide la revisión del expediente abierto
        document.querySelector('[data-asistente-abrir]')?.click();
        setTimeout(() => {
          const b = document.createElement('button');
          b.dataset.sugerencia = 'resumen_paciente';
          b.hidden = true;
          document.querySelector('.asistente')?.appendChild(b);
          b.click();
          b.remove();
        }, 250);
      }
      if (accion === 'agregar-historial') {
        document.querySelector('[data-historial-abrir]')?.click();
        document.querySelector('[data-historial-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, true);
  });
  // Un toque fuera la vuelve a contraer
  document.addEventListener('pointerdown', (ev) => { if (tocada && !barra.contains(ev.target)) contraer(); });
}

document.addEventListener('DOMContentLoaded', () => {
  const iniciar = (fn) => { try { fn(); } catch (e) { console.error('No se pudo iniciar', fn.name, e); } };
  document.querySelectorAll('.acordeon').forEach(iniciarAcordeon);
  document.querySelectorAll('[data-barra-acciones]').forEach(iniciarBarraAcciones);
  iniciar(iniciarPerfilPaciente);
  iniciar(iniciarPortadaPaciente);
  iniciar(iniciarLogoInstitucion);
});

/* Logo de la clínica (Configuración → General): se cambia sin recargar y se guarda en privado/logos. */
function iniciarLogoInstitucion() {
  const caja = document.querySelector('[data-logo-clinica]');
  if (!caja) return;
  const archivo = caja.querySelector('[data-logo-archivo]');
  const elegir = caja.querySelector('[data-logo-elegir]');
  const quitar = caja.querySelector('[data-logo-quitar]');
  const vista = caja.querySelector('.conf-logo__vista');
  const pintar = (url) => {
    vista.textContent = '';
    if (url) {
      const img = document.createElement('img');
      img.src = url;
      img.alt = 'Logo de la clínica';
      vista.appendChild(img);
    } else {
      const vacio = document.createElement('span');
      vacio.className = 'conf-logo__vacio';
      vacio.textContent = 'Sin logo';
      vista.appendChild(vacio);
    }
    elegir.textContent = url ? 'Cambiar logo' : 'Subir logo';
    quitar.hidden = !url;
  };
  const guardar = async (datos, exito) => {
    const r = await fetch(`${BASE}/api/institucion/logo.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
    const res = await r.json();
    if (!r.ok || !res.ok) throw new Error(res.error || 'No se pudo guardar el logo.');
    mostrarNotificacion('exito', exito);
    return res;
  };
  elegir.addEventListener('click', () => archivo.click());
  archivo.addEventListener('change', async () => {
    const imagen = archivo.files[0];
    if (!imagen) return;
    const datos = new FormData();
    datos.append('logo', imagen);
    try {
      const res = await guardar(datos, 'Logo actualizado.');
      pintar(`${res.url}&t=${Date.now()}`);
    } catch (e) { mostrarNotificacion('error', e.message); }
    finally { archivo.value = ''; }
  });
  quitar.addEventListener('click', async () => {
    const datos = new FormData();
    datos.append('accion', 'quitar');
    try {
      await guardar(datos, 'Logo quitado.');
      pintar('');
    } catch (e) { mostrarNotificacion('error', e.message); }
  });
}

/* Portada y foto del paciente: la misma imagen alimenta el avatar y la portada de la
   tarjeta, y la foto grande del expediente. Todo cambio se aplica por id a cada copia. */
function inicialesPaciente(texto) {
  return String(texto || '').trim().split(/\s+/).slice(0, 2).map((parte) => parte[0] || '').join('').toUpperCase();
}

function ponerInicialesFoto(caja, texto) {
  caja.innerHTML = '';
  const span = document.createElement('span');
  span.className = caja.classList.contains('avatar') ? 'perfil__avatar-iniciales' : 'perfil__portada-iniciales';
  span.setAttribute('aria-hidden', 'true');
  span.textContent = texto;
  caja.appendChild(span);
}

/* Foto grande del encabezado del expediente (pacientes/ver.php). */
function actualizarFotoGrande(id, url) {
  const foto = document.querySelector(`[data-foto-paciente="${id}"]`);
  if (!foto) return;
  const img = foto.querySelector('img');
  if (url) {
    const nueva = img || document.createElement('img');
    nueva.src = url;
    nueva.alt = 'Foto del paciente';
    if (!img) foto.prepend(nueva);
    foto.classList.add('hoja-paciente__foto--con');
    foto.querySelector('.hoja-paciente__iniciales')?.remove();
    const etiqueta = foto.querySelector('[data-foto-cambiar] span');
    if (etiqueta) etiqueta.textContent = 'Cambiar';
    return;
  }
  img?.remove();
  foto.classList.remove('hoja-paciente__foto--con');
  if (!foto.querySelector('.hoja-paciente__iniciales')) {
    const span = document.createElement('span');
    span.className = 'hoja-paciente__iniciales';
    span.setAttribute('aria-hidden', 'true');
    span.textContent = inicialesPaciente(foto.closest('.hoja-paciente')?.querySelector('.hoja-paciente__nombre')?.textContent);
    foto.prepend(span);
  }
  const etiqueta = foto.querySelector('[data-foto-cambiar] span');
  if (etiqueta) etiqueta.textContent = 'Agregar foto';
}

/* Portada y avatar dentro de una tarjeta abierta. */
function actualizarFotoTarjeta(tarjeta, url) {
  const iniciales = inicialesPaciente(tarjeta.querySelector('.perfil__nombre')?.textContent);
  const portada = tarjeta.querySelector('.perfil__imagen');
  if (portada) {
    portada.classList.toggle('perfil__imagen--con', Boolean(url));
    let img = portada.querySelector('[data-portada-imagen]');
    if (url) {
      if (!img) {
        img = document.createElement('img');
        img.dataset.portadaImagen = '';
        img.alt = '';
        img.loading = 'eager';
        portada.prepend(img);
      }
      img.src = url;
      portada.querySelector('.perfil__portada-iniciales')?.remove();
    } else {
      img?.remove();
      let inicialesCaja = portada.querySelector('.perfil__portada-iniciales');
      if (!inicialesCaja) {
        inicialesCaja = document.createElement('span');
        inicialesCaja.className = 'perfil__portada-iniciales';
        inicialesCaja.setAttribute('aria-hidden', 'true');
        portada.prepend(inicialesCaja);
      }
      inicialesCaja.textContent = iniciales;
    }
  }
  const avatar = tarjeta.querySelector('.avatar');
  if (avatar) {
    if (url) {
      const img = document.createElement('img');
      img.src = url;
      img.alt = '';
      img.dataset.portadaAvatar = '';
      avatar.innerHTML = '';
      avatar.appendChild(img);
    } else {
      avatar.querySelector('img')?.remove();
      if (!avatar.querySelector('span')) ponerInicialesFoto(avatar, iniciales);
    }
  }
  tarjeta.querySelectorAll('.perfil__portada-accion span').forEach((texto) => {
    texto.textContent = url ? 'Cambiar portada' : 'Agregar portada';
  });
}

/* Refresca por id: tarjetas abiertas (menú del nombre y ficha lateral) y foto grande. */
function refrescarFotoPaciente(id, url) {
  if (url && !/[?&]t=/.test(url)) url += `${url.includes('?') ? '&' : '?'}t=${Date.now()}`;
  tarjetasEnCache.delete(String(id));
  document.querySelectorAll('.perfil[data-perfil-paciente]').forEach((tarjeta) => {
    if (tarjeta.dataset.perfilPaciente === String(id)) actualizarFotoTarjeta(tarjeta, url);
  });
  actualizarFotoGrande(id, url);
}

function iniciarPortadaPaciente() {
  // Entrada de archivo única y fuera de las tarjetas: si la tarjeta se cierra mientras
  // el usuario elige la imagen, el cambio se aplica igual porque se resuelve por id.
  let entrada = document.querySelector('[data-portada-archivo-global]');
  if (!entrada) {
    entrada = document.createElement('input');
    entrada.type = 'file';
    entrada.accept = 'image/jpeg,image/png,image/webp';
    entrada.hidden = true;
    entrada.dataset.portadaArchivoGlobal = '';
    document.body.appendChild(entrada);
  }
  const quitarPortada = async (id) => {
    const datos = new FormData();
    datos.append('paciente_id', id);
    datos.append('accion', 'quitar');
    try {
      const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
      const res = await r.json();
      if (!r.ok || !res.ok) throw new Error(res.error || 'No se pudo quitar la portada.');
      refrescarFotoPaciente(id, '');
      mostrarNotificacion('exito', 'Portada quitada.');
    } catch (e) { mostrarNotificacion('error', e.message); }
  };

  document.addEventListener('click', (ev) => {
    const boton = ev.target.closest('[data-accion-perfil]');
    if (boton) {
      const tarjeta = boton.closest('.perfil');
      const accion = boton.dataset.accionPerfil;
      ev.preventDefault();
      if (accion === 'imprimir') { (window.abrirImpresion ? window.abrirImpresion(boton) : window.print()); return; }
      if (accion === 'exportar') { exportarPacienteJSON(tarjeta?.dataset.perfilPaciente || document.body.dataset.paciente); return; }
      if (accion === 'exportar-datos') { abrirDatosContextuales('exportar', boton); return; }
      if (accion === 'importar-datos') { abrirDatosContextuales('importar', boton); return; }
    }
    const cambiar = ev.target.closest('[data-portada-paciente]');
    if (!cambiar) return;
    const tarjeta = cambiar.closest('.perfil');
    const id = tarjeta?.dataset.perfilPaciente;
    if (!id) return;
    ev.preventDefault();
    entrada.dataset.paciente = id;
    entrada.value = '';
    if (tarjeta.querySelector('[data-portada-imagen]') && typeof abrirMenu === 'function') {
      abrirMenu(cambiar, [
        { icono: 'imagen', texto: 'Cambiar portada', accion: () => entrada.click() },
        { icono: 'papelera', texto: 'Quitar portada', peligro: true, accion: () => quitarPortada(id) },
      ]);
    } else {
      entrada.click();
    }
  });

  entrada.addEventListener('change', async () => {
    const imagen = entrada.files?.[0];
    const id = entrada.dataset.paciente;
    entrada.value = '';
    if (!imagen || !id) return;
    const tarjetas = [...document.querySelectorAll(`.perfil[data-perfil-paciente="${id}"]`)];
    tarjetas.forEach((tarjeta) => tarjeta.querySelector('.perfil__imagen')?.classList.add('subiendo'));
    const datos = new FormData();
    datos.append('paciente_id', id);
    datos.append('foto', imagen);
    try {
      const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
      const res = await r.json();
      if (!r.ok || !res.ok) throw new Error(res.error || 'No se pudo guardar la portada.');
      refrescarFotoPaciente(id, `${res.url}${res.url.includes('?') ? '&' : '?'}t=${Date.now()}`);
      mostrarNotificacion('exito', 'Portada actualizada.');
    } catch (e) {
      mostrarNotificacion('error', e.message);
    } finally {
      tarjetas.forEach((tarjeta) => tarjeta.querySelector('.perfil__imagen')?.classList.remove('subiendo'));
    }
  });
}

/* Si la portada no carga (archivo borrado o sesión vencida), la tarjeta vuelve a las iniciales. */
document.addEventListener('error', (ev) => {
  const img = ev.target;
  if (!(img instanceof HTMLImageElement)) return;
  const nombreDe = (caja) => inicialesPaciente(caja.closest('.perfil')?.querySelector('.perfil__nombre')?.textContent);
  const iniciales = (caja, clase) => {
    if (caja.querySelector('.perfil__portada-iniciales, .perfil__avatar-iniciales')) return;
    const span = document.createElement('span');
    span.className = clase;
    span.setAttribute('aria-hidden', 'true');
    span.textContent = nombreDe(caja);
    caja.prepend(span);
  };
  const portada = img.closest('.perfil__imagen');
  if (portada) {
    img.remove();
    portada.classList.remove('perfil__imagen--con');
    iniciales(portada, 'perfil__portada-iniciales');
    portada.closest('.perfil')?.querySelectorAll('.perfil__portada-accion span').forEach((texto) => {
      texto.textContent = 'Agregar portada';
    });
    return;
  }
  const avatar = img.closest('.avatar');
  if (avatar) {
    img.remove();
    iniciales(avatar, 'perfil__avatar-iniciales');
  }
}, true);

/* Notas, carpetas y bases de datos del expediente (perfil del paciente y sección Pacientes) */
async function crearPaginaPaciente(tipo, pacienteId) {
  try {
    const r = await fetch(`${BASE}/api/pacientes/paginas.php`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
      body: JSON.stringify({ tipo, paciente_id: pacienteId || null }),
    });
    const res = await r.json();
    if (!r.ok) throw new Error(res.error || 'No se pudo crear.');
    location.href = res.url;
  } catch (e) { mostrarNotificacion('error', e.message); }
}
document.addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-pagina-paciente]');
  if (b) { crearPaginaPaciente(b.dataset.paginaPaciente, b.dataset.paciente); return; }
  const herramientas = ev.target.closest('[data-herramientas-datos-pacientes], [data-herramientas-datos-pagina]');
  if (herramientas && typeof abrirMenu === 'function') {
    const datosUrl = `${BASE}/datos/`;
    const paginaId = herramientas.dataset.pagina || '';
    const exportarUrl = paginaId
      ? `${datosUrl}?pagina=${encodeURIComponent(paginaId)}#exportar`
      : `${datosUrl}#exportar`;
    abrirMenu(herramientas, [
      { titulo: 'Herramientas de datos' },
      { icono: 'tabla', texto: 'Importar', detalle: paginaId ? 'CSV · Excel · SQL · HL7 · FHIR · crea nueva base' : 'CSV · Excel · SQL · HL7 · FHIR', accion: () => { location.href = `${datosUrl}#importar`; } },
      { icono: 'documento', texto: 'Exportar', detalle: paginaId ? 'Exportar esta base en otro formato' : 'Pacientes y bases de datos', accion: () => { location.href = exportarUrl; } },
      'separador',
      { icono: 'plantilla', texto: 'Perfiles de formato', detalle: 'Formatos institucionales', accion: () => { location.href = `${datosUrl}#formatos`; } },
      { icono: 'reloj', texto: 'Historial de conversiones', detalle: 'Importaciones y exportaciones', accion: () => { location.href = `${datosUrl}#historial`; } },
    ], { buscar: 'Buscar herramienta' });
    return;
  }
  const menu = ev.target.closest('[data-pagina-paciente-menu]');
  if (menu && typeof abrirMenu === 'function') {
    abrirMenu(menu, [
      ...(menu.dataset.registrar !== '0' ? [{ icono: 'usuario', texto: 'Registrar paciente', accion: () => { location.href = `${BASE}/pacientes/registro.php`; } }, 'separador'] : []),
      { titulo: 'Crear en Pacientes' },
      { icono: 'documento', texto: 'Nota', accion: () => crearPaginaPaciente('nota') },
      { icono: 'tabla', texto: 'Base de datos', accion: () => crearPaginaPaciente('base_datos') },
      { icono: 'carpeta', texto: 'Carpeta', accion: () => crearPaginaPaciente('carpeta') },
      'separador',
      { icono: 'candado', texto: 'Verificar documento impreso', detalle: 'Por su huella', accion: () => { location.href = `${BASE}/pacientes/verificar.php`; } },
    ]);
  }
});

/* ---------- Perfil del paciente ---------- */
function abrirDatosContextuales(vista, devolverFoco = null) {
  if (!['importar', 'exportar'].includes(vista)) return;
  const titulo = vista === 'importar' ? 'Importar bases de datos de pacientes' : 'Formatos para bases de datos';
  const dialogo = document.createElement('dialog');
  dialogo.className = 'datos-contextuales-dialogo';
  dialogo.setAttribute('aria-labelledby', 'datos-contextuales-titulo');
  dialogo.innerHTML = `<div class="datos-contextuales-dialogo__cabecera"><h2 id="datos-contextuales-titulo">${titulo}</h2><button type="button" class="datos-contextuales-dialogo__cerrar" aria-label="Cerrar">×</button></div><iframe title="${titulo}" src="${BASE}/datos/?contexto=${vista}#${vista}"></iframe>`;
  const cerrar = () => dialogo.close();
  dialogo.querySelector('.datos-contextuales-dialogo__cerrar').addEventListener('click', cerrar);
  dialogo.addEventListener('click', (ev) => { if (ev.target === dialogo) cerrar(); });
  dialogo.addEventListener('close', () => {
    dialogo.remove();
    devolverFoco?.focus({ preventScroll: true });
  }, { once: true });
  document.body.appendChild(dialogo);
  dialogo.showModal();
}

function iniciarPerfilPaciente() {
  // Nota individual (pacientes/evento.php): imprimir con la hoja oficial de pacientes/imprimir.php,
  // no con el diálogo del navegador sobre la pantalla oscura.
  const exportacionNota = document.getElementById('registro-exportacion');
  if (exportacionNota && typeof window.abrirImpresion !== 'function') {
    let datosNota = {};
    try { datosNota = JSON.parse(exportacionNota.textContent) || {}; } catch (e) {}
    window.abrirImpresion = () => {
      const parametros = new URLSearchParams({ id: datosNota.paciente?.id || document.body.dataset.paciente || '', imprimir: '1' });
      if (datosNota.id) parametros.append('registros[]', datosNota.id);
      window.open(`${BASE}/pacientes/imprimir.php?${parametros}`, '_blank', 'noopener');
    };
  }
  if (new URLSearchParams(location.search).get('resumir') === '1' && document.querySelector('[data-foto-paciente]')) {
    history.replaceState(null, '', location.pathname + location.search.replace(/[?&]resumir=1/, '').replace(/^&/, '?') + location.hash);
    setTimeout(() => {
      const accion = document.querySelector('[data-barra-accion="preguntar-ia"]');
      if (accion) { accion.click(); return; }
      document.querySelector('[data-asistente-abrir]')?.click();
      setTimeout(() => {
        const sugerencia = document.createElement('button');
        sugerencia.dataset.sugerencia = 'resumen_paciente';
        sugerencia.hidden = true;
        document.querySelector('.asistente')?.appendChild(sugerencia);
        sugerencia.click();
        sugerencia.remove();
      }, 250);
    }, 400);
  }
  document.querySelectorAll('.documento__acciones [data-imprimir]').forEach((boton) => {
    boton.addEventListener('click', () => (window.abrirImpresion ? window.abrirImpresion(boton) : window.print()));
  });
  document.addEventListener('click', (ev) => {
    const disparador = ev.target.closest('.perfil--contexto [data-abrir-registro-selector]');
    const cerrar = ev.target.closest('.perfil--contexto [data-cerrar-registro-selector]');
    const tarjeta = (disparador || cerrar)?.closest('.perfil--contexto');
    if (!tarjeta) return;
    const selector = tarjeta.querySelector('[data-registro-selector-panel]');
    if (!selector) return;
    if (cerrar) {
      selector.hidden = true;
      tarjeta.classList.remove('registro-selector-abierto');
      tarjeta.querySelector('[data-abrir-registro-selector]')?.setAttribute('aria-expanded', 'false');
      tarjeta.classList.remove('registro-selector-contexto--izquierda');
      return;
    }
    const mostrar = selector.hidden;
    selector.hidden = !mostrar;
    disparador.setAttribute('aria-expanded', String(mostrar));
    tarjeta.classList.toggle('registro-selector-abierto', mostrar);
    if (mostrar) {
      const caja = tarjeta.getBoundingClientRect();
      const ancho = selector.getBoundingClientRect().width || 292;
      tarjeta.classList.toggle('registro-selector-contexto--izquierda', caja.right + ancho + 18 > innerWidth);
      selector.querySelector('a')?.focus({ preventScroll: true });
    } else {
      tarjeta.classList.remove('registro-selector-contexto--izquierda');
    }
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    const tarjetaContexto = document.querySelector('.perfil--contexto.registro-selector-abierto');
    if (!tarjetaContexto) return;
    tarjetaContexto.querySelector('[data-registro-selector-panel]').hidden = true;
    tarjetaContexto.classList.remove('registro-selector-abierto', 'registro-selector-contexto--izquierda');
    tarjetaContexto.querySelector('[data-abrir-registro-selector]')?.focus({ preventScroll: true });
  });

  // Tarjeta única del expediente: aparece desde el borde derecho y conserva el foco
  // cuando el usuario abre los menús contextuales que salen de ella.
  const lateral = document.querySelector('[data-tarjeta-lateral]');
  if (lateral) {
    const asa = lateral.querySelector('.tarjeta-lateral__asa');
    const selector = lateral.querySelector('[data-registro-selector-panel]');
    const disparador = lateral.querySelector('[data-abrir-registro-selector]');
    const menuAcciones = lateral.querySelector('[data-expediente-acciones]');
    let temporizador = null;
    const cerrarSelector = () => {
      lateral.classList.remove('registro-selector-abierto');
      if (selector) selector.hidden = true;
      disparador?.setAttribute('aria-expanded', 'false');
    };
    const abrir = () => {
      clearTimeout(temporizador);
      lateral.classList.add('abierta');
      asa?.setAttribute('aria-expanded', 'true');
    };
    const cerrarAhora = () => {
      clearTimeout(temporizador);
      cerrarSelector();
      lateral.classList.remove('abierta');
      asa?.setAttribute('aria-expanded', 'false');
    };
    const cerrar = () => {
      clearTimeout(temporizador);
      temporizador = setTimeout(() => {
        if (document.activeElement?.closest('.popover')) return;
        cerrarAhora();
      }, 260);
    };
    lateral.addEventListener('mouseenter', abrir);
    lateral.addEventListener('mouseleave', (ev) => { if (!ev.relatedTarget?.closest?.('.popover')) cerrar(); });
    asa?.addEventListener('click', () => (lateral.classList.contains('abierta') ? cerrar() : abrir()));
    disparador?.addEventListener('click', () => {
      const mostrar = selector?.hidden ?? false;
      if (selector) selector.hidden = !mostrar;
      disparador.setAttribute('aria-expanded', String(mostrar));
      lateral.classList.toggle('registro-selector-abierto', mostrar);
      if (mostrar) abrir();
    });
    selector?.querySelector('[data-cerrar-registro-selector]')?.addEventListener('click', cerrarSelector);
    lateral.addEventListener('focusin', abrir);
    lateral.addEventListener('focusout', (ev) => {
      if (!lateral.contains(ev.relatedTarget) && !ev.relatedTarget?.closest('.popover')) cerrar();
    });
    document.addEventListener('pointerdown', (ev) => {
      if (!lateral.contains(ev.target) && !ev.target.closest?.('.popover')) cerrarAhora();
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !ev.target?.closest?.('.popover')) cerrarAhora();
    });

    menuAcciones?.addEventListener('click', (ev) => {
      if (typeof abrirMenu !== 'function') {
        if (window.abrirImpresion) window.abrirImpresion(menuAcciones);
        else window.print();
        return;
      }
      const items = [
        { titulo: 'Expediente del paciente' },
        { icono: 'pdf', texto: 'Imprimir o elegir documentos', accion: () => window.abrirImpresion ? window.abrirImpresion(menuAcciones) : window.print() },
        'separador',
        { titulo: 'Importar y exportar' },
        { icono: 'documento', texto: 'Exportar este expediente (JSON)', accion: () => exportarPacienteJSON(document.body.dataset.paciente) },
        ...(menuAcciones.dataset.importarExportar === '1' ? [
          { icono: 'flecha_arriba', texto: 'Formatos para bases de datos', accion: () => abrirDatosContextuales('exportar', menuAcciones) },
          { icono: 'flecha_abajo', texto: 'Importar bases de datos de pacientes', accion: () => abrirDatosContextuales('importar', menuAcciones) },
        ] : []),
      ];
      abrirMenu(ev.currentTarget, items, { volverA: menuAcciones });
    });
  }

  // Evolución de signos vitales
  const fuente = document.getElementById('grafica-signos');
  const caja = document.querySelector('[data-grafica="signos"]');
  if (fuente && caja && typeof Graficas !== 'undefined') {
    const d = JSON.parse(fuente.textContent);
    Graficas.montar(caja.parentElement, { tipo: 'lineas', titulo: 'Evolución por consulta',
      categorias: d.categorias.map((c) => c.etiqueta), series: d.series.map((s) => ({ nombre: s.nombre, valores: s.valores })) });
    caja.remove();
  }

  // Foto: clic para elegir; se sube y se reemplaza sin recargar
  const foto = document.querySelector('[data-foto-paciente]');
  const archivo = foto?.querySelector('[data-foto-archivo]');
  if (foto && archivo) {
    const id = foto.dataset.fotoPaciente;
    const quitar = async () => {
      const datos = new FormData();
      datos.append('paciente_id', id);
      datos.append('accion', 'quitar');
      const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
      if (r.ok) {
        refrescarFotoPaciente(id, '');
        mostrarNotificacion('exito', 'Foto quitada.');
      }
    };
    foto.querySelector('[data-foto-cambiar]').addEventListener('click', (ev) => {
      if (foto.classList.contains('hoja-paciente__foto--con') && typeof abrirMenu === 'function') {
        abrirMenu(ev.currentTarget, [
          { icono: 'imagen', texto: 'Cambiar foto', accion: () => archivo.click() },
          { icono: 'papelera', texto: 'Quitar foto', peligro: true, accion: quitar },
        ]);
      } else {
        archivo.click();
      }
    });
    archivo.addEventListener('change', async () => {
      const f = archivo.files[0];
      if (!f) return;
      const datos = new FormData();
      datos.append('paciente_id', id);
      datos.append('foto', f);
      foto.classList.add('subiendo');
      try {
        const r = await fetch(`${BASE}/api/pacientes/foto.php`, { method: 'POST', headers: { 'X-CSRF-Token': TOKEN_CSRF }, body: datos });
        const res = await r.json();
        if (!r.ok) throw new Error(res.error);
        // La misma imagen alimenta la foto grande y la portada de la ficha lateral
        refrescarFotoPaciente(id, res.url);
        mostrarNotificacion('exito', 'Foto actualizada.');
      } catch (e) {
        mostrarNotificacion('error', e.message || 'No se pudo subir la foto.');
      } finally {
        foto.classList.remove('subiendo');
        archivo.value = '';
      }
    });
  }

  // Propiedades: clic en el valor para editarlo (texto, área o lista de opciones)
  document.querySelectorAll('[data-propiedades-paciente]').forEach((lista) => {
    const id = Number(lista.dataset.propiedadesPaciente);
    // Pinta los bloques de nota con el mismo marcado que el editor (solo lectura).
    const htmlBloquesNota = (bloques) => {
      let n = 0;
      return '<div class="bloques-nota">' + bloques.map((b) => {
        n = b.t === 'numerada' ? n + 1 : 0;
        const attrs = ` class="bloque" data-t="${escaparHtml(b.t)}"` + (n ? ` data-n="${n}"` : '') + (b.color ? ` data-color="${escaparHtml(b.color)}"` : '');
        if (b.t === 'separador') return `<div${attrs}><hr class="bloque__separador"></div>`;
        const casilla = b.t === 'tarea' ? `<span class="bloque__casilla" role="checkbox" aria-checked="${b.c ? 'true' : 'false'}"></span>` : '';
        return `<div${attrs}${b.t === 'tarea' ? ` data-c="${b.c ? 1 : 0}"` : ''}>${casilla}<div class="bloque__texto">${escaparHtml(b.x || '')}</div></div>`;
      }).join('') + '</div>';
    };
    const pintarValor = (dd, texto) => {
      let bloques = null;
      if (dd.dataset.edicion === 'area' && dd.dataset.notaBloques) {
        try { bloques = JSON.parse(dd.dataset.notaBloques); } catch (e) { bloques = null; }
      }
      dd.innerHTML = Array.isArray(bloques) && bloques.length ? htmlBloquesNota(bloques)
        : (texto ? escaparHtml(texto) : '<span class="propiedad-paciente__vacio">Agregar</span>');
    };
    const guardar = async (dd, valor) => {
      // El contenedor dice a qué entidad y a qué API pertenece; si no, se usa la de pacientes
      const entidad = lista.dataset.entidad;
      const cuerpo = { id, campo: dd.dataset.campo, valor };
      if (entidad) cuerpo.entidad = entidad;
      // Si el campo se editó con el motor de notas, sus bloques viajan junto al texto
      if (window.notaClinicaCampo?.() === dd.querySelector('textarea')) {
        const bloques = window.notaClinicaBloques?.();
        if (bloques) cuerpo.bloques = bloques;
      }
      const r = await fetch(lista.dataset.actualizar || `${BASE}/api/pacientes/actualizar.php`, {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify(cuerpo),
      });
      const res = await r.json();
      if (!r.ok || !res.ok) throw new Error(res.error || 'No se pudo guardar.');
      const texto = res.texto ?? res.valor ?? valor;
      if (cuerpo.bloques) dd.dataset.notaBloques = JSON.stringify(cuerpo.bloques);
      dd.dataset.notaTexto = texto;
      pintarValor(dd, texto);
      dd.classList.add('guardado');
      setTimeout(() => dd.classList.remove('guardado'), 900);
    };
    const editar = (dd) => {
      if (dd.querySelector('input, textarea')) return;
      // Cierra y guarda el campo en línea anterior antes de abrir otro.
      const previo = lista.querySelector('.propiedad-paciente__campo');
      if (previo && !dd.contains(previo)) previo._cerrarNota?.(true);
      const actual = dd.dataset.notaTexto ?? (dd.querySelector('.propiedad-paciente__vacio') ? '' : dd.textContent.trim());
      if (dd.dataset.edicion === 'opciones') {
        const opciones = JSON.parse(dd.dataset.opciones || '[]');
        abrirMenu(dd, [
          ...opciones.map((o) => ({ texto: o.texto, marcado: o.texto === actual, accion: () => guardar(dd, o.valor).catch((e) => mostrarNotificacion('error', e.message)) })),
          'separador',
          { icono: 'cerrar', texto: 'Sin valor', accion: () => guardar(dd, '').catch((e) => mostrarNotificacion('error', e.message)) },
        ]);
        return;
      }
      const campo = document.createElement(dd.dataset.edicion === 'area' ? 'textarea' : 'input');
      campo.className = 'propiedad-paciente__campo';
      campo.value = actual;
      if (dd.dataset.campo === 'medicamentos_actuales') {
        campo.dataset.catalogo = 'medicamentos';
        campo.setAttribute('role', 'combobox');
        campo.setAttribute('aria-expanded', 'false');
        campo.setAttribute('aria-autocomplete', 'list');
      }
      if (campo.tagName === 'TEXTAREA') campo.rows = 3;
      if (dd.dataset.edicion === 'area') {
        campo.dataset.notaClinica = (dd.closest('.propiedad-paciente')?.querySelector('dt span')?.textContent || 'Nota clínica').trim();
        campo.dataset.notaInline = '1';
        if (dd.dataset.notaBloques) campo.dataset.notaBloques = dd.dataset.notaBloques;
      }
      dd.innerHTML = '';
      dd.appendChild(campo);
      if (campo.dataset.catalogo) window.inicializarCatalogoClinico?.(campo);
      campo.focus();
      let terminado = false;
      const cerrar = async (confirmar) => {
        if (terminado) return;
        terminado = true;
        const teniaNota = window.notaClinicaCampo?.() === campo;
        const cerrarNota = () => {
          if (teniaNota && window.notaClinicaCampo?.() === campo) window.cerrarNotaClinica?.();
        };
        const valor = campo.value.trim();
        // Con el motor de notas abierto puede cambiar solo el formato (encabezados, color): se guarda igual
        const conNota = teniaNota && window.notaClinicaCampo?.() === campo;
        if (!confirmar || (valor === actual && !conNota)) {
          pintarValor(dd, actual);
          cerrarNota();
          return;
        }
        try { await guardar(dd, valor); } catch (e) {
          mostrarNotificacion('error', e.message);
          pintarValor(dd, actual);
        }
        cerrarNota();
      };
      campo._cerrarNota = (confirmar = true) => cerrar(confirmar);
      campo.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { ev.preventDefault(); cerrar(false); }
        if (ev.key === 'Enter' && campo.dataset.catalogo && campo.getAttribute('aria-expanded') === 'true') { ev.preventDefault(); return; }
        if (ev.key === 'Enter' && (campo.tagName === 'INPUT' || ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); cerrar(true); }
      });
      campo.addEventListener('blur', (ev) => {
        if (ev.relatedTarget?.closest?.('.nota-clinica')) return;
        cerrar(true);
      });
    };
    lista.addEventListener('click', (ev) => {
      const dd = ev.target.closest('dd[data-campo]');
      if (dd && !ev.target.closest('input, textarea')) editar(dd);
    });
    lista.addEventListener('keydown', (ev) => {
      const dd = ev.target.closest('dd[data-campo]');
      if (dd && ev.target === dd && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); editar(dd); }
    });
  });
}

/* ---------- Crear cuenta en tres pasos ---------- */
function iniciarRegistro(form) {
  const datos = JSON.parse(document.getElementById('registro-datos').textContent);
  const pasos = [...form.querySelectorAll('[data-paso]')];
  const imagenes = [...document.querySelectorAll('[data-banner]')];
  let actual = Number(form.dataset.pasoInicial) || 1;
  let capa = 0;

  const ponerBanner = (paso, animar) => {
    const url = datos.banners[(paso - 1) % datos.banners.length];
    if (!animar) { imagenes[0].style.backgroundImage = `url("${url}")`; return; }
    capa = 1 - capa;
    imagenes[capa].style.backgroundImage = `url("${url}")`;
    imagenes.forEach((img, i) => img.classList.toggle('activa', i === capa));
  };

  const mostrar = (paso, animar = true) => {
    const direccion = paso > actual ? 'adelante' : 'atras';
    actual = paso;
    pasos.forEach((p) => {
      const visible = Number(p.dataset.paso) === paso;
      p.hidden = !visible;
      p.classList.toggle(`entra-${direccion}`, visible && animar);
      if (!visible) p.classList.remove('entra-adelante', 'entra-atras');
    });
    ponerBanner(paso, animar);
    if (paso === 3) pintarPermisos();
    pasos[paso - 1].querySelector('input:not([type="hidden"]):not([type="file"]):not([disabled]), select')?.focus({ preventScroll: true });
  };

  // Lo que podrá hacer según el rol elegido
  const rolElegido = () => form.querySelector('input[name="rol"]:checked')?.value || form.querySelector('input[type="hidden"][name="rol"]')?.value || '';
  const pintarPermisos = () => {
    const rol = rolElegido();
    form.querySelector('[data-rol-nombre]').textContent = (datos.roles[rol] || '').toLowerCase();
    form.querySelector('[data-permisos]').innerHTML = (datos.permisos[rol] || []).map((p) => `
      <li><span class="registro__lista-icono"><svg class="icono" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg></span>${escaparHtml(p)}</li>`).join('');
  };

  // Cédula obligatoria para médico
  const cedula = form.querySelector('[data-cedula-campo]');
  const notaCedula = form.querySelector('[data-cedula-nota]');
  const actualizarCedula = () => {
    const obligatoria = form.querySelector('input[name="rol"]:checked')?.dataset.cedula === '1';
    cedula.required = obligatoria;
    notaCedula.textContent = obligatoria ? '(obligatoria para médico)' : '(opcional)';
  };
  form.querySelectorAll('input[name="rol"]').forEach((r) => r.addEventListener('change', actualizarCedula));
  cedula.addEventListener('input', () => { cedula.value = cedula.value.replace(/\D/g, ''); });
  actualizarCedula();

  // Validación del paso antes de avanzar
  const validarPaso = (paso) => {
    const seccion = pasos[paso - 1];
    for (const campo of seccion.querySelectorAll('input:not([type="hidden"]):not([disabled])')) {
      if (campo.type === 'radio') {
        if (campo.required && !seccion.querySelector(`input[name="${campo.name}"]:checked`)) {
          mostrarNotificacion('error', 'Elige tu rol en el establecimiento.');
          return false;
        }
        continue;
      }
      if (campo.name === 'cedula' && campo.value && !/^\d{6,10}$/.test(campo.value)) {
        campo.focus();
        mostrarNotificacion('error', 'La cédula profesional debe tener de 6 a 10 dígitos.');
        return false;
      }
      if (!campo.checkValidity()) {
        campo.focus();
        campo.classList.add('invalido');
        mostrarNotificacion('error', campo.type === 'email' ? 'Escribe un correo de trabajo válido.' : 'Completa este campo para continuar.');
        return false;
      }
    }
    return true;
  };
  form.addEventListener('input', (ev) => ev.target.classList?.remove('invalido'));
  form.querySelectorAll('[data-siguiente]').forEach((b) => b.addEventListener('click', () => { if (validarPaso(actual)) mostrar(actual + 1); }));
  form.querySelectorAll('[data-anterior]').forEach((b) => b.addEventListener('click', () => mostrar(actual - 1)));
  // Enter en un campo avanza al siguiente paso en lugar de enviar
  form.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && actual < 3 && ev.target.matches('input')) {
      ev.preventDefault();
      pasos[actual - 1].querySelector('[data-siguiente]').click();
    }
  });
  form.addEventListener('submit', (ev) => {
    const clave = form.querySelector('#r-clave');
    const confirmar = form.querySelector('#r-confirmar');
    if (!validarPaso(3)) { ev.preventDefault(); return; }
    if (clave.value !== confirmar.value) {
      ev.preventDefault();
      confirmar.focus();
      mostrarNotificacion('error', 'Las contraseñas no coinciden.');
    }
  });

  // Vista previa de la foto de perfil
  const entrada = form.querySelector('[data-foto-entrada]');
  const vista = form.querySelector('[data-foto-vista]');
  let urlVista = null;
  entrada.addEventListener('change', () => {
    const archivo = entrada.files[0];
    if (urlVista) URL.revokeObjectURL(urlVista);
    if (!archivo) { vista.textContent = '?'; return; }
    if (archivo.size > 5 * 1024 * 1024) { mostrarNotificacion('error', 'La foto pesa más de 5 MB.'); entrada.value = ''; return; }
    urlVista = URL.createObjectURL(archivo);
    vista.innerHTML = `<img src="${urlVista}" alt="">`;
  });

  // Iniciales en la vista previa mientras no haya foto
  const iniciales = () => {
    if (entrada.files[0]) return;
    const n = form.querySelector('#r-nombre').value.trim().charAt(0);
    const a = form.querySelector('#r-apellidos').value.trim().charAt(0);
    vista.textContent = (n + a).toUpperCase() || '?';
  };
  form.querySelector('#r-nombre').addEventListener('input', iniciales);
  form.querySelector('#r-apellidos').addEventListener('input', iniciales);
  iniciales();

  mostrar(actual, false);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-registro]').forEach(iniciarRegistro);
});


/* ---------- Secciones de la barra lateral que se pliegan (se recuerda en este navegador) ---------- */
function iniciarSeccionesPlegables() {
  const clave = `expediente-lateral-plegadas-${document.body.dataset.usuario ?? ''}`;
  let plegadas = [];
  try { plegadas = JSON.parse(localStorage.getItem(clave) || '[]'); } catch (e) { /* opcional */ }
  document.querySelectorAll('[data-plegable]').forEach((seccion) => {
    const boton = seccion.querySelector('.lateral-seccion__alternar');
    const fijar = (plegada) => {
      seccion.classList.toggle('plegada', plegada);
      boton.setAttribute('aria-expanded', String(!plegada));
    };
    fijar(plegadas.includes(seccion.dataset.plegable));
    boton.addEventListener('click', () => {
      const plegada = !seccion.classList.contains('plegada');
      fijar(plegada);
      plegadas = plegadas.filter((p) => p !== seccion.dataset.plegable);
      if (plegada) plegadas.push(seccion.dataset.plegable);
      try { localStorage.setItem(clave, JSON.stringify(plegadas)); } catch (e) { /* opcional */ }
    });
  });
}
document.addEventListener('DOMContentLoaded', iniciarSeccionesPlegables);

/* ---------- Pacientes por estado: se abre desde el enlace «#mapa» de la barra lateral ---------- */
function iniciarPanelGeo() {
  const panel = document.querySelector('[data-geo-panel]');
  if (!panel) return;
  const boton = document.querySelector('[data-geo-alternar]');
  const fijar = (abierto, desplazar) => {
    panel.classList.toggle('abierto', abierto);
    boton?.setAttribute('aria-expanded', String(abierto));
    boton?.classList.toggle('activo', abierto);
    if (abierto) panel.querySelector('[data-geo]')?.dispatchEvent(new Event('geo:open'));
    if (abierto && desplazar) setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  };
  boton?.addEventListener('click', () => fijar(!panel.classList.contains('abierto'), true));
  // "Pacientes por estado" en la barra lateral lleva a #mapa: abre el panel
  const revisarAncla = () => { if (location.hash === '#mapa') fijar(true, true); };
  window.addEventListener('hashchange', revisarAncla);
  revisarAncla();
}
document.addEventListener('DOMContentLoaded', iniciarPanelGeo);

/* ---------- Aviso de datos de la clínica: "Recordar más tarde" lo oculta por 12 h ---------- */
function iniciarAvisoDatos() {
  const aviso = document.querySelector('.aviso-papelera--datos');
  if (!aviso) return;
  try {
    const oculto = Number(localStorage.getItem('aviso-datos-oculto')) || 0;
    if (Date.now() - oculto < 12 * 60 * 60 * 1000) { aviso.remove(); return; }
  } catch (e) { /* opcional */ }
  aviso.querySelector('[data-aviso-datos-ocultar]')?.addEventListener('click', () => {
    try { localStorage.setItem('aviso-datos-oculto', String(Date.now())); } catch (e) { /* opcional */ }
    aviso.remove();
  });
}
document.addEventListener('DOMContentLoaded', iniciarAvisoDatos);
