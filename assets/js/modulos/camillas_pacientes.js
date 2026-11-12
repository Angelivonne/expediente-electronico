(() => {
  const LIMITE = 20;
  const modal = document.getElementById('modal-asignar-camilla');
  if (!(modal instanceof HTMLDialogElement)) return;

  const entrada = modal.querySelector('[data-camillas-paciente-buscar]');
  const resultados = modal.querySelector('[data-camillas-paciente-resultados]');
  const seleccion = modal.querySelector('[data-camillas-paciente-seleccion]');
  const paginacion = modal.querySelector('[data-camillas-paciente-paginacion]');
  const anterior = modal.querySelector('[data-camillas-paciente-anterior]');
  const siguiente = modal.querySelector('[data-camillas-paciente-siguiente]');
  const etiquetaPagina = modal.querySelector('[data-camillas-paciente-pagina]');
  const estado = modal.querySelector('[data-camillas-paciente-estado]');
  if (!(entrada instanceof HTMLInputElement)
    || !(resultados instanceof HTMLElement)
    || !(seleccion instanceof HTMLSelectElement)
    || !(paginacion instanceof HTMLElement)
    || !(anterior instanceof HTMLButtonElement)
    || !(siguiente instanceof HTMLButtonElement)
    || !(etiquetaPagina instanceof HTMLElement)
    || !(estado instanceof HTMLElement)) return;

  let pagina = 1;
  let total = 0;
  let temporizador = null;
  let peticion = null;
  let revision = 0;
  let inicializado = false;

  async function buscar(numeroPagina = 1) {
    pagina = numeroPagina;
    const miRevision = ++revision;
    peticion?.abort();
    peticion = new AbortController();
    anterior.disabled = true;
    siguiente.disabled = true;
    resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });
    estado.textContent = 'Buscando pacientes…';

    const consulta = new URLSearchParams({
      q: entrada.value.trim(),
      orden: 'nombre',
      pagina: String(pagina),
      disponibles_camillas: '1',
    });

    try {
      const respuesta = await fetch(`${BASE}/api/pacientes/buscar.php?${consulta}`, {
        headers: { Accept: 'application/json' },
        signal: peticion.signal,
      });
      const datos = await respuesta.json();
      if (!respuesta.ok || datos.ok === false) {
        throw new Error(datos.error || 'No se pudieron consultar los pacientes.');
      }
      if (miRevision !== revision) return;

      const pacientes = Array.isArray(datos.pacientes) ? datos.pacientes : [];
      total = Number(datos.total) || 0;
      const paginas = Math.max(1, Math.ceil(total / LIMITE));
      resultados.replaceChildren();

      if (pacientes.length === 0) {
        const mensaje = document.createElement('p');
        mensaje.className = 'camillas__pacientes-mensaje';
        mensaje.textContent = entrada.value.trim()
          ? 'No hay pacientes disponibles que coincidan con esta búsqueda.'
          : 'No hay pacientes activos disponibles para asignar.';
        resultados.append(mensaje);
      } else {
        pacientes.forEach((paciente) => {
          const boton = document.createElement('button');
          boton.type = 'button';
          boton.className = 'camillas__paciente-opcion';
          boton.dataset.pacienteId = String(Number(paciente.id));
          boton.dataset.pacienteNombre = String(paciente.nombre || '');
          boton.dataset.pacienteFolio = String(paciente.folio || '');
          boton.setAttribute('aria-pressed', String(String(paciente.id) === seleccion.value));

          const nombre = document.createElement('span');
          nombre.textContent = String(paciente.nombre || 'Paciente sin nombre');
          const folio = document.createElement('small');
          folio.textContent = paciente.folio ? String(paciente.folio) : 'Sin folio';
          boton.append(nombre, folio);
          resultados.append(boton);
        });
      }

      paginacion.hidden = total <= LIMITE;
      etiquetaPagina.textContent = `Página ${pagina} de ${paginas} · ${total} pacientes`;
      anterior.disabled = pagina <= 1;
      siguiente.disabled = pagina >= paginas;
      estado.textContent = total
        ? `Mostrando ${Math.min(LIMITE, total - (pagina - 1) * LIMITE)} de ${total} pacientes disponibles.`
        : 'Sin resultados.';
    } catch (error) {
      if (error.name === 'AbortError' || miRevision !== revision) return;
      const mensaje = document.createElement('p');
      mensaje.className = 'camillas__pacientes-mensaje camillas__pacientes-mensaje--error';
      mensaje.textContent = error.message || 'No se pudieron cargar los pacientes.';
      resultados.replaceChildren(mensaje);
      paginacion.hidden = true;
      estado.textContent = 'No se pudieron cargar los resultados.';
    }
  }

  function iniciar() {
    if (inicializado) return;
    inicializado = true;
    buscar();
  }

  document.addEventListener('click', (evento) => {
    if (!(evento.target instanceof Element)) return;
    const disparador = evento.target.closest('[data-camillas-modal-abrir="modal-asignar-camilla"]');
    if (disparador) iniciar();
  });

  entrada.addEventListener('input', () => {
    clearTimeout(temporizador);
    revision++;
    peticion?.abort();
    resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => { boton.disabled = true; });
    estado.textContent = 'Buscando pacientes…';
    temporizador = setTimeout(() => buscar(1), 250);
  });

  anterior.addEventListener('click', () => {
    if (pagina > 1) buscar(pagina - 1);
  });
  siguiente.addEventListener('click', () => {
    if (pagina * LIMITE < total) buscar(pagina + 1);
  });

  resultados.addEventListener('click', (evento) => {
    if (!(evento.target instanceof Element)) return;
    const opcion = evento.target.closest('[data-paciente-id]');
    if (!(opcion instanceof HTMLButtonElement) || opcion.disabled) return;

    const id = opcion.dataset.pacienteId || '';
    const nombre = opcion.dataset.pacienteNombre || 'Paciente sin nombre';
    const folio = opcion.dataset.pacienteFolio || '';
    const texto = folio ? `${folio} · ${nombre}` : nombre;
    let opcionSelect = Array.from(seleccion.options).find((elemento) => elemento.value === id);
    if (!opcionSelect) {
      opcionSelect = new Option(texto, id);
      seleccion.add(opcionSelect);
    } else {
      opcionSelect.textContent = texto;
    }
    seleccion.value = id;
    resultados.querySelectorAll('[data-paciente-id]').forEach((boton) => {
      boton.setAttribute('aria-pressed', String(boton === opcion));
    });
    estado.textContent = `${nombre} quedó seleccionado para el ingreso.`;
  });

  modal.addEventListener('close', () => {
    clearTimeout(temporizador);
    revision++;
    peticion?.abort();
    peticion = null;
  });
})();
