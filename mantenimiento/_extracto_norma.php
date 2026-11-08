<?php
$archivo = $argv[1];
$terminos = array_slice($argv, 2);
$html = file_get_contents($archivo);
$texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$texto = preg_replace('/\s+/u', ' ', $texto);
if ($texto === null) { exit("sin texto\n"); }
foreach ($terminos as $termino) {
    $pos = 0; $n = 0;
    while (($pos = mb_stripos($texto, $termino, $pos)) !== false && $n < 4) {
        echo "== $termino ==\n" . mb_substr($texto, max(0, $pos - 120), 360) . "\n\n";
        $pos += mb_strlen($termino); $n++;
    }
}
