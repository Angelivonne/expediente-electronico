<?php
require_once __DIR__ . "/config/config.php";

$mysqli = new mysqli(DB_HOST, DB_USUARIO, DB_CLAVE, '', DB_PUERTO);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}
$mysqli->select_db(DB_NOMBRE);
$mysqli->set_charset('utf8mb4');

// Read the SQL file
$sql = file_get_contents(__DIR__ . '/database/esquema.sql');

// Extract CREATE TABLE statements for missing tables
$missingTables = array('codigos_acceso', 'codigos_acceso_usos', 'imagenes_medicas', 'push_suscripciones', 'push_avisos_enviados', 'push_cola', 'push_preferencias', 'recuperaciones_clave', 'sincronizacion_cola', 'finanzas_movimientos', 'finanzas_cuentas', 'finanzas_categorias');

// Find each CREATE TABLE block
foreach ($missingTables as $table) {
    // Find "CREATE TABLE ... $table" up to the next "ENGINE=InnoDB" or ";" at end of statement
    $pattern = '/CREATE TABLE (IF NOT EXISTS )?' . preg_quote($table, '/') . '\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB[^;]*;/s';
    if (preg_match($pattern, $sql, $matches)) {
        $stmt = $matches[0];
        // Replace old DB name
        $stmt = str_replace('expediente_expediente', DB_NOMBRE, $stmt);
        echo "Creating $table... ";
        if ($mysqli->query($stmt)) {
            echo "OK\n";
        } else {
            echo "ERROR: " . $mysqli->error . "\n";
        }
    } else {
        echo "Table $table: CREATE statement not found\n";
    }
}

// Also add missing columns to existing tables
$alterStatements = array(
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NULL AFTER id",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS paciente_id INT UNSIGNED NULL AFTER rol",
    "ALTER TABLE configuracion ADD COLUMN IF NOT EXISTS institucion_id INT UNSIGNED NOT NULL DEFAULT 0 FIRST",
);

foreach ($alterStatements as $stmt) {
    $stmt = str_replace('IF NOT EXISTS ', '', $stmt);
    echo "Running: " . substr($stmt, 0, 60) . "... ";
    // Check if column already exists
    $table = '';
    if (preg_match('/ALTER TABLE (\w+)/', $stmt, $m)) $table = $m[1];
    $col = '';
    if (preg_match('/ADD COLUMN (\w+)/', $stmt, $m)) $col = $m[1];
    if ($table && $col) {
        $check = $mysqli->query("SHOW COLUMNS FROM $table LIKE '$col'");
        if ($check && $check->num_rows > 0) {
            echo "already exists, skip\n";
            continue;
        }
    }
    if ($mysqli->query($stmt)) {
        echo "OK\n";
    } else {
        echo "ERROR: " . $mysqli->error . "\n";
    }
}

// Add seccion column to paginas
$check = $mysqli->query("SHOW COLUMNS FROM paginas LIKE 'seccion'");
if (!$check || $check->num_rows == 0) {
    echo "Adding seccion to paginas... ";
    if ($mysqli->query("ALTER TABLE paginas ADD COLUMN seccion VARCHAR(40) NULL AFTER paciente_id")) {
        echo "OK\n";
    } else {
        echo "ERROR: " . $mysqli->error . "\n";
    }
}

// Create admin user if not exists
$st = $mysqli->query("SELECT COUNT(*) FROM usuarios");
if ($st && $st->fetch_row()[0] == 0) {
    $hash = password_hash('Admin123!', PASSWORD_DEFAULT);
    $mysqli->query("INSERT INTO instituciones (id, nombre, tipo, domicilio, entidad, creada_por) VALUES (1, 'Mi Clinica', 'clinica', '', '01', 1)");
    $mysqli->query("INSERT INTO usuarios (nombre, correo, clave_hash, rol, activo, creado_en, institucion_id) VALUES ('Angel', 'angelzamora@test.com', '$hash', 'administrador', 1, NOW(), 1)");
    echo "Admin created: angelzamora@test.com / Admin123!\n";
}

echo "\nDone.\n";
$mysqli->close();
