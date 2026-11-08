<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/vistas/componentes/bloques_nota.php';
exigirPermiso('editar_paciente');

$campos = [
    'nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo', 'curp',
    'entidad_nacimiento', 'nacionalidad', 'telefono', 'correo', 'domicilio', 'municipio_estado',
    'contacto_emergencia', 'telefono_emergencia', 'alergias', 'grupo_sanguineo',
    'padecimientos_cronicos', 'medicamentos_actuales', 'motivo_consulta',
];
$camposExtraPaciente = [
    'estado_civil', 'ocupacion', 'escolaridad', 'afiliacion_institucional', 'tutor_representante', 'grupo_etnico', 'lengua_materna',
    'antecedentes_heredofamiliares', 'antecedentes_personales_patologicos', 'antecedentes_personales_no_patologicos',
    'inmunizaciones', 'antecedentes_ginecoobstetricos', 'habitos_sustancias',
];

$datos   = array_fill_keys(array_merge($campos, $camposExtraPaciente), '');
$errores = [];

// Propiedades que el administrador agregó a la base de pacientes: forman parte del formato de registro
$propiedadesExtra = json_decode((string) config('pacientes_propiedades'), true) ?: [];
$extras = [];   // clave => valor ya validado (texto, número, fecha AAAA-MM-DD, opción o bool)

