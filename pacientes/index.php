<?php
/**
 * Pacientes — vista del motor único.
 *
 * La tabla, el buscador y las celdas editables salen del motor (misma entidad que usan la ficha, los
 * formularios y las herramientas). El mapa por entidad, el panel de propiedades y las notas de sección
 * se conservan tal cual.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/motor/almacen.php';
require_once __DIR__ . '/../app/servicios/motor/vistas.php';

exigirPermiso('ver_expediente');

$busqueda = trim($_GET['q'] ?? '');
$nuevoId  = (int) ($_GET['nuevo'] ?? 0);
$limite   = min(5000, max(50, (int) ($_GET['limite'] ?? 100)));
// Herramientas de la tabla: ordenar y filtrar (la agrupación sigue disponible por URL)
$ordenTabla   = trim((string) ($_GET['orden'] ?? ''));
$dirTabla     = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$campoTabla   = trim((string) ($_GET['campo'] ?? ''));
$valorTabla   = trim((string) ($_GET['valor'] ?? ''));
$filtroTabla  = ($campoTabla !== '' && $valorTabla !== '') ? [$campoTabla => $valorTabla] : [];

$lista          = motorListar('pacientes', ['q' => $busqueda, 'limite' => $limite, 'orden' => $ordenTabla, 'dir' => $dirTabla, 'filtro' => $filtroTabla]);
$pacientes      = $lista['filas'];
$totalPacientes = $lista['total'];
$hayMas         = count($pacientes) < $totalPacientes;

$propiedadesExtra = json_decode((string) config('pacientes_propiedades'), true) ?: [];
$ajustesColumnas  = json_decode((string) config('pacientes_columnas'), true) ?: [];

// Propiedades agregadas: un valor por fila para pintarlas y editarlas en línea
foreach ($pacientes as &$paciente) {
    $extras = $paciente['extras'] ?? [];
    foreach ($propiedadesExtra as $prop) {
        $paciente['extra_' . $prop['clave']] = $extras[$prop['clave']] ?? null;
    }
}
unset($paciente);

$notificacion = tomarFlash();

require_once __DIR__ . '/../app/servicios/clinica/mapa.php';

$tituloPagina   = 'Pacientes';
$seccionActiva  = 'pacientes';
$clavePresencia = 'pacientes:lista';
$migas          = [['texto' => 'Pacientes']];
$claseContenido = 'pagina--ancha';
$bannerAlto     = true;
$bannerImagen   = 'acceso-fondo.jpg';

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

/* ---- Columnas: las de la vista de pacientes del motor + las propiedades agregadas ---- */
$columnas = columnasVista('pacientes');
foreach ($columnas as &$columna) {
    $columna['original'] = $columna['titulo'];
    $columna['titulo']   = $ajustesColumnas[$columna['clave']]['nombre'] ?? $columna['titulo'];
    $columna['oculta']   = !empty($ajustesColumnas[$columna['clave']]['oculta']) && $columna['clave'] !== 'nombre_completo';
}
unset($columna);

foreach ($propiedadesExtra as $prop) {
    $tipo = (string) ($prop['tipo'] ?? 'texto');
    $columnas[] = [
        'clave'    => 'extra_' . $prop['clave'],
        'campo'    => 'extra_' . $prop['clave'],
        'editable' => true,
        'calculo'  => null,
        'titulo'   => (string) $prop['nombre'],
        'tipo'     => in_array($tipo, ['numero', 'fecha', 'casilla', 'seleccion'], true) ? $tipo : 'texto',
        'icono'    => ['numero' => 'numero', 'fecha' => 'calendario', 'seleccion' => 'seleccion', 'casilla' => 'casilla'][$tipo] ?? 'aa',
        'ancho'    => 180,
        'orden'    => $tipo === 'numero' ? 'numero' : ($tipo === 'fecha' ? 'fecha' : 'texto'),
        'editor'   => in_array($tipo, ['seleccion', 'casilla'], true) ? 'opciones' : 'texto',
        'opciones' => $tipo === 'seleccion'
            ? array_combine((array) ($prop['opciones'] ?? []), (array) ($prop['opciones'] ?? []))
            : ($tipo === 'casilla' ? ['1' => 'Sí', '0' => 'No'] : null),
        'grupo'    => 'Otras propiedades',
        'original' => (string) $prop['nombre'],
        'oculta'   => false,
        'extra'    => $prop,
    ];
}

$todasColumnas = $columnas;
$columnas = array_values(array_filter($columnas, fn ($c) => empty($c['oculta'])));

