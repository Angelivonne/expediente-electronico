<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('finanzas');

$entidad = entidadMotor('finanzas_movimientos');
$camposDef = $entidad['campos'] ?? [];
$campos = array_filter(array_keys($camposDef), fn ($c) => !empty($camposDef[$c]['solo_lectura']) === false && !in_array($c, ['id', 'servicio_id'], true));
$serviciosClinicos = [];
$serviciosCatalogoDisponible = false;
try {
    $columnaServicio = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finanzas_movimientos' AND COLUMN_NAME = 'servicio_id'");
    if ((int) $columnaServicio->fetchColumn() > 0) {
        $qServicios = db()->prepare('SELECT id, nombre, precio FROM servicios_clinicos WHERE institucion_id = ? AND activo = 1 ORDER BY nombre LIMIT 300');
        $qServicios->execute([institucionId()]);
        $serviciosClinicos = $qServicios->fetchAll();
        $serviciosCatalogoDisponible = true;
    }
} catch (Throwable $e) {
    // La migración es opcional para conservar el registro manual de movimientos.
}
$servicioId = 0;

$datos   = array_fill_keys($campos, '');
$errores = [];

$datos['fecha']   = date('Y-m-d');
$datos['profesional_id'] = (string) (usuarioActual()['id'] ?? '');
$datos['tipo']    = 'ingreso';
$datos['estado']  = 'pagado';
$datos['metodo']  = 'efectivo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datosEnviados = is_array($_POST['datos'] ?? null) ? $_POST['datos'] : [];
    foreach ($campos as $campo) {
        $valor = $datosEnviados[$campo] ?? '';
        $datos[$campo] = is_scalar($valor) ? trim((string) $valor) : '';
    }
    if (($datos['profesional_id'] ?? '') === '') {
        $datos['profesional_id'] = (string) (usuarioActual()['id'] ?? '');
    }
    $servicioId = max(0, (int) ($_POST['servicio_id'] ?? 0));
    if ($servicioId > 0 && $serviciosCatalogoDisponible) {
        $qServicio = db()->prepare('SELECT nombre, precio FROM servicios_clinicos WHERE id = ? AND institucion_id = ? AND activo = 1');
        $qServicio->execute([$servicioId, institucionId()]);
        $servicioSeleccionado = $qServicio->fetch();
        if (!$servicioSeleccionado) {
            $errores['servicio_id'] = 'El servicio ya no está activo en esta clínica.';
        } else {
            $datos['concepto'] = (string) $servicioSeleccionado['nombre'];
            if ($datos['monto'] === '') {
                $datos['monto'] = (string) $servicioSeleccionado['precio'];
            }
        }
    } elseif ($servicioId > 0) {
        $errores['servicio_id'] = 'El catálogo de servicios aún no está instalado.';
    }

    if ($datos['tipo'] === '') {
        $errores['tipo'] = 'Selecciona el tipo.';
    }
    if ($datos['fecha'] === '') {
        $errores['fecha'] = 'La fecha es obligatoria.';
    } elseif (!DateTime::createFromFormat('!Y-m-d', $datos['fecha'])) {
        $errores['fecha'] = 'Fecha no valida.';
    }
    if ($datos['concepto'] === '') {
        $errores['concepto'] = 'El concepto es obligatorio.';
    }
    if ($datos['monto'] === '' || !is_numeric($datos['monto'])) {
        $errores['monto'] = 'Escribe un monto valido.';
    }

    if (empty($errores) && !csrfValido()) {
        $errores['general'] = 'La sesion expiro. Vuelve a enviar.';
    }

    if (empty($errores)) {
        $entrada = [];
        foreach ($campos as $campo) {
            $v = $datos[$campo];
            if ($v !== '') {
                $entrada[$campo] = $v;
            }
        }
        if (isset($entrada['monto'])) {
            $entrada['monto'] = (float) $entrada['monto'];
        }
        if (isset($entrada['paciente_id']) && $entrada['paciente_id'] !== '') {
            $entrada['paciente_id'] = (int) $entrada['paciente_id'];
        }
        if ($servicioId > 0) {
            $entrada['servicio_id'] = $servicioId;
        }
        $alta = motorCrear('finanzas_movimientos', $entrada);
        if (!empty($alta['ok'])) {
            mensajeFlash('exito', 'Movimiento registrado.');
            redirigir('/finanzas/');
        }
        $errores = array_merge($errores, $alta['errores'] ?? []);
    }
}