// Modo edición ("Editar datos" desde el perfil): mismo formulario, precargado
$idEdicion = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$original  = null;
if ($idEdicion) {
    $consulta = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
    $consulta->execute([$idEdicion, institucionId()]);
    $original = $consulta->fetch();
    if (!$original) {
        mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
        redirigir('/pacientes/');
    }
    foreach ($campos as $campo) {
        $datos[$campo] = (string) ($original[$campo] ?? '');
    }
    $extras = json_decode((string) $original['extras'], true) ?: [];
    foreach ($camposExtraPaciente as $campo) $datos[$campo] = (string) ($extras['_perfil'][$campo] ?? '');
}
$extrasOriginales = $extras;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($campos as $campo) {
        $datos[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }
    foreach ($camposExtraPaciente as $campo) {
        $max = in_array($campo, ['antecedentes_heredofamiliares','antecedentes_personales_patologicos','antecedentes_personales_no_patologicos','inmunizaciones','antecedentes_ginecoobstetricos','habitos_sustancias'], true) ? 4000 : 160;
        $datos[$campo] = mb_substr(trim((string) ($_POST[$campo] ?? '')), 0, $max);
    }
    $datos['curp'] = mb_strtoupper($datos['curp']);
    $confirmado    = isset($_POST['confirmacion']);

    // Bloques del motor de notas para cada campo clínico (se guardan en extras['_bloques']).
    // Solo se tocan los campos que viajaron en el envío; el resto conserva lo guardado.
    $bloquesForm = null;
    if (isset($_POST['nota_bloques']) && is_array($_POST['nota_bloques'])) {
        $bloquesForm = [];
        foreach (CAMPOS_NOTA_PACIENTE as $cb) {
            $previos = sanearBloquesNota($extras['_bloques'][$cb] ?? null);
            if ($previos) {
                $bloquesForm[$cb] = json_encode($previos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            }
            if (!array_key_exists($cb, $_POST['nota_bloques'])) {
                continue;
            }
            $limpios = sanearBloquesNota($_POST['nota_bloques'][$cb]);
            if ($limpios) {
                $bloquesForm[$cb] = json_encode($limpios, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            } else {
                unset($bloquesForm[$cb]);
            }
        }
    }

    // Propiedades agregadas por el administrador: se guardan dentro de extras
    $extrasForm = [];
    foreach ($propiedadesExtra as $prop) {
        $clave = $prop['clave'];
        $valor = is_scalar($_POST['extra'][$clave] ?? null) ? trim((string) $_POST['extra'][$clave]) : '';
        if ($prop['tipo'] === 'casilla') {
            $valor = isset($_POST['extra'][$clave]) ? '1' : '';
        }
        if ($valor === '') {
            continue;
        }
        switch ($prop['tipo']) {
            case 'numero':
                $numero = str_replace(',', '.', $valor);
                is_numeric($numero) ? $extrasForm[$clave] = $numero + 0 : $errores["extra_$clave"] = 'Escribe un número';
                break;
            case 'fecha':
                $f = DateTime::createFromFormat('!Y-m-d', $valor);
                $f && $f->format('Y-m-d') === $valor ? $extrasForm[$clave] = $valor : $errores["extra_$clave"] = 'Fecha no válida';
                break;
            case 'seleccion':
                in_array($valor, $prop['opciones'] ?? [], true) ? $extrasForm[$clave] = $valor : $errores["extra_$clave"] = 'Selecciona una opción de la lista';
                break;
            case 'casilla':
                $extrasForm[$clave] = true;
                break;
            default:
                $extrasForm[$clave] = mb_substr($valor, 0, 500);
        }
    }
    // Los valores enviados se conservan en pantalla si la validación regresa el formulario
    $extras = array_merge($extras, $extrasForm);
    // El re-render usa los bloques enviados, no los viejos de la base.
    if ($bloquesForm !== null) {
        if ($bloquesForm) {
            $extras['_bloques'] = $bloquesForm;
        } else {
            unset($extras['_bloques']);
        }
    }

    // Regla clínica del expediente: la fecha de nacimiento no puede ser futura
    if ($datos['fecha_nacimiento'] !== '') {
        $fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha_nacimiento']);
        if ($fecha && $fecha > new DateTime('today')) {
            $errores['fecha_nacimiento'] = 'Fecha no válida';
        }
    }

    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a enviar el formulario.';
    } elseif ($yaGuardado = envioYaProcesado()) {
        // El mismo envío llegó dos veces (doble clic o reenvío tras un corte): no se registra otra vez
        redirigir($yaGuardado);
    }
    if (!$confirmado) {
        $errores['confirmacion'] = 'Confirma el origen de los datos';
    }

    // Guardado por el motor único: las mismas reglas que la tabla, la ficha y las herramientas
    if (!$errores) {
        $entrada = $datos;
        $entrada['extras'] = $extrasForm;
        if ($bloquesForm !== null) {
            $entrada['extras']['_bloques'] = $bloquesForm;
        }
        if ($original) {
            $resultado = motorActualizar('pacientes', $idEdicion, $entrada);
        } else {
            $resultado = motorCrear('pacientes', $entrada, ['confirmacion' => true]);
        }

        if (empty($resultado['ok'])) {
            $errores = $resultado['errores'] ?? ['general' => 'No se pudo guardar.'];
        } elseif ($original) {
            mensajeFlash('exito', ($resultado['cambios'] ?? 0) ? 'Datos del paciente actualizados.' : 'No hubo cambios que guardar.');
            recordarEnvio('/pacientes/ver.php?id=' . $idEdicion);
            redirigir('/pacientes/ver.php?id=' . $idEdicion);
        } else {
            $id    = (int) $resultado['id'];
            $folio = (string) ($resultado['folio'] ?? '');
            require_once __DIR__ . '/../app/servicios/cuenta/push.php';
            evaluarUmbralesPush('pacientes');   // aviso por umbral "N pacientes nuevos" (solo encola)

            $nombreCompleto = trim("{$datos['nombre']} {$datos['primer_apellido']} {$datos['segundo_apellido']}");
            mensajeFlash('exito', "Paciente registrado: $nombreCompleto (expediente $folio).");
            recordarEnvio('/pacientes/?nuevo=' . $id);
            redirigir('/pacientes/?nuevo=' . $id);
        }
    }
}
// Si la validación devuelve el formulario, conserva también los campos extendidos del perfil.

$seccionActiva  = 'pacientes';
$clavePresencia = $original ? 'paciente:' . $idEdicion : 'pacientes:registro';
if ($original) {
    $nombreOriginal = trim("{$original['nombre']} {$original['primer_apellido']} {$original['segundo_apellido']}");
    $tituloPagina   = 'Editar datos';
    $migas          = [
        ['texto' => 'Pacientes', 'url' => '/pacientes/'],
        ['texto' => $nombreOriginal, 'url' => '/pacientes/ver.php?id=' . $idEdicion],
        ['texto' => 'Editar datos'],
    ];
} else {
    $tituloPagina = 'Registrar paciente';
    $migas        = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => 'Registrar paciente']];
}
$notificacion  = $errores
    ? ['tipo' => 'error', 'texto' => $errores['general'] ?? 'Revisa los campos marcados en rojo.']
    : null;

