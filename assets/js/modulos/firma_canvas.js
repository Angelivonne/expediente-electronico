/* ==========================================================================
   Expedientes Clínicos — firma dibujada (canvas)
   Se activa en cualquier [data-firma-lienzo]: se dibuja con el dedo, el mouse
   o el lápiz; "Guardar" manda el PNG (dataURL) a api/cuenta/firma.php y
   "Borrar" quita la firma guardada. El trazo sale con presión y velocidad
   suavizadas para que parezca tinta.
   ========================================================================== */
(() => {
  const lienzo = document.querySelector('[data-firma-lienzo]');
  if (!lienzo) return;

  const BASE = document.body.dataset.base || '';
  const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';
  const contexto = lienzo.getContext('2d');
  const fondo = lienzo.parentElement.querySelector('[data-firma-fondo]');
  const acciones = lienzo.closest('[data-firma-seccion]')?.querySelector('[data-firma-acciones]');
  const botonGuardar = acciones?.querySelector('[data-firma-guardar]');
  const botonLimpiar = acciones?.querySelector('[data-firma-limpiar]');
  const botonBorrar = acciones?.querySelector('[data-firma-borrar]');
  const aviso = acciones?.querySelector('[data-firma-aviso]');

  let dibujando = false;
  let trazo = false;                       // ¿hay algo dibujado?
  let ultimo = null;                       // último punto {x, y, p}
  let eventos = null;                      // PointerEvent con presión

  const TINTA = '#1a2b4a';

  /* ---------- Tamaño nítido (retina) y trazo base ---------- */
  const ajustar = () => {
    const rect = lienzo.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const escala = Math.min(window.devicePixelRatio || 1, 2);
    const contenido = lienzo.toDataURL();                 // conserva el dibujo al reajustar
    lienzo.width = Math.round(rect.width * escala);
    lienzo.height = Math.round(rect.height * escala);
    contexto.setTransform(escala, 0, 0, escala, 0, 0);
    contexto.lineCap = 'round';
    contexto.lineJoin = 'round';
    contexto.strokeStyle = TINTA;
    const img = new Image();
    img.onload = () => {
      contexto.drawImage(img, 0, 0, rect.width, rect.height);
      URL.revokeObjectURL(img.src);
    };
    img.src = contenido;
  };

  const puntoDe = (ev) => {
    const rect = lienzo.getBoundingClientRect();
    return {
      x: ev.clientX - rect.left,
      y: ev.clientY - rect.top,
      p: ev.pressure && ev.pointerType !== 'mouse' ? ev.pressure : 0.5,
    };
  };

  const trazar = (a, b) => {
    // Grosor según presión y velocidad: arranca delgado, se asienta como pluma
    const velocidad = Math.hypot(b.x - a.x, b.y - a.y);
    const grosor = Math.max(1.1, Math.min(3.4, 1.4 + b.p * 2.6 - velocidad * 0.02));
    contexto.lineWidth = grosor;
    contexto.beginPath();
    // Curva suave con el punto medio (quadraticCurveTo evita el efecto "escalerita")
    contexto.moveTo(a.x, a.y);
    contexto.quadraticCurveTo(a.x, a.y, (a.x + b.x) / 2, (a.y + b.y) / 2);
    contexto.stroke();
    contexto.beginPath();
    contexto.moveTo((a.x + b.x) / 2, (a.y + b.y) / 2);
    contexto.lineTo(b.x, b.y);
    contexto.stroke();
  };

  const empezar = (ev) => {
    if (ev.button !== undefined && ev.button !== 0) return;
    ev.preventDefault();
    lienzo.setPointerCapture?.(ev.pointerId);
    dibujando = true;
    trazo = true;
    ultimo = puntoDe(ev);
    // Punto inicial visible aunque sea un toque corto
    contexto.lineWidth = 2.2;
    contexto.beginPath();
    contexto.arc(ultimo.x, ultimo.y, 1.1, 0, Math.PI * 2);
    contexto.fillStyle = TINTA;
    contexto.fill();
    ocultarFondo();
  };

  const mover = (ev) => {
    if (!dibujando) return;
    ev.preventDefault();
    const punto = puntoDe(ev);
    trazar(ultimo, punto);
    ultimo = punto;
  };

  const terminar = () => {
    dibujando = false;
    ultimo = null;
    refrescar();
  };

  const ocultarFondo = () => {
    if (fondo) fondo.classList.add('firma__rastro--oculto');
  };

  const mostrarFondo = () => {
    if (fondo) fondo.classList.remove('firma__rastro--oculto');
  };

  /* ---------- Guardar / limpiar / borrar ---------- */
  const avisar = (texto, error = false) => {
    if (!aviso) return;
    aviso.textContent = texto;
    aviso.classList.toggle('firma__aviso--error', error);
    aviso.hidden = !texto;
  };

  const refrescar = () => {
    if (botonGuardar) botonGuardar.disabled = !trazo;
    if (botonLimpiar) botonLimpiar.disabled = !trazo;
  };

  const limpiar = () => {
    contexto.clearRect(0, 0, lienzo.width, lienzo.height);
    trazo = false;
    refrescar();
    mostrarFondo();
    avisar('');
  };

  const guardar = async () => {
    if (!trazo) return;
    botonGuardar?.setAttribute('aria-busy', 'true');
    avisar('Guardando…');
    try {
      // Recorte con margen: la firma se guarda ajustada, no el lienzo entero
      const recorte = recortar();
      const r = await fetch(`${BASE}/api/cuenta/firma.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ accion: 'guardar', imagen: recorte }),
      });
      const datos = await r.json();
      if (!r.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo guardar la firma.');
      avisar('Firma guardada.');
      if (botonBorrar) botonBorrar.hidden = false;
      // Refresca la vista previa; si la página no la traía (no había firma), la crea
      const vista = document.querySelector('[data-firma-guardada]');
      if (vista) {
        vista.src = `${datos.url}${datos.url.includes('?') ? '&' : '?'}t=${Date.now()}`;
        vista.hidden = false;
      } else {
        const seccion = lienzo.closest('[data-firma-seccion]');
        const previa = document.createElement('img');
        previa.className = 'persona__firma-img';
        previa.alt = 'Tu firma';
        previa.src = datos.url;
        previa.setAttribute('data-firma-guardada', '');
        seccion?.querySelector('h2')?.after(previa);
      }
    } catch (e) {
      avisar(e.message || 'No se pudo guardar la firma.', true);
    } finally {
      botonGuardar?.removeAttribute('aria-busy');
    }
  };

  /** Devuelve el dataURL PNG solo de la zona con tinta (con margen). */
  const recortar = () => {
    const escala = Math.min(window.devicePixelRatio || 1, 2);
    const ancho = lienzo.width / escala;
    const alto = lienzo.height / escala;
    const paso = 4;
    let x0 = ancho, y0 = alto, x1 = 0, y1 = 0;
    const datos = contexto.getImageData(0, 0, lienzo.width, lienzo.height).data;
    for (let y = 0; y < alto; y += paso / escala) {
      for (let x = 0; x < ancho; x += paso / escala) {
        const i = ((y * escala | 0) * lienzo.width + (x * escala | 0)) * 4 + 3;
        if (datos[i] > 10) {
          if (x < x0) x0 = x;
          if (y < y0) y0 = y;
          if (x > x1) x1 = x;
          if (y > y1) y1 = y;
        }
      }
    }
    if (x1 < x0) return lienzo.toDataURL('image/png');   // sin tinta detectada: lienzo completo
    const margen = 14;
    x0 = Math.max(0, x0 - margen);
    y0 = Math.max(0, y0 - margen);
    x1 = Math.min(ancho, x1 + margen);
    y1 = Math.min(alto, y1 + margen);
    const cuadro = document.createElement('canvas');
    cuadro.width = Math.ceil((x1 - x0) * escala);
    cuadro.height = Math.ceil((y1 - y0) * escala);
    cuadro.getContext('2d').drawImage(lienzo, x0 * escala, y0 * escala, cuadro.width, cuadro.height, 0, 0, cuadro.width, cuadro.height);
    return cuadro.toDataURL('image/png');
  };

  const borrar = async () => {
    botonBorrar?.setAttribute('aria-busy', 'true');
    try {
      const r = await fetch(`${BASE}/api/cuenta/firma.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ accion: 'borrar' }),
      });
      const datos = await r.json();
      if (!r.ok || datos.ok === false) throw new Error(datos.error || 'No se pudo borrar la firma.');
      limpiar();
      const vista = document.querySelector('[data-firma-guardada]');
      if (vista) vista.hidden = true;
      if (botonBorrar) botonBorrar.hidden = true;
      avisar('Firma borrada.');
    } catch (e) {
      avisar(e.message || 'No se pudo borrar la firma.', true);
    } finally {
      botonBorrar?.removeAttribute('aria-busy');
    }
  };

  /* ---------- Eventos ---------- */
  lienzo.addEventListener('pointerdown', empezar);
  lienzo.addEventListener('pointermove', mover);
  lienzo.addEventListener('pointerup', terminar);
  lienzo.addEventListener('pointercancel', terminar);
  lienzo.addEventListener('pointerleave', terminar);
  lienzo.addEventListener('contextmenu', (ev) => ev.preventDefault());
  // Evita el scroll de la página mientras se firma en táctil
  lienzo.style.touchAction = 'none';
  botonGuardar?.addEventListener('click', guardar);
  botonLimpiar?.addEventListener('click', limpiar);
  botonBorrar?.addEventListener('click', borrar);
  window.addEventListener('resize', ajustar);
  ajustar();
  refrescar();
})();
