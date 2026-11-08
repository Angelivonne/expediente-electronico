<?php
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/espacio/paginas.php';

$usuarioId = (int) usuarioActual()['id'];
$arbol = arbolPaginas($usuarioId);
$tituloPagina = 'Notas';
$seccionActiva = 'notas';
$migas = [['texto' => 'Notas']];
$claseContenido = 'pagina--ancha notas-inicio';
$notificacion = tomarFlash();

/** «hace 3 días» a partir de una fecha MySQL (solo la parte visual del listado). */
$hace = function (?string $cuando): string {
    if (!$cuando) {
        return '';
    }
    $ts = strtotime($cuando);
    if (!$ts) {
        return '';
    }
    $d = (int) floor((time() - $ts) / 86400);
    if ($d <= 0) {
        $h = (int) floor((time() - $ts) / 3600);
        return $h <= 0 ? 'hace unos minutos' : ($h === 1 ? 'hace 1 hora' : "hace $h horas");
    }
    if ($d === 1) return 'hace 1 día';
    if ($d < 31) return "hace $d días";
    if ($d < 365) { $m = (int) floor($d / 30); return $m === 1 ? 'hace 1 mes' : "hace $m meses"; }
    $a = (int) floor($d / 365);
    return $a === 1 ? 'hace 1 año' : "hace $a años";
};

$pintarNodo = function (array $nodo, int $nivel = 0) use (&$pintarNodo, $hace): void {
    $tipo = TIPOS_PAGINA[$nodo['tipo']] ?? TIPOS_PAGINA['nota'];
    ?>
    <li class="notas-inicio__nodo" style="--nivel:<?= $nivel ?>">
      <a href="<?= URL_BASE ?>/paginas/ver.php?id=<?= (int) $nodo['id'] ?>">
        <?= iconoRelleno($tipo['icono']) ?><span><?= e($nodo['titulo'] !== '' ? $nodo['titulo'] : $tipo['nueva']) ?></span><small><?= e($hace($nodo['cuando'] ?? null)) ?></small>
      </a>
      <?php if (!empty($nodo['hijos'])): ?><ul><?php foreach ($nodo['hijos'] as $hijo) $pintarNodo($hijo, $nivel + 1); ?></ul><?php endif; ?>
    </li>
    <?php
};

require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>
<header class="pagina__cabecera notas-inicio__cabecera">
  <div><h1 class="pagina__titulo">Notas</h1><p class="pagina__subtitulo">Todas tus notas y carpetas</p></div>
  <button type="button" class="boton boton--primario" data-pagina-menu-nueva data-seccion="privado" aria-haspopup="menu"><?= icono('agregar') ?> Nueva nota</button>
</header>

<div class="notas-inicio__herramientas">
  <label class="notas-inicio__buscar"><?= icono('buscar') ?><input type="search" placeholder="Buscar notas" aria-label="Buscar notas" data-notas-buscar></label>
</div>

<div class="notas-inicio__columnas">
  <section class="notas-inicio__seccion" aria-labelledby="notas-privadas">
    <header><div><h2 id="notas-privadas">Mis notas</h2><p>Notas y carpetas privadas.</p></div><span><?= count($arbol['privado']) ?></span></header>
    <?php if ($arbol['privado']): ?><ul class="notas-inicio__lista" data-notas-lista><?php foreach ($arbol['privado'] as $nodo) $pintarNodo($nodo); ?></ul>
    <?php else: ?><p class="notas-inicio__vacio">Todavía no hay notas privadas.</p><?php endif; ?>
  </section>
  <section class="notas-inicio__seccion" aria-labelledby="notas-compartidas">
    <header><div><h2 id="notas-compartidas">Compartidas contigo</h2><p>Páginas a las que te dio acceso el equipo.</p></div><span><?= count($arbol['compartido']) ?></span></header>
    <?php if ($arbol['compartido']): ?><ul class="notas-inicio__lista" data-notas-lista><?php foreach ($arbol['compartido'] as $nodo) $pintarNodo($nodo); ?></ul>
    <?php else: ?><p class="notas-inicio__vacio">No hay páginas compartidas contigo.</p><?php endif; ?>
  </section>
</div>

<?php if ($arbol['favoritos']): ?>
  <section class="notas-inicio__seccion notas-inicio__favoritos" aria-labelledby="notas-favoritas">
    <header><div><h2 id="notas-favoritas">Favoritos</h2><p>Acceso rápido a tus páginas guardadas.</p></div><span><?= count($arbol['favoritos']) ?></span></header>
    <ul class="notas-inicio__lista" data-notas-lista><?php foreach ($arbol['favoritos'] as $nodo) $pintarNodo($nodo); ?></ul>
  </section>
<?php endif; ?>
<p class="notas-inicio__sin-resultados" data-notas-vacio hidden>No hay páginas que coincidan con la búsqueda.</p>
<?php
$guionesPagina = ['js/modulos/notas_inicio.js'];
require __DIR__ . '/../app/vistas/plantilla/pie.php';
