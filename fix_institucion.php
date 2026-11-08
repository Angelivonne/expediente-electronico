<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
try {
    $pdo->exec("ALTER TABLE usuarios ADD COLUMN institucion_id INT UNSIGNED NULL AFTER id");
    echo "Added institucion_id column\n";
} catch (Throwable $e) {
    echo "Column may already exist: " . $e->getMessage() . "\n";
}
$instId = $pdo->query("SELECT id FROM instituciones LIMIT 1")->fetchColumn();
if ($instId) {
    $pdo->prepare("UPDATE usuarios SET institucion_id = ? WHERE rol = 'administrador'")->execute(array($instId));
    echo "Admin linked to institution $instId\n";
}
echo "Done\n";
