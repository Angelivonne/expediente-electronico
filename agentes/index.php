<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/ia/asistente.php';
require_once __DIR__ . '/../app/servicios/ia/agentes.php';

redirigir('/pacientes/');
if (!puede('asistente')) {
    mensajeFlash('error', 'Tu rol no tiene acceso a los agentes.');
    redirigir('/pacientes/');
}

$tituloPagina   = 'Agentes IA';
$seccionActiva  = 'agentes';
$clavePresencia = 'agentes:pagina';
$claseContenido = 'pagina--agentes pagina--ancha';
$mostrarBanner  = false;
$ocultarCompartir = true;

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<link rel="stylesheet" href="<?= recurso("css/modulos/agentes.css") ?>">

<div class="agentes-app" data-agentes-layout>
  <!-- Barra lateral: Lista de agentes y avatar animado Bloub -->
  <aside class="agentes-sidebar" aria-label="Lista de agentes">
    <div class="agentes-sidebar__header">
      <div class="agentes-sidebar__titulo-box">
        <h2 class="agentes-sidebar__titulo">Agentes IA</h2>
        <span class="agentes-badge" data-agentes-total>0</span>
      </div>
      <button type="button" class="boton boton--primario boton--sm" data-agente-nuevo title="Crear nuevo agente">
        <?= icono('agregar') ?> <span>Nuevo</span>
      </button>
    </div>

    <!-- Avatar animado del agente activo en el sidebar (Bloub SVG Morphing) -->
    <div class="agentes-sidebar__avatar-card" data-agente-side-card>
      <div class="agentes-bloub-container" data-bloub-sidebar></div>
      <div class="agentes-sidebar__avatar-info">
        <h3 class="agentes-sidebar__avatar-nombre" data-side-nombre>Agente IA</h3>
        <p class="agentes-sidebar__avatar-estado" data-side-estado>En espera</p>
      </div>
      <!-- Selector de animación/estado Bloub -->
      <div class="agentes-bloub-controles">
        <button type="button" class="bloub-btn-pill activo" data-bloub-state="idle" title="Reposo">Reposo</button>
        <button type="button" class="bloub-btn-pill" data-bloub-state="orbit" title="Orbitando">Órbita</button>
        <button type="button" class="bloub-btn-pill" data-bloub-state="thinking" title="Pensando">Pensando</button>
        <button type="button" class="bloub-btn-pill" data-bloub-state="alert" title="Alerta">Alerta</button>
      </div>
    </div>

    <!-- Lista de agentes creados -->
    <div class="agentes-sidebar__scroll">
      <div class="agentes-sidebar__subtitulo">Tus Asistentes</div>
      <ul class="agentes-lista" data-agentes-lista></ul>
    </div>
  </aside>

  <!-- Área principal: Chat y espacio de trabajo del agente -->
  <main class="agentes-main" aria-label="Chat con el agente">
    <!-- Estado vacío inicial -->
    <div class="agentes-vacio" data-agentes-vacio>
      <div class="agentes-vacio__bloub" data-bloub-empty></div>
      <h2 class="agentes-vacio__titulo">Tu Asistente Clínico Inteligente</h2>
      <p class="agentes-vacio__desc">
        Configura agentes autónomos con personalidades, modelos y herramientas clínicas personalizadas.
      </p>
      <button type="button" class="boton boton--primario" data-agente-nuevo>
        <?= icono('agregar') ?> Crear tu primer agente
      </button>
    </div>

    <!-- Detalle de conversación activa -->
    <div class="agentes-chat" data-agentes-detalle hidden>
      <header class="agentes-chat__header">
        <div class="agentes-chat__avatar-mini" data-bloub-chat-header></div>
        <div class="agentes-chat__info">
          <div class="agentes-chat__titulo-row">
            <h2 class="agentes-chat__nombre" data-agente-nombre>Nombre</h2>
            <span class="agentes-chip" data-agente-estado>creado</span>
          </div>
          <p class="agentes-chat__desc" data-agente-desc></p>
          <small class="agentes-chat__meta" data-agente-meta></small>
        </div>
        <div class="agentes-chat__acciones">
          <button type="button" class="boton boton--sutil" data-agente-iniciar title="Activar agente"><?= icono('reproducir') ?> Iniciar</button>
          <button type="button" class="boton boton--sutil" data-agente-pausar title="Pausar agente"><?= icono('pausa') ?> Pausar</button>
          <button type="button" class="boton boton--peligro boton--sutil" data-agente-eliminar title="Eliminar agente"><?= icono('eliminar') ?></button>
        </div>
      </header>

      <!-- Mensajes de la conversación -->
      <div class="agentes-mensajes" data-agente-conversacion>
        <!-- Inyección dinámica de burbujas -->
      </div>

      <!-- Formulario de envío del chat -->
      <form class="agentes-input-bar" data-agente-form>
        <textarea rows="1" class="agentes-textarea" placeholder="Escribe tu consulta o instrucción clínica..." data-agente-texto required></textarea>
        <button type="submit" class="boton boton--primario agentes-btn-enviar" title="Enviar mensaje">
          <?= icono('enviar') ?>
        </button>
      </form>
    </div>
  </main>
