<?php
/**
 * Importación segura por CSV sobre la tabla real del catálogo.
 * La clave de actualización se obtiene de los índices únicos de la tabla.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';
require_once __DIR__ . '/csv.php';

exigirPermiso('configurar');

$claveCatalogo = (string) ($_GET['tabla'] ?? '');
$catalogo = catalogoSistema($claveCatalogo);
if (!$catalogo) {
    mensajeFlash('error', 'Ese catálogo no existe.');
    redirigir('/catalogos/');
}

$tabla = (string) $catalogo['tabla'];
$columnas = [];
$esquemaDisponible = true;
try {
    $columnas = catalogoColumnasCsv($tabla);
} catch (Throwable $errorEsquema) {
    $esquemaDisponible = false;
}
$campos = catalogoCamposCsv($columnas);
$llaves = $esquemaDisponible ? catalogoLlavesCsv($tabla, $columnas) : [];
$tieneInstitucion = !empty($catalogo['institucion']) && isset($columnas['institucion_id']);
$resultado = null;
$errores = [];
$mapaCampos = [];

foreach ($campos as $campo) {
    $mapaCampos[catalogoNormalizarCabeceraCsv($campo)] = $campo;
}
foreach (['cp' => 'codigo', 'codigo_postal' => 'codigo', 'código_postal' => 'codigo'] as $alias => $campo) {
    if (isset($columnas[$campo]) && !isset($columnas[$alias])) {
        $mapaCampos[catalogoNormalizarCabeceraCsv($alias)] = $campo;
    }
}

if (!$llaves) {
    $errores[] = $esquemaDisponible
        ? 'No se encontró una clave única para este catálogo; no es posible importar de forma segura.'
        : 'La tabla de este catálogo no está disponible en la base de datos.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif (($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores[] = match ((int) ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
            UPLOAD_ERR_NO_FILE => 'Selecciona un archivo CSV.',
            default => 'No se pudo recibir el archivo. Inténtalo de nuevo.',
        };
    } elseif ((int) ($_FILES['csv']['size'] ?? 0) > 5 * 1024 * 1024) {
        $errores[] = 'El archivo supera el límite de 5 MB.';
    } elseif (!$llaves) {
        // El mensaje de esquema ya está agregado.
    } else {
        $archivo = fopen((string) $_FILES['csv']['tmp_name'], 'rb');
        if ($archivo === false) {
            $errores[] = 'No se pudo leer el archivo seleccionado.';
        } else {
            $delimitador = ',';
            $encabezados = fgetcsv($archivo, null, $delimitador, '"', '\\');
            if ($encabezados !== false && count($encabezados) === 1 && str_contains((string) $encabezados[0], ';')) {
                rewind($archivo);
                $delimitador = ';';
                $encabezados = fgetcsv($archivo, null, $delimitador, '"', '\\');
            }
            if ($encabezados === false || count($encabezados) < 1) {
                $errores[] = 'El CSV está vacío.';
            } else {
                $indicePorCampo = [];
                $encabezadoDuplicado = false;
                foreach ($encabezados as $indice => $encabezado) {
                    $normalizado = catalogoNormalizarCabeceraCsv((string) $encabezado);
                    $campo = $mapaCampos[$normalizado] ?? null;
                    if ($campo === null) {
                        continue;
                    }
                    if (isset($indicePorCampo[$campo])) {
                        $encabezadoDuplicado = true;
                        break;
                    }
                    $indicePorCampo[$campo] = $indice;
                }

                if ($encabezadoDuplicado) {
                    $errores[] = 'El CSV repite una columna del catálogo.';
                } else {
                    $faltantesLlave = array_values(array_diff($llaves, array_keys($indicePorCampo)));
                    if ($faltantesLlave) {
                        $errores[] = 'Faltan columnas de la clave única: ' . implode(', ', $faltantesLlave) . '. Descarga el CSV de este catálogo para usar sus encabezados.';
                    }
                    foreach ($campos as $campo) {
                        $columna = $columnas[$campo];
                        $requerido = (string) ($columna['Null'] ?? 'YES') === 'NO'
                            && ($columna['Default'] ?? null) === null
                            && !str_contains((string) ($columna['Extra'] ?? ''), 'auto_increment');
                        if ($requerido && !isset($indicePorCampo[$campo])) {
                            $errores[] = 'Falta la columna obligatoria «' . $campo . '». Descarga el CSV de este catálogo para usar sus encabezados.';
                        }
                    }
                }

                $filas = [];
                $huellas = [];
                $numeroLinea = 1;
                while (!$errores && ($valores = fgetcsv($archivo, null, $delimitador, '"', '\\')) !== false) {
                    $numeroLinea++;
                    if ($valores === [null] || (count($valores) === 1 && trim((string) $valores[0]) === '')) {
                        continue;
                    }
                    if (count($valores) > count($encabezados)) {
                        $errores[] = 'La fila ' . $numeroLinea . ' tiene más columnas que el encabezado.';
                        break;
                    }
                    if (count($filas) >= 20000) {
                        $errores[] = 'El archivo supera el máximo de 20,000 registros por importación.';
                        break;
                    }

                    $fila = [];
                    foreach ($indicePorCampo as $campo => $indice) {
                        $valor = trim((string) ($valores[$indice] ?? ''));
                        if ($campo === 'activo') {
                            $normalizado = catalogoNormalizarCabeceraCsv($valor);
                            if (in_array($normalizado, ['1', 'si', 'true', 'activo', 'x'], true)) {
                                $valor = '1';
                            } elseif (in_array($normalizado, ['0', 'no', 'false', 'inactivo'], true)) {
                                $valor = '0';
                            } else {
                                $errores[] = 'La fila ' . $numeroLinea . ' tiene un valor no válido en «activo»; usa Sí o No.';
                                break;
                            }
                        }
                        if ($valor === '' && (string) ($columnas[$campo]['Null'] ?? 'YES') === 'NO') {
                            $errores[] = 'La fila ' . $numeroLinea . ' requiere un valor en «' . $campo . '».';
                            break;
                        }
                        $fila[$campo] = $valor === '' && (string) ($columnas[$campo]['Null'] ?? 'YES') === 'YES' ? null : $valor;
                    }
                    if ($errores) {
                        break;
                    }
                    $partesClave = [];
                    foreach ($llaves as $llave) {
                        if (!isset($fila[$llave]) || $fila[$llave] === null || $fila[$llave] === '') {
                            $errores[] = 'La fila ' . $numeroLinea . ' tiene incompleta la clave única (' . implode(', ', $llaves) . ').';
                            break;
                        }
                        $partesClave[] = (string) $fila[$llave];
                    }
                    if ($errores) {
                        break;
                    }
                    $huella = json_encode($partesClave, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (isset($huellas[$huella])) {
                        $errores[] = 'La fila ' . $numeroLinea . ' duplica la misma clave única de la fila ' . $huellas[$huella] . '.';
                        break;
                    }
                    $huellas[$huella] = $numeroLinea;
                    $filas[] = $fila;
                }
                fclose($archivo);

                if (!$errores && !$filas) {
                    $errores[] = 'El CSV no contiene registros para importar.';
                }

                if (!$errores) {
                    $pdo = db();
                    $insertados = 0;
                    $actualizados = 0;
                    $sinCambios = 0;
                    $institucionId = (int) institucionId();
                    try {
                        $pdo->beginTransaction();
                        foreach ($filas as $fila) {
                            $condiciones = [];
                            $parametrosClave = [];
                            foreach ($llaves as $llave) {
                                $condiciones[] = '`' . $llave . '` = ?';
                                $parametrosClave[] = $fila[$llave];
                            }
                            if ($tieneInstitucion) {
                                $condiciones[] = '`institucion_id` = ?';
                                $parametrosClave[] = $institucionId;
                            }
                            $buscar = $pdo->prepare('SELECT `id` FROM `' . $tabla . '` WHERE ' . implode(' AND ', $condiciones) . ' LIMIT 1');
                            $buscar->execute($parametrosClave);
                            $idExistente = $buscar->fetchColumn();

                            if ($idExistente !== false) {
                                $actualizables = array_diff(array_keys($fila), $llaves);
                                if (!$actualizables) {
                                    $sinCambios++;
                                    continue;
                                }
                                $asignaciones = array_map(static fn (string $campo): string => '`' . $campo . '` = ?', $actualizables);
                                $valoresUpdate = array_map(static fn (string $campo) => $fila[$campo], $actualizables);
                                $valoresUpdate[] = (int) $idExistente;
                                $whereUpdate = '`id` = ?';
                                if ($tieneInstitucion) {
                                    $whereUpdate .= ' AND `institucion_id` = ?';
                                    $valoresUpdate[] = $institucionId;
                                }
                                $pdo->prepare('UPDATE `' . $tabla . '` SET ' . implode(', ', $asignaciones) . ' WHERE ' . $whereUpdate)->execute($valoresUpdate);
                                $actualizados++;
                            } else {
                                $columnasInsert = array_keys($fila);
                                $valoresInsert = array_values($fila);
                                if ($tieneInstitucion) {
                                    $columnasInsert[] = 'institucion_id';
                                    $valoresInsert[] = $institucionId;
                                }
                                $listaColumnas = implode(', ', array_map(static fn (string $campo): string => '`' . $campo . '`', $columnasInsert));
                                $marcadores = implode(', ', array_fill(0, count($columnasInsert), '?'));
                                $pdo->prepare('INSERT INTO `' . $tabla . '` (' . $listaColumnas . ') VALUES (' . $marcadores . ')')->execute($valoresInsert);
                                $insertados++;
                            }
                        }
                        $pdo->commit();
                        $resultado = compact('insertados', 'actualizados', 'sinCambios');
                    } catch (Throwable $errorImportacion) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errores[] = 'No se aplicaron cambios. Revisa las claves y referencias del CSV; alguna fila no coincide con el esquema del catálogo.';
                    }
                }
            }
        }
    }
}

$tituloPagina = 'Importar catálogo';
$seccionActiva = 'catalogos';
$claseContenido = 'pagina--ancha';
$migas = [['texto' => 'Catálogos', 'url' => URL_BASE . '/catalogos/'], ['texto' => 'Importar']];
$exportar = URL_BASE . '/catalogos/exportar.php?' . http_build_query(['tabla' => $claveCatalogo]);

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<header class="catalogos__cabecera">
  <h1 class="pagina__titulo">Importar <?= e($catalogo['titulo']) ?></h1>
  <a class="catalogos__volver" href="<?= URL_BASE ?>/catalogos/">Volver a Catálogos</a>
</header>

<section class="catalogos__importacion" aria-label="Importación CSV">
  <div class="catalogos__acciones">
    <a class="boton boton--secundario boton--pequeno" href="<?= e($exportar) ?>">Descargar CSV del catálogo</a>
    <span>CSV UTF-8 (coma o punto y coma) · hasta 5 MB y 20,000 filas · actualiza coincidencias sin duplicarlas</span>
  </div>
  <?php if ($llaves): ?>
    <p class="catalogos__nota">Clave única: <?= e(implode(' + ', $llaves)) ?>. Las referencias deben usar sus identificadores.</p>
  <?php endif; ?>
  <?php if ($llaves): ?><form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" enctype="multipart/form-data" class="catalogos__formulario">
    <?= campoCsrf() ?>
    <label class="catalogos__archivo">
      <span>Archivo CSV</span>
      <input type="file" name="csv" accept=".csv,text/csv" required>
    </label>
    <button type="submit" class="boton boton--primario">Importar</button>
  </form><?php endif; ?>
</section>

<?php if ($resultado !== null): ?>
  <p class="catalogos__mensaje" role="status">
    Importación completada: <?= (int) $resultado['insertados'] ?> nuevos,
    <?= (int) $resultado['actualizados'] ?> actualizados
    <?php if ($resultado['sinCambios']): ?>y <?= (int) $resultado['sinCambios'] ?> sin cambios<?php endif; ?>.
  </p>
<?php endif; ?>
<?php if ($errores): ?>
  <ul class="catalogos__errores" role="alert">
    <?php foreach (array_unique($errores) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
