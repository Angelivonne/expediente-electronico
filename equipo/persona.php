<?php
/**
 * Perfil del personal: foto sobre banner, actividad reciente, datos, páginas e historial.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/clinica/equipo.php';

exigirPermiso('ver_expediente');
$id = (int) ($_GET['id'] ?? 0);
$persona = personaEquipo($id);
if (!$persona) {
    mensajeFlash('error', 'Esa persona no está en tu clínica.');
    redirigir('/equipo/');
}

$yo        = usuarioActual();
$esYo      = (int) $persona['id'] === (int) $yo['id'];
$puedeEdit = puede('configurar');
$puedeVerDetalle = $esYo || puede('auditoria_equipo');
$rol       = ROLES[$persona['rol']] ?? ['nombre' => $persona['rol'], 'descripcion' => ''];
$actividad = $puedeVerDetalle ? actividadPersona($id, 100) : [];
$actividadDiaria = $puedeVerDetalle ? actividadDiariaPersona($id) : null;
$paginas   = $puedeVerDetalle ? paginasPersona($id) : [];
$conteos   = $puedeVerDetalle ? conteosPersona($id) : null;
$enLinea   = !empty($persona['en_linea']);
$ultimoVisto = $persona['ultimo_visto'] ?? null;
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
$nivelActividad = static fn (int $n): int => $n === 0 || !$actividadDiaria['maximo']
    ? 0
    : max(1, (int) ceil($n / $actividadDiaria['maximo'] * 4));
$totalCambios = $actividadDiaria ? array_sum(array_column($actividadDiaria['dias'], 'cambios')) : 0;
$totalSegundos = $actividadDiaria ? array_sum(array_column($actividadDiaria['dias'], 'segundos')) : 0;
$tiempoActivo = static function (int $segundos): string {
    $horas = intdiv($segundos, 3600);
    $minutos = intdiv($segundos % 3600, 60);
    return $horas ? "{$horas} h {$minutos} min" : "{$minutos} min";
};

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

<?php if ($puedeVerDetalle): ?>
<dl class="hoja-paciente__resumen persona__conteos" aria-label="Resumen del trabajo">
  <div><dt>Expedientes · 30 días</dt><dd><?= $conteos['expedientes_30d'] === null ? '—' : (int) $conteos['expedientes_30d'] ?></dd></div>
  <div><dt>Registros · 30 días</dt><dd><?= $conteos['registros_clinicos_30d'] === null ? '—' : (int) $conteos['registros_clinicos_30d'] ?></dd></div>
  <div><dt>Citas próximas</dt><dd><?= $conteos['citas_proximas'] === null ? '—' : (int) $conteos['citas_proximas'] ?></dd></div>
  <div><dt>Páginas activas</dt><dd><?= (int) $conteos['paginas'] ?></dd></div>
</dl>
<?php endif; ?>

<div class="hoja-paciente__cuerpo persona__cuerpo">
  <div class="persona__banca">
    <?php if ($puedeVerDetalle && $actividadDiaria): ?>
    <section class="ficha__seccion persona__actividad-diaria">
      <div class="ficha__titulo-fila">
        <h2 class="ficha__titulo">Actividad registrada</h2>
        <span class="ficha__fuente">16 semanas · no equivale a asistencia</span>
      </div>
      <p class="persona__actividad-resumen"><?= (int) $totalCambios ?> cambios registrados · <?= e($tiempoActivo((int) $totalSegundos)) ?> activos aprox.</p>
      <div class="actividad" data-actividad>
        <div class="actividad__meses" aria-hidden="true">
          <?php for ($s = 0; $s < $actividadDiaria['semanas']; $s++):
            $domingo = (new DateTimeImmutable($actividadDiaria['inicio']))->modify('+' . ($s * 7 + 6) . ' days');
            $diaUno = $domingo->modify('-' . ((int) $domingo->format('j') - 1) . ' days');
            $rotulo = (int) $domingo->format('j') <= 7 && $diaUno <= new DateTimeImmutable($actividadDiaria['hoy'])
                ? ucfirst($meses[(int) $domingo->format('n') - 1])
                : '';
          ?>
            <span><?= e($rotulo) ?></span>
          <?php endfor; ?>
        </div>
        <div class="actividad__rejilla" role="grid" tabindex="0" aria-label="Cambios y tiempo activo aproximado por día">
          <?php for ($s = 0; $s < $actividadDiaria['semanas']; $s++): ?>
            <div class="actividad__semana" role="row" style="--s: <?= $s ?>">
              <?php for ($d = 0; $d < 7; $d++):
                $fechaActividad = (new DateTimeImmutable($actividadDiaria['inicio']))->modify('+' . ($s * 7 + $d) . ' days');
                $claveActividad = $fechaActividad->format('Y-m-d');
                $esFuturo = $claveActividad > $actividadDiaria['hoy'];
                $diaActividad = $actividadDiaria['dias'][$claveActividad];
                $cambios = (int) $diaActividad['cambios'];
                $segundos = (int) $diaActividad['segundos'];
                $texto = $cambios . ($cambios === 1 ? ' cambio' : ' cambios')
                    . ' · ' . $tiempoActivo($segundos) . ' activo aprox. · ' . fechaCorta($claveActividad);
              ?>
                <span class="actividad__dia nivel-<?= $esFuturo ? 'futuro' : $nivelActividad($cambios) ?>" role="gridcell"
                      <?= $esFuturo ? 'aria-hidden="true"' : 'data-texto="' . e($texto) . '" aria-label="' . e($texto) . '"' ?>></span>
              <?php endfor; ?>
            </div>
          <?php endfor; ?>
        </div>
        <div class="actividad__pie">
          <span><?= e(fechaCorta($actividadDiaria['inicio'])) ?> – <?= e(fechaCorta($actividadDiaria['hoy'])) ?></span>
          <span class="actividad__leyenda" aria-hidden="true">
            menos
            <i class="actividad__dia nivel-0"></i><i class="actividad__dia nivel-1"></i><i class="actividad__dia nivel-2"></i><i class="actividad__dia nivel-3"></i><i class="actividad__dia nivel-4"></i>
            más
          </span>
        </div>
        <div class="actividad__globo" role="status" aria-live="polite" hidden></div>
      </div>
      <p class="persona__actividad-aviso">El tiempo activo es un estimado de la pestaña visible con interacción reciente. No registra teclas ni contenido y no demuestra asistencia.</p>
    </section>
    <?php elseif (!$puedeVerDetalle): ?>
    <section class="ficha__seccion">
      <p class="persona__sin-datos">La actividad detallada de otras personas está restringida por permisos.</p>
    </section>
    <?php endif; ?>

    <!-- Actividad reciente (bitácora, como la de pacientes/ver.php) -->
    <?php if ($puedeVerDetalle): ?>
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
    <?php endif; ?>
    <section class="ficha__seccion persona__feedback" data-equipo-feedback data-persona-id="<?= (int) $persona['id'] ?>" data-puede-moderar="<?= puede('auditoria_equipo') && !$esYo ? '1' : '0' ?>">
      <h2>Retroalimentación</h2>
      <?php if ($esYo): ?>
        <p class="persona__feedback-aviso">Aquí aparecen únicamente las notas que un revisor autorizado decidió compartir contigo.</p>
      <?php elseif (puede('auditoria_equipo')): ?>
        <p class="persona__feedback-aviso">Revisa cada nota antes de compartirla. El perfil solo muestra las notas que un revisor publica de forma explícita.</p>
      <?php else: ?>
        <p class="persona__feedback-aviso">Lo que envíes será privado para ti y el personal con permiso de auditoría. La persona solo verá una nota después de que un revisor la comparta.</p>
      <?php endif; ?>

      <?php if (!$esYo && $persona['activo']): ?>
      <form class="persona__feedback-formulario" data-feedback-form>
        <fieldset>
          <legend>Tipo de comentario</legend>
          <label><input type="radio" name="tipo" value="positivo" required> Reconocimiento</label>
          <label><input type="radio" name="tipo" value="atencion" required> Área de atención</label>
        </fieldset>
        <label class="persona__feedback-etiqueta" for="feedback-texto-<?= (int) $persona['id'] ?>">Comentario</label>
        <textarea id="feedback-texto-<?= (int) $persona['id'] ?>" name="texto" maxlength="1000" rows="3" required placeholder="Describe una conducta concreta y respetuosa. No incluyas datos de pacientes."></textarea>
        <div class="persona__feedback-acciones">
          <button type="submit" class="boton boton--sutil">Enviar para revisión</button>
          <span data-feedback-mensaje role="status" aria-live="polite"></span>
        </div>
      </form>
      <?php elseif (!$esYo): ?>
        <p class="persona__feedback-aviso">No se reciben notas nuevas en perfiles de cuentas inactivas.</p>
      <?php endif; ?>

      <div class="persona__feedback-lista" data-feedback-lista aria-live="polite">
        <p class="persona__sin-datos">Cargando retroalimentación…</p>
      </div>
      <p class="persona__feedback-mensaje" data-feedback-mensaje-general role="status" aria-live="polite"></p>
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