</div>

<!-- Modal para Crear / Editar Agente -->
<dialog id="dlg-agente" class="dlg dlg--agente">
  <form class="dlg__caja dlg__caja--mediana">
    <div class="dlg__cabecera">
      <h3 class="dlg__titulo">Configurar Agente Clínico</h3>
      <button type="button" class="boton-icono" data-cerrar aria-label="Cerrar"><?= icono('cerrar') ?></button>
    </div>

    <div class="dlg__cuerpo">
      <div class="form-grupo">
        <label for="ag-nombre" class="form-label">Nombre del agente <span class="req">*</span></label>
        <input id="ag-nombre" name="nombre" class="campo" required maxlength="80" placeholder="Ej. Dr. Diagnóstico, Asistente CIE-10">
      </div>

      <div class="form-grupo">
        <label for="ag-desc" class="form-label">Descripción</label>
        <textarea id="ag-desc" name="description" class="campo" rows="2" maxlength="400" placeholder="¿Cuál es su rol o propósito en la clínica?"></textarea>
      </div>

      <!-- Selector de color Bloub Avatar -->
      <div class="form-grupo">
        <label class="form-label">Color del Avatar</label>
        <div class="agentes-colores" data-color-select></div>
      </div>

      <!-- Selector visual de forma Bloub Avatar -->
      <div class="form-grupo">
        <label class="form-label">Forma del Avatar</label>
        <div class="agentes-formas" data-forma-select></div>
      </div>

      <div class="grid-dos">
        <div class="form-grupo">
          <label for="ag-prov" class="form-label">Proveedor</label>
          <select id="ag-prov" name="servicio" class="campo">
            <option value="gemini">Google Gemini</option>
            <option value="anthropic">Anthropic (Claude)</option>
            <option value="openai">OpenAI (ChatGPT)</option>
            <option value="openrouter">OpenRouter</option>
            <option value="groq">Groq (Ultra-rápido)</option>
            <option value="ollama">Ollama (Local en tu equipo)</option>
          </select>
        </div>

        <div class="form-grupo">
          <label for="ag-modelo" class="form-label">Modelo</label>
          <input id="ag-modelo" name="modelo" class="campo" placeholder="gemini-2.5-flash, claude-3-7-sonnet...">
        </div>
      </div>

      <div class="form-grupo">
        <label for="ag-prompt" class="form-label">Reglas del sistema (System Prompt)</label>
        <textarea id="ag-prompt" name="system_prompt" class="campo" rows="3" placeholder="Instrucciones clínicas estrictas (ej. NOM-004, jerga médica mexicana, validación diagnóstica)..."></textarea>
      </div>
    </div>

    <div class="dlg__acciones">
      <button type="button" class="boton boton--secundario" data-cerrar>Cancelar</button>
      <button type="submit" class="boton boton--primario">Guardar Agente</button>
    </div>
  </form>
</dialog>

<script nonce="<?= nonceCsp() ?>" src="<?= recurso("js/modulos/bloub.js") ?>"></script>
<script nonce="<?= nonceCsp() ?>" src="<?= recurso("js/modulos/agentes.js") ?>"></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>


