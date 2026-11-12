/* Búsqueda paginada de pacientes para los movimientos financieros. */
(() => {
  const TAMANO_PAGINA = 20;

  async function consultarPacientes(busqueda, pagina, signal) {
    const consulta = new URLSearchParams({
      q: busqueda,
      orden: 'nombre',
      pagina: String(pagina),
      solo_local: '1',
    });
    const respuesta = await fetch(`${BASE}/api/pacientes/buscar.php?${consulta}`, {
      headers: { Accept: 'application/json' },
      signal,
    });
    const datos = await respuesta.json();
    if (!respuesta.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo buscar pacientes.');
    return { pacientes: Array.isArray(datos.pacientes) ? datos.pacientes : [], total: Number(datos.total) || 0 };
  }

  function textoPaciente(paciente) {
    return paciente.folio ? `${paciente.folio} · ${paciente.nombre}` : paciente.nombre;
  }

  function pintarResultados(contenedor, pacientes, seleccionado = '') {
    if (!pacientes.length) {
      contenedor.innerHTML = '<p class="finanzas__pacientes-mensaje">No hay pacientes que coincidan con esta búsqueda.</p>';
      return;
    }
    contenedor.innerHTML = pacientes.map((paciente) => `
      <button type="button" class="finanzas__paciente-opcion" data-paciente-id="${Number(paciente.id)}"
        data-paciente-nombre="${escaparHtml(paciente.nombre)}" data-paciente-folio="${escaparHtml(paciente.folio || '')}"
        aria-pressed="${String(String(paciente.id) === String(seleccionado))}">
        <span>${escaparHtml(paciente.nombre)}</span>
        <small>${escaparHtml(paciente.folio || 'Sin folio')}</small>
      </button>`).join('');
  }

  function inicializarFormulario(raiz) {
    const entrada = raiz.querySelector('[data-paciente-buscar]');
    const seleccion = raiz.querySelector('[data-paciente-seleccion]');
    const resultados = raiz.querySelector('[data-paciente-resultados]');
    const paginacion = raiz.querySelector('[data-paciente-paginacion]');
    const anterior = raiz.querySelector('[data-paciente-anterior]');
    const siguiente = raiz.querySelector('[data-paciente-siguiente]');
    const etiquetaPagina = raiz.querySelector('[data-paciente-pagina]');
    const estado = raiz.querySelector('[data-paciente-estado]');
    let pagina = 1;
    let total = 0;
    let temporizador = null;
    let peticion = null;
    let revision = 0;

    async function buscar(numeroPagina = 1) {
      pagina = numeroPagina;
      const miRevision = ++revision;
      peticion?.abort();
      peticion = new AbortController();
      estado.textContent = 'Buscando pacientes…';
      anterior.disabled = true;
      siguiente.disabled = true;
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });

      try {
        const datos = await consultarPacientes(entrada.value.trim(), pagina, peticion.signal);
        if (miRevision !== revision) return;
        total = datos.total;
        const paginas = Math.max(1, Math.ceil(total / TAMANO_PAGINA));
        if (!datos.pacientes.length) {
          const mensaje = entrada.value.trim()
            ? `No hay pacientes que coincidan con «${entrada.value.trim()}».`
            : 'No hay pacientes activos registrados en esta clínica.';
          resultados.innerHTML = `<p class="finanzas__pacientes-mensaje">${escaparHtml(mensaje)}</p>`;
        } else {
          pintarResultados(resultados, datos.pacientes, seleccion.value);
        }
        paginacion.hidden = total <= TAMANO_PAGINA;
        etiquetaPagina.textContent = `Página ${pagina} de ${paginas} · ${total} ${total === 1 ? 'paciente' : 'pacientes'}`;
        anterior.disabled = pagina <= 1;
        siguiente.disabled = pagina >= paginas;
        estado.textContent = total
          ? `Mostrando ${Math.min(TAMANO_PAGINA, total - (pagina - 1) * TAMANO_PAGINA)} de ${total} pacientes.`
          : 'Sin resultados.';
      } catch (error) {
        if (error.name === 'AbortError' || miRevision !== revision) return;
        resultados.innerHTML = `<p class="finanzas__pacientes-mensaje finanzas__pacientes-mensaje--error">${escaparHtml(error.message)}</p>
          <button type="button" class="finanzas__paciente-reintentar" data-paciente-reintentar>Reintentar</button>`;
        paginacion.hidden = true;
        estado.textContent = 'No se pudieron cargar los resultados.';
      }
    }

    entrada.addEventListener('input', () => {
      clearTimeout(temporizador);
      revision++;
      peticion?.abort();
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });
      estado.textContent = 'Buscando pacientes…';
      temporizador = setTimeout(() => buscar(1), 250);
    });
    anterior.addEventListener('click', () => { if (pagina > 1) buscar(pagina - 1); });
    siguiente.addEventListener('click', () => {
      if (pagina * TAMANO_PAGINA < total) buscar(pagina + 1);
    });
    resultados.addEventListener('click', (evento) => {
      if (evento.target.closest('[data-paciente-reintentar]')) {
        buscar(pagina);
        return;
      }
      const opcion = evento.target.closest('[data-paciente-id]');
      if (!opcion) return;
      const id = opcion.dataset.pacienteId;
      const pacienteTexto = textoPaciente({
        nombre: opcion.dataset.pacienteNombre,
        folio: opcion.dataset.pacienteFolio,
      });
      let opcionSelect = Array.from(seleccion.options).find((elemento) => elemento.value === id);
      if (!opcionSelect) {
        opcionSelect = new Option(pacienteTexto, id);
        seleccion.add(opcionSelect);
      } else {
        opcionSelect.textContent = pacienteTexto;
      }
      seleccion.value = id;
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => {
        boton.setAttribute('aria-pressed', String(boton === opcion));
      });
      estado.textContent = `${opcion.dataset.pacienteNombre} quedó asociado al movimiento.`;
    });
    seleccion.addEventListener('change', () => {
      if (!seleccion.value) {
        estado.textContent = 'El movimiento no tendrá paciente asociado.';
        resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => boton.setAttribute('aria-pressed', 'false'));
        return;
      }
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => {
        boton.setAttribute('aria-pressed', String(boton.dataset.pacienteId === seleccion.value));
      });
    });

    buscar();
  }

  function abrirEditorPaciente(celda, tabla) {
    if (document.querySelector('[data-finanzas-paciente-dialog]')) return;
    const fila = celda.closest('tr');
    const registroId = Number(fila?.dataset.id);
    if (!Number.isInteger(registroId) || registroId <= 0) {
      mostrarNotificacion('error', 'No se pudo identificar el movimiento financiero.');
      return;
    }

    const valorOriginal = celda.dataset.valor || '';
    const textoOriginal = celda.textContent.trim();
    const dialogo = document.createElement('dialog');
    dialogo.className = 'finanzas__paciente-dialog';
    dialogo.dataset.finanzasPacienteDialog = '1';
    dialogo.setAttribute('aria-labelledby', 'finanzas-paciente-titulo');
    dialogo.innerHTML = `
      <form method="dialog" class="finanzas__paciente-dialog-cabecera">
        <h2 id="finanzas-paciente-titulo">Cambiar paciente asociado</h2>
        <button type="submit" class="boton-icono" aria-label="Cerrar">×</button>
      </form>
      <p class="finanzas__paciente-actual" data-paciente-actual></p>
      <label class="finanzas__pacientes-buscar" for="finanzas-paciente-busqueda">Buscar paciente</label>
      <input id="finanzas-paciente-busqueda" type="search" data-paciente-buscar placeholder="Nombre, folio o CURP" autocomplete="off">
      <div class="finanzas__pacientes-resultados" data-paciente-resultados role="region" aria-label="Resultados de pacientes"></div>
      <div class="finanzas__pacientes-paginacion" data-paciente-paginacion hidden>
        <button type="button" data-paciente-anterior disabled>Anterior</button>
        <span data-paciente-pagina></span>
        <button type="button" data-paciente-siguiente disabled>Siguiente</button>
      </div>
      <p class="finanzas__pacientes-estado" data-paciente-estado role="status" aria-live="polite"></p>
      <button type="button" class="finanzas__paciente-quitar" data-paciente-quitar${valorOriginal ? '' : ' hidden'}>Quitar asociación</button>`;
    document.body.append(dialogo);

    const entrada = dialogo.querySelector('[data-paciente-buscar]');
    const resultados = dialogo.querySelector('[data-paciente-resultados]');
    const paginacion = dialogo.querySelector('[data-paciente-paginacion]');
    const anterior = dialogo.querySelector('[data-paciente-anterior]');
    const siguiente = dialogo.querySelector('[data-paciente-siguiente]');
    const etiquetaPagina = dialogo.querySelector('[data-paciente-pagina]');
    const estado = dialogo.querySelector('[data-paciente-estado]');
    const actual = dialogo.querySelector('[data-paciente-actual]');
    const quitar = dialogo.querySelector('[data-paciente-quitar]');
    actual.textContent = valorOriginal ? `Paciente actual: ${textoOriginal}` : 'Este movimiento no tiene paciente asociado.';

    let pagina = 1;
    let total = 0;
    let temporizador = null;
    let peticion = null;
    let revision = 0;
    let guardando = false;

    async function guardarPaciente(id, nombreVisible) {
      if (guardando) return;
      guardando = true;
      estado.textContent = 'Guardando cambio…';
      quitar.disabled = true;
      dialogo.querySelector('[type="submit"]').disabled = true;
      dialogo.querySelectorAll('[data-paciente-id], [data-paciente-anterior], [data-paciente-siguiente]').forEach((control) => {
        control.disabled = true;
      });
      try {
        const respuesta = await fetch(tabla.dataset.actualizar || `${BASE}/api/motor/actualizar.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
          body: JSON.stringify({
            entidad: 'finanzas_movimientos',
            id: registroId,
            campo: 'paciente_id',
            valor: id,
          }),
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || !datos.ok) throw new Error(datos.error || 'No se pudo actualizar el paciente asociado.');
        celda.dataset.valor = String(datos.valor ?? '');
        celda.textContent = nombreVisible;
        celda.title = 'Cambiar paciente asociado';
        celda.setAttribute('aria-label', `Paciente asociado: ${nombreVisible}. Activar para cambiar.`);
        mostrarNotificacion('exito', 'Paciente asociado actualizado.');
        dialogo.close();
      } catch (error) {
        estado.textContent = error.message || 'No se pudo guardar el cambio.';
        mostrarNotificacion('error', estado.textContent);
        guardando = false;
        quitar.disabled = false;
        dialogo.querySelector('[type="submit"]').disabled = false;
        dialogo.querySelectorAll('[data-paciente-id]').forEach((control) => { control.disabled = false; });
        anterior.disabled = pagina <= 1;
        siguiente.disabled = pagina >= Math.max(1, Math.ceil(total / TAMANO_PAGINA));
      }
    }

    async function buscar(numeroPagina = 1) {
      pagina = numeroPagina;
      const miRevision = ++revision;
      peticion?.abort();
      peticion = new AbortController();
      estado.textContent = 'Buscando pacientes…';
      anterior.disabled = true;
      siguiente.disabled = true;
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });
      try {
        const datos = await consultarPacientes(entrada.value.trim(), pagina, peticion.signal);
        if (miRevision !== revision) return;
        total = datos.total;
        const paginas = Math.max(1, Math.ceil(total / TAMANO_PAGINA));
        if (datos.pacientes.length) {
          pintarResultados(resultados, datos.pacientes, valorOriginal);
        } else {
          const mensaje = entrada.value.trim()
            ? `No hay pacientes que coincidan con «${entrada.value.trim()}».`
            : 'No hay pacientes activos registrados en esta clínica.';
          resultados.innerHTML = `<p class="finanzas__pacientes-mensaje">${escaparHtml(mensaje)}</p>`;
        }
        paginacion.hidden = total <= TAMANO_PAGINA;
        etiquetaPagina.textContent = `Página ${pagina} de ${paginas} · ${total} ${total === 1 ? 'paciente' : 'pacientes'}`;
        anterior.disabled = pagina <= 1;
        siguiente.disabled = pagina >= paginas;
        estado.textContent = total
          ? `Mostrando ${Math.min(TAMANO_PAGINA, total - (pagina - 1) * TAMANO_PAGINA)} de ${total} pacientes.`
          : 'Sin resultados.';
      } catch (error) {
        if (error.name === 'AbortError' || miRevision !== revision) return;
        resultados.innerHTML = `<p class="finanzas__pacientes-mensaje finanzas__pacientes-mensaje--error">${escaparHtml(error.message)}</p>
          <button type="button" class="finanzas__paciente-reintentar" data-paciente-reintentar>Reintentar</button>`;
        paginacion.hidden = true;
        estado.textContent = 'No se pudieron cargar los resultados.';
      }
    }

    dialogo.addEventListener('close', () => {
      clearTimeout(temporizador);
      revision++;
      peticion?.abort();
      dialogo.remove();
      celda.focus({ preventScroll: true });
    });
    dialogo.addEventListener('cancel', (evento) => {
      if (guardando) evento.preventDefault();
    });
    dialogo.addEventListener('click', (evento) => {
      if (evento.target === dialogo && !guardando) dialogo.close();
    });
    entrada.addEventListener('input', () => {
      clearTimeout(temporizador);
      revision++;
      peticion?.abort();
      resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });
      estado.textContent = 'Buscando pacientes…';
      temporizador = setTimeout(() => buscar(1), 250);
    });
    anterior.addEventListener('click', () => { if (pagina > 1) buscar(pagina - 1); });
    siguiente.addEventListener('click', () => {
      if (pagina * TAMANO_PAGINA < total) buscar(pagina + 1);
    });
    quitar.addEventListener('click', () => guardarPaciente('', 'Sin paciente asociado'));
    resultados.addEventListener('click', (evento) => {
      if (evento.target.closest('[data-paciente-reintentar]')) {
        buscar(pagina);
        return;
      }
      const opcion = evento.target.closest('[data-paciente-id]');
      if (!opcion) return;
      const pacienteTexto = textoPaciente({
        nombre: opcion.dataset.pacienteNombre,
        folio: opcion.dataset.pacienteFolio,
      });
      if (opcion.dataset.pacienteId === valorOriginal) {
        dialogo.close();
        return;
      }
      guardarPaciente(opcion.dataset.pacienteId, pacienteTexto);
    });

    dialogo.showModal();
    entrada.focus();
    buscar();
  }

  document.querySelectorAll('[data-finanzas-pacientes]').forEach(inicializarFormulario);

  const tabla = document.querySelector('table[data-entidad="finanzas_movimientos"]');
  if (tabla) {
    const abrirDesdeEvento = (evento) => {
      const celda = evento.target.closest('td[data-campo="paciente_id"]');
      if (!celda) return;
      evento.preventDefault();
      evento.stopPropagation();
      abrirEditorPaciente(celda, tabla);
    };
    tabla.addEventListener('click', abrirDesdeEvento);
    tabla.addEventListener('keydown', (evento) => {
      if (evento.target.matches('td[data-campo="paciente_id"]') && ['Enter', ' '].includes(evento.key)) {
        abrirDesdeEvento(evento);
      }
    });
  }
})();
