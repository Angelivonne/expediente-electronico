<?php
/**
 * Ensayo de restauración de respaldos (NOM-024: disponibilidad e integridad).
 *
 * Toma el respaldo más reciente, lo descomprime, lo restaura en una base temporal
 * (expediente_femeci_restauracion_prueba) y compara el número de tablas con la base real.
 * Nunca toca la base de producción. Al terminar borra la base temporal.
 *
 * Uso:  C:\xampp\php\php.exe mantenimiento\probar_restauracion.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/servicios/sistema/respaldos.php';

const DB_PRUEBA_RESTAURACION = 'expediente_femeci_restauracion_prueba';

$respaldos = listarRespaldos();
if (!$respaldos) {
    fwrite(STDERR, "No hay respaldos para probar.\n");
    exit(1);
}
$nombre = (string) ($respaldos[0]['archivo'] ?? '');
$ruta = rutaRespaldo($nombre);
if (!$ruta) {
    fwrite(STDERR, "No se encontró el archivo del respaldo.\n");
    exit(1);
}

// 1) Descomprimir a un archivo temporal
$temporal = tempnam(sys_get_temp_dir(), 'restauracion_') . '.sql';
$entrada = gzopen($ruta, 'rb');
$salida = fopen($temporal, 'wb');
if (!$entrada || !$salida) {
    fwrite(STDERR, "No se pudo preparar la descompresión.\n");
    exit(1);
}
while (!gzeof($entrada)) {
    fwrite($salida, gzread($entrada, 1024 * 1024));
}
gzclose($entrada);
fclose($salida);

// 2) Credenciales de administrador para crear la base temporal
$texto = (string) file_get_contents(__DIR__ . '/../privado/credenciales.txt');
$clave = preg_match('/MySQL root\s+\S+\s+(\S+)/u', $texto, $m) ? $m[1] : '';
$cnf = tempnam(sys_get_temp_dir(), 'restauracion_') . '.cnf';
file_put_contents($cnf, "[client]\nuser=root\npassword=\"$clave\"\nhost=127.0.0.1\nport=3306\ndefault-character-set=utf8mb4\n");
$mysql = MYSQL_BIN . '/mysql.exe';

function ejecutarMysql(string $mysql, string $cnf, string $sql, ?string $base = null): array
{
    $comando = [$mysql, '--defaults-extra-file=' . $cnf];
    if ($base !== null) {
        $comando[] = $base;
    }
    $comando[] = '-e';
    $comando[] = $sql;
    $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
    $salida = stream_get_contents($tuberias[1]);
    $error  = stream_get_contents($tuberias[2]);
    fclose($tuberias[1]);
    fclose($tuberias[2]);
    $codigo = proc_close($proceso);
    return [$codigo, trim($salida), trim($error)];
}

// 3) Crear base temporal e importar
[$c1, , $e1] = ejecutarMysql($mysql, $cnf, 'DROP DATABASE IF EXISTS `' . DB_PRUEBA_RESTAURACION . '`; CREATE DATABASE `' . DB_PRUEBA_RESTAURACION . '` CHARACTER SET utf8mb4;');
if ($c1 !== 0) {
    fwrite(STDERR, "No se pudo crear la base temporal: $e1\n");
    exit(1);
}
$comandoImportar = [$mysql, '--defaults-extra-file=' . $cnf, DB_PRUEBA_RESTAURACION];
$proceso = proc_open($comandoImportar, [0 => ['file', $temporal, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
$salidaImportar = stream_get_contents($tuberias[1]);
$errorImportar  = stream_get_contents($tuberias[2]);
fclose($tuberias[1]);
fclose($tuberias[2]);
$codigoImportar = proc_close($proceso);

// 4) Comparar tablas
[$c2, $tablasPrueba] = ejecutarMysql($mysql, $cnf, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_PRUEBA_RESTAURACION . "'");
$tablasReales = (int) db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
if (preg_match('/(\d+)\s*$/', trim($tablasPrueba), $mTablas)) { $tablasPrueba = (int) $mTablas[1]; } else { $tablasPrueba = 0; }

// 5) Limpiar
ejecutarMysql($mysql, $cnf, 'DROP DATABASE IF EXISTS `' . DB_PRUEBA_RESTAURACION . '`;');
@unlink($temporal);
@unlink($cnf);

echo "Respaldo probado: $nombre\n";
echo "Importación: " . ($codigoImportar === 0 ? 'OK' : 'FALLÓ') . "\n";
if ($codigoImportar !== 0) {
    echo "Detalle: " . mb_substr($errorImportar !== '' ? $errorImportar : $salidaImportar, 0, 500) . "\n";
}
echo "Tablas en la base real: $tablasReales · tablas restauradas: $tablasPrueba\n";
$ok = $codigoImportar === 0 && $tablasPrueba > 0 && $tablasPrueba === $tablasReales;
echo $ok ? "RESULTADO: restauración verificada sin tocar producción.\n" : "RESULTADO: revisar la restauración.\n";
exit($ok ? 0 : 1);
