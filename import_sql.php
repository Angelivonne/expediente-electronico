<?php
// Import SQL schema
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$sqlFile = __DIR__ . '/database/esquema.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Split by semicolons (basic approach)
$statements = array_filter(array_map('trim', explode(';', $sql)));
$count = 0;
$errors = 0;
foreach ($statements as $stmt) {
    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (Throwable $e) {
        $errors++;
        echo "Error: " . $e->getMessage() . "\n";
        echo "Stmt: " . substr($stmt, 0, 100) . "...\n";
    }
}
echo "Executed $count statements, $errors errors\n";
