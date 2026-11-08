<?php
/**
 * Revisión de retención del expediente clínico (NOM-004 5.4: mínimo 5 años desde el último acto médico).
 *
 * No borra nada: solo informa qué expedientes ya cumplieron el periodo y cuáles siguen dentro del plazo.
 * La conservación se cumple porque el sistema no tiene borrado físico de pacientes ni de historial:
 * solo baja lógica (eliminado_en) y respaldos diarios.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

const ANIOS_RETENCION = 5;
$limite = date('Y-m-d', strtotime('-' . ANIOS_RETENCION . ' years'));

$consulta = db()->prepare(
    "SELECT p.id, p.folio, CONCAT_WS(' ', p.nombre, p.primer_apellido, p.segundo_apellido) AS paciente,
            MAX(h.fecha) AS ultimo_acto
       FROM pacientes p
       LEFT JOIN historial_clinico h ON h.paciente_id = p.id
      GROUP BY p.id, p.folio, p.nombre, p.primer_apellido, p.segundo_apellido
      ORDER BY ultimo_acto"
);
$consulta->execute();
$filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

$fuera = array_values(array_filter($filas, fn ($p) => $p['ultimo_acto'] !== null && substr((string) $p['ultimo_acto'], 0, 10) < $limite));
$dentro = count($filas) - count($fuera);

echo "Retención mínima: " . ANIOS_RETENCION . " años (corte: $limite)\n";
echo "Pacientes dentro del plazo: $dentro\n";
echo "Pacientes que ya cumplieron el plazo (revisar archivo, no borrar): " . count($fuera) . "\n";
foreach ($fuera as $p) {
    echo '  · ' . $p['folio'] . ' · ' . mb_substr((string) $p['paciente'], 0, 40) . ' · último acto: ' . substr((string) $p['ultimo_acto'], 0, 10) . "\n";
}
echo "\nSin ruta de borrado físico: las bajas son lógicas (eliminado_en) y el historial clínico no tiene DELETE.\n";
