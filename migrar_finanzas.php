<?php
/**
 * Script de migración inmediata para asegurar todas las columnas de finanzas y catálogos en Railway.
 */
require_once __DIR__ . '/app/nucleo/inicio.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$columnas = [
    'vence_en'           => 'DATE NULL',
    'servicio_id'        => 'INT UNSIGNED NULL',
    'establecimiento_id' => 'INT UNSIGNED NULL',
    'factura_id'         => 'INT UNSIGNED NULL',
    'referencia'         => 'VARCHAR(80) NULL',
    'estado'             => "VARCHAR(20) NOT NULL DEFAULT 'pagado'",
    'metodo'             => 'VARCHAR(30) NULL',
    'descripcion'        => 'VARCHAR(255) NULL',
    'institucion_id'     => 'INT UNSIGNED NULL',
];

echo "=== MIGRANDO FINANZAS_MOVIMIENTOS ===\n";
foreach ($columnas as $col => $def) {
    if (!columnaExiste('finanzas_movimientos', $col)) {
        try {
            $pdo->exec("ALTER TABLE `finanzas_movimientos` ADD COLUMN `$col` $def");
            columnaExiste('finanzas_movimientos', $col, true);
            echo "OK: agregado $col\n";
        } catch (Throwable $e) {
            echo "ERR: $col -> " . $e->getMessage() . "\n";
        }
    } else {
        echo "YA EXISTE: $col\n";
    }
}

echo "\n=== MIGRANDO CATÁLOGOS_META ===\n";
if (!tablaExiste('catalogos_meta')) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `catalogos_meta` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `clave` VARCHAR(60) NOT NULL UNIQUE,
            `fuente` VARCHAR(255) NULL,
            `version` VARCHAR(60) NULL,
            `vigencia` DATE NULL,
            `responsable` VARCHAR(160) NULL,
            `notas` TEXT NULL,
            `actualizado_en` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "OK: tabla catalogos_meta creada\n";
    } catch (Throwable $e) {
        echo "ERR catalogos_meta: " . $e->getMessage() . "\n";
    }
} else {
    echo "YA EXISTE: catalogos_meta\n";
}

echo "\nCOMPLETADO.\n";
