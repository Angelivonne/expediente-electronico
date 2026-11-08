(() => {
  const buscar = document.querySelector('[data-notas-buscar]');
  if (!buscar) return;
  const listas = [...document.querySelectorAll('[data-notas-lista]')];
  const vacio = document.querySelector('[data-notas-vacio]');
  const normalizar = (texto) => String(texto ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLocaleLowerCase('es-MX').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  const coincide = (texto, consulta) => {
    const busqueda = normalizar(consulta);
    if (!busqueda) return true;
    const contenido = normalizar(texto);
    return busqueda.split(' ').every((termino) => contenido.includes(termino));
  };
  const filtrarNodo = (nodo, consulta) => {
    const propio = !consulta || coincide(nodo.querySelector(':scope > a')?.textContent || '', consulta);
    const hijos = [...nodo.querySelectorAll(':scope > ul > .notas-inicio__nodo')];
    const visible = propio || hijos.some((hijo) => filtrarNodo(hijo, consulta));
    nodo.hidden = !visible;
    return visible;
  };
  const filtrar = () => {
    const consulta = normalizar(buscar.value);
    let visibles = 0;
    listas.forEach((lista) => {
      const cuenta = [...lista.querySelectorAll(':scope > .notas-inicio__nodo')].filter((nodo) => filtrarNodo(nodo, consulta)).length;
      visibles += cuenta;
      const seccion = lista.closest('.notas-inicio__seccion');
      if (seccion) seccion.hidden = Boolean(consulta) && cuenta === 0;
    });
    if (vacio) vacio.hidden = !consulta || visibles > 0;
  };
  buscar.addEventListener('input', filtrar);
  buscar.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { buscar.value = ''; filtrar(); } });
})();
