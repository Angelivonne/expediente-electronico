<?php
/**
 * Formulario completo para cada nota o documento clínico de la NOM-004-SSA3-2012.
 * El tipo de documento se elige desde el selector lateral de la ficha del paciente.
 * Envía a pacientes/historial.php (origen=completo); si algo falla, regresa aquí con lo escrito y el
 * mensaje de cada campo. Las entradas son inmutables una vez guardadas (NOM-004 5.10).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
require_once __DIR__ . '/../app/vistas/componentes/bloques_nota.php';
exigirPermiso('historial');

$pacienteId = (int) ($_GET['paciente'] ?? 0);
$consulta = db()->prepare('SELECT id, folio, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, alergias
                             FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
$consulta->execute([$pacienteId, institucionId()]);
$p = $consulta->fetch();
if (!$p) {
    mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
    redirigir('/pacientes/');
}
$nombrePaciente = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
$tipo = is_string($_GET['tipo'] ?? null) && isset(TIPOS_REGISTRO[$_GET['tipo']]) ? $_GET['tipo'] : '';
$info = $tipo ? TIPOS_REGISTRO[$tipo] : null;
if (!$info) { redirigir('/pacientes/ver.php?id=' . $pacienteId . '#historial'); }

// Lo escrito antes de un error de validación (lo deja pacientes/historial.php en la sesión)
$borrador = $_SESSION['borrador_registro'] ?? null;
unset($_SESSION['borrador_registro']);
$datos = $errores = [];
if ($borrador && $borrador['paciente'] === $pacienteId && $borrador['tipo'] === $tipo) {
    $datos   = (array) $borrador['datos'];
    $errores = (array) $borrador['errores'];
}

// Cuerpo de la nota con el motor de bloques: mismas secciones que el documento de la NOM-004.
// El editor rellena el campo oculto "cuerpo" y se guarda dentro de la entrada (solo texto).
$seccionesCuerpo = [
    'consulta'        => ['Padecimiento actual', 'Exploración física', 'Resultados', 'Diagnóstico', 'Evolución', 'Plan y tratamiento', 'Pronóstico'],
    'urgencias'       => ['Interrogatorio', 'Exploración física', 'Estado mental', 'Resultados', 'Diagnóstico', 'Tratamiento', 'Pronóstico', 'Destino'],
    'interconsulta'   => ['Motivo de la interconsulta', 'Resumen clínico', 'Criterios diagnósticos', 'Plan de estudios', 'Sugerencias', 'Pronóstico'],
    'referencia'      => ['Motivo de envío', 'Resumen clínico', 'Impresión diagnóstica', 'Terapéutica empleada'],
    'egreso'          => ['Diagnósticos finales', 'Resumen de la evolución', 'Manejo durante la estancia', 'Problemas pendientes', 'Plan', 'Recomendaciones', 'Pronóstico'],
    'receta'          => ['Indicaciones'],
    'estudio'         => ['Resultado', 'Valores fuera de rango', 'Interpretación'],
    'hospitalizacion' => ['Diagnóstico de ingreso', 'Evolución', 'Diagnóstico de egreso', 'Resumen de la estancia'],
    'consentimiento'  => ['Acto autorizado', 'En qué consiste', 'Riesgos', 'Beneficios', 'Alternativas'],
    'incapacidad'     => ['Motivo', 'Observaciones'],
    'preoperatoria'   => ['Plan quirúrgico', 'Cuidados preoperatorios'],
    'transoperatoria' => ['Hallazgos', 'Técnica quirúrgica', 'Complicaciones', 'Estado final'],
    'postoperatoria'  => ['Evolución postoperatoria', 'Plan', 'Complicaciones'],
    'enfermeria'      => ['Valoración', 'Cuidados realizados', 'Medicamentos administrados', 'Observaciones'],
    'resumen_clinico' => ['Padecimiento actual', 'Diagnóstico', 'Tratamiento', 'Evolución', 'Pronóstico', 'Estudios'],
];
$cuerpoBloques = [];
foreach ($seccionesCuerpo[$tipo] ?? ['Nota'] as $tituloSeccion) {
    $cuerpoBloques[] = ['t' => 'h2', 'x' => $tituloSeccion];
    $cuerpoBloques[] = ['t' => 'p', 'x' => ''];
}
$cuerpoPrevio = sanearBloquesNota($datos['cuerpo'] ?? []);
if ($cuerpoPrevio) {
    $cuerpoBloques = $cuerpoPrevio;
}
$jsonCuerpo = json_encode($cuerpoBloques, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// Quien firma la entrada (NOM-004 5.10)
$autor = db()->prepare('SELECT nombre, cedula_profesional FROM usuarios WHERE id = ?');
$autor->execute([usuarioActual()['id']]);
$autor = $autor->fetch();

// Receta: institución y establecimiento de la última receta de quien prescribe, para no reescribirlos
$prescriptorPrevio = [];
if ($tipo === 'receta') {
    $ultima = db()->prepare("SELECT detalle FROM historial_clinico WHERE autor_id = ? AND tipo = 'receta' ORDER BY id DESC LIMIT 1");
    $ultima->execute([usuarioActual()['id']]);
    $prescriptorPrevio = (json_decode((string) $ultima->fetchColumn(), true) ?: [])['prescriptor'] ?? [];
}
$establecimientoConfig = (string) config('nombre_establecimiento');
$viasAdministracion = viasAdministracionDisponibles();

// Estudios/archivos que ya pertenecen a este paciente; la nota puede vincularse a uno sin duplicar el archivo.
$archivosEstudio = [];
if ($tipo === 'estudio') {
    require_once __DIR__ . '/../app/servicios/clinica/imagenes.php';
    foreach (estudiosDelPaciente($pacienteId) as $estudioArchivo) {
        $archivosEstudio[$estudioArchivo['uid']] = trim(($estudioArchivo['fecha'] ?? '') . ' · ' . (MODALIDADES[$estudioArchivo['modalidad']] ?? $estudioArchivo['modalidad']) . ' · ' . ($estudioArchivo['descripcion'] ?: 'Estudio adjunto') . ' · ' . $estudioArchivo['archivos'] . ' archivo(s)');
    }
}

/* ---------- Ayudas para pintar campos (mismo marcado que pacientes/registro.php) ---------- */
$valor = function (string $campo, string $porDefecto = '') use ($datos): string {
    $v = preg_match('/^(\w+)\[(\w+)\]$/', $campo, $m) ? ($datos[$m[1]][$m[2]] ?? null) : ($datos[$campo] ?? null);
    return is_string($v) ? $v : ($datos ? '' : $porDefecto);
};
$idDe  = fn (string $nombre): string => 'r-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $nombre), '-');
$aviso = fn (string $clave): string => isset($errores[$clave]) ? '<span class="campo__ayuda">' . e($errores[$clave]) . '</span>' : '';
$marca = fn (bool $req): string => $req ? ' <span class="requerido">*</span>' : '';