$grupos = [];
foreach ($columnas as $columna) {
    if (!empty($columna['grupo'])) {
        $grupos[$columna['grupo']] = ($grupos[$columna['grupo']] ?? 0) + 1;
    }
}

// Cálculos de la pantalla (no son datos: se derivan al pintar)
$calculos = [
    'nombre_completo'  => fn (array $f): string => trim("{$f['nombre']} {$f['primer_apellido']} {$f['segundo_apellido']}"),
    'fecha_nacimiento' => fn (array $f): string => $f['fecha_nacimiento'] ? fechaCorta((string) $f['fecha_nacimiento']) : '',
    'edad'             => fn (array $f): string => $f['fecha_nacimiento'] ? (string) edad((string) $f['fecha_nacimiento']) : '',
    'confirmacion'     => fn (array $f): string => !empty($f['confirmacion_datos']) ? 'Sí' : 'No',
    'registro'         => fn (array $f): string => $f['creado_en'] ? date('d/m/Y', strtotime((string) $f['creado_en'])) : '',
];
$valores = [
    'fecha_nacimiento' => fn (array $f): string => (string) ($f['fecha_nacimiento'] ?? ''),
    'creado_en'        => fn (array $f): string => (string) ($f['creado_en'] ?? ''),
];
foreach ($columnas as &$columna) {
    if (!empty($columna['calculo']) && isset($calculos[$columna['calculo']])) {
        $columna['calculo'] = $calculos[$columna['calculo']];
    } elseif (!empty($columna['calculo'])) {
        $columna['calculo'] = null;
    }
    if (isset($valores[$columna['clave']])) {
        $columna['valor'] = $valores[$columna['clave']];
    }
    if ($columna['clave'] === 'edad') {
        $columna['clase'] = 'num';
    }
}
unset($columna);

// Opciones de los editores en línea (campos del motor + propiedades agregadas)
$opcionesEditor = opcionesEditorMotor('pacientes');
foreach ($columnas as $columna) {
    if (!empty($columna['opciones']) && $columna['campo'] !== null) {
        $opcionesEditor[$columna['campo']] = $columna['opciones'];
    }
}

$tabla = [
    'columnas'  => $columnas,
    'filas'     => $pacientes,
    'atributos' => ['data-entidad' => 'pacientes', 'data-actualizar' => URL_BASE . '/api/motor/actualizar.php'],
    'fila_atributos' => function (array $fila, int $i) use ($nuevoId): array {
        $atributos = ['data-paciente' => $fila['id'], 'data-id' => $fila['id'], 'style' => '--i: ' . min($i, 20)];
        if ((int) $fila['id'] === $nuevoId) {
            $atributos['class'] = 'nuevo';
        }
        return $atributos;
    },
    'celdas' => [
        'nombre_completo' => function (array $columna, array $fila): string {
            $nombre = trim("{$fila['nombre']} {$fila['primer_apellido']} {$fila['segundo_apellido']}");
            return '<td class="bd-tabla__celda-titulo" title="' . e($nombre) . '">'
                . '<a class="bd-tabla__titulo" href="' . URL_BASE . '/pacientes/ver.php?id=' . (int) $fila['id'] . '">'
                . icono('pagina') . '<span>' . e($nombre) . '</span></a></td>';
        },
    ],
    'grupos' => $grupos,
];
?>

<div class="pagina__cabecera">
  <h1 class="pagina__titulo"<?= atributosEditable('pacientes_titulo', 'Pacientes') ?>><?= e(textoEditable('pacientes_titulo', 'Pacientes')) ?></h1>
  <p class="pagina__subtitulo"<?= atributosEditable('pacientes_subtitulo', 'Expediente clínico · NOM-004-SSA3-2012') ?>><?= e(textoEditable('pacientes_subtitulo', 'Expediente clínico · NOM-004-SSA3-2012')) ?></p>
</div>

