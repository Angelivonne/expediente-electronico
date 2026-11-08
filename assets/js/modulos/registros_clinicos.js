/* Registros clínicos (pacientes/nuevo_registro.php): renglones de medicamentos y diagnósticos,
   campos que dependen de otro, IMC, validación antes de enviar y aviso al salir sin guardar.
   La validación definitiva la hace pacientes/historial.php. */
(() => {
  const form = document.querySelector('[data-registro-clinico]');
  const campoCatalogoInicial = document.querySelector('[data-catalogo]');
  const raizCatalogo = form || campoCatalogoInicial?.closest('form');

  /* ---------- Búsqueda de diagnósticos y medicamentos del catálogo ---------- */
  const baseApi = '/ExpedienteFEMECI/api';
  let siguienteLista = 0;
  const temporizadores = new WeakMap();
  const solicitudes = new WeakMap();
  const normalizarCatalogo = (valor) => String(valor ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase().replace(/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/gu, ' ').replace(/[^\p{L}\p{N}]+/gu, ' ').trim().replace(/\s+/g, ' ');
  const coincidenciaExacta = (item, tipo, escrito) => {
    const consultas = tipo === 'cie10'
      ? [item.codigo, item.descripcion, `${item.codigo || ''} ${item.descripcion || ''}`, item.nombre]
      : [item.principio, `${item.principio || ''} ${item.concentracion || ''}`, `${item.principio || ''} ${item.forma || ''} ${item.concentracion || ''}`, item.nombre];
    const busqueda = normalizarCatalogo(escrito);
    return Boolean(busqueda && consultas.some((c) => normalizarCatalogo(c) === busqueda));
  };
  const inicializarCatalogo = (raiz = raizCatalogo) => {
    if (!raiz) return;
    const entradas = raiz.matches?.('[data-catalogo]') ? [raiz] : raiz.querySelectorAll('[data-catalogo]');
    entradas.forEach((entrada) => {
    if (entrada.dataset.catalogoListo) return;
    entrada.dataset.catalogoListo = '1';
    const campo = entrada.closest('.campo') || entrada.closest('td[data-campo]') || entrada.closest('dd[data-campo]');
    if (!campo) return;
    campo.classList.add('campo--catalogo');
    const seccionActiva = entrada.closest('.seccion, .ficha__seccion, tr');
    const lista = document.createElement('ul');
    lista.className = 'registro-clinico__sugerencias';
    lista.id = `registro-sugerencias-${++siguienteLista}`;
    lista.setAttribute('role', 'listbox');
    lista.hidden = true;
    campo.appendChild(lista);
    entrada.setAttribute('aria-controls', lista.id);
    let elementos = [];
    let activo = -1;
    const cerrar = () => { lista.hidden = true; entrada.setAttribute('aria-expanded', 'false'); activo = -1; };
    const escoger = (item) => {
      if (entrada.dataset.catalogo === 'cie10') {
        const renglon = entrada.closest('[data-fila]');
        const codigo = renglon?.querySelector('[data-cie]');
        entrada.value = item.descripcion || item.nombre;
        entrada.dataset.seleccionCatalogo = entrada.value;
        const idCatalogo = renglon?.querySelector('[data-cie-id]');
        if (idCatalogo) idCatalogo.value = item.id || '';
        if (codigo) { codigo.value = item.codigo || ''; codigo.dispatchEvent(new Event('input', { bubbles: true })); }
      } else {
        entrada.value = item.principio || item.nombre;
        const renglon = entrada.closest('[data-fila]');
        const idCatalogo = renglon?.querySelector('[data-medicamento-id]');
        if (idCatalogo) idCatalogo.value = item.id || '';
        const presentacion = renglon?.querySelector('[name="presentacion[]"]');
        const partes = [item.forma, item.concentracion].filter(Boolean);
        if (presentacion && partes.length) { presentacion.value = partes.join(' · ').slice(0, 120); presentacion.dispatchEvent(new Event('input', { bubbles: true })); }
        else if (!renglon && item.concentracion) {
          entrada.value = `${entrada.value} ${item.concentracion}`;
        }
        const via = renglon?.querySelector('[name="via[]"]');
        if (via && item.via && [...via.options].some((opcion) => opcion.value.toLocaleLowerCase() === item.via.toLocaleLowerCase())) {
          via.value = [...via.options].find((opcion) => opcion.value.toLocaleLowerCase() === item.via.toLocaleLowerCase()).value;
          via.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
      entrada.dataset.seleccionCatalogo = entrada.value;
      entrada.dispatchEvent(new Event('input', { bubbles: true }));
      cerrar();
    };
    const mostrar = (resultados, tipo) => {
      elementos = resultados;
      activo = -1;
      lista.replaceChildren();
      resultados.forEach((item, indice) => {
        const li = document.createElement('li');
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.role = 'option';
        boton.className = 'registro-clinico__sugerencia';
        const titulo = document.createElement('span');
        const detalle = document.createElement('small');
        if (tipo === 'cie10') {
          titulo.textContent = `${item.codigo || ''} · ${item.descripcion || item.nombre || ''}`;
          detalle.textContent = item.capitulo || '';
        } else {
          titulo.textContent = [item.principio || item.nombre || '', item.clave_compendio].filter(Boolean).join(' · ');
          const oficial = (item.descripcion_compendio || '').replace(/\s+/g, ' ').trim();
          detalle.textContent = oficial ? `${item.grupo ? `${item.grupo} · ` : ''}${oficial.slice(0, 180)}${oficial.length > 180 ? '…' : ''}` : [item.forma || item.presentacion, item.concentracion, item.via].filter(Boolean).join(' · ');
        }
        boton.append(titulo, detalle);
        boton.addEventListener('pointerdown', (ev) => ev.preventDefault());
        boton.addEventListener('click', () => escoger(item));
        li.appendChild(boton);
        lista.appendChild(li);
      });
      lista.hidden = resultados.length === 0;
      entrada.setAttribute('aria-expanded', resultados.length ? 'true' : 'false');
    };
    entrada.addEventListener('input', () => {
      const escrito = entrada.value.trim();
      if (entrada.dataset.seleccionCatalogo && normalizarCatalogo(escrito) === normalizarCatalogo(entrada.dataset.seleccionCatalogo)) {
        clearTimeout(temporizadores.get(entrada));
        solicitudes.get(entrada)?.abort();
        cerrar();
        return;
      }
      if (entrada.dataset.catalogo === 'cie10' && entrada.dataset.seleccionCatalogo) {
        const codigo = entrada.closest('[data-fila]')?.querySelector('[data-cie]');
        if (codigo) codigo.value = '';
        const idCatalogo = entrada.closest('[data-fila]')?.querySelector('[data-cie-id]');
        if (idCatalogo) idCatalogo.value = '';
        delete entrada.dataset.seleccionCatalogo;
      }
      if (entrada.dataset.catalogo === 'medicamentos' && entrada.dataset.seleccionCatalogo) {
        const idCatalogo = entrada.closest('[data-fila]')?.querySelector('[data-medicamento-id]');
        if (idCatalogo) idCatalogo.value = '';
        delete entrada.dataset.seleccionCatalogo;
      }
      clearTimeout(temporizadores.get(entrada));
      solicitudes.get(entrada)?.abort();
      if (escrito.length < 2) { cerrar(); return; }
      const espera = setTimeout(async () => {
        // El dictado marca el renglón como elegido después del evento input; no reabrir sugerencias.
        if (entrada.dataset.seleccionCatalogo && normalizarCatalogo(entrada.value.trim()) === normalizarCatalogo(entrada.dataset.seleccionCatalogo)) {
          cerrar();
          return;
        }
        const controlador = new AbortController();
        solicitudes.set(entrada, controlador);
        const recurso = entrada.dataset.catalogo === 'cie10' ? 'cie10' : 'medicamentos';
        try {
          const respuesta = await fetch(`${baseApi}/${recurso}/buscar.php?q=${encodeURIComponent(escrito)}`, { signal: controlador.signal, headers: { Accept: 'application/json' } });
          if (!respuesta.ok) return cerrar();
          const datos = await respuesta.json();
          const resultados = recurso === 'cie10' ? (datos.diagnosticos || []) : (datos.medicamentos || []);
          if (Number(datos.total) === 1 && resultados.length === 1 && coincidenciaExacta(resultados[0], recurso, escrito)) {
            escoger(resultados[0]);
            return;
          }
          mostrar(resultados, recurso);
        } catch (error) {
          if (error.name !== 'AbortError') cerrar();
        }
      }, 180);
      temporizadores.set(entrada, espera);
    });
    entrada.addEventListener('keydown', (ev) => {
      if (lista.hidden) return;
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        activo = (activo + (ev.key === 'ArrowDown' ? 1 : -1) + elementos.length) % elementos.length;
        lista.querySelectorAll('[role="option"]').forEach((opcion, indice) => opcion.setAttribute('aria-selected', indice === activo ? 'true' : 'false'));
      } else if (ev.key === 'Enter' && elementos.length) {
        ev.preventDefault(); escoger(elementos[activo >= 0 ? activo : 0]);
      } else if (ev.key === 'Escape') cerrar();
    });
    entrada.addEventListener('focus', () => seccionActiva?.classList.add('seccion--catalogo-activo'));
    entrada.addEventListener('blur', () => setTimeout(() => {
      cerrar();
      seccionActiva?.classList.remove('seccion--catalogo-activo');
    }, 120));
    });
  };
  window.inicializarCatalogoClinico = inicializarCatalogo;
  inicializarCatalogo();

  // El mismo catálogo también ayuda al registrar medicamentos actuales en la ficha del paciente.
  if (!form) return;

  const mejorar = (raiz) => {
    if (typeof mejorarSelect === 'function') raiz.querySelectorAll('select').forEach(mejorarSelect);
  };
  const error = (campo, mensaje) => {
    if (typeof marcarError === 'function') { marcarError(campo, mensaje); return; }
    campo.closest('.campo')?.classList.toggle('campo--error', Boolean(mensaje));
  };
  const vacio = (el) => (el.type === 'checkbox' ? !el.checked : !String(el.value).trim());

  /* ---------- Renglones repetibles ---------- */
  const numerar = (caja) => caja.querySelectorAll(':scope > [data-fila]').forEach((fila, i) => {
    const n = fila.querySelector('[data-fila-numero]');
    if (n) n.textContent = String(i + 1);
  });

  form.addEventListener('click', (ev) => {
    const agregar = ev.target.closest('[data-fila-agregar]');
    if (agregar) {
      const nombre = agregar.dataset.filaAgregar;
      const caja = form.querySelector(`[data-filas="${nombre}"]`);
      const plantilla = form.querySelector(`template[data-plantilla="${nombre}"]`);
      const fila = plantilla.content.firstElementChild.cloneNode(true);
      caja.appendChild(fila);
      mejorar(fila);
      inicializarCatalogo(fila);
      numerar(caja);
      fila.querySelector('input')?.focus();
      return;
    }
    const quitar = ev.target.closest('[data-fila-quitar]');
    if (quitar) {
      const fila = quitar.closest('[data-fila]');
      const caja = fila.parentElement;
      if (caja.querySelectorAll(':scope > [data-fila]').length > 1) {
        const siguiente = fila.nextElementSibling || fila.previousElementSibling;
        fila.remove();
        numerar(caja);
        siguiente?.querySelector('input')?.focus();
      }
    }
  });

  /* ---------- Campos que dependen de otro: data-mostrar-si="campo:valor" o "campo:!valor" ---------- */
  const dependientes = [...form.querySelectorAll('[data-mostrar-si]')];
  const aplicarDependientes = () => dependientes.forEach((caja) => {
    const [nombre, condicion] = caja.dataset.mostrarSi.split(':');
    const actual = form.elements[nombre]?.value || '';
    const negado = condicion.startsWith('!');
    const valor = negado ? condicion.slice(1) : condicion;
    const visible = negado ? actual !== '' && actual !== valor : actual === valor;
    caja.hidden = !visible;
    caja.querySelectorAll('input, textarea, select').forEach((el) => { el.disabled = !visible; });
  });
  aplicarDependientes();
  form.addEventListener('change', (ev) => { if (ev.target.matches('select')) aplicarDependientes(); });

  // Consentimiento: si lo otorga el paciente, su nombre; si es otra persona, se escribe el suyo
  const otorga = form.elements.otorga;
  const otorgante = form.elements.otorgante;
  if (otorga && otorgante) {
    const nombrePaciente = form.dataset.pacienteNombre || '';
    otorga.addEventListener('change', () => {
      if (otorga.value === 'Paciente' && !otorgante.value.trim()) otorgante.value = nombrePaciente;
      else if (otorga.value !== 'Paciente' && otorgante.value === nombrePaciente) otorgante.value = '';
    });
  }

  /* ---------- IMC a partir de peso y talla ---------- */
  const imc = form.querySelector('[data-imc]');
  const numero = (el) => parseFloat(String(el?.value || '').replace(',', '.'));
  if (imc) {
    const peso = form.querySelector('[data-signo="peso"]');
    const talla = form.querySelector('[data-signo="talla"]');
    const calcular = () => {
      const p = numero(peso);
      const t = numero(talla) / 100;
      imc.value = p > 0 && t > 0.3 ? (p / (t * t)).toFixed(1) : '';
    };
    peso.addEventListener('input', calcular);
    talla.addEventListener('input', calcular);
  }

  /* ---------- Formato mientras se escribe ---------- */
  form.addEventListener('input', (ev) => {
    const el = ev.target;
    if (el.matches('[data-cie]')) el.value = el.value.toUpperCase().replace(/[^A-Z0-9.]/g, '');
    if (el.matches('[data-solo-digitos]')) el.value = el.value.replace(/\D/g, '');
    if (el.matches('[data-signo]')) el.value = el.value.replace(/[^0-9.,]/g, '');
    if (el.value) error(el, '');
  });
  form.addEventListener('change', (ev) => {
    if (ev.target.matches('select, input[type="date"], input[type="time"]')) error(ev.target, '');
    if (ev.target.matches('input[type="checkbox"]')) {
      ev.target.closest('.casilla')?.classList.remove('casilla--error');
    }
  });

  /* ---------- Validación antes de enviar (la del servidor es la definitiva) ---------- */
  let enviando = false;
  form.addEventListener('submit', (ev) => {
    if (enviando) { ev.preventDefault(); return; }
    let primero = null;
    const marcar = (el, mensaje) => { error(el, mensaje); primero = primero || el; };

    form.querySelectorAll('[required]:not([disabled])').forEach((el) => {
      if (!vacio(el)) return;
      if (el.type === 'checkbox') {
        el.closest('.casilla')?.classList.add('casilla--error');
        primero = primero || el;
      } else {
        marcar(el, 'Campo obligatorio');
      }
    });

    // Renglones: solo se validan los que tienen algo escrito; las listas obligatorias necesitan uno
    form.querySelectorAll('[data-filas]').forEach((caja) => {
      const filas = [...caja.querySelectorAll(':scope > [data-fila]')];
      const conDatos = filas.filter((f) => [...f.querySelectorAll('input:not([readonly])')].some((i) => i.value.trim()));
      conDatos.forEach((f) => f.querySelectorAll('[data-requerido-fila]').forEach((el) => {
        if (vacio(el)) marcar(el, 'Campo obligatorio');
      }));
      if (!conDatos.length && caja.hasAttribute('data-filas-requeridas')) {
        const el = filas[0]?.querySelector('[data-requerido-fila]');
        if (el) marcar(el, 'Agrega al menos un renglón');
      }
    });

    form.querySelectorAll('[data-cie]').forEach((el) => {
      if (el.value && !/^[A-Z]\d{2}(\.?[0-9A-Z]{1,4})?$/.test(el.value)) marcar(el, 'Código CIE-10 no válido');
    });
    form.querySelectorAll('[data-signo]').forEach((el) => {
      if (el.value && Number.isNaN(numero(el))) marcar(el, 'Escribe un número');
    });
    const cedula = form.querySelector('[data-solo-digitos]');
    if (cedula && cedula.value && !/^\d{6,10}$/.test(cedula.value)) marcar(cedula, 'De 6 a 10 dígitos');

    if (primero) {
      ev.preventDefault();
      const visible = primero.classList.contains('select-oculto') ? primero.nextElementSibling : primero;
      visible?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      visible?.focus({ preventScroll: true });
      if (typeof mostrarNotificacion === 'function') mostrarNotificacion('error', 'Revisa los campos marcados en rojo.');
      return;
    }
    enviando = true;
    sinGuardar = false;
    form.querySelector('[type="submit"]')?.setAttribute('disabled', '');
  });

  /* ---------- Aviso al salir con cambios sin guardar ---------- */
  let sinGuardar = false;
  form.addEventListener('input', (ev) => { if (ev.isTrusted) sinGuardar = true; });
  window.addEventListener('beforeunload', (ev) => {
    if (!sinGuardar) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  // Los errores que devolvió el servidor: el primero queda a la vista (tras crear los selectores de la app)
  document.addEventListener('DOMContentLoaded', () => {
    const conError = form.querySelector('.campo--error input, .campo--error textarea, .campo--error select, .casilla--error input');
    if (!conError) return;
    const visible = conError.classList.contains('select-oculto') ? conError.nextElementSibling : conError;
    visible?.scrollIntoView({ block: 'center' });
  });
})();
