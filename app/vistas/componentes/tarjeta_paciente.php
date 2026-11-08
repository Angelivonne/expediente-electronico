<?php
/**
 * Tarjeta "Perfil del paciente" con su menú de acciones.
 * Se usa como menú flotante (clic izquierdo en la tabla) y en pacientes/ver.php.
 */
require_once __DIR__ . '/../../datos/registros_clinicos.php';

/** Obtiene un paciente activo por id, o null si no existe. */
function buscarPaciente(int $id): ?array
{
    $consulta = db()->prepare(
        'SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, creado_en, foto
           FROM pacientes
          WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL'
    );
    $consulta->execute([$id, institucionId()]);
    return $consulta->fetch() ?: null;
}

/** URL de la portada, o null si el archivo ya no está en la carpeta privada. */
function fotoPacienteUrl(array $paciente): ?string
{
    $archivo = basename((string) ($paciente['foto'] ?? ''));
    if ($archivo === '' || !is_file(__DIR__ . '/../../../privado/fotos_pacientes/' . $archivo)) {
        return null;
    }
    if (!empty($paciente['foto_url'])) {
        return $paciente['foto_url'];
    }
    return URL_BASE . '/api/pacientes/foto.php?id=' . (int) $paciente['id'] . '&v=' . substr(md5($archivo), 0, 8);
}

