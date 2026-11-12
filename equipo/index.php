<?php
/**
 * Personas: el personal de la clínica y su perfil. Cada tarjeta de la lista lleva al
 * perfil completo (persona.php), igual que en los pacientes.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/equipo.php';

exigirPermiso('ver_expediente');

$tituloPagina = 'Personas';
$seccionActiva = 'equipo';
$migas = [['texto' => 'Inicio', 'url' => URL_BASE . '/pacientes/'], ['texto' => 'Personas']];
$claseContenido = 'pagina--ancha equipo-inicio';
$mostrarBanner = false;
$paginas = personalEquipo();
$yo = (int) usuarioActual()['id'];
$deRol = fn (string $rol): array => ROLES[$rol] ?? ['nombre' => $rol, 'descripcion' => ''];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<header class="pagina__cabecera equipo__cabecera">
  <h1 class="pagina__titulo">Personas <span class="equipo__total"><?= count($paginas) ?></span></h1>
  <?php if (puede('configurar')): ?>
    <a class="boton boton--primario equipo__invitar" href="<?= URL_BASE ?>/configuracion/" title="Invitar personas"><?= icono('agregar') ?> Invitar</a>
  <?php endif; ?>
</header>

<section class="equipo__actividad" data-equipo-actividad aria-label="Actividad del equipo">
  <div class="equipo__actividad-pestanas" role="tablist" aria-label="Actividad de personas">
    <button type="button" role="tab" aria-selected="true" data-equipo-vista="notificaciones">Notificaciones</button>
    <button type="button" role="tab" aria-selected="false" data-equipo-vista="estadisticas">Estadísticas</button>
  </div>
  <div class="equipo__actividad-filtros" data-equipo-filtros>
    <label><span class="sr-only">Buscar persona</span><input type="search" data-equipo-actividad-buscar placeholder="Buscar persona" autocomplete="off"></label>
    <label><span class="sr-only">Periodo de actividad</span>
      <select data-equipo-actividad-periodo>
        <option value="24h">24 horas</option>
        <option value="7d">7 días</option>
        <option value="30d" selected>30 días</option>
        <option value="90d">90 días</option>
        <option value="todo">Todo</option>
      </select>
    </label>
  </div>
  <div class="equipo__actividad-contenido" data-equipo-actividad-contenido role="tabpanel" aria-live="polite">
    <p class="equipo__actividad-cargando">Cargando actividad…</p>
  </div>
</section>

<section class="equipo__directorio" aria-labelledby="equipo-directorio-titulo">
  <h2 id="equipo-directorio-titulo">Directorio del equipo</h2>
<ul class="equipo__lista" data-equipo-lista>
  <?php if (!$paginas): ?>
    <li class="equipo__vacio">Sin personas.</li>
  <?php endif; ?>
  <?php foreach ($paginas as $u): ?>
    <?php $rol = $deRol($u['rol']); $enLinea = !empty($u['en_linea']); $visto = $u['ultimo_visto'] ?? null; ?>
    <li data-equipo-persona data-id="<?= (int) $u['id'] ?>">
      <a class="equipo__persona<?= (int) $u['id'] === $yo ? ' equipo__persona--yo' : '' ?>" href="<?= URL_BASE ?>/equipo/persona.php?id=<?= (int) $u['id'] ?>">
        <span class="equipo__foto">
          <?= avatar((int) $u['id'], $u['nombre'], $u['foto']) ?>
          <span class="equipo__presencia<?= $enLinea ? ' equipo__presencia--en-linea' : '' ?>" data-equipo-presencia data-id="<?= (int) $u['id'] ?>" aria-label="<?= $enLinea ? 'En línea' : 'Desconectada' ?>" title="<?= $enLinea ? 'En línea' : 'Desconectada' ?>"><i aria-hidden="true"></i></span>
        </span>
        <span class="equipo__identidad">
          <b><?= e($u['nombre']) ?><?= (int) $u['id'] === $yo ? ' · Tú' : '' ?></b>
          <small><?= e($rol['nombre']) ?></small>
        </span>
        <span class="equipo__meta">
          <small class="equipo__ultima" data-equipo-visto data-id="<?= (int) $u['id'] ?>"><?= $enLinea ? 'En línea' : ($visto ? 'Últ. vez ' . e(hace($visto)) : 'Sin actividad') ?></small>
          <?php if (!$u['activo']): ?><small class="equipo__cuenta"><?= $u['ultimo_acceso'] === null ? 'Pendiente' : 'Desactivada' ?></small><?php endif; ?>
        </span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
</section>

<?php
require __DIR__ . '/../app/vistas/plantilla/pie.php';
