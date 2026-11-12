<?php

const VERSION_CONSENTIMIENTO_MORBILIDAD = '1.0';

function textoConsentimientoMorbilidad(string $institucion, string $destinatario): string
{
    return 'Autorizo voluntariamente a ' . $institucion
        . ' a calcular y compartir con ' . $destinatario
        . ' estadísticas agregadas de morbilidad obtenidas de mi expediente, solo para análisis estadístico. '
        . 'El informe no incluye nombres, CURP, folio, edad ni sexo; los diagnósticos con menos de cinco pacientes se excluyen. '
        . 'Esta autorización no condiciona mi atención. Puedo retirarla para informes futuros. '
        . 'La información que ya haya sido entregada no puede recuperarse ni retirarse de copias previas.';
}

function guardarEvidenciaConsentimientoMorbilidad(array $archivo, int $institucionId): array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($archivo['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('Adjunta una copia firmada en PDF, JPG o PNG.');
    }
    if ((int) ($archivo['size'] ?? 0) < 1 || (int) $archivo['size'] > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('La copia debe pesar como máximo 8 MB.');
    }

    $temporal = (string) $archivo['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporal);
    $extensiones = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($extensiones[$mime])) {
        throw new InvalidArgumentException('El formato de la copia no está permitido. Usa PDF, JPG o PNG.');
    }
    if ($mime === 'application/pdf') {
        if (file_get_contents($temporal, false, null, 0, 5) !== '%PDF-') {
            throw new InvalidArgumentException('El archivo PDF no tiene una firma válida.');
        }
    } else {
        $imagen = @getimagesize($temporal);
        if (!$imagen || ($imagen['mime'] ?? '') !== $mime) {
            throw new InvalidArgumentException('El archivo de imagen no es válido.');
        }
    }

    $sha256 = hash_file('sha256', $temporal);
    if ($sha256 === false) {
        throw new RuntimeException('No se pudo calcular la huella de la copia firmada.');
    }

    $directorio = dirname(__DIR__, 3) . '/privado/subidas/consentimientos_morbilidad/' . $institucionId;
    if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento privado de consentimientos.');
    }
    $nombreGuardado = bin2hex(random_bytes(32)) . '.' . $extensiones[$mime];
    if (!guardarSubida($temporal, $directorio . DIRECTORY_SEPARATOR . $nombreGuardado)) {
        throw new RuntimeException('No se pudo guardar la copia firmada.');
    }

    return [
        'archivo' => $nombreGuardado,
        'nombre' => mb_substr(basename((string) ($archivo['name'] ?? 'consentimiento')), 0, 200),
        'mime' => $mime,
        'sha256' => $sha256,
        'ruta' => $directorio . DIRECTORY_SEPARATOR . $nombreGuardado,
    ];
}

function rutaEvidenciaConsentimientoMorbilidad(int $institucionId, string $nombre): ?string
{
    if (!preg_match('/^[a-f0-9]{64}\.(pdf|jpg|png)$/', $nombre)) {
        return null;
    }
    $directorio = realpath(dirname(__DIR__, 3) . '/privado/subidas/consentimientos_morbilidad/' . $institucionId);
    if ($directorio === false) {
        return null;
    }
    $ruta = realpath($directorio . DIRECTORY_SEPARATOR . $nombre);
    if ($ruta === false || !str_starts_with($ruta, $directorio . DIRECTORY_SEPARATOR) || !is_file($ruta)) {
        return null;
    }
    return $ruta;
}
