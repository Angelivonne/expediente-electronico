<?php

if (!function_exists('sanoTexto')) {
    function sanoTexto(string $texto): string
    {
        return $texto;
    }
}

require_once __DIR__ . '/../app/datos/catalogos.php';
require_once __DIR__ . '/../app/servicios/clinica/dictado.php';
require_once __DIR__ . '/../app/servicios/clinica/redaccion_dictado.php';

$casos = [
    'No me duele la panza.' => static function (array $campos): bool {
        return !isset($campos['motivo_consulta']);
    },
    'No tengo fiebre.' => static function (array $campos): bool {
        return !isset($campos['motivo_consulta']);
    },
    '¿Te duele la panza?' => static function (array $campos): bool {
        return $campos === [];
    },
    'Me duele la pansa desde ayer.' => static function (array $campos): bool {
        return isset($campos['motivo_consulta'])
            && str_contains(mb_strtolower($campos['motivo_consulta'], 'UTF-8'), 'dolor abdominal');
    },
    'Nacida en Jalisco, nacionalidad mexicana.' => static function (array $campos): bool {
        return ($campos['entidad_nacimiento'] ?? null) === 'JC'
            && ($campos['nacionalidad'] ?? null) === 'Mexicana';
    },
];

foreach ($casos as $texto => $verificar) {
    $campos = camposDeDictado($texto);
    if (!$verificar($campos)) {
        fwrite(STDERR, "Fallo en: {$texto}\n" . json_encode($campos, JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}

$dialogoRespondido = camposDeDictado('¿Te duele la panza? Sí, me duele la panza desde ayer.');
if (!isset($dialogoRespondido['motivo_consulta'])) {
    fwrite(STDERR, "No se conservó la respuesta después de la pregunta.\n");
    exit(1);
}

$redactado = dictadoRedactarTexto('¿Te duele la panza? Me duele la pansa desde ayer.');
if (str_contains($redactado, '?.') || !preg_match('/\?\s+/u', $redactado)) {
    fwrite(STDERR, "Puntuacion inesperada: {$redactado}\n");
    exit(1);
}
$negacionRedactada = dictadoRedactarTexto('No me duele la panza.');
if ($negacionRedactada !== 'Niega dolor abdominal.') {
    fwrite(STDERR, "Negacion redactada incorrectamente: {$negacionRedactada}\n");
    exit(1);
}
$fiebreNegadaRedactada = dictadoRedactarTexto('No tengo fiebre.');
if ($fiebreNegadaRedactada !== 'Niega fiebre.') {
    fwrite(STDERR, "Fiebre negada redactada incorrectamente: {$fiebreNegadaRedactada}\n");
    exit(1);
}
$primeraPersona = dictadoRedactarTexto('Tengo tos desde ayer.');
if (!str_starts_with($primeraPersona, 'Refiere tos')) {
    fwrite(STDERR, "Primera persona redactada incorrectamente: {$primeraPersona}\n");
    exit(1);
}
$terceraPersona = dictadoRedactarTexto('La paciente refiere que le duele la panza desde ayer.');
if (!str_contains($terceraPersona, 'La paciente refiere dolor abdominal')) {
    fwrite(STDERR, "Tercera persona redactada incorrectamente: {$terceraPersona}\n");
    exit(1);
}

echo "Pruebas de dictado correctas.\n";
