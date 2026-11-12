<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/config/config.php';
require_once $raiz . '/config/db.php';
require_once $raiz . '/app/nucleo/funciones.php';
require_once $raiz . '/app/servicios/sistema/respaldos.php';

function salidaActualizacionHistorial(string $mensaje, bool $error = false): never
{
    fwrite($error ? STDERR : STDOUT, ($error ? "[ERROR] " : "[OK] ") . $mensaje . PHP_EOL);
    exit($error ? 1 : 0);
}

function privilegiosEnEsquema(PDO $pdo): array
{
    $alcance = '/\\bON\\s+(?:\\*\\.\\*|`?' . preg_quote(DB_NOMBRE, '/') . '`?\\.\\*)\\s+TO\\b/i';
    $resultado = [];
    foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
        if (preg_match('/^\\s*GRANT\\s+(.+?)\\s+ON\\s+/i', $grant, $coincidencia)
            && preg_match($alcance, $grant)) {
            $resultado[] = strtoupper($coincidencia[1]) . ' ' . strtoupper($grant);
        }
    }
    return $resultado;
}

function tienePrivilegiosEsquema(PDO $pdo, array $permisos): bool
{
    foreach (privilegiosEnEsquema($pdo) as $grant) {
        if (str_contains($grant, 'ALL PRIVILEGES')) {
            return true;
        }
        $coinciden = true;
        foreach ($permisos as $permiso) {
            if (!preg_match('/\\b' . preg_quote($permiso, '/') . '\\b/', $grant)) {
                $coinciden = false;
                break;
            }
        }
        if ($coinciden) {
            return true;
        }
    }
    return false;
}

function puedeLeerTablaNueva(PDO $pdo): bool
{
    return tienePrivilegiosEsquema($pdo, ['SELECT']);
}

function puedeOtorgarPrivilegios(PDO $pdo): bool
{
    foreach (privilegiosEnEsquema($pdo) as $grant) {
        if (str_contains($grant, 'WITH GRANT OPTION')) {
            return true;
        }
    }
    return false;
}

function credencialesMigracion(PDO $aplicacion): array
{
    $candidatas = [['usuario' => DB_USUARIO, 'clave' => DB_CLAVE]];
    $archivo = dirname(__DIR__) . '/privado/respaldo_bd.php';
    if (is_file($archivo)) {
        $credenciales = require $archivo;
        if (is_array($credenciales) && !empty($credenciales['usuario'])) {
            $candidatas[] = [
                'usuario' => (string) $credenciales['usuario'],
                'clave' => (string) ($credenciales['clave'] ?? ''),
            ];
        }
    }

    foreach ($candidatas as $credencial) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PUERTO, DB_NOMBRE),
                $credencial['usuario'],
                $credencial['clave'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            if (!tienePrivilegiosEsquema($pdo, ['CREATE', 'ALTER', 'TRIGGER', 'REFERENCES'])) {
                continue;
            }
            if (!puedeLeerTablaNueva($aplicacion) && !puedeOtorgarPrivilegios($pdo)) {
                continue;
            }
            return [$credencial, $pdo];
        } catch (Throwable) {
            continue;
        }
    }

    throw new RuntimeException(
        'No hay una cuenta disponible para instalar el índice y dar acceso de lectura a la aplicación. No se modificó la base.'
    );
}

function tablaBusquedaHistorialLista(PDO $pdo): bool
{
    $consulta = $pdo->prepare(
        'SELECT COUNT(DISTINCT INDEX_NAME)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_TYPE = ?'
    );
    $consulta->execute(['historial_clinico_busqueda', 'FULLTEXT']);
    $indice = (int) $consulta->fetchColumn() > 0;

    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE()
            AND TRIGGER_NAME IN (?, ?)'
    );
    $consulta->execute(['historial_busqueda_ai', 'historial_busqueda_au']);
    return $indice && (int) $consulta->fetchColumn() === 2;
}

