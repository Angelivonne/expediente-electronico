<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SHOW TABLES");
$tables = $st->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables: " . count($tables) . "\n";
foreach ($tables as $t) echo "  $t\n";
echo "\nFinanzas tables:\n";
foreach (array("finanzas_conceptos", "finanzas_movimientos", "finanzas_cierres", "finanzas_facturas", "finanzas_presupuestos", "finanzas_recurrentes") as $t) {
    echo "  $t: " . (in_array($t, $tables) ? "OK" : "MISSING") . "\n";
}

