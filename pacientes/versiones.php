<?php
/**
 * Historial de versiones de cualquier registro del motor (pacientes, medicamentos, catálogos…).
 *
 *   ?entidad=pacientes&registro=9   (atajo: ?id=9 para pacientes)
 *
 * Cada cambio guarda el estado anterior; aquí se consulta y se puede restaurar. Restaurar guarda además
 * el estado vigente como nueva versión, así que nada se pierde.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';

$entidadClave = (string) ($_GET['entidad'] ?? 'pacientes');
$registroId   = (int) ($_GET['registro'] ?? ($_GET['id'] ?? 0));
$entidad      = entidadMotor($entidadClave);
if (!$entidad || $registroId <= 0) {
    mensajeFlash('error', 'El registro no existe.');
    redirigir('/pacientes/');
}
exigirPermiso($entidad['permiso'] ?? 'historial');

$registro = motorObtener($entidadClave, $registroId);
if (!$registro) {
    mensajeFlash('error', 'El registro no existe o fue dado de baja.');
    redirigir('/pacientes/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_restaurar'] ?? '') === '1') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } else {
        $version = motorObtener('versiones', (int) ($_POST['version'] ?? 0));
        if (!$version || $version['entidad'] !== $entidadClave || (int) $version['registro_id'] !== $registroId) {
            mensajeFlash('error', 'Esa versión no pertenece a este registro.');
        } else {
            $anteriores = json_decode((string) $version['datos'], true) ?: [];
            $restaurar = [];
            foreach ($entidad['campos'] as $campo => $definicion) {
                if (!empty($definicion['solo_lectura']) || in_array($campo, ['folio', 'confirmacion_datos'], true)) {
                    continue;
                }
                if (array_key_exists($campo, $anteriores)) {
                    $restaurar[$campo] = $anteriores[$campo];
                }
            }
            if (!empty($anteriores['extras'])) {
                $extras = json_decode((string) $anteriores['extras'], true);
                if (is_array($extras)) {
                    $restaurar['extras'] = $extras;
                }
            }
            $resultado = $restaurar ? motorActualizar($entidadClave, $registroId, $restaurar) : ['ok' => false];
            mensajeFlash(!empty($resultado['ok']) ? 'exito' : 'error', !empty($resultado['ok']) ? 'Versión restaurada. Se guardó el estado anterior como nueva versión.' : 'No se pudo restaurar la versión.');
        }
    }
    redirigir('/pacientes/versiones.php?entidad=' . urlencode($entidadClave) . '&registro=' . $registroId);
}

$versiones = motorListar('versiones', ['filtro' => ['entidad' => $entidadClave, 'registro_id' => $registroId], 'limite' => 100])['filas'];

$autores = [];
if ($versiones) {
    $idsAutores = array_values(array_unique(array_map(fn ($v) => (int) $v['usuario_id'], $versiones)));
    $marcas = implode(',', array_fill(0, count($idsAutores), '?'));
    $consulta = db()->prepare("SELECT id, nombre FROM usuarios WHERE id IN ($marcas)");
    $consulta->execute($idsAutores);
    foreach ($consulta as $autor) {
        $autores[(int) $autor['id']] = $autor['nombre'];
    }
}

$etiquetas = [];
foreach ($entidad['campos'] as $campo => $definicion) {
    $etiquetas[$campo] = (string) ($definicion['texto'] ?? $campo);
}

$nombreRegistro = trim((string) ($registro['titulo'] ?? ''));
if ($entidadClave === 'pacientes') {
    $nombreRegistro = trim(($registro['nombre'] ?? '') . ' ' . ($registro['primer_apellido'] ?? '') . ' ' . ($registro['segundo_apellido'] ?? ''));
}
$nombreRegistro = $nombreRegistro !== '' ? $nombreRegistro : 'Registro ' . $registroId;

$tituloPagina   = 'Versiones · ' . $nombreRegistro;
$seccionActiva  = $entidadClave === 'pacientes' ? 'pacientes' : 'catalogos';
$claseContenido = 'pagina--ancha';
$volver = $entidadClave === 'pacientes'
    ? '/pacientes/ver.php?id=' . $registroId
    : '/catalogos/editar_tabla.php?tabla=' . urlencode($entidadClave);
$migas = [
    ['texto' => $entidad['titulo'], 'url' => $volver],
    ['texto' => $nombreRegistro],
    ['texto' => 'Versiones'],
];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <span class="pagina__meta"><?= icono('documento') ?> Historial de cambios · <?= e($entidad['titulo']) ?></span>
  <h1 class="pagina__titulo">Versiones del registro</h1>
  <p class="pagina__subtitulo">Cada cambio guarda el estado anterior. La versión más reciente aparece arriba.</p>
</div>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Cambios registrados</h2>
    <span class="ficha__fuente"><?= count($versiones) ?> versión(es)</span>
  </div>

  <?php if (!$versiones): ?>
    <p class="ficha__vacio">Todavía no hay cambios registrados de este registro.</p>
  <?php else: ?>
    <ul class="versiones">
      <?php foreach ($versiones as $version): $datos = json_decode((string) $version['datos'], true) ?: []; ?>
        <li class="versiones__item">
          <details>
            <summary class="versiones__resumen">
              <span class="versiones__fecha"><?= e(fechaCorta(substr((string) $version['creado_en'], 0, 10))) ?> · <?= e(hora12((string) $version['creado_en'])) ?></span>
              <span class="versiones__autor"><?= e($autores[(int) $version['usuario_id']] ?? 'Sistema') ?></span>
            </summary>
            <dl class="versiones__campos">
              <?php foreach ($etiquetas as $campo => $etiqueta): ?>
                <?php if (!array_key_exists($campo, $datos) || $datos[$campo] === null || $datos[$campo] === '') { continue; } ?>
                <div><dt><?= e($etiqueta) ?></dt><dd><?= e((string) $datos[$campo]) ?></dd></div>
              <?php endforeach; ?>
            </dl>
            <form method="post" class="versiones__restaurar">
              <?= campoCsrf() ?>
              <input type="hidden" name="accion_restaurar" value="1">
              <input type="hidden" name="version" value="<?= (int) $version['id'] ?>">
              <button type="submit" class="boton boton--sutil">Restaurar esta versión</button>
            </form>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="documento__acciones">
    <a class="boton boton--fantasma" href="<?= URL_BASE . e($volver) ?>"><?= icono('atras') ?> Volver</a>
  </div>
</section>
<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
