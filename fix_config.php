<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
try {
    $pdo->exec("ALTER TABLE configuracion ADD COLUMN institucion_id INT UNSIGNED NOT NULL DEFAULT 0 FIRST");
    echo "Added institucion_id column\n";
} catch (Throwable $e) {
    echo "Column may already exist: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE configuracion DROP PRIMARY KEY");
    echo "Dropped primary key\n";
} catch (Throwable $e) {
    echo "Drop PK: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("ALTER TABLE configuracion ADD PRIMARY KEY (institucion_id, clave)");
    echo "Added composite PK\n";
} catch (Throwable $e) {
    echo "Add PK: " . $e->getMessage() . "\n";
}
echo "Done\n";
