<?php
/**
 * Portadas: galería de imágenes y cambio de portada de una página o de Pacientes.
 *   GET                         → {galeria: [{ruta, url}]}
 *   POST JSON {destino, id?|clave?, portada|null}   destino 'pagina' (Puede editar) o 'seccion' + clave (administrador)
 *   POST multipart foto + destino (+ id)     sube una imagen y la pone de portada
 *   POST JSON {destino, id?|clave?, alto}    alto en px (120–520) o null para el tamaño predeterminado
 */
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

header('Content-Type: application/json; charset=UTF-8');

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Imágenes que se pueden elegir: las de assets/img/banners y las fotos de la app. */
function galeriaPortadas(): array
{
    $rutas = [];
    foreach (glob(__DIR__ . '/../assets/img/banners/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $archivo) {
        $rutas[] = 'assets/img/banners/' . basename($archivo);
    }
    sort($rutas);
    foreach (['acceso-fondo.jpg', 'banner.jpg', 'acceso-pintura.jpg'] as $base) {
        $rutas[] = 'assets/img/' . $base;
    }
    return $rutas;
}

/** Etiquetas descriptivas para las nuevas obras incorporadas a la galería. */
function nombrePortada(string $ruta): string
{
    static $nombres = [
        'renoir-falaise-verte-et-mer-1898.webp' => 'Pierre-Auguste Renoir · Falaise verte et mer, 1898',
        'thomas-moran-moonlight-seascape.webp' => 'Thomas Moran · Moonlight seascape',
        'eugene-boudin-paysage-boise.webp' => 'Eugène Boudin · Paysage boisé',
        'akseli-gallen-kallela-red-sea-suez.webp' => 'Akseli Gallen-Kallela · Red Sea, Suez',
        'george-inness-blue-niagara.webp' => 'George Inness · Blue Niagara',
        'andre-derain-village-dans-la-campagne.webp' => 'André Derain · Village dans la campagne',
        'george-inness-in-the-meadow.webp' => 'George Inness · In the Meadow',
        'george-inness-landscape-hudson-valley.webp' => 'George Inness · Landscape, Hudson Valley',
        'frederic-edwin-church-twilight-catskills.webp' => 'Frederic Edwin Church · Twilight in the Catskills',
        'print-1966-382.webp' => 'Estampa · 1966.382',
        'pierre-bonnard-terrasse-et-balcon.webp' => 'Pierre Bonnard · Terrasse et balcon',
        'john-frederick-kensett-lake-erie.webp' => 'John Frederick Kensett · Lake Erie',
        'theodore-rousseau-landscape-with-boatman.webp' => 'Théodore Rousseau · Landscape with Boatman',
        'george-inness-october-noon.webp' => 'George Inness · October Noon',
        'robert-havell-hudson-river-tarrytown.webp' => 'Robert Havell · View of the Hudson River from Tarrytown',
        'print-1951-323.webp' => 'Estampa · 1951.323',
        'james-ward-ryelands-sheep.webp' => 'James Ward · Ryelands Sheep',
        'jules-dupre-landscape-normandy.webp' => 'Jules Dupré · Landscape of Normandy',
        'john-frederick-kensett-bergen-park-colorado.webp' => 'John Frederick Kensett · Bergen Park (Colorado)',
        'ivan-aivazovsky-ox-carts-ukrainian-steppe.webp' => 'Ivan Aivazovsky · Ox-carts in the Ukrainian steppe',
        'christen-kobke-section-near-lime-kiln.webp' => 'Christen Købke · Section near the Lime Kiln',
        'print-1983-155.webp' => 'Estampa · 1983.155',
        'worthington-whittredge-autumn-delaware.webp' => 'Worthington Whittredge · Autumn on the Delaware',
    ];

    return $nombres[basename($ruta)] ?? pathinfo($ruta, PATHINFO_FILENAME);
}

/** true si la ruta es de la galería o una portada subida por la app. */
function portadaValida(string $ruta): bool
{
    return in_array($ruta, galeriaPortadas(), true) || preg_match('#^assets/subidas/portada-[0-9a-f]{16}\.(jpg|png|webp)$#', $ruta) === 1;
}

$usuarioId = (int) usuarioActual()['id'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responder(200, ['ok' => true, 'galeria' => array_map(
        fn ($r) => ['ruta' => $r, 'url' => URL_BASE . '/' . $r, 'nombre' => nombrePortada($r)],
        galeriaPortadas()
    )]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    responder(403, ['ok' => false, 'error' => 'La sesión expiró. Recarga la página.']);
}
if (!dentroDelLimite('portadas:usuario:' . $usuarioId, 60, 600)) {
    responder(429, ['ok' => false, 'error' => 'Demasiados cambios seguidos. Espera un momento.']);
}

$esSubida = !empty($_FILES['foto']);
$entrada  = $esSubida ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);
$destino  = (string) ($entrada['destino'] ?? '');

// Quién puede cambiar cada portada
if ($destino === 'pagina') {
    $pagina = cargarPagina((int) ($entrada['id'] ?? 0), $usuarioId);
    if (!$pagina || $pagina['en_papelera'] || !nivelAlcanza($pagina, 'editar')) {
        responder(403, ['ok' => false, 'error' => 'No tienes permiso para cambiar la portada de esta página.']);
    }
} elseif ($destino === 'seccion') {
    $clave = (string) ($entrada['clave'] ?? '');
    if (!puede('configurar')) {
        responder(403, ['ok' => false, 'error' => 'Solo el administrador cambia las portadas de las secciones.']);
    }
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $clave)) {
        responder(400, ['ok' => false, 'error' => 'Sección no válida.']);
    }
} else {
    responder(400, ['ok' => false, 'error' => 'Destino no válido.']);
}

