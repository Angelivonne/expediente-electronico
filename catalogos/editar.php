<?php
/**
 * Entrada de catálogos: abre directamente la tabla editable con sus registros reales.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/datos/catalogos_sistema.php';

exigirPermiso('ver_expediente');

$clave    = (string) ($_GET['tabla'] ?? '');
$catalogo = catalogoSistema($clave);
if (!$catalogo) {
    mensajeFlash('error', 'Ese catálogo no existe.');
    redirigir('/catalogos/');
}

redirigir('/catalogos/editar_tabla.php?tabla=' . urlencode($clave));
