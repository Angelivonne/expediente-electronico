<?php
// Script de diagnóstico directo a Railway MySQL
$host   = 'mysql-production-8e33.up.railway.app';
$port   = 48037;
$dbname = 'railway';
$user   = 'root';
$pass   = 'GVXqEoPPRHXZVsXkjlqoCKQQEwzHfrMO';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "Conexion exitosa a Railway MySQL\n\n";
} catch (Throwable $e) {
    echo "No se pudo conectar: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== DIAGNOSTICO DE COLUMNA institucion_id ===\n\n";

$tablas = ['pacientes', 'citas', 'usuarios', 'historial_clinico', 'bitacora', 'comentarios',
           'finanzas_movimientos', 'finanzas_facturas', 'servicios_clinicos', 'plantillas'];

$faltantes = [];
foreach ($tablas as $tabla) {
    try {
        $st = $pdo->query("SHOW TABLES LIKE '$tabla'");
        if (!$st->fetch()) {
            echo "NOEXISTE $tabla\n";
            continue;
        }
        $st = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE 'institucion_id'");
        $existe = (bool) $st->fetch();
        if ($existe) {
            echo "OK $tabla.institucion_id EXISTE\n";
        } else {
            echo "FALTA $tabla.institucion_id\n";
            $faltantes[] = $tabla;
        }
    } catch (Throwable $e) {
        echo "ERROR $tabla: " . $e->getMessage() . "\n";
    }
}

echo "\n=== APLICANDO ALTER TABLE ===\n\n";

foreach ($faltantes as $tabla) {
    try {
        $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN institucion_id INT UNSIGNED NULL");
        $st = $pdo->query("SHOW COLUMNS FROM `$tabla` LIKE 'institucion_id'");
        if ($st->fetch()) {
            echo "EXITO $tabla.institucion_id ANADIDO\n";
        } else {
            echo "FALLO $tabla columna sigue faltando\n";
        }
    } catch (Throwable $e) {
        echo "ERROR $tabla: " . $e->getMessage() . "\n";
    }
}

if (empty($faltantes)) {
    echo "TODAS las tablas ya tienen institucion_id\n";
    echo "\n=== PROBANDO QUERY ===\n";
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM pacientes c WHERE c.institucion_id = 1");
        echo "QUERY OK: " . $st->fetchColumn() . " registros\n";
    } catch (Throwable $e) {
        echo "QUERY FALLO: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIN ===\n";
