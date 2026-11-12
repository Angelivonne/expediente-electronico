/* Registra un estimado de tiempo activo; nunca transmite teclas ni contenido. */
(() => {
  if (!document.body.dataset.usuario || typeof TOKEN_CSRF === 'undefined') return;

  const BASE = document.body.dataset.base || '';
  const INTERVALO = 60_000;
  const UMBRAL_INACTIVIDAD = 5 * 60_000;
  let ultimaActividad = Date.now();
  let enCurso = false;

  const marcarActividad = () => { ultimaActividad = Date.now(); };
  ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'].forEach((evento) => {
    document.addEventListener(evento, marcarActividad, { passive: true });
  });

  async function latir() {
    const activo = Date.now() - ultimaActividad <= UMBRAL_INACTIVIDAD;
    if (enCurso || document.visibilityState !== 'visible' || !navigator.onLine || !activo) return;
    enCurso = true;
    try {
      const respuesta = await fetch(`${BASE}/api/equipo/tiempo.php`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': TOKEN_CSRF },
        body: JSON.stringify({ activo: true }),
      });
      if (!respuesta.ok) throw new Error('No se pudo guardar la actividad del personal.');
      const datos = await respuesta.json();
      if (datos.ok !== true) throw new Error(datos.error || 'No se pudo guardar la actividad del personal.');
    } catch (error) {
      console.warn('[equipo_tiempo] No se pudo registrar el tiempo activo.', error);
    } finally {
      enCurso = false;
    }
  }

  window.setTimeout(latir, 10_000);
  window.setInterval(latir, INTERVALO);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') latir();
  });
  window.addEventListener('online', latir);
})();
