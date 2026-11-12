<?php
/** Transporte permitido para el módulo de conexiones entre instituciones. */
function conexionesAccesoLocal(): bool
{
    $host = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));
    $cliente = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && in_array($cliente, ['127.0.0.1', '::1'], true);
}

function conexionesProxySeguro(): bool
{
    $cliente = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $protocoloReenviado = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return in_array($cliente, ['127.0.0.1', '::1'], true)
        && $protocoloReenviado === 'https';
}

function conexionesAccesoRedLocal(): bool
{
    $host = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));
    $cliente = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $hostNumero = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    $clienteNumero = filter_var($cliente, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($hostNumero === false || $clienteNumero === false) {
        return false;
    }

    $esPrivada = static function (string $ip): bool {
        $numero = ip2long($ip);
        return $numero !== false && (
            ($numero & 0xFF000000) === 0x0A000000
            || ($numero & 0xFFF00000) === 0xAC100000
            || ($numero & 0xFFFF0000) === 0xC0A80000
        );
    };

    return $esPrivada($host)
        && $esPrivada($cliente)
        && ((ip2long($host) & 0xFFFFFF00) === (ip2long($cliente) & 0xFFFFFF00));
}

function conexionesCanalProtegido(): bool
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return $https || conexionesAccesoLocal() || conexionesProxySeguro() || conexionesAccesoRedLocal();
}
