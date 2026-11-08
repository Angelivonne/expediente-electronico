<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$auto = db()->prepare("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 AND rol = 'administrador' ORDER BY id LIMIT 1");
$auto->execute();
$cuenta = $auto->fetch();
echo "Admin found: " . var_export($cuenta, true) . "\n";
if ($cuenta) {
    echo "Auto-login would work\n";
} else {
    echo "No admin found - checking all users:\n";
    $st = db()->query("SELECT id, nombre, rol, activo FROM usuarios");
    while ($row = $st->fetch()) {
        echo "  id=" . $row["id"] . " nombre=" . $row["nombre"] . " rol=" . $row["rol"] . " activo=" . $row["activo"] . "\n";
    }
}
echo "ACCESO_AUTOMATICO: " . (defined("ACCESO_AUTOMATICO") ? (ACCESO_AUTOMATICO ? "true" : "false") : "not defined") . "\n";