// Solo el alto de la portada
if (!$esSubida && array_key_exists('alto', $entrada)) {
    $alto = $entrada['alto'] === null ? null : max(120, min(520, (int) $entrada['alto']));
    if ($destino === 'pagina') {
        db()->prepare('UPDATE paginas SET portada_alto = ? WHERE id = ?')->execute([$alto, $pagina['id']]);
    } else {
        guardarConfig('portada_alto_' . $clave, $alto === null ? null : (string) $alto);
    }
    responder(200, ['ok' => true, 'alto' => $alto]);
}

if ($esSubida) {
    $archivo = $_FILES['foto'];
    if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > 8 * 1024 * 1024) {
        responder(400, ['ok' => false, 'error' => 'La imagen no se pudo subir o pesa más de 8 MB.']);
    }
    $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $tipo  = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    $info  = @getimagesize($archivo['tmp_name']);
    if (!isset($tipos[$tipo]) || !$info || $info[0] < 600 || $info[1] < 150) {
        responder(415, ['ok' => false, 'error' => 'Usa una imagen JPG, PNG o WebP de al menos 600 × 150 píxeles.']);
    }
    $nombre = 'portada-' . bin2hex(random_bytes(8)) . '.' . $tipos[$tipo];
    if (!guardarSubida($archivo['tmp_name'], __DIR__ . '/../assets/subidas/' . $nombre)) {
        responder(500, ['ok' => false, 'error' => 'No se pudo guardar la imagen.']);
    }
    $portada = 'assets/subidas/' . $nombre;
} else {
    $portada = isset($entrada['portada']) && $entrada['portada'] !== null ? (string) $entrada['portada'] : null;
    if ($portada !== null && !portadaValida($portada)) {
        responder(400, ['ok' => false, 'error' => 'Esa imagen no está en la galería.']);
    }
}

if ($destino === 'pagina') {
    db()->prepare('UPDATE paginas SET portada = ?, actualizado_en = NOW(), actualizado_por = ? WHERE id = ?')->execute([$portada, $usuarioId, $pagina['id']]);
    registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'portada', $pagina['portada'], $portada);
} else {
    guardarConfig('portada_' . $clave, $portada);
    if ($clave === 'pacientes-index') {
        guardarConfig('portada_pacientes', null);
    }
    registrarBitacora('configuracion', 0, 'actualizar', 'portada_' . $clave, null, $portada);
}
responder(200, ['ok' => true, 'portada' => $portada, 'url' => $portada ? URL_BASE . '/' . $portada : null]);
