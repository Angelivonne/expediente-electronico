<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
try {
    $pdo->exec("ALTER TABLE bitacora ADD COLUMN institucion_id INT UNSIGNED NULL AFTER usuario_id");
    echo "Added institucion_id to bitacora\n";
} catch (Throwable $e) {
    echo "bitacora.institucion_id: " . $e->getMessage() . "\n";
}
// Check columns
$st = $pdo->query("SHOW COLUMNS FROM bitacora");
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "  " . $row["Field"] . " " . $row["Type"] . "\n";
}

