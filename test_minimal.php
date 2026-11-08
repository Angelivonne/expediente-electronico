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

