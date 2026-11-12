<?php
/**
 * Metadatos de un catálogo: fuente, versión, vigencia y responsable (persistidos y con bitácora).
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

$consulta = db()->prepare('SELECT * FROM catalogos_meta WHERE clave = ? LIMIT 1');
$consulta->execute([$claveCatalogo]);
$meta = $consulta->fetch(PDO::FETCH_ASSOC) ?: [
    'fuente' => $catalogo['fuente'] ?? '',
    'version' => $catalogo['version'] ?? '',
    'vigencia' => null,
    'responsable' => null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        mensajeFlash('error', 'La sesión expiró. Vuelve a intentarlo.');
    } else {
        $fuenteMeta = mb_substr(trim((string) ($_POST['fuente'] ?? '')), 0, 160);
        $versionMeta = mb_substr(trim((string) ($_POST['version'] ?? '')), 0, 80);
        $vigenciaMeta = trim((string) ($_POST['vigencia'] ?? ''));
        $responsableMeta = mb_substr(trim((string) ($_POST['responsable'] ?? '')), 0, 120);
        $vigenciaValida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigenciaMeta) ? $vigenciaMeta : null;
        $anteriorJson = json_encode(['fuente' => $meta['fuente'] ?? null, 'version' => $meta['version'] ?? null, 'vigencia' => $meta['vigencia'] ?? null, 'responsable' => $meta['responsable'] ?? null], JSON_UNESCAPED_UNICODE);
        db()->prepare(
            'INSERT INTO catalogos_meta (clave, fuente, version, vigencia, responsable, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE fuente = VALUES(fuente), version = VALUES(version),
                                     vigencia = VALUES(vigencia), responsable = VALUES(responsable),
                                     usuario_id = VALUES(usuario_id)'
        )->execute([$claveCatalogo, $fuenteMeta, $versionMeta, $vigenciaValida, $responsableMeta, (int) usuarioActual()['id']]);
        registrarBitacora('catalogos_meta', 0, 'actualizar', $claveCatalogo, $anteriorJson, json_encode(['fuente' => $fuenteMeta, 'version' => $versionMeta, 'vigencia' => $vigenciaValida, 'responsable' => $responsableMeta], JSON_UNESCAPED_UNICODE));
        mensajeFlash('exito', 'Metadatos de «' . $catalogo['titulo'] . '» actualizados.');
    }
    redirigir('/catalogos/meta.php?tabla=' . urlencode($claveCatalogo));
}

$tituloPagina   = 'Metadatos de catálogo';
$seccionActiva  = 'catalogos';
$claseContenido = 'pagina--ancha';
$migas          = [['texto' => 'catálogos', 'url' => URL_BASE . '/catalogos/'], ['texto' => 'Metadatos']];

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<div class="pagina__cabecera">
  <h1 class="pagina__titulo"><?= e('Metadatos: ' . $catalogo['titulo']) ?></h1>
  <p class="pagina__subtitulo">Fuente, versión, vigencia y responsable · tabla <?= e((string) $catalogo['tabla']) ?></p>
</div>


<section class="bd">
  <div class="bd__cabecera">
    <h2 class="bd__titulo">Datos del catálogo</h2>
    <div class="bd__herramientas">
      <a class="boton boton--sutil" href="<?= URL_BASE ?>/catalogos/">Volver</a>
    </div>
  </div>
  <form method="post" action="<?= e($_SERVER['REQUEST_URI']) ?>" class="nuevo-registro__form">
    <?= campoCsrf() ?>
    <div class="nuevo-registro__campos">
      <label class="nuevo-registro__campo">
        <span>Fuente</span>
        <input name="fuente" maxlength="160" value="<?= e((string) ($meta['fuente'] ?? '')) ?>">
      </label>
      <label class="nuevo-registro__campo">
        <span>Versión</span>
        <input name="version" maxlength="80" value="<?= e((string) ($meta['version'] ?? '')) ?>">
      </label>
      <label class="nuevo-registro__campo">
        <span>Vigencia</span>
        <input type="date" name="vigencia" value="<?= e((string) ($meta['vigencia'] ?? '')) ?>">
      </label>
      <label class="nuevo-registro__campo">
        <span>Responsable</span>
        <input name="responsable" maxlength="120" value="<?= e((string) ($meta['responsable'] ?? '')) ?>">
      </label>
    </div>
    <div class="nuevo-registro__acciones">
      <button type="submit" class="boton boton--primario">Guardar metadatos</button>
    </div>
  </form>
</section>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>