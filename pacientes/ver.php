<?php
/**
 * Perfil completo del paciente: datos del expediente, actividad e historial.
 * Se abre al elegir un paciente en la búsqueda o desde su menú.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/vistas/componentes/bloques_nota.php';
exigirPermiso('ver_expediente');

$id = (int) ($_GET['id'] ?? 0);
$consulta = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND institucion_id = ? AND eliminado_en IS NULL');
$consulta->execute([$id, institucionId()]);
$p = $consulta->fetch();

if (!$p) {
    mensajeFlash('error', 'El paciente no existe o fue dado de baja.');
    redirigir('/pacientes/');
}
$extrasPerfil = json_decode((string) ($p['extras'] ?? ''), true) ?: [];
$estadosDiagnostico = $extrasPerfil['_diagnosticos_estado'] ?? [];
foreach (['estado_civil','ocupacion','escolaridad','afiliacion_institucional','tutor_representante','antecedentes_heredofamiliares','antecedentes_personales_patologicos','antecedentes_personales_no_patologicos','inmunizaciones','antecedentes_ginecoobstetricos','habitos_sustancias'] as $campoPerfil) {
    $p[$campoPerfil] = $extrasPerfil['_perfil'][$campoPerfil] ?? null;
}
// Toda consulta del expediente queda registrada (NOM-024: bitácora de accesos)
registrarBitacora('pacientes', $id, 'acceso', 'consulta_expediente');
// Comentarios del equipo sobre este expediente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['accion_comentario'] ?? ''), ['1', 'resolver'], true)) {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a enviar el comentario.');
    } else {
        if (($_POST['accion_comentario'] ?? '') === 'resolver') {
            // Solo se resuelven comentarios de ESTE expediente y de la misma institución
            $idComentario = (int) ($_POST['comentario'] ?? 0);
            $comentarioActual = motorObtener('comentarios', $idComentario);
            if ($comentarioActual && (int) $comentarioActual['registro_id'] === $id && $comentarioActual['entidad'] === 'pacientes') {
                motorActualizar('comentarios', $idComentario, ['resuelto_en' => date('Y-m-d H:i:s')]);
                mensajeFlash('exito', 'Comentario marcado como resuelto.');
            } else {
                mensajeFlash('error', 'Ese comentario no pertenece a este expediente.');
            }
            redirigir('/pacientes/ver.php?id=' . $id);
        }
        $textoComentario = mb_substr(trim((string) ($_POST['texto'] ?? '')), 0, 1000);
        $altaComentario = motorCrear('comentarios', ['entidad' => 'pacientes', 'registro_id' => $id, 'texto' => $textoComentario]);
        if (!empty($altaComentario['ok'])) {
            mensajeFlash('exito', 'Comentario agregado.');
        } else {
            mensajeFlash('error', $altaComentario['errores']['texto'] ?? 'Escribe un comentario.');
        }
    }
    redirigir('/pacientes/ver.php?id=' . $id);
}

// Comentarios de un evento del historial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_comentario_historial'] ?? '') === '1') {
    $idEvento = (int) ($_POST['evento'] ?? 0);
    $pertenece = db()->prepare('SELECT id FROM historial_clinico WHERE id = ? AND paciente_id = ? AND institucion_id = ?');
    $pertenece->execute([$idEvento, $id, institucionId()]);
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a enviar el comentario.');
    } elseif (!$pertenece->fetchColumn()) {
        mensajeFlash('error', 'Ese evento no pertenece a este expediente.');
    } else {
        $alto = motorCrear('comentarios', ['entidad' => 'historial', 'registro_id' => $idEvento, 'texto' => mb_substr(trim((string) ($_POST['texto'] ?? '')), 0, 1000)]);
        mensajeFlash(!empty($alto['ok']) ? 'exito' : 'error', !empty($alto['ok']) ? 'Comentario agregado al evento.' : 'Escribe un comentario.');
    }
    redirigir('/pacientes/ver.php?id=' . $id . '#evento-' . $idEvento);
}
$comentariosExpediente = [];
$autoresComentarios = [];
try {
    $comentariosExpediente = motorListar('comentarios', ['filtro' => ['entidad' => 'pacientes', 'registro_id' => $id], 'limite' => 100])['filas'];
    if ($comentariosExpediente) {
        $idsAutores = array_values(array_filter(array_unique(array_map(fn ($c) => (int) ($c['usuario_id'] ?? 0), $comentariosExpediente))));
        if ($idsAutores) {
            $marcas = implode(',', array_fill(0, count($idsAutores), '?'));
            $consultaAutores = db()->prepare("SELECT id, nombre FROM usuarios WHERE id IN ($marcas)");
            $consultaAutores->execute($idsAutores);
            foreach ($consultaAutores as $autor) {
                $autoresComentarios[(int) $autor['id']] = $autor['nombre'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[ver.php] comentariosExpediente ignorado por error de esquema: ' . $e->getMessage());
}

$nombreCompleto = trim("{$p['nombre']} {$p['primer_apellido']} {$p['segundo_apellido']}");
$iniciales      = mb_strtoupper(mb_substr($p['nombre'], 0, 1) . mb_substr($p['primer_apellido'], 0, 1));
$sexo           = SEXOS[$p['sexo']] ?? '';

// Historial clínico (más reciente primero)
$historial = db()->prepare('SELECT * FROM historial_clinico WHERE paciente_id = ? ORDER BY fecha DESC, id DESC');
$historial->execute([$p['id']]);
$historial = $historial->fetchAll();
$firmasHistorial = [];
if ($historial) {
    try {
        $idsHistorial = array_map(fn ($registro) => (int) $registro['id'], $historial);
        $marcasFirmas = implode(',', array_fill(0, count($idsHistorial), '?'));
        $stmtFirmasHistorial = db()->prepare("SELECT historial_id, usuario_nombre, cedula, huella, firmado_en FROM firmas_clinicas WHERE revocada_en IS NULL AND historial_id IN ($marcasFirmas)");
        $stmtFirmasHistorial->execute($idsHistorial);
        foreach ($stmtFirmasHistorial as $firmaHistorial) {
            $firmasHistorial[(int) $firmaHistorial['historial_id']] = $firmaHistorial;
        }
    } catch (Throwable $e) {
        error_log('[ver.php] firmasHistorial ignorado por error de esquema: ' . $e->getMessage());
    }
}
require_once __DIR__ . '/../app/datos/registros_clinicos.php';
require_once __DIR__ . '/../app/servicios/clinica/firmas_clinicas.php';
$conteoTipos = array_count_values(array_column($historial, 'tipo'));

// Resumen clínico derivado del historial (diagnósticos, tratamiento y hospitalizaciones)
require_once __DIR__ . '/../app/servicios/clinica/resumen_clinico.php';
$diagnosticos      = diagnosticosDelHistorial($historial);
$medicamentos      = tratamientosActivosDelHistorial($historial);
$hospitalizaciones = hospitalizacionesDelHistorial($historial);
$hospitalizado     = array_values(array_filter($hospitalizaciones, fn ($h) => $h['activa']))[0] ?? null;

// Identificación de localización: entidad reconocida en el domicilio
require_once __DIR__ . '/../app/servicios/clinica/mapa.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas_paciente.php';
require_once __DIR__ . '/../app/vistas/componentes/tarjeta_paciente.php';
require_once __DIR__ . '/../app/vistas/componentes/registro_selector_lateral.php';
$paginasPaciente = paginasDePaciente((int) $p['id'], (int) usuarioActual()['id']);
$entidadDomicilio = entidadDesdeTexto($p['municipio_estado']);

/* ---- Actividad del expediente: movimientos de la bitácora por día, últimas 16 semanas ---- */
const SEMANAS = 16;
$hoy    = new DateTimeImmutable('today');
$inicio = $hoy->modify('monday this week')->modify('-' . (SEMANAS - 1) . ' weeks');

