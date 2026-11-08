/* Estado clínico declarado por personal para cada diagnóstico del catálogo. */
(() => {
  document.querySelectorAll('[data-diagnostico-estado]').forEach((selector) => {
    selector.addEventListener('change', async () => {
      const valorAnterior = selector.dataset.anterior ?? '';
      const aviso = selector.parentElement.querySelector('[data-diagnostico-estado-aviso]');
      selector.disabled = true;
      try {
        const respuesta = await fetch(selector.dataset.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf"]')?.content || '' },
          body: JSON.stringify({ paciente: selector.dataset.paciente, codigo: selector.dataset.codigo, estado: selector.value }),
        });
        const datos = await respuesta.json();
        if (!respuesta.ok || !datos.ok) throw new Error(datos.error || 'No se pudo guardar el estado.');
        selector.dataset.anterior = selector.value;
        if (aviso) aviso.textContent = selector.value ? `Actualizado ${datos.fecha} por ${datos.profesional}.` : 'Estado clínico sin especificar.';
      } catch (error) {
        selector.value = valorAnterior;
        if (aviso) aviso.textContent = error.message || 'No se pudo guardar el estado.';
      } finally { selector.disabled = false; }
    });
  });
})();
