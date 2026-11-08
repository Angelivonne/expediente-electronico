<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$tables = array("pacientes", "historial_clinico", "paginas", "citas", "conversiones", "invitaciones", "bitacora");
foreach ($tables as $t) {
    try {
        if ($t === "bitacora") {
            $pdo->exec("ALTER TABLE $t ADD COLUMN institucion_id INT UNSIGNED NULL AFTER usuario_id");
        } else {
            $pdo->exec("ALTER TABLE $t ADD COLUMN institucion_id INT UNSIGNED NULL AFTER id");
        }
        echo "Added institucion_id to $t\n";
    } catch (Throwable $e) {
        echo "$t: " . $e->getMessage() . "\n";
    }
}
echo "Done\n";