// Ayudas para pintar valores y errores
$valorCampo = fn (string $c): string => e($datos[$c]);
$error = fn (string $c): string => isset($errores[$c]) ? ' campo--error' : '';
$aviso = fn (string $c): string => isset($errores[$c]) ? '<span class="campo__ayuda" data-original="">' . e($errores[$c]) . '</span>' : '';
$sel   = fn (string $c, string $valor): string => $datos[$c] === $valor ? ' selected' : '';

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera">
  <span class="pagina__meta"><?= iconoRelleno('documento') ?> <?= $original ? e($original['folio']) : 'Nuevo expediente' ?></span>
  <h1 class="pagina__titulo"><?= $original ? 'Editar datos del paciente' : 'Registro de pacientes' ?></h1>
  <button type="button" class="dictado-mostrar" data-dictado-mostrar title="Llenar el formulario por voz (Alt+Shift+D)"><?= icono('microfono') ?> Dictar <kbd class="tecla">Alt ⇧ D</kbd></button>
</div>

<form method="post" id="form-paciente" novalidate data-borrador="<?= $original ? 'paciente-' . $idEdicion : 'paciente-nuevo' ?>">
  <?= campoCsrf() ?>
  <?= campoEnvio() ?>
  <?php if ($original): ?><input type="hidden" name="id" value="<?= $idEdicion ?>"><?php endif; ?>

  <!-- Datos de identificación (NOM-004 6.1.1 / NOM-024 6.5) -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('usuario') ?> Datos de identificación</h2>
    <div class="seccion__cuerpo">
    <div class="rejilla rejilla--5">

      <div class="campo<?= $error('nombre') ?>">
        <label class="campo__etiqueta" for="nombre">Nombre(s) <span class="requerido">*</span></label>
        <div class="campo__control"><input id="nombre" name="nombre" value="<?= $valorCampo('nombre') ?>" maxlength="80" required autocomplete="off"></div>
        <?= $aviso('nombre') ?>
      </div>

      <div class="campo<?= $error('primer_apellido') ?>">
        <label class="campo__etiqueta" for="primer_apellido">Apellido paterno <span class="requerido">*</span></label>
        <div class="campo__control"><input id="primer_apellido" name="primer_apellido" value="<?= $valorCampo('primer_apellido') ?>" maxlength="60" required autocomplete="off"></div>
        <?= $aviso('primer_apellido') ?>
      </div>

      <div class="campo">
        <label class="campo__etiqueta" for="segundo_apellido">Apellido materno</label>
        <div class="campo__control"><input id="segundo_apellido" name="segundo_apellido" value="<?= $valorCampo('segundo_apellido') ?>" maxlength="60" autocomplete="off"></div>
      </div>

      <div class="campo<?= $error('fecha_nacimiento') ?>">
        <label class="campo__etiqueta" for="fecha_nacimiento">Fecha de nacimiento <span class="requerido">*</span></label>
        <div class="campo__control">
          <?= icono('calendario') ?>
          <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= $valorCampo('fecha_nacimiento') ?>" required max="<?= date('Y-m-d') ?>">
        </div>
        <?= $aviso('fecha_nacimiento') ?>
      </div>

      <div class="campo<?= $error('sexo') ?>">
        <label class="campo__etiqueta" for="sexo">Sexo <span class="requerido">*</span></label>
        <div class="campo__control campo__control--select">
          <select id="sexo" name="sexo" required>
            <option value="">Selecciona</option>
            <?php foreach (SEXOS as $clave => $texto): ?>
              <option value="<?= $clave ?>"<?= $sel('sexo', $clave) ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('sexo') ?>
      </div>

    </div>
    <div class="rejilla">

      <div class="campo col-6<?= $error('curp') ?>">
        <label class="campo__etiqueta" for="curp">CURP</label>
        <div class="campo__control">
          <input id="curp" name="curp" value="<?= $valorCampo('curp') ?>" maxlength="18" autocomplete="off" spellcheck="false">
        </div>
        <?= $aviso('curp') ?>
      </div>

      <div class="campo col-3<?= $error('entidad_nacimiento') ?>">
        <label class="campo__etiqueta" for="entidad_nacimiento">Entidad de nacimiento</label>
        <div class="campo__control campo__control--select">
          <select id="entidad_nacimiento" name="entidad_nacimiento">
            <option value="">Selecciona</option>
            <?php foreach (ENTIDADES as $clave => $texto): ?>
              <option value="<?= $clave ?>"<?= $sel('entidad_nacimiento', $clave) ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('entidad_nacimiento') ?>
      </div>

      <div class="campo col-3">
        <label class="campo__etiqueta" for="nacionalidad">Nacionalidad</label>
        <div class="campo__control"><input id="nacionalidad" name="nacionalidad" value="<?= $valorCampo('nacionalidad') ?>" maxlength="40" autocomplete="off"></div>
      </div>

      <div class="campo col-3"><label class="campo__etiqueta" for="estado_civil">Estado civil</label><div class="campo__control"><input id="estado_civil" name="estado_civil" value="<?= $valorCampo('estado_civil') ?>" maxlength="40"></div></div>
      <div class="campo col-3"><label class="campo__etiqueta" for="grupo_etnico">Grupo étnico</label><div class="campo__control"><input id="grupo_etnico" name="grupo_etnico" value="<?= $valorCampo('grupo_etnico') ?>" maxlength="60" placeholder="En su caso"></div></div>
      <div class="campo col-3"><label class="campo__etiqueta" for="lengua_materna">Lengua materna</label><div class="campo__control"><input id="lengua_materna" name="lengua_materna" value="<?= $valorCampo('lengua_materna') ?>" maxlength="60"></div></div>
      <div class="campo col-3"><label class="campo__etiqueta" for="ocupacion">Ocupación</label><div class="campo__control"><input id="ocupacion" name="ocupacion" value="<?= $valorCampo('ocupacion') ?>" maxlength="100"></div></div>
      <div class="campo col-3"><label class="campo__etiqueta" for="escolaridad">Escolaridad</label><div class="campo__control"><input id="escolaridad" name="escolaridad" value="<?= $valorCampo('escolaridad') ?>" maxlength="100"></div></div>
      <div class="campo col-3"><label class="campo__etiqueta" for="afiliacion_institucional">Afiliación institucional</label><div class="campo__control"><input id="afiliacion_institucional" name="afiliacion_institucional" value="<?= $valorCampo('afiliacion_institucional') ?>" maxlength="120"></div></div>

    </div>
    </div>
  </section>

  <!-- Contacto y domicilio -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('casa') ?> Contacto y domicilio</h2>
    <div class="seccion__cuerpo rejilla">

      <div class="campo col-6">
        <label class="campo__etiqueta" for="telefono">Teléfono</label>
        <div class="campo__control"><input type="tel" id="telefono" name="telefono" value="<?= $valorCampo('telefono') ?>" maxlength="20" autocomplete="off"></div>
      </div>

      <div class="campo col-6<?= $error('correo') ?>">
        <label class="campo__etiqueta" for="correo">Correo electrónico</label>
        <div class="campo__control"><input type="email" id="correo" name="correo" value="<?= $valorCampo('correo') ?>" maxlength="120" autocomplete="off"></div>
        <?= $aviso('correo') ?>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="domicilio">Domicilio</label>
        <div class="campo__control"><input id="domicilio" name="domicilio" value="<?= $valorCampo('domicilio') ?>" maxlength="200" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="municipio_estado">Municipio / Estado</label>
        <div class="campo__control"><input id="municipio_estado" name="municipio_estado" value="<?= $valorCampo('municipio_estado') ?>" maxlength="120" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="contacto_emergencia">Contacto de emergencia</label>
        <div class="campo__control"><input id="contacto_emergencia" name="contacto_emergencia" value="<?= $valorCampo('contacto_emergencia') ?>" maxlength="120" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="telefono_emergencia">Teléfono de emergencia</label>
        <div class="campo__control"><input type="tel" id="telefono_emergencia" name="telefono_emergencia" value="<?= $valorCampo('telefono_emergencia') ?>" maxlength="20" autocomplete="off"></div>
      </div>
      <div class="campo col-6"><label class="campo__etiqueta" for="tutor_representante">Tutor o representante (si corresponde)</label><div class="campo__control"><input id="tutor_representante" name="tutor_representante" value="<?= $valorCampo('tutor_representante') ?>" maxlength="160"></div></div>

    </div>
  </section>

  <!-- Información clínica inicial -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('escudo') ?> Información clínica inicial</h2>
    <div class="seccion__cuerpo rejilla">

      <div class="campo col-6">
        <label class="campo__etiqueta" for="alergias">Alergias</label>
        <div class="campo__control"><input id="alergias" name="alergias" value="<?= $valorCampo('alergias') ?>" maxlength="255" autocomplete="off"></div>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="padecimientos_cronicos">Padecimientos crónicos</label>
        <div class="campo__control"><input id="padecimientos_cronicos" name="padecimientos_cronicos" value="<?= $valorCampo('padecimientos_cronicos') ?>" maxlength="255" autocomplete="off"></div>
      </div>

      <div class="campo col-6<?= $error('grupo_sanguineo') ?>">
        <label class="campo__etiqueta" for="grupo_sanguineo">Grupo sanguíneo</label>
        <div class="campo__control campo__control--select">
          <select id="grupo_sanguineo" name="grupo_sanguineo">
            <option value="">Selecciona</option>
            <?php foreach (GRUPOS_SANGUINEOS as $grupo): ?>
              <option value="<?= e($grupo) ?>"<?= $sel('grupo_sanguineo', $grupo) ?>><?= e($grupo) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?= $aviso('grupo_sanguineo') ?>
      </div>

      <div class="campo col-6">
        <label class="campo__etiqueta" for="medicamentos_actuales">Medicamentos actuales</label>
        <div class="campo__control"><?= icono('buscar') ?><input id="medicamentos_actuales" name="medicamentos_actuales" value="<?= $valorCampo('medicamentos_actuales') ?>" maxlength="255" autocomplete="off" placeholder="Buscar medicamento" data-catalogo="medicamentos" role="combobox" aria-expanded="false" aria-autocomplete="list"></div>
      </div>

      <?php foreach (['antecedentes_heredofamiliares'=>'Antecedentes heredofamiliares','antecedentes_personales_patologicos'=>'Antecedentes personales patológicos','antecedentes_personales_no_patologicos'=>'Antecedentes personales no patológicos','inmunizaciones'=>'Inmunizaciones','antecedentes_ginecoobstetricos'=>'Antecedentes ginecoobstétricos (si corresponde)','habitos_sustancias'=>'Hábitos y consumo de sustancias'] as $campoClinico=>$etiquetaClinica): ?>
      <div class="campo col-6"><label class="campo__etiqueta" for="<?= $campoClinico ?>"><?= e($etiquetaClinica) ?></label><div class="campo__control campo__control--area"><textarea id="<?= $campoClinico ?>" name="<?= $campoClinico ?>" rows="2" data-nota-clinica="<?= e($etiquetaClinica) ?>" data-nota-bloques="<?= e($extras['_bloques'][$campoClinico] ?? '') ?>"><?= $valorCampo($campoClinico) ?></textarea></div></div>
      <?php endforeach; ?>

    </div>
  </section>

  <?php if ($propiedadesExtra || puede('configurar')): ?>
  <!-- Propiedades agregadas a la base de pacientes -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('lista') ?> Evaluación
      <?php if (puede('configurar')): ?><a class="seccion__accion" href="<?= URL_BASE ?>/pacientes/#propiedades" title="Agregar, renombrar o quitar propiedades de la base de pacientes">Editar formato</a><?php endif; ?>
    </h2>
    <div class="seccion__cuerpo rejilla">
      <?php if (!$propiedadesExtra): ?>
        <p class="seccion__vacio col-12">Las propiedades que agregues a la base de pacientes aparecen aquí y en cada expediente.</p>
      <?php endif; ?>
      <?php foreach ($propiedadesExtra as $prop):
          $clave = $prop['clave'];
          $idCampo = 'extra_' . $clave;
          $valor = $extras[$clave] ?? '';
          $claseError = isset($errores[$idCampo]) ? ' campo--error' : ''; ?>
        <?php if ($prop['tipo'] === 'casilla'): ?>
          <div class="campo col-6<?= $claseError ?>">
            <label class="casilla"><input type="checkbox" name="extra[<?= e($clave) ?>]" value="1"<?= $valor === true ? ' checked' : '' ?>> <?= e($prop['nombre']) ?></label>
          </div>
        <?php else: ?>
          <div class="campo col-6<?= $claseError ?>">
            <label class="campo__etiqueta" for="<?= e($idCampo) ?>"><?= e($prop['nombre']) ?></label>
            <?php if ($prop['tipo'] === 'seleccion'): ?>
              <div class="campo__control campo__control--select">
                <select id="<?= e($idCampo) ?>" name="extra[<?= e($clave) ?>]">
                  <option value="">Selecciona</option>
                  <?php foreach ($prop['opciones'] ?? [] as $opcion): ?>
                    <option value="<?= e($opcion) ?>"<?= (string) $valor === $opcion ? ' selected' : '' ?>><?= e($opcion) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <div class="campo__control"><input id="<?= e($idCampo) ?>" name="extra[<?= e($clave) ?>]" value="<?= e((string) $valor) ?>"
                <?= $prop['tipo'] === 'fecha' ? 'type="date"' : ($prop['tipo'] === 'numero' ? 'inputmode="decimal"' : 'maxlength="500"') ?> autocomplete="off"></div>
            <?php endif; ?>
            <?= isset($errores[$idCampo]) ? '<span class="campo__ayuda">' . e($errores[$idCampo]) . '</span>' : '' ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Motivo de consulta -->
  <section class="seccion">
    <h2 class="seccion__titulo"><?= iconoRelleno('mensaje') ?> Motivo de consulta</h2>
    <div class="seccion__cuerpo rejilla">
      <div class="campo col-12">
        <label class="campo__etiqueta" for="motivo_consulta">Motivo de consulta / observaciones iniciales</label>
        <div class="campo__control campo__control--area">
          <textarea id="motivo_consulta" name="motivo_consulta" rows="2" data-nota-clinica="Motivo de consulta" data-nota-bloques="<?= e($extras['_bloques']['motivo_consulta'] ?? '') ?>"><?= $valorCampo('motivo_consulta') ?></textarea>
        </div>
      </div>
    </div>
  </section>

  <div class="pie-formulario">
    <div class="pie-formulario__legal">
      <label class="casilla"<?= isset($errores['confirmacion']) ? ' style="color: var(--peligro)"' : '' ?>>
        <input type="checkbox" name="confirmacion" id="confirmacion" required>
        Confirmo que los datos fueron proporcionados por el paciente o su representante
      </label>
      <span class="nota-privacidad"><?= icono('candado') ?> <a href="<?= URL_BASE ?>/aviso-privacidad.php?institucion=<?= (int) institucionId() ?>" target="_blank" rel="noopener">Aviso de privacidad</a> y control de acceso</span>
    </div>
    <div class="pie-formulario__acciones">
      <a class="boton boton--fantasma" href="<?= URL_BASE ?><?= $original ? '/pacientes/ver.php?id=' . $idEdicion : '/pacientes/' ?>">Cancelar</a>
      <button type="submit" class="boton boton--secundario"><?= $original ? 'Guardar cambios' : 'Guardar paciente' ?></button>
    </div>
  </div>

</form>

<?php require __DIR__ . '/../app/vistas/componentes/dictado.php'; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
