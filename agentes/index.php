<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/ia/asistente.php';
require_once __DIR__ . '/../app/servicios/ia/agentes.php';
if (!puede('asistente')) { mensajeFlash('error','Tu rol no tiene acceso a los agentes.'); redirigir('/pacientes/'); }
$tituloPagina = 'Agentes';
$seccionActiva = 'agentes';
$clavePresencia = 'agentes:pagina';
$claseContenido = 'pagina--agentes';
$mostrarBanner = false;
$ocultarCompartir = true;
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<link rel="stylesheet" href="<?= recurso("css/modulos/agentes.css") ?>">
<div class="agentes-layout" data-agentes-layout>
  <aside class="agentes-panel" aria-label="Equipo">
    <div class="agentes-panel__cabecera">
      <h2>Equipo</h2>
      <button type="button" class="boton" data-agente-nuevo>+ Nuevo agente</button>
    </div>
    <ul class="agentes-lista" data-agentes-lista></ul>
    <p class="agentes-leyenda">Cada agente tiene figura, color, modelo y reglas propias.</p>
  </aside>
  <section class="agentes-main" aria-label="Agente seleccionado">
    <div class="agentes-vacio" data-agentes-vacio>
      <div class="agentes-vacio__figura" aria-hidden="true">
        <span class="agent-figure agent-figure--gallery">
          <span class="agent-figure__shape shape-circle"></span><span class="agent-figure__face"></span>
        </span>
        <span class="agent-figure agent-figure--gallery"><span class="agent-figure__shape shape-triangle"></span><span class="agent-figure__face"></span></span>
        <span class="agent-figure agent-figure--gallery"><span class="agent-figure__shape shape-hexagon"></span><span class="agent-figure__face"></span></span>
      </div>
      <h3>Crea tu primer agente</h3>
      <button type="button" class="boton boton--primario" data-agente-nuevo>Crear agente</button>
    </div>
    <div class="agentes-detalle" data-agentes-detalle hidden>
      <header class="agentes-detalle__cabecera">
        <div class="agentes-detalle__avatar" data-agente-avatar></div>
        <div>
          <h3 data-agente-nombre></h3>
          <p data-agente-desc></p>
          <small data-agente-meta></small>
        </div>
        <span class="agentes-detalle__acciones">
          <button type="button" class="boton boton--sutil" data-agente-iniciar>Iniciar</button>
          <button type="button" class="boton boton--sutil" data-agente-pausar>Pausar</button>
          <button type="button" class="boton boton--sutil" data-agente-eliminar>Eliminar</button>
        </span>
      </header>
      <div class="agentes-conversacion" data-agente-conversacion></div>
      <form class="agentes-form" data-agente-form>
        <textarea rows="2" placeholder="Escribe al agente…" data-agente-texto></textarea>
        <button type="submit" class="boton boton--primario">Enviar</button>
      </form>
    </div>
  </section>
</div>

<dialog id="dlg-agente" class="dlg">
  <form method="dialog" class="dlg__caja">
    <h3>Crear agente</h3>
    <label>Nombre <input name="nombre" required maxlength="80"></label>
    <label>Descripción <textarea name="description" maxlength="400"></textarea></label>
    <div class="agentes-formas" data-forma-select></div>
    <label>Proveedor <select name="servicio"><option value="anthropic">Anthropic</option><option value="openai">OpenAI</option><option value="openrouter">OpenRouter</option><option value="gemini">Gemini</option><option value="groq">Groq</option><option value="ollama">Ollama (local)</option><option value="personalizado">Otro</option></select></label>
    <label>Modelo <input name="modelo" placeholder="claude-opus-5-5 o gpt-4o"></label>
    <label>URL <input name="api_url" placeholder="https://.../v1"></label>
    <label>Clave <input name="api_clave" type="password"></label>
    <label>Esfuerzo <select name="esfuerzo"><option value="low">Rápido</option><option value="medium" selected>Auto</option><option value="high">Profundo</option></select></label>
    <label>Reglas del sistema <textarea name="system_prompt" rows="3"></textarea></label>
    <label>Instrucciones <textarea name="instrucciones" rows="2"></textarea></label>
    <div class="dlg__acciones"><button type="button" class="boton" data-cerrar>Cancelar</button><button type="submit" class="boton boton--primario">Guardar</button></div>
  </form>
</dialog>
<script src="<?= recurso("js/modulos/agentes.js") ?>"></script>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
