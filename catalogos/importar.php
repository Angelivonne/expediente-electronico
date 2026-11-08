<?php
/**
 * Importar catálogo desde CSV — sobre la tabla real del catálogo (mismo motor).
 *
 * Columnas aceptadas: la clave del catálogo (clave/código), nombre, descripción, activo
 * y columnas propias de la tabla que vengan en el encabezado (p. ej. capitulo, entidad_id).
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

exigirPermiso('configurar');

$claveCatalogo = (string) ($_GET['tabla'] ?? '');
$catalogo = catalogoSistema($claveCatalogo);
if (!$catalogo) {
    mensajeFlash('error', 'Ese catálogo no existe.');
    redirigir('/catalogos/');
}

$tabla  = (string) $catalogo['tabla'];
$campos = [];
foreach (db()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $tabla) . '`') as $columna) {
    $campos[] = (string) $columna['Field'];
}
$esInstitucion = !empty($catalogo['institucion']);
$llaveCol = 'clave';
foreach (['clave', 'codigo', 'cp', 'codigo_postal'] as $candidata) {
    if (in_array($candidata, $campos, true)) {
        $llaveCol = $candidata;
        break;
    }
}

/** Normaliza encabezados y valores para comparar sin acentos ni mayúsculas. */
$normalizar = function (string $texto): string {
    $texto = str_replace("\xEF\xBB\xBF", '', $texto);
    $texto = strtolower(trim($texto));
    return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', ' ' => '_']);
};
$activoVerdadero = ['1', 'si', 'sí', 'true', 'activo', 'x'];

$resultado = null;
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif (($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores[] = 'No se recibió el archivo.';
    } elseif ((int) $_FILES['csv']['size'] > 2 * 1024 * 1024) {
        $errores[] = 'El archivo supera 2 MB.';
    } else {
        $contenido = file_get_contents($_FILES['csv']['tmp_name']);
        $lineas = preg_split('/\r\n|\n|\r/', (string) $contenido);
        $lineas = array_values(array_filter($lineas, fn ($l) => trim($l) !== ''));
        if (count($lineas) < 2) {
            $errores[] = 'El archivo está vacío o no tiene datos.';
        } else {
            $encabezados = array_map('trim', str_getcsv(array_shift($lineas), ',', '"', '\\'));
            $indicePorCampo = [];
            foreach ($encabezados as $i => $encabezado) {
                $normal = $normalizar($encabezado);
                if ($normal === '') {
                    continue;
                }
                if (in_array($normal, ['clave', 'codigo', 'cp'], true) && $llaveCol !== $normal) {
                    $normal = $llaveCol;
                }
                if ($normal === $llaveCol) {
                    $indicePorCampo[$llaveCol] = $i;
                } elseif (in_array($normal, $campos, true)) {
                    $indicePorCampo[$normal] = $i;
                }
            }
            if (!isset($indicePorCampo[$llaveCol])) {
                $errores[] = 'El CSV debe incluir la columna «' . $llaveCol . '».';
            } else {
                $pdo = db();
                $institucionId = (int) institucionId();
                $insertados = 0;
                $actualizados = 0;
                $porFila = [];
                $insertCols = array_values(array_filter(array_keys($indicePorCampo), fn ($c) => !in_array($c, ['id'], true)));

                foreach ($lineas as $numeroFila => $linea) {
                    $valores = str_getcsv($linea, ',', '"', '\\');
                    $fila = [];
                    foreach ($indicePorCampo as $campo => $indice) {
                        $fila[$campo] = trim((string) ($valores[$indice] ?? ''));
                    }
                    $llave = $fila[$llaveCol] ?? '';
                    if ($llave === '') {
                        $porFila[] = 'Fila ' . ($numeroFila + 2) . ': clave vacía.';
                        continue;
                    }
                    if (isset($fila['activo'])) {
                        $fila['activo'] = in_array($normalizar($fila['activo']), $activoVerdadero, true) ? 1 : 0;
                    }
                    $condicion = $llaveCol . ' = ?';
                    $parametros = [$llave];
                    if ($esInstitucion) {
                        $condicion .= ' AND institucion_id = ?';
                        $parametros[] = $institucionId;
                    }
                    $buscar = $pdo->prepare('SELECT id FROM `' . str_replace('`', '', $tabla) . '` WHERE ' . $condicion . ' LIMIT 1');
                    $buscar->execute($parametros);
                    $idExistente = $buscar->fetchColumn();
                    try {
                        if ($idExistente) {
                            $asignaciones = [];
                            $valoresUpdate = [];
                            foreach ($fila as $campo => $valor) {
                                if ($campo === $llaveCol) {
                                    continue;
                                }
                                $asignaciones[] = $campo . ' = ?';
                                $valoresUpdate[] = $valor;
                            }
                            if ($asignaciones) {
                                $valoresUpdate[] = (int) $idExistente;
                                $pdo->prepare('UPDATE `' . str_replace('`', '', $tabla) . '` SET ' . implode(', ', $asignaciones) . ' WHERE id = ?')->execute($valoresUpdate);
                            }
                            $actualizados++;
                        } else {
                            $columnas = $insertCols;
                            $datos = [];
                            foreach ($columnas as $campo) {
                                $datos[] = $fila[$campo] ?? '';
                            }
                            if ($esInstitucion && in_array('institucion_id', $campos, true)) {
                                $columnas[] = 'institucion_id';
                                $datos[] = $institucionId;
                            }
                            $marcadores = implode(', ', array_fill(0, count($columnas), '?'));
                            $pdo->prepare('INSERT INTO `' . str_replace('`', '', $tabla) . '` (' . implode(', ', $columnas) . ') VALUES (' . $marcadores . ')')->execute($datos);
                            $insertados++;
                        }
                    } catch (Throwable $e) {
                        $porFila[] = 'Fila ' . ($numeroFila + 2) . ': ' . $e->getMessage();
                    }
                }
                $resultado = ['insertados' => $insertados, 'actualizados' => $actualizados, 'errores' => array_slice($porFila, 0, 20)];
            }
        }
    }
}