<section class="bd" data-bd>
  <div class="bd__cabecera">
    <h2 class="bd__titulo"<?= atributosEditable('pacientes_bd', 'Registro de pacientes') ?>><?= e(textoEditable('pacientes_bd', 'Registro de pacientes')) ?></h2>

    <div class="bd__herramientas">
      <?php if (puede('configurar')): ?><button type="button" class="boton-icono" data-propiedades title="Propiedades" aria-label="Propiedades"><?= icono('lista') ?></button><?php endif; ?>
      <button type="button" class="boton-icono" data-abrir-busqueda title="Buscar paciente (nombre, CURP o folio)" aria-label="Buscar paciente"><?= icono('buscar') ?></button>
      <?php if (puede('importar_exportar')): ?><button type="button" class="boton-icono" data-herramientas-datos-pacientes aria-haspopup="menu" title="Herramientas de datos" aria-label="Herramientas de datos"><?= icono('tabla') ?></button><?php endif; ?>
      <button type="button" class="boton boton--primario bd__nuevo" data-pagina-paciente-menu data-registrar="<?= puede('editar_paciente') ? '1' : '0' ?>" aria-haspopup="menu" title="Registrar o crear"><?= icono('agregar') ?> Nuevo</button>
    </div>
  </div>

  <?php if ($busqueda !== ''): ?>
    <p class="pagina__descripcion">
      Resultados para «<?= e($busqueda) ?>» · <a href="<?= URL_BASE ?>/pacientes/">Ver todos</a>
    </p>
  <?php endif; ?>

  <?php require __DIR__ . '/../app/vistas/componentes/vista_tabla.php'; ?>

  <p class="bd__vacio" data-bd-vacio <?= $pacientes ? 'hidden' : '' ?>>
    <?= $busqueda !== '' || $pacientes ? 'Ningún paciente coincide con la búsqueda.' : 'Aún no hay pacientes registrados.' ?>
  </p>

  <?php if ($hayMas): ?>
    <a class="bd__mas" href="?<?= e(http_build_query(array_filter(['q' => $busqueda, 'limite' => $limite + 200]))) ?>">Mostrar más · <?= count($pacientes) ?> de <?= $totalPacientes ?></a>
  <?php endif; ?>
</section>

<!-- Pacientes por estado: panel plegado; se conserva para cuando se abra desde otra vista -->
<div class="geo-panel" data-geo-panel>
<div class="geo-panel__interior">
<section class="geo" id="mapa" data-geo>
  <div class="geo__superior">
    <div class="geo__controles">
      <div class="geo__selector">
        <button type="button" class="geo__boton" data-geo-boton aria-haspopup="listbox" aria-expanded="false">
          <?= icono('ubicacion') ?><span data-geo-nombre>México</span><?= icono('flecha_abajo', 'geo__chevron') ?>
        </button>
        <ul class="geo__opciones" role="listbox" data-geo-opciones hidden><li role="option" data-entidad="" aria-selected="true"><span>México</span><span>—</span></li></ul>
      </div>

      <div class="geo__cifra">
        <p class="geo__etiqueta">
          Pacientes registrados
          <span class="geo__info" title="Se cuenta la entidad escrita en el campo «Municipio / Estado» del registro."><?= icono('info') ?></span>
        </p>
        <p class="geo__numero" data-geo-numero>—</p>
        <p class="geo__nota" data-geo-nota>El mapa se carga cuando abres esta sección.</p>
      </div>
    </div>

    <div class="geo__mapa" data-geo-mapa aria-live="polite">
      <p class="geo__vacio">El mapa aparecerá al abrir esta sección.</p>
    </div>
      </div>
    </div>
  </div>

  <div class="geo__ranking">
    <div class="geo__ranking-cabecera">
      <div>
        <h2 class="geo__titulo">Estados por pacientes</h2>
        <p class="geo__subtitulo">Ordenados por número de pacientes</p>
      </div>
      <button type="button" class="geo__ver-todos" data-geo-todos aria-expanded="false" hidden>Ver todos los estados <?= icono('adelante') ?></button>
    </div>
    <ol class="geo__lista-estados" data-geo-lista hidden></ol>
  </div>

  <div class="geo__pacientes" data-geo-pacientes hidden>
    <h3 class="geo__titulo" data-geo-pacientes-titulo></h3>
    <ul data-geo-pacientes-lista></ul>
  </div>
</section>
</div>
</div>

<?php require_once __DIR__ . '/../app/vistas/componentes/notas_seccion.php'; notasDeSeccion('pacientes'); ?>



<script type="application/json" id="propiedades-pacientes"><?= json_encode(['columnas' => array_map(fn ($c) => ['clave' => $c['clave'], 'titulo' => $c['titulo'], 'original' => $c['original'], 'oculta' => $c['oculta'], 'extra' => isset($c['extra']), 'icono' => $c['icono'], 'grupo' => $c['grupo']], $todasColumnas), 'editable' => puede('configurar')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<script type="application/json" id="opciones-editor"><?= json_encode($opcionesEditor, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
