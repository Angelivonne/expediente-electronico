<?php
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/config/db.php";
$pdo = db();
$st = $pdo->query("SHOW TABLES");
$tables = $st->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables: " . count($tables) . "\n";
foreach ($tables as $t) echo "  $t\n";
echo "\nMissing tables:\n";
$expected = array("asistente_memoria","bitacora","citas","codigos_acceso","codigos_acceso_usos","codigos_verificacion","configuracion","conversiones","dictados_calificacion","firmas","historial_clinico","imagenes_medicas","instituciones","invitaciones","limites","notificaciones","pacientes","pagina_filas","pagina_permisos","paginas","paginas_favoritas","peticiones_idempotentes","presencia","push_avisos_enviados","push_cola","push_preferencias","push_suscripciones","recuperaciones_clave","sincronizacion_cola","usuarios","finanzas_movimientos","finanzas_cuentas","finanzas_categorias");
foreach ($expected as $t) {
    if (!in_array($t, $tables)) echo "  MISSING: $t\n";
}

