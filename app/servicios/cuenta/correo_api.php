<?php
// Envio de correo por API (SendGrid) para Railway/cloud donde SMTP esta bloqueado.
// Configuracion: privado/correo_api.php con ['proveedor' => 'sendgrid', 'api_key' => '...', 'remitente' => '...']
const ARCHIVO_CORREO_API = __DIR__ . '/../../../privado/correo_api.php';

function configuracionCorreoApi(): ?array
{
    if (!is_file(ARCHIVO_CORREO_API)) {
        return null;
    }
    $cfg = include ARCHIVO_CORREO_API;
    return is_array($cfg) && !empty($cfg['api_key']) ? $cfg : null;
}

function enviarCorreoApi(array $cfg, string $para, string $asunto, string $texto): ?string
{
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return 'Correo de destino no valido.';
    }
    $proveedor = $cfg['proveedor'] ?? 'sendgrid';
    $apiKey = (string) $cfg['api_key'];
    $remitente = (string) ($cfg['remitente'] ?? MAIL_REMITENTE);

    if ($proveedor === 'sendgrid') {
        $payload = [
            'personalizations' => [['to' => [['email' => $para]]]],
            'from' => ['email' => $remitente],
            'subject' => $asunto,
            'content' => [['type' => 'text/plain', 'value' => $texto]],
        ];
        $curl = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $cuerpo = curl_exec($curl);
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($cuerpo === false || $error) {
            return 'Error de conexion con SendGrid.';
        }
        if ($codigo >= 200 && $codigo < 300) {
            return null;
        }
        return 'SendGrid rechazo el envio (HTTP ' . $codigo . '): ' . substr((string) $cuerpo, 0, 200);
    }

    return 'Proveedor no soportado: ' . $proveedor;
}