/**
 * Campo de una línea. Opciones: col, max, req, tipo, icono, defecto, valor (renglones), clave (error),
 * fila (true en renglones repetibles: sin id, la etiqueta se asocia con aria-label), attrs, campoAttrs.
 */
$campo = function (string $nombre, string $etiqueta, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores): string {
    $o += ['col' => 6, 'max' => 255, 'req' => false, 'tipo' => 'text', 'icono' => null, 'defecto' => '', 'valor' => null,
           'clave' => $nombre, 'fila' => false, 'attrs' => '', 'campoAttrs' => '', 'unidad' => null];
    $id  = $o['fila'] ? '' : $idDe($nombre);
    $val = $o['valor'] ?? $valor($nombre, $o['defecto']);
    $req = $o['req'] ? ($o['fila'] ? ' data-requerido-fila' : ' required') : '';
    if ($o['tipo'] === 'hidden') {
        return '<input type="hidden" name="' . e($nombre) . '" value="' . e($val) . '"' . $o['attrs'] . '>';
    }
    $control = 'campo__control' . ($o['unidad'] !== null ? ' campo__control--unidad' : '');
    return '<div class="campo col-' . $o['col'] . (isset($errores[$o['clave']]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
         . ($o['fila'] ? '<span class="campo__etiqueta">' : '<label class="campo__etiqueta" for="' . $id . '">') . e($etiqueta) . $marca($o['req'])
         . ($o['fila'] ? '</span>' : '</label>')
         . '<div class="' . $control . '">' . ($o['icono'] ? icono($o['icono']) : '')
         . '<input type="' . $o['tipo'] . '"' . ($id ? ' id="' . $id . '"' : ' aria-label="' . e($etiqueta) . '"') . ' name="' . e($nombre) . '" value="' . e($val) . '"'
         . ($o['tipo'] === 'text' ? ' maxlength="' . $o['max'] . '"' : '') . $req . ' autocomplete="off"' . $o['attrs'] . '>'
         . ($o['unidad'] !== null ? '<span>' . e($o['unidad']) . '</span>' : '') . '</div>'
         . $aviso($o['clave']) . '</div>';
};

/** Área de texto (caja completa). Opciones: col, max, req, filas, campoAttrs. */
$area = function (string $nombre, string $etiqueta, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores, $datos): string {
    $o += ['col' => 12, 'max' => 5000, 'req' => false, 'filas' => 3, 'campoAttrs' => ''];
    $id = $idDe($nombre);
    $nb = is_string($datos['nota_bloques'][$nombre] ?? null) ? $datos['nota_bloques'][$nombre] : '';
    return '<div class="campo col-' . $o['col'] . (isset($errores[$nombre]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
         . '<label class="campo__etiqueta" for="' . $id . '">' . e($etiqueta) . $marca($o['req']) . '</label>'
         . '<div class="campo__control campo__control--area"><textarea id="' . $id . '" name="' . e($nombre) . '" rows="' . $o['filas'] . '" maxlength="' . $o['max'] . '" data-nota-clinica="' . e($etiqueta) . '"' . ($nb !== '' ? ' data-nota-bloques="' . e($nb) . '"' : '')
         . ($o['req'] ? ' required' : '') . '>' . e($valor($nombre)) . '</textarea></div>' . $aviso($nombre) . '</div>';
};

/** Lista de opciones (assets/js/espacio.js la convierte en el selector de la app). Opciones: col, req, valor, clave, fila, campoAttrs. */
$lista = function (string $nombre, string $etiqueta, array $opciones, array $o = []) use ($valor, $idDe, $aviso, $marca, $errores): string {
    $o += ['col' => 6, 'req' => false, 'valor' => null, 'clave' => $nombre, 'fila' => false, 'campoAttrs' => '', 'defecto' => ''];
    $id  = $o['fila'] ? '' : $idDe($nombre);
    $val = $o['valor'] ?? $valor($nombre, $o['defecto']);
    $html = '<div class="campo col-' . $o['col'] . (isset($errores[$o['clave']]) ? ' campo--error' : '') . '"' . $o['campoAttrs'] . '>'
          . ($o['fila'] ? '<span class="campo__etiqueta">' : '<label class="campo__etiqueta" for="' . $id . '">') . e($etiqueta) . $marca($o['req'])
          . ($o['fila'] ? '</span>' : '</label>')
          . '<div class="campo__control campo__control--select"><select' . ($id ? ' id="' . $id . '"' : ' aria-label="' . e($etiqueta) . '"') . ' name="' . e($nombre) . '"'
          . ($o['req'] ? ($o['fila'] ? ' data-requerido-fila' : ' required') : '') . '><option value="">Selecciona</option>';
    foreach ($opciones as $claveOpcion => $opcion) {
        $valorOpcion = is_int($claveOpcion) ? $opcion : (string) $claveOpcion;
        $textoOpcion = is_int($claveOpcion) ? $opcion : (string) $opcion;
        $html .= '<option value="' . e($valorOpcion) . '"' . ($val === $valorOpcion ? ' selected' : '') . '>' . e($textoOpcion) . '</option>';
    }
    return $html . '</select></div>' . $aviso($o['clave']) . '</div>';
};

/** Fecha y hora de la nota (NOM-004 5.10). */
$fechaHora = fn (string $etiqueta = 'Fecha'): string =>
    $campo('fecha', $etiqueta, ['col' => 3, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario', 'defecto' => date('Y-m-d'), 'attrs' => ' max="' . date('Y-m-d') . '"'])
  . $campo('hora', 'Hora', ['col' => 3, 'tipo' => 'time', 'icono' => 'reloj', 'defecto' => date('H:i')]);

/** Signos vitales (con el IMC calculado en el navegador a partir de peso y talla). */
$signos = function () use ($campo, $valor, $datos): string {
    $cortas = ['fc' => 'Frec. cardiaca', 'fr' => 'Frec. respiratoria', 'spo2' => 'Saturación O₂', 'glucosa' => 'Glucosa'];
    $html = '<p class="registro-clinico__subtitulo">Signos vitales</p><div class="rejilla">';
    foreach (SIGNOS_VITALES as $clave => [$etiqueta, , $unidad]) {
        $html .= $campo("signos[$clave]", $cortas[$clave] ?? $etiqueta,
            ['col' => 2, 'max' => 6, 'unidad' => $unidad, 'attrs' => ' inputmode="decimal" data-signo="' . $clave . '"']);
    }
    $imc = imcDesdeSignos(array_map('floatval', array_filter((array) ($datos['signos'] ?? []), 'is_numeric')));
    $html .= '<div class="campo col-2"><label class="campo__etiqueta" for="r-imc">IMC</label><div class="campo__control campo__control--unidad">'
           . '<input id="r-imc" value="' . ($imc !== null ? e(number_format($imc, 1)) : '') . '" readonly tabindex="-1" data-imc><span>kg/m²</span></div></div>';
    return $html . '</div>';
};

/** Renglones de diagnóstico con código CIE-10 opcional. */
$diagnosticos = function (string $etiqueta = 'Diagnóstico', bool $req = false, string $agregar = 'Otro diagnóstico') use ($campo, $datos): string {
    $filas = is_array($datos['diagnostico'] ?? null) ? array_values($datos['diagnostico']) : [''];
    $cies  = is_array($datos['cie10'] ?? null) ? array_values($datos['cie10']) : [];
    $fila = fn (int $i, string $d, string $c, bool $plantilla = false) =>
        '<div class="registro-clinico__fila registro-clinico__fila--linea" data-fila>'
      . $campo('cie10_id[]', 'ID CIE-10', ['tipo' => 'hidden', 'fila' => true, 'valor' => (string) (($datos['cie10_id'][$i] ?? '')), 'clave' => '', 'attrs' => ' data-cie-id'])
      . '<div class="rejilla">'
      . $campo('diagnostico[]', $etiqueta, ['col' => 8, 'req' => $req, 'fila' => true, 'valor' => $d, 'clave' => $plantilla ? '' : "diagnostico[$i]", 'icono' => 'buscar', 'attrs' => ' placeholder="Buscar en CIE-10" data-catalogo="cie10" role="combobox" aria-expanded="false" aria-autocomplete="list"'])
      . $campo('cie10[]', 'CIE-10', ['col' => 3, 'max' => 10, 'fila' => true, 'valor' => $c, 'clave' => $plantilla ? '' : "cie10[$i]", 'attrs' => ' data-cie spellcheck="false"'])
      . '</div><button type="button" class="registro-clinico__quitar" data-fila-quitar aria-label="Quitar diagnóstico" title="Quitar">' . icono('cerrar') . '</button></div>';
    $html = '<div class="registro-clinico__filas" data-filas="diagnosticos"' . ($req ? ' data-filas-requeridas' : '') . '>';
    foreach ($filas as $i => $d) {
        $html .= $fila($i, (string) $d, (string) ($cies[$i] ?? ''));
    }
    return $html . '</div><template data-plantilla="diagnosticos">' . $fila(0, '', '', true) . '</template>'
         . '<button type="button" class="historial__otro" data-fila-agregar="diagnosticos">' . icono('agregar') . ' ' . e($agregar) . '</button>';
};

/** Renglones de medicamentos de la receta. */
$medicamentos = function () use ($campo, $lista, $datos, $viasAdministracion): string {
    $col = fn (string $c) => is_array($datos[$c] ?? null) ? array_values($datos[$c]) : [];
    $nombres = $col('medicamento') ?: [''];
    $fila = function (int $i, bool $plantilla = false) use ($campo, $lista, $col, $viasAdministracion): string {
        $v = fn (string $c) => $plantilla ? '' : (string) ($col($c)[$i] ?? '');
        $k = fn (string $c) => $plantilla ? '' : $c . "[$i]";
        return '<div class="registro-clinico__fila" data-fila>'
             . '<div class="registro-clinico__fila-cabecera"><span>Medicamento <span data-fila-numero>' . ($i + 1) . '</span></span>'
             . '<button type="button" class="registro-clinico__quitar" data-fila-quitar aria-label="Quitar medicamento" title="Quitar">' . icono('cerrar') . '</button></div>'
             . $campo('medicamento_id[]', '', ['tipo' => 'hidden', 'fila' => true, 'valor' => $v('medicamento_id'), 'clave' => '', 'attrs' => ' data-medicamento-id'])
             . '<div class="rejilla">'
              . $campo('medicamento[]', 'Medicamento', ['col' => 5, 'max' => 160, 'req' => true, 'fila' => true, 'valor' => $v('medicamento'), 'clave' => $k('medicamento'), 'icono' => 'buscar', 'attrs' => ' placeholder="Buscar medicamento" data-catalogo="medicamentos" role="combobox" aria-expanded="false" aria-autocomplete="list"'])
              . $campo('presentacion[]', 'Presentación', ['col' => 4, 'max' => 120, 'req' => true, 'fila' => true, 'valor' => $v('presentacion'), 'clave' => $k('presentacion')])
             . $campo('cantidad[]', 'Cantidad a surtir', ['col' => 3, 'max' => 60, 'fila' => true, 'valor' => $v('cantidad'), 'clave' => $k('cantidad')])
             . $campo('dosis[]', 'Dosis', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('dosis'), 'clave' => $k('dosis')])
             . $lista('via[]', 'Vía', $viasAdministracion, ['col' => 3, 'req' => true, 'fila' => true, 'valor' => $v('via'), 'clave' => $k('via')])
             . $campo('periodicidad[]', 'Frecuencia', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('periodicidad'), 'clave' => $k('periodicidad')])
             . $campo('duracion[]', 'Duración', ['col' => 3, 'max' => 60, 'req' => true, 'fila' => true, 'valor' => $v('duracion'), 'clave' => $k('duracion')])
             . $campo('fecha_inicio[]', 'Inicio del tratamiento', ['col' => 3, 'tipo' => 'date', 'req' => true, 'fila' => true, 'valor' => $v('fecha_inicio') ?: date('Y-m-d'), 'clave' => $k('fecha_inicio')])
             . $campo('fecha_fin[]', 'Fin previsto', ['col' => 3, 'tipo' => 'date', 'fila' => true, 'valor' => $v('fecha_fin'), 'clave' => $k('fecha_fin')])
             . $lista('estado_tratamiento[]', 'Estado', ['activo' => 'Activo', 'suspendido' => 'Suspendido', 'finalizado' => 'Finalizado'], ['col' => 3, 'req' => true, 'fila' => true, 'valor' => $v('estado_tratamiento') ?: 'activo', 'clave' => $k('estado_tratamiento')])
             . '</div></div>';
    };
    $html = '<div class="registro-clinico__filas" data-filas="medicamentos" data-filas-requeridas>';
    foreach (array_keys($nombres) as $i) {
        $html .= $fila($i);
    }
    return $html . '</div><template data-plantilla="medicamentos">' . $fila(0, true) . '</template>'
         . '<button type="button" class="historial__otro" data-fila-agregar="medicamentos">' . icono('agregar') . ' Otro medicamento</button>';
};

/** Casilla de verificación con el estilo del registro. */
$casilla = fn (string $nombre, string $texto, bool $req = false): string =>
    '<label class="casilla' . (isset($errores[$nombre]) ? ' casilla--error' : '') . '"><input type="checkbox" name="' . $nombre . '" value="1"'
  . (!empty($datos[$nombre]) ? ' checked' : '') . ($req ? ' required' : '') . '> ' . e($texto) . '</label>' . $aviso($nombre);

$seccion = fn (string $icono, string $titulo): string =>
    '<h2 class="seccion__titulo">' . iconoRelleno($icono) . ' ' . e($titulo) . '</h2>';

$seccionActiva  = 'pacientes';
$pacienteActual = $pacienteId;
$tituloPagina   = ($info ? $info['documento'] : 'Nuevo registro clínico') . ' · ' . $nombrePaciente;
$migas = [
    ['texto' => 'Pacientes', 'url' => '/pacientes/'],
    ['texto' => $nombrePaciente, 'url' => '/pacientes/ver.php?id=' . $pacienteId],
    ['texto' => $info ? $info['documento'] : 'Nuevo registro'],
];
$notificacion = tomarFlash();
$urlTipo = fn (string $t): string => URL_BASE . '/pacientes/nuevo_registro.php?paciente=' . $pacienteId . '&amp;tipo=' . $t;

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<div class="pagina__cabecera registro-clinico__cabecera">
  <span class="pagina__meta"><?= iconoRelleno($info['relleno'] ?? 'documento') ?> <?= e($p['folio']) ?> · <?= e($nombrePaciente) ?></span>
  <h1 class="pagina__titulo"><?= e($info ? $info['documento'] : 'Nuevo registro clínico') ?></h1>
  <p class="registro-clinico__paciente">
    <span><?= edad($p['fecha_nacimiento']) ?> años · <?= e(SEXOS[$p['sexo']] ?? '') ?></span>
    <span class="<?= sinAlergiasRegistradas($p['alergias']) ? '' : 'registro-clinico__alergias' ?>">Alergias: <?= e($p['alergias'] ?: 'no registradas') ?></span>
    <?php if ($info): ?><span><?= e($info['norma']) ?></span><?php endif; ?>
  </p>
  <button type="button" class="dictado-mostrar" data-dictado-mostrar title="<?= $tipo === 'receta' ? 'Llenar la receta por voz' : 'Llenar la nota por voz' ?> (Alt+Shift+D)"><?= icono('microfono') ?> <?= $tipo === 'receta' ? 'Dictar receta' : 'Dictar nota' ?> <kbd class="tecla">Alt ⇧ D</kbd></button>
</div>


  <form method="post" action="<?= URL_BASE ?>/pacientes/historial.php" id="registro-clinico" class="historial__form registro-clinico" data-registro-clinico data-paciente-nombre="<?= e($nombrePaciente) ?>" data-borrador="registro-<?= $pacienteId ?>-<?= e($tipo) ?>" novalidate>
    <?= campoCsrf() ?>
    <?= campoEnvio() ?>
    <input type="hidden" name="paciente_id" value="<?= $pacienteId ?>">
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <input type="hidden" name="origen" value="completo">

    <!-- Cuerpo de la nota con el mismo editor por bloques de las notas (espacio.js) -->
    <section class="notas-seccion registro-clinico__cuerpo" aria-label="Cuerpo de la nota">
      <div class="notas-seccion__hoja" data-hoja-seccion data-editable="1" data-version="0">
        <script type="application/json" id="editor-datos"><?= $jsonCuerpo ?></script>
        <div class="editor" data-editor-bloques data-editor-formulario="1" aria-label="Cuerpo de la nota"></div>
      </div>
      <input type="hidden" name="cuerpo" id="registro-cuerpo" value="">
    </section>

    <?php switch ($tipo):
      case 'consulta': ?>
      <section class="seccion">
        <?= $seccion('mensaje', 'Motivo de consulta') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo', 'Motivo de consulta', ['req' => true, 'max' => 200]) ?>
          <?= $lista('modalidad_consulta', 'Tipo de atención', array_values(MODALIDADES_CONSULTA), ['req' => true, 'defecto' => MODALIDADES_CONSULTA['general']]) ?>
          <?= $fechaHora() ?>
          <?= $area('padecimiento', 'Padecimiento actual') ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Exploración física') ?>
        <div class="seccion__cuerpo">
          <?= $signos() ?>
          <div class="rejilla"><?= $area('exploracion', 'Exploración física') ?></div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultados y diagnóstico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resultados', 'Resultados relevantes de estudios', ['max' => 3000, 'filas' => 2]) ?></div>
          <?= $diagnosticos('Diagnóstico o problema clínico', true, 'Agregar diagnóstico o problema clínico') ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Evolución y plan') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('nota', 'Nota de evolución', ['req' => true]) ?>
          <?= $area('plan', 'Plan y tratamiento', ['col' => 8, 'max' => 3000, 'req' => true]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['col' => 4, 'max' => 200, 'req' => true]) ?>
        </div>
      </section>
    <?php break;

      case 'urgencias': ?>
      <section class="seccion">
        <?= $seccion('reloj', 'Atención') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo', 'Motivo de la atención', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha de atención') ?>
          <?= $area('interrogatorio', 'Resumen del interrogatorio', ['req' => true]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Exploración física') ?>
        <div class="seccion__cuerpo">
          <?= $signos() ?>
          <div class="rejilla">
            <?= $area('exploracion', 'Exploración física', ['req' => true]) ?>
            <?= $area('estado_mental', 'Estado mental', ['max' => 1000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultados y diagnóstico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resultados', 'Resultados relevantes de estudios', ['max' => 3000, 'filas' => 2]) ?></div>
          <?= $diagnosticos('Diagnóstico o problema clínico', true) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Tratamiento y destino') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('plan', 'Tratamiento', ['req' => true, 'max' => 3000]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['max' => 200, 'req' => true]) ?>
          <?= $lista('destino', 'Destino del paciente', DESTINOS_URGENCIAS, ['req' => true]) ?>
        </div>
      </section>
    <?php break;

      case 'interconsulta': ?>
      <section class="seccion">
        <?= $seccion('usuarios', 'Solicitud') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('servicio_consultado', 'Servicio consultado', ['req' => true, 'max' => 120]) ?>
          <?= $fechaHora() ?>
          <?= $campo('servicio_solicitante', 'Servicio que solicita', ['req' => true, 'max' => 120]) ?>
          <?= $area('motivo', 'Motivo de la interconsulta', ['req' => true, 'max' => 2000, 'filas' => 2]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Resumen clínico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('resumen', 'Resumen clínico') ?></div>
          <?= $signos() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Valoración') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('criterios', 'Criterios diagnósticos', ['req' => true, 'max' => 3000]) ?>
            <?= $area('plan_estudios', 'Plan de estudios', ['max' => 3000, 'filas' => 2, 'req' => true]) ?>
            <?= $area('sugerencias', 'Sugerencias diagnósticas y de tratamiento', ['req' => true, 'max' => 3000]) ?>
          </div>
          <?= $diagnosticos() ?>
          <div class="rejilla"><?= $campo('pronostico', 'Pronóstico', ['max' => 200]) ?></div>
        </div>
      </section>
    <?php break;

      case 'referencia': ?>
      <section class="seccion">
        <?= $seccion('casa', 'Envío') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('tipo_envio', 'Tipo de envío', TIPOS_ENVIO, ['req' => true, 'defecto' => 'Referencia']) ?>
          <?= $fechaHora('Fecha de envío') ?>
          <?= $campo('establecimiento', 'Establecimiento que envía', ['max' => 200, 'defecto' => $establecimientoConfig]) ?>
          <?= $campo('destino', 'Establecimiento que recibe', ['req' => true, 'max' => 200]) ?>
          <?= $campo('servicio_destino', 'Servicio que recibe', ['max' => 120]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Motivo y resumen clínico') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('motivo', 'Motivo de envío', ['req' => true, 'max' => 2000, 'filas' => 2]) ?>
            <?= $area('resumen', 'Resumen clínico', ['req' => true]) ?>
          </div>
          <?= $signos() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Impresión diagnóstica y terapéutica') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos('Impresión diagnóstica', true) ?>
          <div class="rejilla"><?= $area('terapeutica', 'Terapéutica empleada', ['max' => 3000]) ?></div>
        </div>
      </section>
    <?php break;

      case 'egreso': ?>
      <section class="seccion">
        <?= $seccion('reabrir', 'Egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('motivo_egreso', 'Motivo del egreso', MOTIVOS_EGRESO, ['req' => true]) ?>
          <?= $fechaHora('Fecha de egreso') ?>
          <?= $campo('ingreso', 'Fecha de ingreso', ['col' => 6, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $campo('servicio', 'Servicio', ['max' => 120]) ?>
          <?= $area('causas_defuncion', 'Causas de la defunción', ['req' => true, 'max' => 2000, 'filas' => 2, 'campoAttrs' => ' data-mostrar-si="motivo_egreso:Defunción"']) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Diagnósticos y evolución') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos('Diagnóstico final', true) ?>
          <div class="rejilla">
            <?= $area('resumen', 'Resumen de la evolución y estado actual', ['req' => true]) ?>
            <?= $area('manejo', 'Manejo durante la estancia', ['max' => 3000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Plan al egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('pendientes', 'Problemas clínicos pendientes', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('plan', 'Plan de manejo y tratamiento', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('recomendaciones', 'Recomendaciones para la vigilancia ambulatoria', ['max' => 3000, 'filas' => 2]) ?>
          <?= $area('factores_riesgo', 'Atención de factores de riesgo', ['max' => 2000, 'filas' => 2, 'col' => 8]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['col' => 4, 'max' => 200]) ?>
        </div>
      </section>
    <?php break;

      case 'incapacidad': ?>
      <section class="seccion">
        <?= $seccion('reloj', 'Incapacidad') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('fecha_inicio', 'Fecha de inicio', ['col' => 4, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $campo('fecha_fin', 'Fecha de fin', ['col' => 4, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $campo('dias', 'Días de incapacidad', ['col' => 4, 'tipo' => 'number', 'min' => '1', 'max' => '365', 'icono' => 'calendario']) ?>
          <?= $lista('tipo_incapacidad', 'Tipo de incapacidad', ['IMSS', 'ISSSTE', 'Seguro Popular / INSABI', 'Particular', 'Otro'], ['req' => true]) ?>
          <?= $fechaHora('Fecha de la nota') ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Diagnóstico y motivo') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('motivo', 'Motivo de la incapacidad', ['req' => true, 'max' => 2000, 'filas' => 2]) ?>
          </div>
          <?= $diagnosticos('Diagnóstico (CIE-10)', false) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Observaciones') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla"><?= $area('observaciones', 'Observaciones', ['max' => 3000, 'filas' => 2]) ?></div>
        </div>
      </section>
    <?php break;

      case 'preoperatoria': ?>
      <section class="seccion">
        <?= $seccion('documento', 'Cirugía programada') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('fecha_cirugia', 'Fecha de la cirugía', ['col' => 4, 'req' => true, 'tipo' => 'date', 'icono' => 'calendario']) ?>
          <?= $campo('tipo_intervencion', 'Tipo de intervención', ['col' => 8, 'req' => true, 'max' => 200]) ?>
          <?= $campo('riesgo_quirurgico', 'Riesgo quirúrgico', ['col' => 4, 'max' => 200]) ?>
          <?= $fechaHora() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Diagnóstico y plan') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos() ?>
          <div class="rejilla"><?= $area('plan_quirurgico', 'Plan quirúrgico', ['req' => true, 'max' => 3000, 'filas' => 3]) ?></div>
          <div class="rejilla"><?= $area('cuidados', 'Cuidados preoperatorios', ['req' => true, 'max' => 3000, 'filas' => 2]) ?></div>
        </div>
      </section>
    <?php break;

      case 'transoperatoria': ?>
      <section class="seccion">
        <?= $seccion('documento', 'Procedimiento realizado') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('procedimiento', 'Procedimiento', ['col' => 8, 'req' => true, 'max' => 300]) ?>
          <?= $campo('inicio', 'Hora de inicio', ['col' => 2, 'max' => 5, 'placeholder' => 'HH:MM']) ?>
          <?= $campo('fin', 'Hora de fin', ['col' => 2, 'max' => 5, 'placeholder' => 'HH:MM']) ?>
          <?= $campo('sangrado', 'Sangrado', ['col' => 4, 'max' => 100]) ?>
          <?= $fechaHora() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Hallazgos y técnica') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos() ?>
          <div class="rejilla">
            <?= $area('hallazgos', 'Hallazgos', ['max' => 5000, 'filas' => 3]) ?>
            <?= $area('tecnica', 'Técnica quirúrgica', ['max' => 5000, 'filas' => 3]) ?>
            <?= $area('complicaciones', 'Complicaciones', ['max' => 3000, 'filas' => 2]) ?>
            <?= $area('estado_final', 'Estado final del paciente', ['max' => 1000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
    <?php break;

      case 'postoperatoria': ?>
      <section class="seccion">
        <?= $seccion('documento', 'Postoperatorio') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('procedimiento', 'Procedimiento', ['col' => 8, 'req' => true, 'max' => 300]) ?>
          <?= $campo('pronostico', 'Pronóstico', ['col' => 4, 'max' => 200]) ?>
          <?= $fechaHora() ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Evolución y plan') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos() ?>
          <div class="rejilla">
            <?= $area('evolucion', 'Evolución postoperatoria', ['req' => true, 'max' => 5000, 'filas' => 3]) ?>
            <?= $area('plan', 'Plan', ['max' => 3000, 'filas' => 2]) ?>
            <?= $area('complicaciones', 'Complicaciones', ['max' => 3000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
    <?php break;

      case 'enfermeria': ?>
      <section class="seccion">
        <?= $seccion('corazon', 'Valoración de enfermería') ?>
        <div class="seccion__cuerpo">
          <?= $signos() ?>
          <div class="rejilla"><?= $area('valoracion', 'Valoración', ['req' => true, 'max' => 5000, 'filas' => 3]) ?></div>
          <div class="rejilla"><?= $area('cuidados', 'Cuidados realizados', ['req' => true, 'max' => 5000, 'filas' => 2]) ?></div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('pildora', 'Medicamentos y observaciones') ?>
        <div class="seccion__cuerpo">
          <?= $diagnosticos() ?>
          <div class="rejilla">
            <?= $area('medicamentos', 'Medicamentos administrados', ['max' => 3000, 'filas' => 2]) ?>
            <?= $area('observaciones', 'Observaciones', ['max' => 3000, 'filas' => 2]) ?>
          </div>
        </div>
      </section>
    <?php break;

      case 'resumen_clinico': ?>
      <section class="seccion">
        <?= $seccion('documento', 'Resumen clínico') ?>
        <div class="seccion__cuerpo">
          <?= $area('padecimiento_actual', 'Padecimiento actual', ['req' => true, 'max' => 5000, 'filas' => 3]) ?>
          <?= $diagnosticos() ?>
          <div class="rejilla">
            <?= $area('tratamiento', 'Tratamientos', ['max' => 3000, 'filas' => 2]) ?>
            <?= $area('evolucion', 'Evolución', ['max' => 5000, 'filas' => 2]) ?>
            <?= $area('pronostico', 'Pronóstico', ['max' => 200, 'filas' => 1]) ?>
            <?= $area('estudios', 'Estudios de laboratorio y gabinete', ['max' => 5000, 'filas' => 2]) ?>
          </div>
          <?= $fechaHora() ?>
        </div>
      </section>
    <?php break;

      case 'receta': ?>
      <section class="seccion">
        <?= $seccion('editar', 'Medicamentos') ?>
        <div class="seccion__cuerpo"><?= $medicamentos() ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('mensaje', 'Indicaciones') ?>
        <div class="seccion__cuerpo rejilla"><?= $area('indicaciones', 'Indicaciones para el paciente', ['max' => 2000]) ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('escudo', 'Quien prescribe') ?>
        <div class="seccion__cuerpo rejilla">
          <div class="campo col-6"><label class="campo__etiqueta" for="r-medico">Médico</label>
            <div class="campo__control"><input id="r-medico" value="<?= e($autor['nombre']) ?>" readonly tabindex="-1"></div></div>
          <?php if ($autor['cedula_profesional']): ?>
            <div class="campo col-6"><label class="campo__etiqueta" for="r-cedula">Cédula profesional</label>
              <div class="campo__control"><input id="r-cedula" value="<?= e($autor['cedula_profesional']) ?>" readonly tabindex="-1"></div></div>
          <?php else: ?>
            <?= $campo('cedula_prescriptor', 'Cédula profesional', ['req' => true, 'max' => 10, 'attrs' => ' inputmode="numeric" pattern="\d{6,10}" data-solo-digitos']) ?>
          <?php endif; ?>
          <?= $campo('institucion', 'Institución que expidió el título', ['req' => true, 'max' => 200, 'defecto' => $prescriptorPrevio['institucion'] ?? '']) ?>
          <?= $campo('establecimiento', 'Establecimiento', ['req' => true, 'max' => 200, 'defecto' => $prescriptorPrevio['establecimiento'] ?? $establecimientoConfig]) ?>
          <?= $campo('domicilio', 'Domicilio del establecimiento', ['max' => 255, 'defecto' => $prescriptorPrevio['domicilio'] ?? '']) ?>
          <?= $fechaHora('Fecha de expedición') ?>
        </div>
      </section>
    <?php break;

      case 'hospitalizacion': ?>
      <section class="seccion">
        <?= $seccion('casa', 'Ingreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('motivo_ingreso', 'Motivo de ingreso', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha de ingreso') ?>
          <?= $campo('servicio', 'Servicio', ['req' => true, 'max' => 120]) ?>
          <?= $campo('cama', 'Cama', ['col' => 3, 'max' => 20]) ?>
          <?= $campo('diagnostico_ingreso', 'Diagnóstico de ingreso', ['col' => 12]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('editar', 'Evolución') ?>
        <div class="seccion__cuerpo rejilla"><?= $area('evolucion', 'Evolución durante la estancia') ?></div>
      </section>
      <section class="seccion">
        <?= $seccion('reabrir', 'Egreso') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('egreso', 'Fecha de egreso', ['col' => 3, 'tipo' => 'date', 'icono' => 'calendario', 'attrs' => ' max="' . date('Y-m-d') . '"']) ?>
          <?= $lista('estado_egreso', 'Estado al egreso', ESTADOS_EGRESO, ['col' => 3]) ?>
          <?= $campo('diagnostico_egreso', 'Diagnóstico de egreso') ?>
          <?= $area('resumen', 'Resumen de la estancia') ?>
        </div>
      </section>
    <?php break;

      case 'estudio': ?>
      <section class="seccion">
        <?= $seccion('tabla', 'Estudio') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('estudio', 'Estudio', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora('Fecha del resultado') ?>
          <?= $lista('categoria', 'Tipo de estudio', CATEGORIAS_ESTUDIO, ['req' => true]) ?>
          <?= $campo('realizado_en', 'Laboratorio o unidad que lo realizó', ['max' => 200]) ?>
          <?= $campo('solicitante', 'Profesional solicitante', ['req' => true, 'max' => 160, 'defecto' => $autor['nombre'] ?? '']) ?>
          <?php if ($archivosEstudio): ?>
            <?= $lista('estudio_uid', 'Archivos asociados del expediente', ['' => 'Sin vincular'] + $archivosEstudio, ['col' => 12, 'valor' => $valor('estudio_uid')]) ?>
          <?php endif; ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('buscar', 'Resultado') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $area('resultado', 'Resultado', ['req' => true, 'filas' => 4]) ?>
          <?= $area('fuera_rango', 'Valores fuera de rango', ['max' => 2000, 'filas' => 2]) ?>
          <?= $area('interpretacion', 'Interpretación', ['max' => 3000]) ?>
        </div>
      </section>
    <?php break;

      case 'consentimiento': ?>
      <section class="seccion">
        <?= $seccion('escudo', 'Acto autorizado') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('procedimiento', 'Procedimiento', ['req' => true, 'max' => 200]) ?>
          <?= $fechaHora() ?>
          <?= $area('descripcion', 'En qué consiste', ['max' => 3000, 'filas' => 2]) ?>
          <?= $campo('lugar', 'Lugar', ['max' => 120]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('lista', 'Riesgos, beneficios y alternativas') ?>
        <div class="seccion__cuerpo">
          <div class="rejilla">
            <?= $area('riesgos', 'Riesgos', ['req' => true, 'max' => 3000]) ?>
            <?= $area('beneficios', 'Beneficios esperados', ['req' => true, 'max' => 3000]) ?>
            <?= $area('alternativas', 'Alternativas', ['max' => 3000, 'filas' => 2]) ?>
          </div>
          <div class="registro-clinico__casillas"><?= $casilla('contingencias', 'Autoriza atender las contingencias y urgencias derivadas del acto autorizado') ?></div>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuario', 'Quien otorga el consentimiento') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $lista('otorga', 'Otorga', OTORGANTES_CONSENTIMIENTO, ['req' => true, 'col' => 4, 'defecto' => 'Paciente']) ?>
          <?= $campo('otorgante', 'Nombre completo', ['col' => 8, 'max' => 160, 'req' => true, 'defecto' => $nombrePaciente]) ?>
          <?= $campo('parentesco', 'Parentesco o relación con el paciente', ['max' => 60, 'req' => true, 'campoAttrs' => ' data-mostrar-si="otorga:!Paciente"']) ?>
          <?= $lista('decision', 'Decisión', ['Acepta', 'No acepta'], ['req' => true]) ?>
        </div>
      </section>
      <section class="seccion">
        <?= $seccion('usuarios', 'Testigos') ?>
        <div class="seccion__cuerpo rejilla">
          <?= $campo('testigo1', 'Testigo 1 · nombre completo', ['req' => true, 'max' => 160]) ?>
          <?= $campo('testigo2', 'Testigo 2 · nombre completo', ['req' => true, 'max' => 160]) ?>
        </div>
      </section>
    <?php break;
    endswitch; ?>

    <div class="pie-formulario">
      <div class="pie-formulario__legal">
        <?php if ($tipo === 'consentimiento'): ?>
          <?= $casilla('explicado', 'Confirmo que expliqué el procedimiento en lenguaje comprensible y resolví las dudas', true) ?>
        <?php endif; ?>
        <span class="nota-privacidad"><?= icono('candado') ?> Firmará <?= e($autor['nombre']) ?><?= $autor['cedula_profesional'] ? ' · cédula ' . e($autor['cedula_profesional']) : '' ?>. Una vez guardado no se puede modificar (NOM-004 5.10).</span>
      </div>
      <div class="pie-formulario__acciones">
        <a class="boton boton--fantasma" href="<?= URL_BASE ?>/pacientes/ver.php?id=<?= $pacienteId ?>#historial">Cancelar</a>
        <button type="submit" class="boton boton--secundario">Guardar en el historial</button>
      </div>
    </div>
  </form>

<?php require __DIR__ . '/../app/vistas/componentes/dictado.php'; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
