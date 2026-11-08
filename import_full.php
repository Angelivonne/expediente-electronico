<?php
require_once __DIR__ . "/config/config.php";

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, '', DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->query("DROP DATABASE IF EXISTS " . DB_NOMBRE);
$mysqli->query("CREATE DATABASE " . DB_NOMBRE . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$mysqli->select_db(DB_NOMBRE);
$mysqli->set_charset('utf8mb4');

$sqlFile = __DIR__ . '/database/esquema.sql';
if (!file_exists($sqlFile)) {
    die("SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

// Replace old DB name with new one
$sql = str_replace('expediente_expediente', DB_NOMBRE, $sql);
// Remove USE statement
$sql = preg_replace('/USE\s+\w+\s*;/i', '', $sql);
// Remove IF NOT EXISTS from ADD COLUMN, ADD INDEX, etc. (MySQL < 8.0.29)
$sql = preg_replace('/ADD (COLUMN|INDEX|UNIQUE INDEX|UNIQUE KEY|FOREIGN KEY|KEY|CONSTRAINT) IF NOT EXISTS/i', 'ADD $1', $sql);
$sql = preg_replace('/ADD (INDEX|UNIQUE INDEX|UNIQUE KEY|KEY) (\w+) IF NOT EXISTS/i', 'ADD $1 $2', $sql);
// Handle: FOREIGN KEY IF NOT EXISTS anywhere
$sql = preg_replace('/FOREIGN KEY IF NOT EXISTS/i', 'FOREIGN KEY', $sql);
// Handle: ADD CONSTRAINT name IF NOT EXISTS
$sql = preg_replace('/ADD CONSTRAINT (\w+) IF NOT EXISTS/i', 'ADD CONSTRAINT $1', $sql);

$mysqli->multi_query($sql);
$count = 0;
$errors = 0;
$lastError = '';
do {
    try {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } catch (Throwable $e) {
        // ignore
    }
    $count++;
    if ($mysqli->error) {
        $errors++;
        $lastError = $mysqli->error;
        if ($errors <= 10) {
            echo "Error #$errors: " . $mysqli->error . "\n";
        }
    }
    try {
        $hasMore = $mysqli->more_results();
    } catch (Throwable $e) {
        $hasMore = false;
    }
    if ($hasMore) {
        try {
            $mysqli->next_result();
        } catch (Throwable $e) {
            $errors++;
            if ($errors <= 10) {
                echo "Next result error: " . $e->getMessage() . "\n";
            }
            // Try to continue
            try {
                while ($mysqli->more_results()) {
                    $mysqli->next_result();
                    if ($r = $mysqli->store_result()) $r->free();
                }
            } catch (Throwable $e2) {
                // give up
            }
            break;
        }
    }
} while ($hasMore);

echo "Completed. Statements: $count, Errors: $errors\n";
if ($errors > 10) {
    echo "Last error: $lastError\n";
}

// Now check which tables exist
$st = $mysqli->query("SHOW TABLES");
$tables = [];
while ($row = $st->fetch_row()) {
    $tables[] = $row[0];
}
echo "\nTables created: " . count($tables) . "\n";


// Create admin user
$hash = password_hash('Admin123!', PASSWORD_DEFAULT);
$mysqli->query("INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo, creado_en, institucion_id) VALUES ('Angel', 'angelzamora@test.com', '" . $hash . "', 'administrador', 1, NOW(), 1)");
// Create institution
$mysqli->query("INSERT INTO instituciones (id, nombre, tipo, domicilio, entidad, creada_por) VALUES (1, 'Mi Clinica', 'clinica', '', '01', 1)");
$mysqli->query("UPDATE usuarios SET institucion_id = 1 WHERE id = 1");
echo "\nAdmin and institution created.\n";
echo "Login: angelzamora@test.com / Admin123!\n";

$mysqli->close();
