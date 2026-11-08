<?php
define('RESPUESTA_JSON', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';
try {
    \ = db();
    echo 'DB OK: ' . \->query('SELECT 1')->fetchColumn();
} catch (Throwable \) {
    echo 'DB ERROR: ' . \->getMessage();
}
echo '\nURL_BASE: ' . URL_BASE;
echo '\nDB_NOMBRE: ' . DB_NOMBRE;