function tarjetaPaciente(array $paciente, string $claseExtra = ''): string
{
    $nombreCompleto   = trim("{$paciente['nombre']} {$paciente['primer_apellido']} {$paciente['segundo_apellido']}");
    $iniciales        = mb_strtoupper(mb_substr($paciente['nombre'], 0, 1) . mb_substr($paciente['primer_apellido'], 0, 1));
    $numeroExpediente = preg_replace('/\D/', '', $paciente['folio']);

    // La ficha lateral agrupa las notas en un selector contiguo, sin alargar su menú principal.
    $id = (int) $paciente['id'];
    $esFichaLateral = str_contains($claseExtra, 'perfil--lateral');
    $esMenuTabla = str_contains($claseExtra, 'perfil--contexto');
    $urlPerfil = '/pacientes/ver.php?id=' . $id;
    $fotoUrl = fotoPacienteUrl($paciente);
    $puedePortada = puede('editar_paciente');
    $opciones = ($esFichaLateral || $esMenuTabla) ? array_values(array_filter([
        ['texto' => 'Perfil del paciente', 'icono' => 'usuario', 'url' => $urlPerfil . '#perfil'],
        puede('historial') ? ['texto' => 'Registro clínico', 'icono' => 'clinico', 'selector' => true, 'principal' => true] : null,
        puede('agenda') ? ['texto' => 'Agendar cita', 'icono' => 'calendario', 'url' => '/agenda/?nueva=1&paciente_id=' . $id] : null,
        puede('asistente') ? ['texto' => 'Resumir con IA', 'icono' => 'chispa', 'url' => $urlPerfil . '&resumir=1'] : null,
        puede('editar_paciente') ? ['texto' => 'Editar ficha', 'icono' => 'editar', 'url' => '/pacientes/registro.php?id=' . $id] : null,
    ])) : array_values(array_filter([
        puede('historial') ? ['texto' => 'Registro clínico', 'icono' => 'clinico', 'url' => $urlPerfil . '#historial', 'principal' => true] : null,
        ['texto' => 'Perfil del paciente', 'icono' => 'usuario', 'url' => $urlPerfil . '#perfil'],
        puede('agenda') ? ['texto' => 'Agendar cita', 'icono' => 'calendario', 'url' => '/agenda/?nueva=1&paciente_id=' . $id] : null,
        puede('asistente') ? ['texto' => 'Resumir con IA', 'icono' => 'chispa', 'url' => $urlPerfil . '&resumir=1'] : null,
        puede('editar_paciente') ? ['texto' => 'Editar ficha', 'icono' => 'editar', 'url' => '/pacientes/registro.php?id=' . $id] : null,
    ]));

    // Acciones que antes vivían en el botón de tres puntos y en el encabezado: ahora son renglones del menú.
    $utilidades = [];
    if ($esFichaLateral) {
        $utilidades[] = ['texto' => 'Imprimir o elegir documentos', 'icono' => 'pdf', 'accion' => 'imprimir'];
        $utilidades[] = ['texto' => 'Exportar este expediente', 'icono' => 'documento', 'accion' => 'exportar'];
        if (puede('importar_exportar')) {
            $utilidades[] = ['texto' => 'Formatos para bases de datos', 'icono' => 'flecha_arriba', 'accion' => 'exportar-datos'];
            $utilidades[] = ['texto' => 'Importar bases de datos', 'icono' => 'flecha_abajo', 'accion' => 'importar-datos'];
        }
    }
    if ($utilidades) {
        $opciones[] = ['separador' => true];
        $opciones = array_merge($opciones, $utilidades);
    }

    ob_start();
    ?>
    <article class="perfil <?= e($claseExtra) ?>" data-perfil-paciente="<?= $id ?>" aria-label="Perfil de <?= e($nombreCompleto) ?>">
      <header class="perfil__cabecera">
      <?php if ($puedePortada): ?>
        <button type="button" class="avatar avatar--boton" data-portada-paciente title="<?= $fotoUrl ? 'Cambiar portada' : 'Agregar portada' ?>" aria-label="<?= $fotoUrl ? 'Cambiar portada' : 'Agregar portada' ?>"><?php if ($fotoUrl): ?><img src="<?= e($fotoUrl) ?>" alt="" data-portada-avatar loading="eager"><?php else: ?><?= e($iniciales) ?><?php endif; ?></button>
      <?php else: ?>
        <span class="avatar" aria-hidden="true"><?php if ($fotoUrl): ?><img src="<?= e($fotoUrl) ?>" alt=""><?php else: ?><?= e($iniciales) ?><?php endif; ?></span>
      <?php endif; ?>
        <div class="perfil__identidad">
          <h2 class="perfil__nombre"><?= e($nombreCompleto) ?></h2>
          <p class="perfil__datos">Expediente <?= e($numeroExpediente) ?> · <?= edad($paciente['fecha_nacimiento']) ?> años</p>
        </div>
      </header>

      <?php if ($puedePortada): ?><button type="button"<?php else: ?><div<?php endif; ?> class="perfil__imagen<?= $fotoUrl ? ' perfil__imagen--con' : '' ?>"<?= $puedePortada ? ' data-portada-paciente' : ' role="img"' ?> aria-label="Portada del expediente">
        <?php if ($fotoUrl): ?>
          <img src="<?= e($fotoUrl) ?>" alt="" data-portada-imagen loading="eager" decoding="async" fetchpriority="high">
        <?php else: ?>
          <span class="perfil__portada-iniciales" aria-hidden="true"><?= e($iniciales) ?></span>
        <?php endif; ?>
        <?php if ($puedePortada): ?>
          <span class="perfil__portada-accion"><?= icono('camara') ?><span><?= $fotoUrl ? 'Cambiar portada' : 'Agregar portada' ?></span></span>
        <?php endif; ?>
      <?php if ($puedePortada): ?></button><?php else: ?></div><?php endif; ?>
      <h3 class="perfil__titulo menu-flotante__titulo"><?= ($esFichaLateral || $esMenuTabla) ? 'Acciones del expediente' : 'Perfil del paciente' ?></h3>

      <ul class="menu menu-flotante">
        <?php foreach ($opciones as $i => $op):
          if (!empty($op['separador'])): ?>
            <li class="menu__separador" role="separator"></li>
          <?php continue; endif;
          $clases = 'menu__opcion'
            . (!empty($op['principal']) ? ' menu__opcion--principal' : '')
            . (!empty($op['peligro']) ? ' menu__opcion--peligro' : '');
          if (!empty($op['selector'])) {
              $atributos = 'type="button" data-abrir-registro-selector aria-expanded="false" aria-controls="registro-selector-' . $id . '"';
          } elseif (!empty($op['accion'])) {
              $atributos = 'type="button" data-accion-perfil="' . e($op['accion']) . '"';
          } else {
              $atributos = 'href="' . URL_BASE . e($op['url']) . '"';
          }
        ?>
          <li>
            <?php if (!empty($op['selector'])): ?>
              <button class="<?= $clases ?>" <?= $atributos ?> style="--i: <?= $i ?>">
                <i class="menu__icono"><?= icono($op['icono']) ?></i><span><?= e($op['texto']) ?></span><?= icono('chevron', 'menu-flotante__flecha') ?>
              </button>
            <?php elseif (!empty($op['accion'])): ?>
              <button class="<?= $clases ?>" <?= $atributos ?> style="--i: <?= $i ?>">
                <i class="menu__icono"><?= icono($op['icono']) ?></i><span><?= e($op['texto']) ?></span>
              </button>
            <?php else: ?>
              <a class="<?= $clases ?>" <?= $atributos ?> style="--i: <?= $i ?>">
                <i class="menu__icono"><?= icono($op['icono']) ?></i><span><?= e($op['texto']) ?></span>
              </a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php if ($esMenuTabla && puede('historial')): ?>
        <aside class="registro-selector-lateral registro-selector-contexto" id="registro-selector-<?= $id ?>" data-registro-selector-panel aria-label="Elegir tipo de registro" hidden>
          <header><div><h2>Registro clínico</h2><p>Elige el documento que vas a crear.</p></div><button type="button" data-cerrar-registro-selector aria-label="Cerrar">×</button></header>
          <?php foreach (array_unique(array_column(TIPOS_REGISTRO, 'grupo')) as $grupo): ?>
            <section><h3><?= e($grupo) ?></h3><ul>
              <?php foreach (TIPOS_REGISTRO as $tipoRegistro => $datosTipo): if ($datosTipo['grupo'] !== $grupo) continue; ?>
                <li><a href="<?= URL_BASE ?>/pacientes/nuevo_registro.php?paciente=<?= $id ?>&amp;tipo=<?= e($tipoRegistro) ?>"><i class="registro-selector-lateral__icono"><?= iconoRelleno($datosTipo['relleno']) ?></i><span><?= e($datosTipo['documento']) ?></span><?= icono('chevron') ?></a></li>
              <?php endforeach; ?>
            </ul></section>
          <?php endforeach; ?>
        </aside>
      <?php endif; ?>

      <footer class="perfil__pie">
        <span>Registrado el <?= fechaCorta($paciente['creado_en']) ?></span>
      </footer>
    </article>
    <?php
    return ob_get_clean();
}
