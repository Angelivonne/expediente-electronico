<?php
/**
 * Entrada del menú catálogos: abre el catálogo dentro del espacio de trabajo (expedientes
 * electrónicos), que es donde vive su página.
 *
 * Antes este archivo solo buscaba notas con el título exacto y por eso nunca las encontraba:
 * las páginas de catálogo son bases de datos (no notas), pueden tener otro título y hay que
 * respetar la institución y los permisos. La lista de catálogos vive en
 * app/datos/catalogos_sistema.php; aquí solo se resuelve a qué página lleva cada uno.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

exigirPermiso('ver_expediente');

$clave    = (string) ($_GET['tabla'] ?? '');
$catalogo = catalogoSistema($clave);
if (!$catalogo) {
    mensajeFlash('error', 'Ese catálogo no existe.');
    redirigir('/');
}

$usuarioId = (int) usuarioActual()['id'];

/** página del catálogo en la institución (bases de datos y notas, sin papelera), o null. */
$paginaDeCatalogo = function (array $catalogo): ?array {
    $consulta = db()->prepare(
        "SELECT * FROM paginas
          WHERE institucion_id = ? AND eliminado_en IS NULL AND tipo IN ('base_datos', 'nota')
          ORDER BY (tipo = 'base_datos') DESC, id"
    );
    $consulta->execute([institucionId()]);
    $buscado = normalizarTituloCatalogo($catalogo['titulo']);
    foreach ($consulta->fetchAll() as $fila) {
        if (normalizarTituloCatalogo((string) $fila['titulo']) === $buscado) {
            return $fila;
        }
    }
    return null;
};

$pagina = $paginaDeCatalogo($catalogo);

// La página existe pero está privada: los catálogos son de la clínica, se comparten con el equipo
if ($pagina) {
    if (!cargarPagina((int) $pagina['id'], $usuarioId) && puede('configurar') && !(int) $pagina['compartida']) {
        db()->prepare('UPDATE paginas SET compartida = 1, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')
            ->execute([$usuarioId, (int) $pagina['id']]);
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'compartida', '0', '1');
        paginasDelEspacio($usuarioId, true);
    }
    redirigir('/paginas/ver.php?id=' . (int) $pagina['id']);
}

// Sin página todavía: si el catálogo ya tiene datos en una tabla, se abre su editor
if (catalogoConTabla($catalogo)) {
    redirigir($catalogo['editor'] ?? ('/catalogos/editar_tabla.php?tabla=' . urlencode($clave)));
}

// catálogo sin página todavía: se crea una sola vez, compartida, marcada con su origen
$contenido = json_encode(['origen' => $clave], JSON_UNESCAPED_UNICODE);
$orden     = (int) db()->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM paginas WHERE institucion_id = ' . (int) institucionId() . ' AND padre_id IS NULL')->fetchColumn();

db()->prepare(
    "INSERT INTO paginas (institucion_id, tipo, titulo, contenido, propietario_id, compartida, orden)
     VALUES (?, 'base_datos', ?, ?, ?, 1, ?)"
)->execute([institucionId(), mb_substr($catalogo['titulo'], 0, 200), $contenido, $usuarioId, $orden]);
$nuevaId = (int) db()->lastInsertId();
registrarBitacora('paginas', $nuevaId, 'crear', 'catalogo', null, $catalogo['clave']);
paginasDelEspacio($usuarioId, true);

mensajeFlash('exito', 'Se creó la página del catálogo «' . $catalogo['titulo'] . '» con sus columnas.');
redirigir('/paginas/ver.php?id=' . $nuevaId);






