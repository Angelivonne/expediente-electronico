(() => {
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
  const buscarTabla = (entidad) => [...document.querySelectorAll('.bd-tabla[data-entidad]')]
    .find((tabla) => tabla.dataset.entidad === entidad);

  document.querySelectorAll('[data-vistas-tabla]').forEach((panel) => {
    const entidad = panel.dataset.entidad;
    const tabla = buscarTabla(entidad);
    const seleccion = panel.querySelector('[data-vistas-select]');
    const mensaje = panel.querySelector('[data-vistas-mensaje]');
    const columnas = panel.querySelector('[data-vistas-columnas]');
    if (!entidad || !tabla || !seleccion || !mensaje || !columnas) return;

    let vistas = [];
    let ocultas = new Set();
    const cabecera = () => [...tabla.tHead.rows].at(-1)?.cells || [];
    const marcarColumnas = () => {
      const celdas = cabecera();
      celdas.forEach((celda, indice) => { celda.hidden = ocultas.has(String(indice)); });
      [...tabla.tBodies].flatMap((cuerpo) => [...cuerpo.rows]).forEach((fila) => {
        celdas.forEach((celda, indice) => {
          if (fila.cells[indice]) fila.cells[indice].hidden = ocultas.has(String(indice));
        });
      });
    };
    const pintarColumnas = () => {
      columnas.replaceChildren();
      cabecera().forEach((celda, indice) => {
        const etiqueta = document.createElement('label');
        const casilla = document.createElement('input');
        const texto = celda.textContent.trim().replace(/\s+/g, ' ');
        casilla.type = 'checkbox';
        casilla.checked = !ocultas.has(String(indice));
        casilla.addEventListener('change', () => {
          if (casilla.checked) ocultas.delete(String(indice));
          else ocultas.add(String(indice));
          marcarColumnas();
        });
        etiqueta.append(casilla, document.createTextNode(texto || `Columna ${indice + 1}`));
        columnas.append(etiqueta);
      });
    };
    const hablar = (texto, error = false) => {
      mensaje.textContent = texto;
      mensaje.dataset.error = error ? '1' : '0';
    };
    const llamada = async (metodo, cuerpo) => {
      const url = new URL(panel.dataset.url, location.href);
      if (metodo === 'GET') url.searchParams.set('entidad', entidad);
      const respuesta = await fetch(url, {
        method: metodo,
        headers: {
          Accept: 'application/json',
          ...(metodo !== 'GET' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}),
        },
        ...(cuerpo ? { body: JSON.stringify(cuerpo) } : {}),
      });
      const datos = await respuesta.json();
      if (!respuesta.ok || !datos.ok) throw new Error(datos.error || 'No se pudo completar la acción.');
      return datos;
    };
    const cargar = async () => {
      const datos = await llamada('GET');
      vistas = datos.vistas;
      seleccion.replaceChildren(new Option('Selecciona una vista', ''));
      vistas.forEach((vista) => seleccion.add(new Option(vista.nombre, String(vista.id))));
    };

    pintarColumnas();
    cargar().catch((error) => hablar(error.message, true));

    panel.querySelector('[data-vistas-guardar]')?.addEventListener('click', async () => {
      const nombre = panel.querySelector('[data-vistas-nombre]')?.value.trim();
      if (!nombre) return hablar('Escribe un nombre para la vista.', true);
      const params = new URLSearchParams(location.search);
      const consulta = {};
      ['orden', 'dir', 'campo', 'valor', 'agrupar'].forEach((clave) => { consulta[clave] = params.get(clave) || ''; });
      try {
        await llamada('POST', { entidad, nombre, estado: { consulta, ocultas: [...ocultas] } });
        await cargar();
        hablar('Vista guardada para tu cuenta.');
      } catch (error) {
        hablar(error.message, true);
      }
    });

    panel.querySelector('[data-vistas-aplicar]')?.addEventListener('click', () => {
      const vista = vistas.find((item) => String(item.id) === seleccion.value);
      if (!vista) return hablar('Selecciona una vista guardada.', true);
      ocultas = new Set((vista.estado.ocultas || []).map(String));
      pintarColumnas();
      marcarColumnas();
      const params = new URLSearchParams(location.search);
      ['orden', 'dir', 'campo', 'valor', 'agrupar'].forEach((clave) => {
        params.delete(clave);
        const valor = vista.estado.consulta?.[clave];
        if (typeof valor === 'string' && valor !== '') params.set(clave, valor);
      });
      location.assign(`${location.pathname}${params.size ? `?${params}` : ''}`);
    });

    panel.querySelector('[data-vistas-eliminar]')?.addEventListener('click', async () => {
      const id = Number(seleccion.value);
      if (!id) return hablar('Selecciona una vista guardada.', true);
      if (!window.confirm('¿Eliminar esta vista guardada?')) return;
      try {
        await llamada('DELETE', { entidad, id });
        await cargar();
        hablar('Vista eliminada.');
      } catch (error) {
        hablar(error.message, true);
      }
    });
  });
})();
