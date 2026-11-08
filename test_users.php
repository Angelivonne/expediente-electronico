<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("DESCRIBE usuarios");
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    echo $row["Field"] . " " . $row["Type"] . " " . $row["Null"] . " " . $row["Default"] . "\n";
}

