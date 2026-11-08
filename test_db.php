<?php
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
try {
    $pdo = db();
    echo 'DB OK: ' . $pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage();
}
echo "\nURL_BASE: " . URL_BASE;
echo "\nDB_NOMBRE: " . DB_NOMBRE;
echo "\nDB_HOST: " . DB_HOST;
