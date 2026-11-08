<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SELECT id, nombre, correo, rol, activo, institucion_id FROM usuarios");
echo "Users:\n";
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "  id=" . $row["id"] . " nombre=" . $row["nombre"] . " correo=" . $row["correo"] . " rol=" . $row["rol"] . " activo=" . $row["activo"] . " inst=" . $row["institucion_id"] . "\n";
}
echo "\nACCESO_AUTOMATICO: " . (defined("ACCESO_AUTOMATICO") ? (ACCESO_AUTOMATICO ? "true" : "false") : "not defined") . "\n";
echo "RECAPTCHA_SITE_KEY: " . (RECAPTCHA_SITE_KEY ?: "(empty)") . "\n";