$tituloPagina   = 'Nuevo movimiento';
$seccionActiva  = 'finanzas';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'Finanzas', 'url' => URL_BASE . '/finanzas/'], ['texto' => 'Nuevo movimiento']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo">Nuevo movimiento</h1>
</div>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if (!empty($errores['general'])): ?>
  <p class="pagina__descripcion pagina__descripcion--error"><?= e($errores['general']) ?></p>
<?php endif; ?>

<form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" id="form-finanzas">
  <?= campoCsrf() ?>
  <input type="hidden" name="accion_nueva" value="1">

  <section class="seccion">
    <h2 class="seccion__titulo"><?= icono('documento') ?> Datos del movimiento</h2>
    <div class="seccion__cuerpo">
      <?php if ($serviciosClinicos): ?>
        <div class="campo col-6<?= isset($errores['servicio_id']) ? ' campo--error' : '' ?>">
          <label class="campo__etiqueta" for="servicio-clinico">Servicio del catálogo</label>
          <div class="campo__control campo__control--select">
            <select id="servicio-clinico" name="servicio_id" data-finanzas-servicio>
              <option value="">Sin servicio asociado</option>
              <?php foreach ($serviciosClinicos as $servicio): ?>
                <option value="<?= (int) $servicio['id'] ?>" data-nombre="<?= e($servicio['nombre']) ?>" data-precio="<?= e((string) $servicio['precio']) ?>"<?= $servicioId === (int) $servicio['id'] ? ' selected' : '' ?>><?= e($servicio['nombre']) ?> · $<?= number_format((float) $servicio['precio'], 2, '.', ',') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (isset($errores['servicio_id'])): ?><span class="campo__ayuda"><?= e($errores['servicio_id']) ?></span><?php endif; ?>
        </div>
      <?php elseif (puede('configurar')): ?>
        <p class="pagina__descripcion">El catálogo está vacío o falta su migración. <a href="<?= URL_BASE ?>/finanzas/servicios.php">Administrar servicios</a>.</p>
      <?php endif; ?>
      <div class="rejilla rejilla--5">
        <?php foreach ($campos as $nombre): ?>
          <?php
            $def = $camposDef[$nombre] ?? [];
            $texto = (string) ($def['texto'] ?? $nombre);
            $tipo = (string) ($def['tipo'] ?? 'texto');
            $valor = (string) ($datos[$nombre] ?? '');
            $requerido = !empty($def['req']);
            $error = (string) ($errores[$nombre] ?? '');
            $opciones = $def['opciones'] ?? [];
          ?>
          <div class="campo<?= $error !== '' ? ' campo--error' : '' ?>">
            <label class="campo__etiqueta" for="<?= e($nombre) ?>"><?= e($texto) ?><?= $requerido ? ' *' : '' ?></label>
            <div class="campo__control">
              <?php if ($tipo === 'casilla'): ?>
                <input id="<?= e($nombre) ?>" type="checkbox" name="datos[<?= e($nombre) ?>]" value="1"<?= $valor !== '' ? ' checked' : '' ?>>
              <?php elseif ($tipo === 'opcion' && !empty($opciones)): ?>
                <select id="<?= e($nombre) ?>" name="datos[<?= e($nombre) ?>]">
                  <option value="">—</option>
                  <?php foreach ($opciones as $claveOpcion => $textoOpcion): ?>
                    <option value="<?= e((string) $claveOpcion) ?>"<?= $valor === (string) $claveOpcion ? ' selected' : '' ?>><?= e((string) $textoOpcion) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($tipo === 'fecha'): ?>
                <input id="<?= e($nombre) ?>" type="date" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $requerido ? ' required' : '' ?>>
              <?php elseif ($tipo === 'numero'): ?>
                <input id="<?= e($nombre) ?>" type="number" step="0.01" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $nombre === 'monto' ? ' data-finanzas-monto' : '' ?><?= $requerido ? ' required' : '' ?>>
              <?php else: ?>
                <input id="<?= e($nombre) ?>" type="text" name="datos[<?= e($nombre) ?>]" value="<?= e($valor) ?>"<?= $nombre === 'concepto' ? ' data-finanzas-concepto' : '' ?><?= !empty($def['max']) ? ' maxlength="' . (int) $def['max'] . '"' : '' ?><?= $requerido ? ' required' : '' ?>>
              <?php endif; ?>
            </div>
            <?php if ($error !== ''): ?><span class="campo__ayuda"><?= e($error) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <div class="seccion__acciones">
    <a class="boton boton--fantasma" href="<?= URL_BASE ?>/finanzas/">Cancelar</a>
    <button type="submit" class="boton boton--primario">Guardar movimiento</button>
  </div>
</form>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
