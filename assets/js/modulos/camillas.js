document.addEventListener('click', (evento) => {
  if (!(evento.target instanceof Element)) return;

  const abrir = evento.target.closest('[data-camillas-modal-abrir]');
  if (abrir) {
    abrir.closest('.camillas__menu')?.removeAttribute('open');
    document.getElementById(abrir.dataset.camillasModalAbrir)?.showModal();
    return;
  }

  const cerrar = evento.target.closest('[data-camillas-modal-cerrar]');
  if (cerrar) {
    document.getElementById(cerrar.dataset.camillasModalCerrar)?.close();
    return;
  }

  const asignar = evento.target.closest('[data-camillas-asignar]');
  if (asignar) {
    asignar.closest('.camillas__menu')?.removeAttribute('open');
    const selector = document.querySelector('#modal-asignar-camilla [name="camilla_id"]');
    const dialogo = document.getElementById('modal-asignar-camilla');
    if (selector && dialogo) {
      selector.value = asignar.dataset.camillasAsignar;
      dialogo.showModal();
    }
    return;
  }

  const trasladar = evento.target.closest('[data-camillas-trasladar]');
  if (trasladar) {
    trasladar.closest('.camillas__menu')?.removeAttribute('open');
    const dialogo = document.getElementById('modal-trasladar');
    const origen = document.getElementById('traslado_origen_id');
    const codigo = document.getElementById('traslado_origen_codigo');
    const paciente = document.getElementById('traslado_paciente_nombre');
    if (dialogo && origen && codigo && paciente) {
      origen.value = trasladar.dataset.camillaId || '';
      codigo.textContent = trasladar.dataset.camillaCodigo || '';
      paciente.textContent = trasladar.dataset.pacienteNombre || '';
      dialogo.showModal();
    }
  }
});

const menusCamillas = [...document.querySelectorAll('.camillas__menu')];
const cerrarMenusCamillas = () => menusCamillas.forEach((menu) => { menu.open = false; });

document.addEventListener('click', (evento) => {
  if (!(evento.target instanceof Element)) return;
  menusCamillas.forEach((menu) => {
    if (!menu.contains(evento.target) || evento.target.closest('[role="menuitem"]')) {
      menu.open = false;
    }
  });
});

document.addEventListener('scroll', cerrarMenusCamillas, true);
window.addEventListener('resize', cerrarMenusCamillas);
document.addEventListener('keydown', (evento) => {
  if (evento.key !== 'Escape') return;
  const abierto = menusCamillas.find((menu) => menu.open);
  if (abierto) {
    abierto.open = false;
    abierto.querySelector('summary')?.focus();
  }
});

document.addEventListener('submit', (evento) => {
  if (!(evento.target instanceof HTMLFormElement)) return;

  if (evento.target.matches('[data-confirmar-alta]')
    && !window.confirm('¿Confirmas el alta y el paso de la cama a desinfección?')) {
    evento.preventDefault();
    return;
  }

  const estado = evento.target.dataset.confirmarEstado;
  const confirmaciones = {
    mantenimiento: '¿Retirar esta cama del servicio para enviarla a mantenimiento?',
    reservada: '¿Cancelar la reserva y dejar esta cama disponible?',
  };
  if (estado && confirmaciones[estado] && !window.confirm(confirmaciones[estado])) {
    evento.preventDefault();
  }
});