$actividad = db()->prepare(
    "SELECT DATE(creado_en) AS dia, COUNT(*) AS total
       FROM bitacora
      WHERE tabla = 'pacientes' AND registro_id = ? AND accion IN ('crear', 'actualizar') AND creado_en >= ?
      GROUP BY DATE(creado_en)"
);
$actividad->execute([$id, $inicio->format('Y-m-d')]);
$porDia = array_column($actividad->fetchAll(), 'total', 'dia');
$maximo = $porDia ? max($porDia) : 0;

/** Nivel de intensidad 0–4 relativo al día con más movimientos. */
$nivel = fn (int $n): int => $n === 0 ? 0 : max(1, (int) ceil($n / $maximo * 4));

$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$etiquetaMes = fn (DateTimeImmutable $d): string => ucfirst($meses[(int) $d->format('n') - 1]);
$mesesLargos = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/* ---- Gráficas (datos reales): historial clínico por mes y movimientos del expediente por año ---- */
$coloresTipo = [
    'consulta' => '#2563eb', 'urgencias' => '#dc2626', 'interconsulta' => '#7c3aed',
    'referencia' => '#d97706', 'egreso' => '#16a34a', 'receta' => '#38bdf8',
    'estudio' => '#a1a1aa', 'hospitalizacion' => '#0d9488', 'consentimiento' => '#db2777',
    'incapacidad' => '#4f46e5', 'preoperatoria' => '#f97316', 'transoperatoria' => '#e11d48',
    'postoperatoria' => '#14b8a6', 'enfermeria' => '#84cc16', 'resumen_clinico' => '#6366f1',
];
$plurales = array_map(static fn ($tipo) => $tipo['plural'], TIPOS_REGISTRO);
$plurales['incapacidad'] = 'Incapacidades';
$primerMes   = $hoy->modify('first day of this month')->modify('-11 months');
$categoriasMes = [];
for ($i = 0; $i < 12; $i++) {
    $mes = $primerMes->modify("+$i months");
    $categoriasMes[$mes->format('Y-m')] = [
        'etiqueta' => $etiquetaMes($mes),
        'titulo'   => ucfirst($mesesLargos[(int) $mes->format('n') - 1]) . ' ' . $mes->format('Y'),
    ];
}
$conteoMes = [];
foreach ($historial as $h) {
    $clave = substr($h['fecha'], 0, 7);
    if (isset($categoriasMes[$clave])) {
        $conteoMes[$h['tipo']][$clave] = ($conteoMes[$h['tipo']][$clave] ?? 0) + 1;
    }
}
$seriesHistorial = [];
foreach ($plurales as $tipo => $nombre) {
    if (!empty($conteoMes[$tipo])) {
        $seriesHistorial[] = ['id' => $tipo, 'nombre' => $nombre, 'color' => $coloresTipo[$tipo] ?? '#64748b',
                              'valores' => array_map(fn ($c) => $conteoMes[$tipo][$c] ?? 0, array_keys($categoriasMes))];
    }
}

$anio = (int) $hoy->format('Y');
$movimientos = db()->prepare(
    "SELECT YEAR(creado_en) AS anio, MONTH(creado_en) AS mes, COUNT(*) AS total
       FROM bitacora
      WHERE tabla = 'pacientes' AND registro_id = ? AND accion IN ('crear', 'actualizar') AND creado_en >= ?
      GROUP BY YEAR(creado_en), MONTH(creado_en)"
);
$movimientos->execute([$id, $primerMes->modify('-12 months')->format('Y-m-d')]);
$porAnioMes = [];
foreach ($movimientos->fetchAll() as $m) {
    $porAnioMes[(int) $m['anio']][(int) $m['mes']] = (int) $m['total'];
}
$mesActual = (int) $hoy->format('n');
$graficas = [
    'barras' => [
        'categorias' => array_values($categoriasMes),
        'series'     => $seriesHistorial,
        'unidad'     => ['evento', 'eventos'],
    ],
    'lineas' => [
        'categorias' => array_values($categoriasMes),
        'series'     => [
            ['id' => 'actual', 'nombre' => 'Últimos 12 meses', 'color' => '#2563eb',
             'valores' => array_map(fn ($c) => $porAnioMes[(int) substr($c, 0, 4)][(int) substr($c, 5, 2)] ?? 0, array_keys($categoriasMes))],
            ['id' => 'anterior', 'nombre' => 'Mismos meses del año anterior', 'color' => '#a1a1aa',
             'valores' => array_map(fn ($c) => $porAnioMes[(int) substr($c, 0, 4) - 1][(int) substr($c, 5, 2)] ?? 0, array_keys($categoriasMes))],
        ],
        'unidad'     => ['movimiento', 'movimientos'],
    ],
];
// Una serie sin ningún movimiento (p. ej. el año anterior de un paciente nuevo) no se dibuja
$graficas['lineas']['series'] = array_values(array_filter($graficas['lineas']['series'], fn ($s) => array_sum(array_filter($s['valores'])) > 0));
$hayMovimientos = !empty($graficas['lineas']['series']);

/* ---- Encabezado del perfil: foto, alertas, resumen y propiedades editables en línea ---- */
$puedeEditar = puede('editar_paciente');
// Portal del paciente: si ya tiene cuenta se muestra en lugar de generar otro código (assets/js/modulos/portal_acceso.js)
$cuentaPortal = null;
if ($puedeEditar) {
    try {
        $c = db()->prepare("SELECT correo, activo, ultimo_acceso, creado_en FROM usuarios WHERE paciente_id = ? AND rol = 'paciente' ORDER BY id LIMIT 1");
        $c->execute([$p['id']]);
        if ($cuenta = $c->fetch()) {
            $cuentaPortal = ['correo' => $cuenta['correo'], 'activa' => (bool) $cuenta['activo'], 'creada' => fechaCorta($cuenta['creado_en']),
                             'ultimo_acceso' => $cuenta['ultimo_acceso'] ? hace($cuenta['ultimo_acceso']) : null];
        }
    } catch (Throwable $e) {
        error_log('[ver.php] cuentaPortal ignorado por error de esquema: ' . $e->getMessage());
    }
}
$fotoUrl = fotoPacienteUrl($p);
$sinAlergias = sinAlergiasRegistradas($p['alergias']);
$consultas = array_values(array_filter($historial, fn ($h) => $h['tipo'] === 'consulta'));
$ultimaConsulta = $consultas[0] ?? null;
$tiposAtencion = ['consulta', 'urgencias', 'interconsulta', 'referencia', 'egreso', 'hospitalizacion'];
$ultimaAtencion = array_values(array_filter($historial, fn ($h) => in_array($h['tipo'], $tiposAtencion, true)))[0] ?? null;

