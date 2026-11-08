<?php
/**
 * Perfil del personal: foto sobre banner, actividad reciente, datos, páginas e historial.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/equipo.php';

if (usuarioActual()['rol'] === 'paciente') {
    mensajeFlash('error', 'Los perfiles del personal no están disponibles para el portal del paciente.');
    redirigir('/');
}
$id = (int) ($_GET['id'] ?? 0);
$persona = personaEquipo($id);
if (!$persona) {
    mensajeFlash('error', 'Esa persona no está en tu clínica.');
    redirigir('/equipo/');
}

$yo        = usuarioActual();
$esYo      = (int) $persona['id'] === (int) $yo['id'];
$puedeEdit = puede('configurar');
$rol       = ROLES[$persona['rol']] ?? ['nombre' => $persona['rol'], 'descripcion' => ''];
$actividad = actividadPersona($id, 14);
$paginas   = paginasPersona($id);
$conteos   = conteosPersona($id);
$enLinea   = !empty($persona['en_linea']);
$ultimoVisto = $persona['ultimo_visto'] ?? null;

$tituloPagina  = $persona['nombre'];
$seccionActiva = 'equipo';
$detalleTitulo = $persona['nombre'] . ($esYo ? ' (Tú)' : '');
$migas = [['texto' => 'Inicio', 'url' => URL_BASE . '/pacientes/'], ['texto' => $persona['nombre']]];
$claseContenido = 'pagina--expediente persona';
$mostrarBanner = true;


require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<section class="hoja-paciente persona">
  <div class="persona__arriba">
    <span class="equipo__foto equipo__foto--perfil">
      <?= avatar((int) $persona['id'], $persona['nombre'], $persona['foto']) ?>
      <span class="equipo__presencia<?= $enLinea ? ' equipo__presencia--en-linea' : '' ?>" data-equipo-presencia data-id="<?= (int) $persona['id'] ?>" aria-label="<?= $enLinea ? 'En línea' : 'Desconectada' ?>" title="<?= $enLinea ? 'En línea' : 'Desconectada' ?>"><i aria-hidden="true"></i></span>
    </span>
    <div>
      <h1 class="hoja-paciente__nombre"><?= e($persona['nombre']) ?><?= $esYo ? ' <small>(Tú)</small>' : '' ?></h1>
      <p class="hoja-paciente__linea">
        <span class="hoja-paciente__rol"><?= e($rol['nombre']) ?></span>
        <?php if ($persona['cedula_profesional']): ?><span>Céd. <?= e($persona['cedula_profesional']) ?></span><?php endif; ?>
        <span class="equipo__ultima" data-equipo-visto data-id="<?= (int) $persona['id'] ?>"><?= $enLinea ? 'En línea' : ($ultimoVisto ? 'Últ. vez ' . e(hace($ultimoVisto)) : 'Sin actividad') ?></span>
      </p>
    </div>
  </div>
</section>

<dl class="hoja-paciente__resumen persona__conteos" aria-label="Resumen del trabajo">
  <div><dt>Expedientes · 30 días</dt><dd><?= $conteos['expedientes_30d'] === null ? '—' : (int) $conteos['expedientes_30d'] ?></dd></div>
  <div><dt>Registros · 30 días</dt><dd><?= $conteos['registros_clinicos_30d'] === null ? '—' : (int) $conteos['registros_clinicos_30d'] ?></dd></div>
  <div><dt>Citas próximas</dt><dd><?= $conteos['citas_proximas'] === null ? '—' : (int) $conteos['citas_proximas'] ?></dd></div>
  <div><dt>Páginas activas</dt><dd><?= (int) $conteos['paginas'] ?></dd></div>
</dl>

<div class="hoja-paciente__cuerpo persona__cuerpo">
  <div class="persona__banca">
    <!-- Actividad reciente (bitácora, como la de pacientes/ver.php) -->
    <section class="ficha__seccion persona__actividad">
      <h2>Actividad reciente</h2>
      <?php if ($actividad): ?>
        <ul class="persona__actividad-lista">
          <?php foreach ($actividad as $a): ?>
            <li>
              <?php if ($a['enlace']): ?><a href="<?= e($a['enlace']) ?>"><?= e($a['texto']) ?></a>
              <?php else: ?><span><?= e($a['texto']) ?></span><?php endif; ?>
              <small>· <?= e(fechaCorta($a['cuando'])) ?></small>
              <?php if (!empty($a['detalle'])): ?><p class="persona__actividad-detalle"><?= e($a['detalle']) ?></p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="persona__sin-datos">Sin movimientos registrados todavía.</p>
      <?php endif; ?>
    </section>

    <!-- Páginas de esta persona (accesibles por el equipo) -->
    <section class="ficha__seccion">
      <h2>Páginas del espacio</h2>
      <?php if ($paginas): ?>
        <ul class="paginas-paciente persona__paginas">
          <?php foreach ($paginas as $pg):
            $tipoPg = TIPOS_PAGINA[$pg['tipo']] ?? TIPOS_PAGINA['nota'];
            $lay = fn (?string $c) => $c ? preg_replace('/(:\d{2})$/', '', fechaCorta($c)) : ''; ?>
            <li>
              <a href="<?= URL_BASE ?>/paginas/ver.php?id=<?= (int) $pg['id'] ?>">
                <?= iconoRelleno($tipoPg['icono']) ?>
                <span><?= e($pg['titulo'] !== '' ? $pg['titulo'] : $tipoPg['nueva']) ?></span>
                <?php if ($pg['paciente_id']): ?><small>Expediente <?= e($pg['folio'] ?? '') ?></small><?php endif; ?>
                <small><?= e($lay($pg['actualizado_en'])) ?></small>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="persona__sin-datos">No tiene páginas en el espacio de trabajo.</p>
      <?php endif; ?>
    </section>
  </div>

  <!-- About (lado derecho, como en la hoja de paciente) -->
  <aside class="hoja-paciente__lado persona__lado">
    <section class="ficha__seccion">
      <h2>Perfil profesional</h2>
      <dl class="persona__datos">
        <dt>Rol</dt><dd><?= e($rol['nombre']) ?></dd>
        <dt>Correo</dt><dd><?= e($persona['correo']) ?></dd>
        <dt>Cédula profesional</dt><dd><?= $persona['cedula_profesional'] ? e($persona['cedula_profesional']) : '—' ?></dd>
        <dt>Cuenta</dt><dd><?= $persona['activo'] ? 'Activa' : ($persona['ultimo_acceso'] === null ? 'Pendiente' : 'Desactivada') ?></dd>
        <dt>Estado ahora</dt><dd><?= $enLinea ? 'En línea' : 'Desconectada' ?></dd>
        <dt>Última actividad</dt><dd><?= $ultimoVisto ? e(fechaCorta($ultimoVisto)) : '—' ?></dd>
      </dl>
      <?php if ($esYo): ?>
        <a class="boton boton--sutil persona__cta" href="<?= URL_BASE ?>/cuenta/" title="Tu cuenta: contraseña, notificaciones y seguridad"><?= icono('engrane') ?> Mi cuenta</a>
      <?php elseif ($puedeEdit): ?>
        <a class="boton boton--sutil persona__cta" href="<?= URL_BASE ?>/configuracion/" title="Cambiar rol, permisos y estado en Personas y permisos"><?= icono('engrane') ?> Editar cuenta y permisos</a>
      <?php endif; ?>
    </section>

    <?php if ($esYo || $persona['firma']): ?>
    <!-- Firma dibujada: se firma con el dedo/mouse/lápiz y se estampa en los documentos impresos -->
    <section class="ficha__seccion persona__firma" data-firma-seccion>
      <h2>Firma</h2>
      <?php if ($persona['firma']): ?>
        <img class="persona__firma-img" src="<?= e(URL_BASE . '/' . $persona['firma']) ?>" alt="Firma de <?= e($persona['nombre']) ?>" data-firma-guardada>
      <?php endif; ?>
      <?php if ($esYo): ?>
        <div class="persona__firma-lienzo">
          <canvas class="firma__lienzo" data-firma-lienzo aria-label="Dibuja tu firma con el dedo, el mouse o el lápiz"></canvas>
          <span class="firma__rastro" data-firma-fondo aria-hidden="true">Firma aquí</span>
        </div>
        <div class="firma__acciones" data-firma-acciones>
          <button type="button" class="boton boton--sutil" data-firma-guardar>Guardar firma</button>
          <button type="button" class="boton boton--sutil" data-firma-limpiar>Limpiar</button>
          <button type="button" class="boton boton--sutil firma__borrar" data-firma-borrar<?= $persona['firma'] ? '' : ' hidden' ?>>Borrar</button>
          <p class="firma__aviso" data-firma-aviso hidden></p>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

  </aside>
</div>
<?php
require __DIR__ . '/../app/vistas/plantilla/pie.php';
