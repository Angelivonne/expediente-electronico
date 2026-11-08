<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");
echo "Step 1: before require\n";
require_once __DIR__ . "/config/config.php";
echo "Step 2: config loaded\n";
require_once __DIR__ . "/config/db.php";
echo "Step 3: db loaded\n";
$pdo = db();
echo "Step 4: db connected\n";
require_once __DIR__ . "/app/nucleo/funciones.php";
echo "Step 5: funciones loaded\n";
require_once __DIR__ . "/app/nucleo/seguridad.php";
echo "Step 5b: seguridad loaded\n";
require_once __DIR__ . "/app/nucleo/configuracion.php";
echo "Step 6: configuracion loaded\n";
require_once __DIR__ . "/app/nucleo/institucion.php";
echo "Step 7: institucion loaded\n";
iniciarSesionSegura();
echo "Step 8: session started\n";
echo "Step 9: auto-login check\n";
if (!usuarioActual() && (defined("ACCESO_AUTOMATICO") && ACCESO_AUTOMATICO) && !defined("PAGINA_PUBLICA")) {
    $auto = db()->prepare("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 AND rol = 'administrador' ORDER BY id LIMIT 1");
    $auto->execute();
    if ($cuentaAuto = $auto->fetch()) {
        $_SESSION = array();
        session_regenerate_id(true);
        $_SESSION["usuario"]    = array("id" => (int) $cuentaAuto["id"], "nombre" => $cuentaAuto["nombre"], "rol" => $cuentaAuto["rol"]);
        $_SESSION["inicio"]     = time();
        $_SESSION["regenerada"] = time();
        $_SESSION["ultimo_uso"] = time();
        db()->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")->execute(array((int) $cuentaAuto["id"]));
        echo "Step 9a: auto-login done\n";
    } else {
        echo "Step 9b: no admin found\n";
    }
}
echo "Step 10: usuarioActual = " . var_export(usuarioActual(), true) . "\n";
restringirPortalPaciente();
echo "Step 11: restringirPortalPaciente done\n";
exigirInstitucion();
echo "Step 12: exigirInstitucion done\n";
echo "ALL OK\n";
