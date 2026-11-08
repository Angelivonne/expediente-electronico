/* ==========================================================================
   Imprimir desde el perfil del paciente: elegir qué se imprime antes de abrir el documento
   (pacientes/imprimir.php). Arriba, las secciones del expediente como fichas (todas activas);
   debajo, los documentos recientes. Si se elige un documento, se imprimen solo esos.
   ========================================================================== */
(() => {
  const fuente = document.getElementById('impresion-datos');
  if (!fuente) return;
  const datos = JSON.parse(fuente.textContent);
  const partes = Array.isArray(datos.partes) ? datos.partes : [];
  const registros = Array.isArray(datos.registros) ? datos.registros : [];
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const ficha = (p) => `
    <label class="impresion-ficha"><input type="checkbox" name="partes" value="${esc(p.clave)}" checked>
      <span>${esc(p.texto)}${p.n ? `<em>${esc(p.n)}</em>` : ''}</span></label>`;
  const documento = (r) => `
    <label class="impresion-doc"><input type="checkbox" name="registros" value="${esc(r.id)}">
      <span>${esc(r.texto)}</span>${r.fecha ? `<small>${esc(r.fecha)}</small>` : ''}</label>`;
  const recientes = Math.min(5, registros.length);

  document.querySelectorAll('[data-historial-imprimir]').forEach((boton) => {
    boton.addEventListener('click', () => window.abrirImpresion(boton));
  });

  window.abrirImpresion = (ancla) => {
    if (typeof abrirPopover !== 'function') { window.print(); return; }
    const caja = document.createElement('div');
    caja.className = 'impresion-menu';
    caja.innerHTML = `
      <header class="impresion-menu__cabecera">
        <span class="impresion-menu__titulo">Imprimir</span>
        <button type="button" class="impresion-menu__atajo" data-alternar-partes>Ninguna</button>
      </header>
      <div class="impresion-menu__fichas">${partes.map(ficha).join('')}</div>
      ${registros.length ? `
        <div class="impresion-menu__separador"></div>
        <header class="impresion-menu__cabecera">
          <span class="impresion-menu__titulo">Documentos recientes</span>
          <span class="impresion-menu__atajos">
            <button type="button" class="impresion-menu__atajo" data-seleccionar-recientes="1">El último</button>
            ${recientes > 1 ? `<button type="button" class="impresion-menu__atajo" data-seleccionar-recientes="${recientes}">Últimos ${recientes}</button>` : ''}
          </span>
        </header>
        <div class="impresion-menu__docs">${registros.map(documento).join('')}</div>` : ''}
      <footer class="impresion-menu__pie">
        <p class="impresion-menu__resumen" data-resumen></p>
        <button type="button" class="boton boton--primario impresion-menu__enviar" data-imprimir-ahora>Imprimir</button>
      </footer>`;
    abrirPopover(ancla, caja, { clase: 'popover--menu popover--impresion', alinear: 'derecha' });

    const resumen = caja.querySelector('[data-resumen]');
    const alternar = caja.querySelector('[data-alternar-partes]');
    const marcados = (nombre) => [...caja.querySelectorAll(`input[name="${nombre}"]:checked`)].map((i) => i.value);
    const actualizar = () => {
      const docs = marcados('registros');
      const activas = marcados('partes');
      const fichas = [...caja.querySelectorAll('input[name="partes"]')];
      // Con documentos elegidos, las secciones no aplican: se imprimen solo esos documentos
      const soloDocs = docs.length > 0;
      fichas.forEach((i) => { i.disabled = soloDocs; });
      caja.classList.toggle('impresion-menu--solo-documentos', soloDocs);
      alternar.disabled = soloDocs;
      alternar.textContent = fichas.length && activas.length === fichas.length ? 'Ninguna' : 'Todas';
      resumen.textContent = soloDocs
        ? (docs.length === 1 ? 'Se imprimirá el documento elegido.' : `Se imprimirán ${docs.length} documentos.`)
        : activas.length === fichas.length ? 'Se imprimirá el expediente completo.' : activas.length ? `Se imprimirán ${activas.length} de ${fichas.length} secciones.` : 'Elige al menos una sección o un documento.';
      caja.querySelector('[data-imprimir-ahora]').disabled = !docs.length && !activas.length;
    };
    caja.addEventListener('change', actualizar);

    alternar.addEventListener('click', () => {
      const fichas = [...caja.querySelectorAll('input[name="partes"]')];
      const encender = fichas.some((i) => !i.checked);
      fichas.forEach((i) => { i.checked = encender; });
      actualizar();
    });

    caja.querySelectorAll('[data-seleccionar-recientes]').forEach((boton) => {
      boton.addEventListener('click', () => {
        const limite = Number(boton.dataset.seleccionarRecientes);
        const ids = new Set(registros.slice(0, limite).map((registro) => String(registro.id)));
        caja.querySelectorAll('input[name="registros"]').forEach((entrada) => { entrada.checked = ids.has(entrada.value); });
        actualizar();
      });
    });
    actualizar();

    caja.querySelector('[data-imprimir-ahora]').addEventListener('click', () => {
      const parametros = new URLSearchParams({ id: datos.id, imprimir: '1' });
      const docs = marcados('registros');
      const activas = marcados('partes');
      if (docs.length) docs.forEach((d) => parametros.append('registros[]', d));
      else if (activas.length < partes.length) activas.forEach((p) => parametros.append('partes[]', p));
      window.open(`${document.body.dataset.base ?? ''}/pacientes/imprimir.php?${parametros}`, '_blank', 'noopener');
      if (typeof cerrarPopover === 'function') cerrarPopover();
    });
  };
})();