// Signos vitales: los de la consulta más reciente que los tenga y su evolución (para la gráfica)
$signosCampos = [
    'sistolica' => ['TA sistólica', 'mmHg'], 'diastolica' => ['TA diastólica', 'mmHg'], 'fc' => ['Frecuencia cardiaca', 'lpm'],
    'fr' => ['Frecuencia respiratoria', 'rpm'], 'temperatura' => ['Temperatura', '°C'], 'spo2' => ['Saturación', '%'],
    'glucosa' => ['Glucosa', 'mg/dL'], 'peso' => ['Peso', 'kg'], 'talla' => ['Talla', 'cm'],
];
$conSignos = array_values(array_filter($historial, fn ($h) => !empty((json_decode($h['detalle'], true) ?: [])['signos'])));
$signosRecientes = $conSignos ? (json_decode($conSignos[0]['detalle'], true)['signos'] ?? []) : [];
$imc = null;
if (is_numeric($signosRecientes['peso'] ?? null) && is_numeric($signosRecientes['talla'] ?? null) && $signosRecientes['talla'] > 0) {
    $t = $signosRecientes['talla'] > 3 ? $signosRecientes['talla'] / 100 : $signosRecientes['talla'];
    $imc = round($signosRecientes['peso'] / ($t * $t), 1);
}
$evolucion = array_reverse(array_slice($conSignos, 0, 12));
if (count($evolucion) >= 2) {
    $series = [];
    foreach (['sistolica' => ['TA sistólica', '#2563eb'], 'diastolica' => ['TA diastólica', '#38bdf8'], 'fc' => ['Frecuencia cardiaca', '#a1a1aa'],
              'fr' => ['Frecuencia respiratoria', '#0d9488'], 'temperatura' => ['Temperatura', '#f97316'], 'spo2' => ['Saturación', '#7c3aed'],
              'glucosa' => ['Glucosa', '#db2777'], 'peso' => ['Peso', '#65a30d'], 'talla' => ['Talla', '#a16207']] as $clave => [$nombre, $color]) {
        $valores = array_map(fn ($h) => is_numeric($s = (json_decode($h['detalle'], true)['signos'][$clave] ?? null)) ? 0 + $s : null, $evolucion);
        if (array_filter($valores, fn ($v) => $v !== null)) {
            $series[] = ['id' => $clave, 'nombre' => $nombre, 'color' => $color, 'valores' => $valores];
        }
    }
    $graficaSignos = $series ? ['categorias' => array_map(fn ($h) => ['etiqueta' => fechaCorta($h['fecha']), 'titulo' => fechaCorta($h['fecha'])], $evolucion), 'series' => $series, 'unidad' => ['', '']] : null;
}

