<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SHOW TABLES");
$tables = $st->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . count($tables) . "\n";
foreach ($tables as $t) echo "  $t\n";
echo "\n";
// Check usuarios table
try {
    $st = $pdo->query("SELECT COUNT(*) FROM usuarios");
    echo "usuarios count: " . $st->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "usuarios error: " . $e->getMessage() . "\n";
}