$tituloPagina   = 'Importar catálogo';
$seccionActiva  = 'catalogos';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'catálogos', 'url' => URL_BASE . '/catalogos/'], ['texto' => 'Importar']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';

$exportar = URL_BASE . '/api/motor/exportar.php?' . http_build_query(['entidad' => $claveCatalogo]);
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Importar ' . $catalogo['titulo']) ?></h1>
  <p class="pagina__subtitulo">CSV con la clave del catálogo, nombre, descripción y activo. Se actualiza por clave, no se duplica.</p>
</div>

<section class="bd">
  <div class="bd__cabecera">
    <h2 class="bd__titulo">Archivo CSV</h2>
    <div class="bd__herramientas">
      <a class="boton boton--sutil" href="<?= e($exportar) ?>">Descargar ejemplo (CSV actual)</a>
      <a class="boton boton--sutil" href="<?= URL_BASE ?>/catalogos/">Volver</a>
    </div>
  </div>
  <p class="pagina__descripcion">Tabla: <?= e($tabla) ?> · clave: <?= e($llaveCol) ?> · fuente: <?= e($catalogo['fuente'] ?? '—') ?> · norma: <?= e($catalogo['norma'] ?? '—') ?></p>
  <p class="pagina__descripcion">Columnas detectadas: <?= e(implode(', ', $campos)) ?></p>
  <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" enctype="multipart/form-data" class="nuevo-registro__form">
    <?= campoCsrf() ?>
    <label class="nuevo-registro__campo">
      <span>Archivo CSV</span>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </label>
    <div class="nuevo-registro__acciones">
      <button type="submit" class="boton boton--primario">Importar</button>
    </div>
  </form>
</section>

<?php if ($resultado !== null): ?>
  <section class="bd">
    <div class="bd__cabecera"><h2 class="bd__titulo">Resultado</h2></div>
    <div class="finanzas__plano">
      <table class="bd-tabla">
        <thead><tr><th>Insertados</th><th>Actualizados</th><th>Errores</th></tr></thead>
        <tbody><tr><td><?= (int) $resultado['insertados'] ?></td><td><?= (int) $resultado['actualizados'] ?></td><td><?= count($resultado['errores']) ?></td></tr></tbody>
      </table>
    </div>
    <?php if ($resultado['errores']): ?>
      <ul class="pagina__descripcion">
        <?php foreach ($resultado['errores'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($errores): ?>
  <section class="bd"><div class="finanzas__plano"><ul class="pagina__descripcion"><?php foreach ($errores as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div></section>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>