// Próximas citas del paciente
$proximasDelPaciente = [];
if (puede('agenda')) {
    try {
        $c = db()->prepare("SELECT c.id, c.inicio, c.fin, c.motivo, c.estado, u.nombre AS profesional FROM citas c JOIN usuarios u ON u.id = c.profesional_id
                             WHERE c.paciente_id = ? AND c.fin >= NOW() AND c.estado NOT IN ('cancelada', 'no_asistio') ORDER BY c.inicio LIMIT 4");
        $c->execute([$p['id']]);
        $proximasDelPaciente = $c->fetchAll();
    } catch (Throwable $e) {
        error_log('[ver.php] proximasDelPaciente ignorado por error de esquema: ' . $e->getMessage());
    }
}

// Propiedades del expediente: icono, etiqueta, valor y cómo se edita en línea (null = desde "Editar datos")
$opcionesEntidad = array_map(fn ($k, $v) => ['valor' => $k, 'texto' => $v], array_keys(ENTIDADES), ENTIDADES);
// Propiedades que se agregaron en la tabla de pacientes (columnas nuevas): cada expediente las muestra
$extrasPaciente = json_decode((string) $p['extras'], true) ?: [];
$propiedadesAgregadas = [];
foreach (json_decode((string) config('pacientes_propiedades'), true) ?: [] as $prop) {
    $valor = $extrasPaciente[$prop['clave']] ?? null;
    $texto = is_bool($valor) ? ($valor ? 'Sí' : 'No') : ($valor !== null ? (string) $valor : null);
    if ($prop['tipo'] === 'fecha' && $texto) {
        $texto = fechaCorta($texto);
    }
    $edicion = match ($prop['tipo']) {
        'seleccion' => array_map(fn ($o) => ['valor' => $o, 'texto' => $o], $prop['opciones'] ?? []),
        'casilla'   => [['valor' => '1', 'texto' => 'Sí'], ['valor' => '0', 'texto' => 'No']],
        default     => 'texto',
    };
    $icono = ['numero' => 'numero', 'fecha' => 'calendario', 'seleccion' => 'seleccion', 'casilla' => 'casilla'][$prop['tipo']] ?? 'texto';
    $propiedadesAgregadas[] = ['extra_' . $prop['clave'], $icono, $prop['nombre'], $texto, $edicion];
}

$propiedades = [
    'Identificación' => [
        ['curp', 'huella', 'CURP', $p['curp'], 'texto'],
        ['fecha_nacimiento', 'calendario', 'Fecha de nacimiento', fechaCorta($p['fecha_nacimiento']), null],
        ['sexo', 'usuario', 'Sexo', $sexo, array_map(fn ($k, $v) => ['valor' => $k, 'texto' => $v], array_keys(SEXOS), SEXOS)],
        ['entidad_nacimiento', 'ubicacion', 'Entidad de nacimiento', ENTIDADES[$p['entidad_nacimiento']] ?? null, $opcionesEntidad],
        ['nacionalidad', 'bandera', 'Nacionalidad', $p['nacionalidad'], 'texto'],
        ['estado_civil', 'usuarios', 'Estado civil', $p['estado_civil'] ?? null, 'texto'],
        ['ocupacion', 'texto', 'Ocupación', $p['ocupacion'] ?? null, 'texto'],
        ['escolaridad', 'texto', 'Escolaridad', $p['escolaridad'] ?? null, 'texto'],
        ['afiliacion_institucional', 'clinico', 'Afiliación institucional', $p['afiliacion_institucional'] ?? null, 'texto'],
    ],
    'Contacto' => [
        ['telefono', 'telefono', 'Teléfono', $p['telefono'], 'texto'],
        ['correo', 'correo', 'Correo electrónico', $p['correo'], 'texto'],
        ['domicilio', 'casa', 'Domicilio', $p['domicilio'], 'texto'],
        ['municipio_estado', 'ubicacion', 'Municipio y estado', $p['municipio_estado'], 'texto'],
        ['contacto_emergencia', 'usuarios', 'Contacto de emergencia', $p['contacto_emergencia'], 'texto'],
        ['telefono_emergencia', 'telefono', 'Teléfono de emergencia', $p['telefono_emergencia'], 'texto'],
        ['tutor_representante', 'usuarios', 'Tutor o representante', $p['tutor_representante'] ?? null, 'texto'],
    ],
    'Antecedentes' => [
        ['alergias', 'alerta', 'Alergias', $p['alergias'], 'texto'],
        ['grupo_sanguineo', 'gota', 'Grupo sanguíneo', $p['grupo_sanguineo'], array_map(fn ($g) => ['valor' => $g, 'texto' => $g], GRUPOS_SANGUINEOS)],
        ['padecimientos_cronicos', 'corazon', 'Padecimientos crónicos', $p['padecimientos_cronicos'], 'texto'],
        ['medicamentos_actuales', 'pildora', 'Medicamentos reportados (captura manual)', $p['medicamentos_actuales'], 'texto'],
        ['motivo_consulta', 'clinico', 'Motivo de consulta', $p['motivo_consulta'], 'area'],
        ['antecedentes_heredofamiliares', 'usuarios', 'Heredofamiliares', $p['antecedentes_heredofamiliares'] ?? null, 'area'],
        ['antecedentes_personales_patologicos', 'alerta', 'Personales patológicos', $p['antecedentes_personales_patologicos'] ?? null, 'area'],
        ['antecedentes_personales_no_patologicos', 'texto', 'Personales no patológicos', $p['antecedentes_personales_no_patologicos'] ?? null, 'area'],
        ['inmunizaciones', 'escudo', 'Inmunizaciones', $p['inmunizaciones'] ?? null, 'area'],
        ['antecedentes_ginecoobstetricos', 'calendario', 'Ginecoobstétricos', $p['antecedentes_ginecoobstetricos'] ?? null, 'area'],
        ['habitos_sustancias', 'texto', 'Hábitos y consumo de sustancias', $p['habitos_sustancias'] ?? null, 'area'],
    ],
];

$tituloPagina  = $nombreCompleto;
$claseContenido = 'pagina--expediente';
$mostrarBanner = true;
$seccionActiva  = 'pacientes';
$clavePresencia = 'paciente:' . $p['id'];
$pacienteActual = $p['id'];
$migas          = [['texto' => 'Pacientes', 'url' => '/pacientes/'], ['texto' => $nombreCompleto]];
$notificacion  = tomarFlash();

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<!-- Tarjeta única de acciones del expediente, desplegable desde el borde derecho -->
<div class="tarjeta-lateral" data-tarjeta-lateral>
  <button type="button" class="tarjeta-lateral__asa" aria-label="Abrir acciones del paciente" aria-expanded="false"></button>
  <?= tarjetaPaciente(array_replace($p, ['foto_url' => $fotoUrl]), 'perfil--flotante perfil--lateral') ?>
  <?= selectorRegistroLateral((int) $p['id']) ?>
</div>

<header class="hoja-paciente" id="perfil">
  <div class="hoja-paciente__foto<?= $fotoUrl ? ' hoja-paciente__foto--con' : '' ?>" data-foto-paciente="<?= $p['id'] ?>">
    <?php if ($fotoUrl): ?>
      <img src="<?= e($fotoUrl) ?>" alt="Foto de <?= e($nombreCompleto) ?>">
    <?php else: ?>
      <span class="hoja-paciente__iniciales" aria-hidden="true"><?= e($iniciales) ?></span>
    <?php endif; ?>
    <?php if ($puedeEditar): ?>
      <button type="button" class="hoja-paciente__foto-boton" data-foto-cambiar title="Cambiar foto"><?= icono('camara') ?><span><?= $fotoUrl ? 'Cambiar' : 'Agregar foto' ?></span></button>
      <input type="file" accept="image/jpeg,image/png,image/webp" data-foto-archivo hidden>
    <?php endif; ?>
  </div>
  <div class="hoja-paciente__identidad">
    <h1 class="hoja-paciente__nombre"><?= e($nombreCompleto) ?></h1>
            <?php if (puede('historial')): ?><a class="hoja-paciente__versiones" href="<?= URL_BASE ?>/pacientes/versiones.php?id=<?= (int) $p['id'] ?>"><?= icono('documento') ?> Versiones</a><?php endif; ?>
    <p class="hoja-paciente__linea">
      <span><?= e($p['folio']) ?></span>
      <span><?= edad($p['fecha_nacimiento']) ?> años</span>
      <span><?= e($sexo) ?></span>
      <span>Nació el <?= e(fechaCorta($p['fecha_nacimiento'])) ?></span>
    </p>
    <?php if (!$sinAlergias || $p['padecimientos_cronicos'] || $hospitalizado): ?>
      <ul class="hoja-paciente__alertas">
        <?php if (!$sinAlergias): ?><li class="hoja-paciente__alerta hoja-paciente__alerta--alergia"><?= icono('alerta') ?><span><b>Alergias</b> <?= e($p['alergias']) ?></span></li><?php endif; ?>
        <?php if ($hospitalizado): ?><li class="hoja-paciente__alerta hoja-paciente__alerta--hospital"><?= icono('clinico') ?><span><b>Hospitalizado</b> <?= e($hospitalizado['servicio'] ?: $hospitalizado['motivo']) ?> · desde <?= e(fechaCorta($hospitalizado['ingreso'])) ?></span></li><?php endif; ?>
        <?php if ($p['padecimientos_cronicos']): ?><li class="hoja-paciente__alerta"><?= icono('corazon') ?><span><b>Crónicos</b> <?= e($p['padecimientos_cronicos']) ?></span></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</header>

<!-- Resumen: lo que se busca primero al abrir un expediente -->
<dl class="hoja-paciente__resumen">
  <div><dt>Grupo sanguíneo</dt><dd><?= e($p['grupo_sanguineo'] ?: '—') ?></dd></div>
  <div><dt>Última consulta</dt><dd><?= $ultimaConsulta ? e(fechaCorta($ultimaConsulta['fecha'])) : '—' ?></dd></div>
  <div><dt>Última atención</dt><dd><?= $ultimaAtencion ? '<a href="' . URL_BASE . '/pacientes/evento.php?id=' . (int) $ultimaAtencion['id'] . '">' . e(nombreTipoRegistro($ultimaAtencion['tipo'])) . ' · ' . e(fechaCorta($ultimaAtencion['fecha'])) . '</a>' : '—' ?></dd></div>
  <div><dt>Próxima cita</dt><dd><?= $proximasDelPaciente ? e(cuandoCita($proximasDelPaciente[0]['inicio'])) : '—' ?></dd></div>
  <div><dt>Consultas</dt><dd><?= $conteoTipos['consulta'] ?? 0 ?></dd></div>
  <div><dt>Recetas</dt><dd><?= $conteoTipos['receta'] ?? 0 ?></dd></div>
  <div><dt>Estudios</dt><dd><?= $conteoTipos['estudio'] ?? 0 ?></dd></div>
</dl>

<!-- Cuerpo en dos columnas: datos del expediente a la izquierda; signos, agenda, actividad y gráficas a la derecha -->
<div class="hoja-paciente__cuerpo">
<div class="hoja-paciente__datos">
<?php require_once __DIR__ . '/../app/servicios/clinica/sugerencias.php'; $sugerencias = sugerenciasDelExpediente((int) $p['id']); ?>
<?php if ($sugerencias): ?>
<!-- Sugerencias: reglas fijas sobre el expediente; cada una se puede resolver con la IA en un clic -->
<section class="ficha__seccion sugerencias-ia" data-sugerencias-ia>
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo sugerencias-ia__titulo"><?= icono('chispa') ?> Sugerencias</h2>
    <span class="ficha__fuente"><?= count($sugerencias) ?> · calculadas del expediente</span>
  </div>
  <ul class="sugerencias-ia__lista">
    <?php foreach (array_slice($sugerencias, 0, 6) as $sg): ?>
      <li class="sugerencias-ia__item sugerencias-ia__item--<?= e($sg['nivel']) ?>">
        <span class="sugerencias-ia__punto" aria-hidden="true"></span>
        <span class="sugerencias-ia__texto"><?= e($sg['texto']) ?></span>
        <button type="button" class="sugerencias-ia__accion" data-pedir-ia="<?= e($sg['peticion']) ?>"><?= icono('chispa') ?><span>Resolver con IA</span></button>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<!-- Resumen clínico: se arma solo con lo registrado en el historial -->
<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Diagnósticos</h2>
    <span class="ficha__fuente">Del historial clínico</span>
  </div>
  <?php if ($diagnosticos): ?>
    <ul class="lista-clinica">
      <?php foreach (array_slice($diagnosticos, 0, 12) as $dx): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= $dx['entrada'] ?>">
          <span class="lista-clinica__codigo"><?= e($dx['cie10'] ?: '—') ?></span>
          <span class="lista-clinica__texto"><?= e($dx['descripcion']) ?><small><?= e(nombreTipoRegistro($dx['tipo'])) ?> · <?= e(fechaCorta($dx['ultima'])) ?><?= $dx['veces'] > 1 ? ' · registrado ' . $dx['veces'] . ' veces desde ' . e(fechaCorta($dx['primera'])) : '' ?></small></span>
        </a>
            <?php if ($dx['cie10'] && puede('historial')): $estadoDx = $estadosDiagnostico[$dx['cie10']]['estado'] ?? ''; ?>
              <label class="propiedad-paciente__estado">Estado clínico
                <select data-diagnostico-estado data-endpoint="<?= URL_BASE ?>/api/pacientes/diagnostico_estado.php" data-codigo="<?= e($dx['cie10']) ?>" data-paciente="<?= (int) $p['id'] ?>" data-anterior="<?= e($estadoDx) ?>">
                  <option value=""<?= $estadoDx === '' ? ' selected' : '' ?>>Sin especificar</option>
                  <?php foreach (['activo' => 'Activo', 'resuelto' => 'Resuelto', 'descartado' => 'Descartado'] as $valorEstado => $textoEstado): ?>
                    <option value="<?= $valorEstado ?>"<?= $estadoDx === $valorEstado ? ' selected' : '' ?>><?= $textoEstado ?></option>
                  <?php endforeach; ?>
                </select>
                <span data-diagnostico-estado-aviso aria-live="polite"><?= !empty($estadosDiagnostico[$dx['cie10']]['fecha']) ? 'Actualizado ' . e(fechaCorta($estadosDiagnostico[$dx['cie10']]['fecha'])) . ' por ' . e($estadosDiagnostico[$dx['cie10']]['profesional'] ?? 'personal clínico') : 'Elige el estado confirmado clínicamente.' ?></span>
              </label>
            <?php else: ?>
              <span class="ficha__fuente">Estado clínico: <?= e($estadosDiagnostico[$dx['cie10']]['estado'] ?? 'no documentado') ?></span>
            <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin diagnósticos. Aparecen aquí al registrarlos en una consulta, urgencias, interconsulta o egreso.</p>
  <?php endif; ?>
</section>

<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Tratamiento</h2>
    <span class="ficha__fuente">Se derivan del estado y las fechas de las recetas registradas</span>
  </div>
  <?php if ($medicamentos): ?>
    <ul class="lista-clinica">
      <?php foreach ($medicamentos as $m): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= $m['entrada'] ?>">
          <span class="lista-clinica__icono"><?= icono('pildora') ?></span>
          <span class="lista-clinica__texto"><?= e($m['nombre']) ?><?= !empty($m['presentacion']) ? ' · ' . e($m['presentacion']) : '' ?>
            <small><?= e(implode(' · ', array_filter([$m['dosis'] ?? '', isset($m['via']) ? 'vía ' . mb_strtolower($m['via']) : '', $m['periodicidad'] ?? '', !empty($m['duracion']) ? 'durante ' . $m['duracion'] : '', !empty($m['fecha_inicio']) ? 'inicio ' . fechaCorta($m['fecha_inicio']) : '', !empty($m['fecha_fin']) ? 'fin ' . fechaCorta($m['fecha_fin']) : '']))) ?> · estado activo · receta del <?= e(fechaCorta($m['fecha'])) ?> · <?= e($m['autor']) ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin tratamientos activos documentados.</p>
  <?php endif; ?>
  <?php if (puede('historial')): ?>
    <a class="hoja-paciente__agendar" href="<?= URL_BASE ?>/pacientes/nuevo_registro.php?paciente=<?= $p['id'] ?>&amp;tipo=receta"><?= icono('agregar') ?> Nueva receta</a>
  <?php endif; ?>
</section>

<?php if ($hospitalizaciones): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Hospitalizaciones</h2>
      <span class="ficha__fuente">Notas de hospitalización</span>
    </div>
    <ul class="lista-clinica">
      <?php foreach ($hospitalizaciones as $ho): ?>
        <li><a href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= $ho['entrada'] ?>">
          <span class="lista-clinica__icono<?= $ho['activa'] ? ' lista-clinica__icono--activo' : '' ?>"><?= icono('clinico') ?></span>
          <span class="lista-clinica__texto"><?= e($ho['motivo']) ?>
            <small><?= e($ho['servicio']) ?><?= $ho['cama'] ? ' · cama ' . e($ho['cama']) : '' ?> · <?= e(fechaCorta($ho['ingreso'])) ?> – <?= $ho['egreso'] ? e(fechaCorta($ho['egreso'])) : 'hospitalizado' ?> · <?= $ho['dias'] ?> <?= $ho['dias'] === 1 ? 'día' : 'días' ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<!-- Imágenes médicas: estudios DICOM (TC, RM, RX, US…), fotos clínicas y reportes; se abren en el visor -->
<?php require_once __DIR__ . '/../app/servicios/clinica/imagenes.php'; $estudiosImagen = estudiosDelPaciente((int) $p['id']); ?>
<section class="ficha__seccion" data-imagenes-medicas="<?= (int) $p['id'] ?>" data-puede-subir="<?= puede('editar_paciente') ? '1' : '0' ?>">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Imágenes</h2>
    <span class="ficha__fuente">Tomografía, resonancia, radiografía, fotos y reportes</span>
  </div>
  <?php if ($estudiosImagen): ?>
    <ul class="lista-clinica">
      <?php foreach ($estudiosImagen as $est):
          $nSeries = count($est['series']);
          $archivosAdjuntos = array_merge([], ...array_map(fn ($serie) => $serie['imagenes'], $est['series']));
          $nombresOriginales = array_values(array_unique(array_filter(array_column($archivosAdjuntos, 'nombre'))));
          $nombres = array_values(array_unique(array_filter(array_column($archivosAdjuntos, 'subido_por')))); ?>
        <li><a href="<?= URL_BASE ?>/pacientes/visor.php?paciente=<?= (int) $p['id'] ?>&amp;estudio=<?= rawurlencode($est['uid']) ?>">
          <span class="lista-clinica__codigo"><?= e($est['modalidad']) ?></span>
          <span class="lista-clinica__texto"><?= e($est['descripcion'] ?: (MODALIDADES[$est['modalidad']] ?? 'Estudio')) ?>
            <small><?= e(MODALIDADES[$est['modalidad']] ?? $est['modalidad']) ?> · <?= e(fechaCorta($est['fecha'])) ?><?= $est['formato'] === 'dicom' ? ' · ' . $nSeries . ' ' . ($nSeries === 1 ? 'serie' : 'series') . ' · ' . $est['archivos'] . ' ' . ($est['archivos'] === 1 ? 'imagen' : 'imágenes') : '' ?><?= $nombres ? ' · Subido por ' . e(implode(', ', array_slice($nombres, 0, 3))) : '' ?><?= $nombresOriginales ? ' · Archivo original: ' . e(implode(', ', array_slice($nombresOriginales, 0, 2))) . (count($nombresOriginales) > 2 ? ' y ' . (count($nombresOriginales) - 2) . ' más' : '') : '' ?><?= !empty($est['procedencia']) ? ' · Institución declarada en archivo: ' . e(implode(', ', array_slice($est['procedencia'], 0, 2))) : '' ?></small></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (puede('editar_paciente')): ?>
    <label class="imagenes-subir" data-imagenes-zona>
      <input type="file" multiple accept=".dcm,.dicom,application/dicom,image/jpeg,image/png,image/webp,application/pdf" data-imagenes-archivo hidden>
      <?= icono('agregar') ?><span data-imagenes-texto>Subir estudio: arrastra aquí la carpeta o los archivos DICOM, fotos o PDF</span>
    </label>
  <?php elseif (!$estudiosImagen): ?>
    <p class="ficha__vacio">Sin imágenes en el expediente.</p>
  <?php endif; ?>
</section>

<!-- Propiedades: cada dato se edita en línea (clic en el valor) -->
<?php if ($propiedadesAgregadas) { $propiedades = ['Evaluación' => $propiedadesAgregadas] + $propiedades; } ?>
<?php foreach ($propiedades as $grupo => $filas): ?>
  <section class="ficha__seccion">
    <h2 class="ficha__titulo"><?= e($grupo) ?></h2>
    <dl class="propiedades-paciente" data-propiedades-paciente="<?= $p['id'] ?>" data-entidad="pacientes" data-actualizar="<?= URL_BASE ?>/api/motor/actualizar.php">
      <?php foreach ($filas as [$campo, $ico, $etiqueta, $valor, $edicion]): $editable = $puedeEditar && $edicion !== null; $bloquesDD = $edicion === 'area' ? sanearBloquesNota($extrasPerfil['_bloques'][$campo] ?? null) : []; ?>
        <div class="propiedad-paciente<?= $campo === 'alergias' && !$sinAlergias ? ' propiedad-paciente--alerta' : '' ?>">
          <dt><?= icono($ico) ?><span><?= e($etiqueta) ?></span></dt>
          <dd<?php if ($editable): ?> tabindex="0" role="button" data-campo="<?= $campo ?>" data-edicion="<?= is_array($edicion) ? 'opciones' : $edicion ?>"<?= $edicion === 'area' ? ' data-nota-bloques="' . e($extrasPerfil['_bloques'][$campo] ?? '') . '" data-nota-texto="' . e((string) $valor) . '"' : '' ?><?= $campo === 'medicamentos_actuales' ? ' data-catalogo="medicamentos"' : '' ?><?= is_array($edicion) ? ' data-opciones="' . e(json_encode($edicion, JSON_UNESCAPED_UNICODE)) . '"' : '' ?><?php endif; ?>><?= $bloquesDD ? renderBloquesNota($bloquesDD) : ($valor !== null && $valor !== '' ? e($valor) : '<span class="propiedad-paciente__vacio">' . ($editable ? 'Agregar' : 'Vacío') . '</span>') ?></dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </section>
<?php endforeach; ?>
<p class="hoja-paciente__registro">Registrado el <?= e(fechaCorta($p['creado_en'])) ?><?= $p['actualizado_en'] ? ' · última modificación ' . e(fechaCorta($p['actualizado_en'])) : '' ?> · <?= $p['confirmacion_datos'] ? 'datos confirmados por el paciente' : 'datos sin confirmar por el paciente' ?></p>
<!-- Notas y bases de datos propias del expediente (como en el espacio de trabajo) -->
<section class="ficha__seccion">
  <h2 class="ficha__titulo">Notas y bases de datos</h2>
  <?php if ($paginasPaciente): ?>
    <ul class="paginas-paciente">
      <?php foreach ($paginasPaciente as $pg): ?>
        <li><a href="<?= e($pg['url']) ?>"><?= iconoRelleno(TIPOS_PAGINA[$pg['tipo']]['icono']) ?><span><?= e($pg['titulo']) ?></span><small><?= e(fechaCorta($pg['actualizado_en'] ?? '')) ?></small></a></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin notas ni bases de datos de este paciente.</p>
  <?php endif; ?>
  <div class="paginas-paciente__nuevas">
    <button type="button" data-pagina-paciente="nota" data-paciente="<?= $p['id'] ?>"><?= icono('documento') ?> Nota</button>
    <button type="button" data-pagina-paciente="base_datos" data-paciente="<?= $p['id'] ?>"><?= icono('tabla') ?> Base de datos</button>
    <button type="button" data-pagina-paciente="carpeta" data-paciente="<?= $p['id'] ?>"><?= icono('carpeta') ?> Carpeta</button>
  </div>
</section>

</div>
<aside class="hoja-paciente__lado">
<?php if ($signosRecientes): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Signos vitales</h2>
  <span class="ficha__fuente">Registro del <?= e(fechaCorta($conSignos[0]['fecha'])) ?> · <?= e(nombreTipoRegistro($conSignos[0]['tipo'])) ?></span>
    </div>
    <dl class="signos">
      <?php if (is_numeric($signosRecientes['sistolica'] ?? null) && is_numeric($signosRecientes['diastolica'] ?? null)): ?>
        <div class="signo<?= $signosRecientes['sistolica'] >= 140 || $signosRecientes['diastolica'] >= 90 ? ' signo--fuera' : '' ?>"><dt>Presión arterial</dt><dd><?= e($signosRecientes['sistolica']) ?>/<?= e($signosRecientes['diastolica']) ?> <small>mmHg</small></dd></div>
      <?php endif; ?>
      <?php foreach (['fc', 'fr', 'temperatura', 'spo2', 'glucosa', 'peso', 'talla'] as $clave): if (!is_numeric($signosRecientes[$clave] ?? null)) continue;
        $v = 0 + $signosRecientes[$clave];
        $fuera = match ($clave) { 'fc' => $v > 100 || $v < 50, 'temperatura' => $v >= 38 || $v < 35.5, 'spo2' => $v < 92, 'glucosa' => $v >= 200 || $v < 70, 'fr' => $v > 24 || $v < 10, default => false };
      ?>
        <div class="signo<?= $fuera ? ' signo--fuera' : '' ?>"><dt><?= e($signosCampos[$clave][0]) ?></dt><dd><?= e($v) ?> <small><?= e($signosCampos[$clave][1]) ?></small></dd></div>
      <?php endforeach; ?>
      <?php if ($imc): ?>
        <div class="signo<?= $imc >= 30 || $imc < 18.5 ? ' signo--fuera' : '' ?>"><dt>IMC</dt><dd><?= $imc ?> <small><?= $imc < 18.5 ? 'bajo peso' : ($imc < 25 ? 'normal' : ($imc < 30 ? 'sobrepeso' : 'obesidad')) ?></small></dd></div>
      <?php endif; ?>
    </dl>
    <?php if (!empty($graficaSignos)): ?>
      <script type="application/json" id="grafica-signos"><?= json_encode($graficaSignos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <div class="grafica signos__grafica" data-grafica="signos"><h3 class="grafica__titulo" hidden>Evolución de signos vitales</h3><div class="grafica__lienzo" data-grafica-lienzo></div><ul class="grafica__leyenda" data-grafica-leyenda></ul></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php if (puede('agenda')): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Próximas citas</h2>
      <span class="ficha__fuente">Agenda</span>
    </div>
    <?php if ($proximasDelPaciente): ?>
      <ul class="hoja-paciente__citas">
        <?php foreach ($proximasDelPaciente as $c): ?>
          <li><a href="<?= URL_BASE ?>/agenda/?fecha=<?= substr($c['inicio'], 0, 10) ?>&amp;cita=<?= (int) $c['id'] ?>">
            <span class="hoja-paciente__cita-fecha"><?= e(cuandoCita($c['inicio'])) ?></span>
            <span class="hoja-paciente__cita-texto"><?= e($c['motivo'] ?: 'Cita') ?><small><?= e($c['profesional']) ?> · <?= e(ESTADOS_CITA[$c['estado']] ?? $c['estado']) ?></small></span>
            <?= icono('flecha_derecha') ?>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="ficha__vacio">Sin citas programadas.</p>
    <?php endif; ?>
    <a class="hoja-paciente__agendar" href="<?= URL_BASE ?>/agenda/?nueva=1&amp;paciente_id=<?= $p['id'] ?>"><?= icono('agregar') ?> Agendar cita</a>
  </section>
<?php endif; ?>
<?php if ($entidadDomicilio): ?>
  <section class="ficha__seccion">
    <div class="ficha__titulo-fila">
      <h2 class="ficha__titulo">Ubicación</h2>
      <span class="ficha__fuente">Del domicilio registrado</span>
    </div>
    <div class="ubicacion-paciente">
      <?= mapaMexico([], [], $entidadDomicilio, 'mapa--perfil') ?>
      <p><b><?= e(ENTIDADES[$entidadDomicilio]) ?></b><span><?= e($p['municipio_estado']) ?></span></p>
    </div>
  </section>
<?php endif; ?>

<!-- Actividad del expediente: cada cuadro es un día; la intensidad es el número de movimientos en la bitácora -->
<section class="ficha__seccion">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Actividad</h2>
    <span class="ficha__fuente">Bitácora · 16 semanas</span>
  </div>

  <div class="actividad" data-actividad>
    <div class="actividad__meses" aria-hidden="true">
      <?php for ($s = 0; $s < SEMANAS; $s++):
        // El mes se rotula en la semana que contiene su día 1 (si ese día ya pasó)
        $domingo  = $inicio->modify('+' . ($s * 7 + 6) . ' days');
        $diaUno   = $domingo->modify('-' . ((int) $domingo->format('j') - 1) . ' days');
        $rotulo   = (int) $domingo->format('j') <= 7 && $diaUno <= $hoy ? $etiquetaMes($domingo) : '';
      ?>
        <span><?= $rotulo ?></span>
      <?php endfor; ?>
    </div>

    <div class="actividad__rejilla" role="grid" tabindex="0"
         aria-label="Actividad diaria del expediente. Usa las flechas para recorrer los días.">
      <?php for ($s = 0; $s < SEMANAS; $s++): ?>
        <div class="actividad__semana" role="row" style="--s: <?= $s ?>">
          <?php for ($d = 0; $d < 7; $d++):
            $dia   = $inicio->modify('+' . ($s * 7 + $d) . ' days');
            $clave = $dia->format('Y-m-d');
            $futuro = $dia > $hoy;
            $n     = (int) ($porDia[$clave] ?? 0);
            $texto = ($n === 0 ? 'Sin movimientos' : ($n === 1 ? '1 movimiento' : "$n movimientos")) . ' · ' . fechaCorta($clave);
          ?>
            <span class="actividad__dia nivel-<?= $futuro ? 'futuro' : $nivel($n) ?>" role="gridcell"
                  <?= $futuro ? 'aria-hidden="true"' : 'data-texto="' . e($texto) . '" aria-label="' . e($texto) . '"' ?>></span>
          <?php endfor; ?>
        </div>
      <?php endfor; ?>
    </div>

    <div class="actividad__pie">
      <span><?= $inicio->format('j') . ' ' . $meses[(int) $inicio->format('n') - 1] ?> – <?= $hoy->format('j') . ' ' . $meses[(int) $hoy->format('n') - 1] ?></span>
      <span class="actividad__leyenda" aria-hidden="true">
        menos
        <i class="actividad__dia nivel-0"></i><i class="actividad__dia nivel-1"></i><i class="actividad__dia nivel-2"></i><i class="actividad__dia nivel-3"></i><i class="actividad__dia nivel-4"></i>
        más
      </span>
    </div>
    <div class="actividad__globo" role="status" aria-live="polite" hidden></div>
  </div>
</section>

<!-- Gráficas directamente sobre la página (sin tarjetas); los datos salen del historial y de la bitácora -->
<script type="application/json" id="graficas-datos"><?= json_encode($graficas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="graficas">
<section class="ficha__seccion grafica" data-grafica="barras" aria-labelledby="g-historial">
  <div class="grafica__cabecera">
    <div>
      <h2 class="grafica__titulo" id="g-historial">Registros por mes</h2>
      <span class="ficha__fuente">Historial · 12 meses</span>
    </div>
  </div>
  <?php if ($seriesHistorial): ?>
    <div class="grafica__lienzo" data-grafica-lienzo></div>
    <ul class="grafica__leyenda" data-grafica-leyenda></ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin eventos del historial en los últimos 12 meses.</p>
  <?php endif; ?>
</section>

<section class="ficha__seccion grafica" data-grafica="lineas" aria-labelledby="g-movimientos">
  <div class="grafica__cabecera">
    <div>
      <h2 class="grafica__titulo" id="g-movimientos">Cambios al expediente</h2>
      <span class="ficha__fuente">Bitácora · 12 meses</span>
    </div>
  </div>
  <?php if ($hayMovimientos): ?>
    <div class="grafica__lienzo" data-grafica-lienzo></div>
    <ul class="grafica__leyenda" data-grafica-leyenda></ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin movimientos registrados en los últimos dos años.</p>
  <?php endif; ?>
</section>
</div>
</aside>
</div>

<section class="ficha__seccion" id="historial" data-historial>
  <div class="historial__cabecera">
    <h2 class="ficha__titulo">Historial clínico</h2>
    <button type="button" class="boton boton--sutil historial__imprimir" data-historial-imprimir title="Elegir secciones o documentos para imprimir"><?= icono('pdf') ?><span>Imprimir</span></button>
  </div>
  <div class="historial__filtros">
    <label for="historial-tipo">Tipo de documento</label>
    <select id="historial-tipo" data-historial-tipo aria-label="Filtrar por tipo de documento">
      <option value="">Todos los tipos</option>
      <?php foreach (TIPOS_REGISTRO as $claveTipo => $datosTipo): ?>
        <option value="<?= e($claveTipo) ?>"><?= e($datosTipo['documento']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php
$comentariosHistorial = [];
if ($historial) {
    try {
        $idsEventos = array_map(fn ($h) => (int) $h['id'], $historial);
        $marcasEventos = implode(',', array_fill(0, count($idsEventos), '?'));
        $stmtComentarios = db()->prepare("SELECT c.id, c.registro_id, c.texto, c.creado_en, c.resuelto_en, u.nombre AS autor FROM comentarios c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.institucion_id = ? AND c.entidad = 'historial' AND c.registro_id IN ($marcasEventos) ORDER BY c.creado_en DESC");
        $stmtComentarios->execute(array_merge([institucionId()], $idsEventos));
        foreach ($stmtComentarios->fetchAll() as $comentarioEvento) {
            $comentariosHistorial[(int) $comentarioEvento['registro_id']][] = $comentarioEvento;
        }
    } catch (Throwable $e) {
        error_log('[ver.php] comentariosHistorial ignorado por error de esquema: ' . $e->getMessage());
    }
}
?><?php if ($historial): ?>

    <!-- Historial en filas: fecha, tipo de documento, motivo/resumen y profesional. -->
    <?php
      $diasSemana = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
      $resumenEvento = function (array $h, array $d): array {
          $corto = fn ($t, $n = 170) => mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string) $t)), 0, $n, '…');
          return match ($h['tipo']) {
              'receta' => [$corto(implode(' · ', array_map(fn ($m) => trim(($m['nombre'] ?? '') . ' ' . ($m['dosis'] ?? '') . ' ' . ($m['periodicidad'] ?? '')), $d['medicamentos'] ?? []))), false],
              'estudio' => [$corto(($d['categoria'] ?? '') . ($d['resultado'] ?? '' ? ' · ' . $d['resultado'] : '')), !empty($d['fuera_rango'])],
              'hospitalizacion' => [$corto(implode(' · ', array_filter([$d['servicio'] ?? '', !empty($d['cama']) ? 'cama ' . $d['cama'] : '', !empty($d['egreso']) ? 'egreso ' . fechaCorta($d['egreso']) : 'internado', $d['estado_egreso'] ?? '']))), false],
              'consentimiento' => [$corto(implode(' · ', array_filter([$d['procedimiento'] ?? '', $d['decision'] ?? '', $d['otorga'] ?? '']))), false],
              'referencia' => [$corto(implode(' · ', array_filter([$d['destino'] ?? '', $d['diagnostico'] ?? '']))), false],
              'interconsulta' => [$corto(implode(' · ', array_filter([$d['servicio_consultado'] ?? '', $d['diagnostico'] ?? '']))), false],
              default => [$corto($d['diagnostico'] ?? ($d['nota'] ?? ($d['resumen'] ?? ''))), false],
          };
      };
    ?>
    <div class="bd__marco historial-tabla__marco">
    <table class="bd-tabla historial-tabla acordeon linea-tiempo">
      <colgroup><col style="width:150px"><col style="width:150px"><col style="width:420px"><col style="width:190px"></colgroup>
      <thead>
        <tr class="bd-tabla__grupos"><th scope="colgroup" colspan="2">Atención</th><th scope="colgroup" colspan="2">Documento clínico</th></tr>
        <tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Registro</th><th scope="col">Profesional</th></tr>
      </thead>
      <tbody>
      <?php foreach ($historial as $h):
        $d = json_decode($h['detalle'], true) ?: [];
        $titulo = $h['tipo'] === 'receta' ? 'Receta · ' . implode(', ', array_column($d['medicamentos'] ?? [], 'nombre')) : $h['titulo'];
        [$resumen, $fueraRango] = $resumenEvento($h, $d);
        $diaSemana = $diasSemana[(int) date('w', strtotime($h['fecha']))];
        $firmaEventoHistorial = $firmasHistorial[(int) $h['id']] ?? null;
        $firmaEventoHistorialValida = $firmaEventoHistorial ? firmaRegistroClinicoValida($h, $firmaEventoHistorial) : false;
        ?>
        <tr class="historial__evento acordeon__item linea-tiempo__evento linea-tiempo--<?= e($h['tipo']) ?>" data-tipo="<?= e($h['tipo']) ?>">
          <td><time class="historial-tabla__fecha" datetime="<?= e(str_replace(' ', 'T', $h['fecha'])) ?>"><b><?= e(fechaCorta($h['fecha'])) ?></b><small><?= e($diaSemana) ?> · <?= e(substr($h['fecha'], 11, 5)) ?></small></time></td>
          <td><span class="historial-tabla__tipo"><?= icono(TIPOS_REGISTRO[$h['tipo']]['icono'] ?? 'documento') ?><?= e(TIPOS_REGISTRO[$h['tipo']]['corto'] ?? nombreTipoRegistro($h['tipo'])) ?></span></td>
          <td><button type="button" class="acordeon__cabecera historial-tabla__abrir" aria-expanded="false" aria-controls="evento-<?= (int) $h['id'] ?>">
            <span class="historial-tabla__registro"><b><?= e($titulo) ?></b>
              <?php if ($resumen !== ''): ?><small><?= e($resumen) ?></small><?php endif; ?>
              <?php if ($fueraRango): ?><small class="historial-tabla__alerta">Fuera de rango</small><?php endif; ?>
              <?php if ($firmaEventoHistorial): ?><small>Firmado electrónicamente · <?= e((string) $firmaEventoHistorial['usuario_nombre']) ?> · <?= $firmaEventoHistorialValida ? 'huella válida' : 'huella no coincide' ?></small><?php endif; ?>
            </span><?= icono('flecha_abajo', 'acordeon__chevron') ?>
          </button></td>
          <td title="<?= e($h['autor_nombre']) ?>"><?= e($h['autor_nombre']) ?></td>
        </tr>
        <tr class="acordeon__panel historial-tabla__detalle">
          <td colspan="4"><div class="historial-tabla__detalle-contenido" id="evento-<?= (int) $h['id'] ?>" role="region" aria-label="Detalle de <?= e($titulo) ?>">
              <?php $registro = $h; $modoDetalle = 'compacto'; require __DIR__ . '/../app/vistas/componentes/historial_detalle.php'; ?>
              <div class="historial__pie-evento">
                <p class="historial__autor"><?= e($h['autor_nombre']) ?><?= $h['autor_cedula'] ? ' · Cédula ' . e($h['autor_cedula']) : '' ?> · registrado <?= e(fechaCorta($h['creado_en'] ?? $h['fecha'])) ?></p>
                <a class="historial__completo" href="<?= URL_BASE ?>/pacientes/evento.php?id=<?= (int) $h['id'] ?>"><?= icono('expandir') ?> Ver completo</a>
                <?php if ($h['tipo'] === 'receta'): ?>
                  <a class="historial__completo" style="margin-left:8px;" href="<?= URL_BASE ?>/pacientes/receta_imprimir.php?id=<?= (int) $h['id'] ?>" target="_blank" rel="noopener"><?= icono('documento') ?> Imprimir receta</a>
                <?php endif; ?>
              </div>
              <?php $eventoComentarios = $comentariosHistorial[(int) $h['id']] ?? []; ?>
              <div class="historial-comentarios">
                <p class="historial-comentarios__titulo">Comentarios del evento<?= $eventoComentarios ? ' (' . count($eventoComentarios) . ')' : '' ?></p>
                <?php if ($eventoComentarios): ?>
                  <ul class="historial-comentarios__lista">
                    <?php foreach ($eventoComentarios as $comentarioEvento): ?>
                      <li>
                        <span class="historial-comentarios__texto"><?= e((string) $comentarioEvento['texto']) ?></span>
                        <span class="historial-comentarios__meta"><?= e($comentarioEvento['autor'] ?? 'Equipo') ?> · <?= e(fechaCorta(substr((string) $comentarioEvento['creado_en'], 0, 10))) ?><?= !empty($comentarioEvento['resuelto_en']) ? ' · Resuelto' : '' ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <?php if (puede('historial')): ?>
                  <form method="post" class="historial-comentarios__form">
                    <?= campoCsrf() ?>
                    <input type="hidden" name="accion_comentario_historial" value="1">
                    <input type="hidden" name="evento" value="<?= (int) $h['id'] ?>">
                    <input name="texto" maxlength="1000" required placeholder="Comentario del evento… usa @nombre para mencionar" aria-label="Comentario del evento">
                    <button type="submit" class="boton boton--sutil">Comentar</button>
                  </form>
                <?php endif; ?>
              </div>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?>
    <p class="ficha__vacio">Sin eventos. La primera consulta abre el historial.</p>
  <?php endif; ?>