function tieneAccesoLecturaBusqueda(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM historial_clinico_busqueda LIMIT 0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function otorgarLecturaBusqueda(PDO $pdo): bool
{
    $aplicacion = db();
    if (tieneAccesoLecturaBusqueda($aplicacion)) {
        return true;
    }

    if (!puedeOtorgarPrivilegios($pdo)) {
        return false;
    }

    $cuenta = (string) $aplicacion->query('SELECT CURRENT_USER()')->fetchColumn();
    [$usuario, $host] = array_pad(explode('@', $cuenta, 2), 2, 'localhost');
    $sql = 'GRANT SELECT ON `' . str_replace('`', '``', DB_NOMBRE) . '`.`historial_clinico_busqueda` TO '
         . $pdo->quote(trim($usuario, '`')) . '@' . $pdo->quote(trim($host, '`'));
    $pdo->exec($sql);
    return tieneAccesoLecturaBusqueda(db());
}

function ejecutarMigracionBusquedaHistorial(string $opciones): void
{
    $archivo = dirname(__DIR__) . '/database/migracion_busqueda_historial.sql';
    if (!is_file($archivo)) {
        throw new RuntimeException('Falta database/migracion_busqueda_historial.sql.');
    }
    $mysql = MYSQL_BIN . '/mysql.exe';
    if (!is_file($mysql)) {
        throw new RuntimeException('No se encontró mysql.exe para aplicar la migración.');
    }

    $proceso = proc_open(
        [$mysql, '--defaults-extra-file=' . $opciones, '--default-character-set=utf8mb4', '--binary-mode', DB_NOMBRE],
        [0 => ['file', $archivo, 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['pipe', 'w']],
        $tuberias,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo iniciar mysql.exe para aplicar la migración.');
    }

    $error = stream_get_contents($tuberias[2]);
    fclose($tuberias[2]);
    $codigo = proc_close($proceso);
    if ($codigo !== 0) {
        throw new RuntimeException('MySQL no pudo aplicar la búsqueda clínica: ' . trim((string) $error));
    }
}

try {
    $pdo = db();
    if (tablaBusquedaHistorialLista($pdo)) {
        if (!tieneAccesoLecturaBusqueda($pdo)) {
            [, $pdoMigracion] = credencialesMigracion($pdo);
            if (!otorgarLecturaBusqueda($pdoMigracion)) {
                salidaActualizacionHistorial(
                    'El índice ya existe, pero falta SELECT sobre historial_clinico_busqueda para el usuario de la aplicación.',
                    true
                );
            }
        }
        salidaActualizacionHistorial('La búsqueda de notas clínicas ya está actualizada.');
    }

    [$credenciales, $pdoMigracion] = credencialesMigracion($pdo);

    $candado = candadoRespaldos(false);
    if (!$candado) {
        salidaActualizacionHistorial('Hay otro respaldo o actualización en curso; vuelve a iniciar el expediente.', true);
    }

    $opciones = null;
    $mensajeExito = '';
    try {
        $respaldo = crearRespaldo('antes_de_migracion', $candado, $credenciales);
        if (!$respaldo['ok']) {
            throw new RuntimeException('No se aplicó ningún cambio porque falló el respaldo previo: ' . $respaldo['error']);
        }

        $opciones = archivoOpcionesMysql($credenciales);
        ejecutarMigracionBusquedaHistorial($opciones);

        if (!tablaBusquedaHistorialLista($pdo)) {
            throw new RuntimeException('La migración terminó, pero no aparecen el índice y sus triggers.');
        }
        if (!otorgarLecturaBusqueda($pdoMigracion)) {
            throw new RuntimeException(
                'Se creó el índice, pero el usuario de la aplicación no tiene SELECT sobre la tabla auxiliar.'
            );
        }

        $mensajeExito = 'Búsqueda clínica activada. Respaldo previo guardado como ' . $respaldo['archivo'] . '.';
    } finally {
        if ($opciones !== null) {
            @unlink($opciones);
        }
        soltarCandado($candado);
    }
    salidaActualizacionHistorial($mensajeExito);
} catch (Throwable $e) {
    error_log('[actualizar_busqueda_historial] ' . $e->getMessage());
    salidaActualizacionHistorial($e->getMessage(), true);
}