</section>

<?php
// Opciones de impresión: secciones del expediente y cada documento del historial (assets/js/modulos/impresion_opciones.js)
$partesImpresion = [
    ['clave' => 'ficha', 'texto' => 'Ficha de identificación'],
    ['clave' => 'alergias', 'texto' => 'Alergias'],
    ['clave' => 'antecedentes', 'texto' => 'Antecedentes'],
    ['clave' => 'signos', 'texto' => 'Signos vitales'],
];
foreach (TIPOS_REGISTRO as $tipo => $info) {
    if (!empty($conteoTipos[$tipo])) {
        $partesImpresion[] = ['clave' => $tipo, 'texto' => $tipo === 'receta' ? 'Recetas' : $info['plural'], 'n' => $conteoTipos[$tipo]];
    }
}
?>
<section class="ficha__seccion comentarios no-imprimir" id="comentarios">
  <div class="ficha__titulo-fila">
    <h2 class="ficha__titulo">Comentarios</h2>
    <span class="ficha__fuente"><?= count($comentariosExpediente) ?> del equipo</span>
  </div>
  <?php if ($comentariosExpediente): ?>
    <ul class="comentarios__lista">
      <?php foreach ($comentariosExpediente as $comentario): ?>
        <li class="comentarios__item<?= !empty($comentario['resuelto_en']) ? ' comentarios__item--resuelto' : '' ?>">
          <p class="comentarios__texto"><?= nl2br(e((string) $comentario['texto'])) ?></p>
          <p class="comentarios__meta"><?= e($autoresComentarios[(int) $comentario['usuario_id']] ?? 'Usuario') ?> · <?= e(fechaCorta(substr((string) $comentario['creado_en'], 0, 10))) ?> <?= e(hora12((string) $comentario['creado_en'])) ?><?= !empty($comentario['resuelto_en']) ? ' · Resuelto' : '' ?></p>
          <?php if (empty($comentario['resuelto_en'])): ?>
            <form method="post" class="comentarios__resolver">
              <?= campoCsrf() ?>
              <input type="hidden" name="accion_comentario" value="resolver">
              <input type="hidden" name="comentario" value="<?= (int) $comentario['id'] ?>">
              <button type="submit" class="comentarios__resolver-boton">Marcar como resuelto</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ficha__vacio">Sin comentarios. Se usan para pendientes y avisos del equipo; lo clínico va en el historial.</p>
  <?php endif; ?>
  <form method="post" class="comentarios__form">
    <?= campoCsrf() ?>
    <input type="hidden" name="accion_comentario" value="1">
    <label class="comentarios__campo">
      <textarea name="texto" rows="2" maxlength="1000" required placeholder="Pendiente o aviso… usa @nombre para mencionar"></textarea>
    </label>
    <button type="submit" class="boton boton--secundario">Comentar</button>
  </form>
</section>
<?php require_once __DIR__ . '/../app/vistas/componentes/notas_seccion.php'; notasDeSeccion('expediente:' . (int) $p['id']); ?>

<script type="application/json" id="impresion-datos"><?= json_encode([
    'id' => (int) $p['id'],
    'partes' => $partesImpresion,
    'registros' => array_map(fn ($r) => ['id' => (int) $r['id'], 'texto' => nombreTipoRegistro($r['tipo'], 'documento') . ($r['titulo'] ? ' · ' . mb_strimwidth($r['titulo'], 0, 48, '…') : ''), 'fecha' => fechaCorta($r['fecha'])], $historial),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
