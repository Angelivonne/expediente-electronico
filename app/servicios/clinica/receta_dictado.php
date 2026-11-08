<?php
/**
 * Reconocimiento de medicamentos del catálogo institucional dentro de un dictado.
 *
 * A partir del texto que transcribe el navegador identifica los medicamentos que existen en
 * la tabla `medicamentos` y arma cada renglón de la receta con los datos que exige la
 * NOM-004-SSA3-2012 (6.2.6) y el Reglamento de Insumos para la Salud: nombre genérico,
 * presentación, dosis, vía, frecuencia y duración. La presentación y la vía se toman del
 * catálogo oficial (Compendio Nacional de Insumos, NOM-024), no se inventan.
 *
 * La cantidad a surtir se calcula de lo que dictó el médico (tomas por día × días); si el
 * médico dictó una cantidad explícita ("surtir 21 tabletas"), esa manda.
 *
 * El reconocimiento tolera errores típicos del dictado: además del nombre exacto del catálogo
 * compara una clave fonética del español (b/v, s/z/c, h muda, j/g…) y una distancia de edición
 * corta, de modo que «omeprasol» encuentra «Omeprazol» y «losartan» encuentra «Losartán».
 * También entiende nombres comerciales frecuentes («Tempra» por paracetamol).
 */

require_once __DIR__ . '/dictado.php';
require_once __DIR__ . '/../../datos/registros_clinicos.php';

/** Palabras que no identifican un medicamento y solo ensucian la búsqueda en el catálogo. */
const DICTADO_RECETA_IGNORAR = [
    'cada', 'hora', 'horas', 'dia', 'dias', 'semana', 'semanas', 'mes', 'meses', 'durante',
    'tomar', 'tome', 'tomara', 'tomando', 'aplicar', 'aplicacion', 'administrar', 'ingerir',
    'ingiera', 'tableta', 'tabletas', 'comprimido', 'comprimidos', 'capsula', 'capsulas',
    'gragea', 'grageas', 'miligramo', 'miligramos', 'gramo', 'gramos', 'mililitro', 'mililitros',
    'microgramo', 'microgramos', 'unidad', 'unidades', 'dosis', 'via', 'oral', 'intravenosa',
    'intravenoso', 'intramuscular', 'subcutanea', 'subcutaneo', 'sublingual', 'topica', 'topico',
    'inhalada', 'inhalado', 'nasal', 'rectal', 'vaginal', 'oftalmica', 'oftalmico', 'otica',
    'otico', 'tratamiento', 'medicamento', 'medicamentos', 'receta', 'receto', 'prescribo',
    'indico', 'surtir', 'surtame', 'dispensar', 'dispensa', 'cantidad', 'frasco', 'frascos',
    'caja', 'cajas', 'sobre', 'sobres', 'ampolleta', 'ampolletas', 'gota', 'gotas', 'paciente',
    'doctor', 'doctora', 'manana', 'tarde', 'noche', 'ayunas', 'alimento', 'alimentos',
    'comida', 'comidas', 'agua', 'para', 'por', 'del', 'las', 'los', 'que', 'con', 'una', 'uno',
    'un', 'medio', 'media', 'veces', 'vez', 'total', 'hasta', 'terminar', 'tiempo', 'indefinido',
    'indefinidamente', 'aplicaciones', 'inhalaciones', 'inyectable', 'solucion', 'suspension',
    'jarabe', 'polvo', 'crema', 'pomada', 'unguento', 'gel', 'parche', 'parches', 'supositorio',
    'supositorios', 'ovulo', 'ovulos', 'nebulizacion', 'nebulizaciones', 'nebulizador',
    'alcohol', 'etilico', 'tabaco', 'tabaquismo', 'cigarro', 'cigarros', 'fuma', 'fumador',
    'drogas', 'marihuana', 'cocaina', 'sustancias', 'niega',
];

/** Formas farmacéuticas que se pueden contar para calcular la cantidad a surtir. */
const DICTADO_RECETA_FORMAS = [
    'tableta', 'tabletas', 'comprimido', 'comprimidos', 'capsula', 'capsulas', 'gragea',
    'grageas', 'gota', 'gotas', 'sobre', 'sobres', 'ampolleta', 'ampolletas', 'parche',
    'parches', 'aplicacion', 'aplicaciones', 'inhalacion', 'inhalaciones', 'nebulizacion',
    'nebulizaciones', 'supositorio', 'supositorios', 'ovulo', 'ovulos',
];

/** Números hablados que usa el dictado (se convierten a dígitos antes de interpretar). */
const DICTADO_RECETA_NUMEROS = [
    'cero' => 0, 'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4,
    'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
    'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15,
    'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
    'veinte' => 20, 'veintiuno' => 21, 'veintidos' => 22, 'veintitres' => 23,
    'veinticuatro' => 24, 'veinticinco' => 25, 'veintiseis' => 26, 'veintisiete' => 27,
    'veintiocho' => 28, 'veintinueve' => 29, 'treinta' => 30, 'cuarenta' => 40,
    'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80, 'noventa' => 90,
    'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'trescientos' => 300,
    'cuatrocientos' => 400, 'quinientos' => 500, 'seiscientos' => 600, 'setecientos' => 700,
    'ochocientos' => 800, 'novecientos' => 900, 'mil' => 1000,
];

/** Alias hablados de vías de administración → valor canónico del catálogo. */
const DICTADO_RECETA_VIAS = [
    'intravenosa' => 'Intravenosa', 'intravenoso' => 'Intravenosa', 'endovenosa' => 'Intravenosa',
    'iv' => 'Intravenosa', 'intramuscular' => 'Intramuscular', 'im' => 'Intramuscular',
    'subcutanea' => 'Subcutánea', 'subcutaneo' => 'Subcutánea', 'sc' => 'Subcutánea',
    'sublingual' => 'Sublingual', 'via oral' => 'Oral', 'por la boca' => 'Oral', 'oral' => 'Oral',
    'topica' => 'Tópica', 'topico' => 'Tópica', 'cutanea' => 'Tópica', 'crema' => 'Tópica',
    'pomada' => 'Tópica', 'unguento' => 'Tópica', 'gel' => 'Tópica',
    'inhalada' => 'Inhalada', 'inhalado' => 'Inhalada', 'inhalador' => 'Inhalada',
    'inhalaciones' => 'Inhalada', 'inhalacion' => 'Inhalada', 'aerosol' => 'Inhalada',
    'nebulizador' => 'Inhalada', 'nebulizacion' => 'Inhalada',
    'oftalmica' => 'Oftálmica', 'oftalmico' => 'Oftálmica', 'ocular' => 'Oftálmica',
    'colirio' => 'Oftálmica', 'ojos' => 'Oftálmica', 'otica' => 'Ótica', 'otico' => 'Ótica',
    'oido' => 'Ótica', 'oidos' => 'Ótica', 'nasal' => 'Nasal', 'nariz' => 'Nasal',
    'rectal' => 'Rectal', 'supositorio' => 'Rectal', 'vaginal' => 'Vaginal', 'ovulo' => 'Vaginal',
];

/**
 * Nombres comerciales y sinónimos hablados → grupo de palabras que designan lo mismo.
 * Sirve para que el dictado reconozca «Tempra» o «acetaminofén» aunque el catálogo diga
 * «Paracetamol», y al revés. Se compara por palabra completa, nunca por fragmento.
 */
const DICTADO_RECETA_ALIAS = [
    'paracetamol'      => ['acetaminofen', 'tempra', 'panadol', 'tylenol'],
    'metamizol'        => ['dipirona', 'prodolina', 'novalgina'],
    'ibuprofeno'       => ['motrin', 'advil', 'brufen'],
    'naproxeno'        => ['naprosyn', 'floginase'],
    'diclofenaco'      => ['voltaren', 'artren'],
    'ketorolaco'       => ['toradol', 'dolten'],
    'acido acetilsalicilico' => ['aspirina', 'asa'],
    'omeprazol'        => ['losec', 'omeprasol'],
    'pantoprazol'      => ['protonix'],
    'ranitidina'       => ['zantac'],
    'loratadina'       => ['claritin', 'loratadina'],
    'cetirizina'       => ['zyrtec'],
    'ambroxol'         => ['mucosolvan'],
    'salbutamol'       => ['ventolin', 'albuterol'],
    'budesonida'       => ['pulmicort'],
    'amoxicilina'      => ['amoxil'],
    'azitromicina'     => ['zithromax'],
    'ciprofloxacino'   => ['cipro'],
    'levofloxacino'    => ['levaquin'],
    'metformina'       => ['glucophage'],
    'losartan'         => ['cozaar'],
    'enalapril'        => ['renitec'],
    'captopril'        => ['capoten'],
    'amlodipino'       => ['norvasc'],
    'furosemida'       => ['lasix'],
    'espironolactona'  => ['aldactone'],
    'atorvastatina'    => ['lipitor'],
    'simvastatina'     => ['zocor'],
    'levotiroxina'     => ['synthroid', 'eutirox'],
    'sertralina'       => ['zoloft'],
    'fluoxetina'       => ['prozac'],
    'escitalopram'     => ['lexapro'],
    'alprazolam'       => ['xanax'],
    'clonazepam'       => ['rivotril'],
    'tramadol'         => ['tramal'],
    'prednisona'       => ['prednisone', 'meticorten'],
    'dexametasona'     => ['decadron'],
    'warfarina'        => ['coumadin'],
    'clopidogrel'      => ['plavix'],
];

/** Texto plano en minúsculas, sin acentos y sin signos, para comparar con el catálogo. */
function recetaDictadoNormalizar(string $texto): string
{
    $plano = function_exists('dictadoSinAcentos')
        ? dictadoSinAcentos($texto)
        : mb_strtolower($texto, 'UTF-8');
    // Conserva el punto decimal ("7.5 g") antes de quitar la puntuación.
    $plano = preg_replace('/(\d)[.,](\d)/u', "$1\x02$2", $plano) ?? $plano;
    $plano = preg_replace('/(?<=\d)(?=\p{L})|(?<=\p{L})(?=\d)/u', ' ', $plano) ?? $plano;
    $plano = preg_replace('/[^\p{L}\p{N}\x02]+/u', ' ', $plano) ?? $plano;
    $plano = str_replace("\x02", '.', $plano);
    return trim((string) preg_replace('/\s+/u', ' ', $plano));
}

/** Convierte los números hablados a dígitos ("cada ocho horas" → "cada 8 horas"). */
function recetaDictadoNumeros(string $texto): string
{
    $tokens = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $salida = [];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (preg_match('/^\d/u', $token)) {
            $salida[] = $token;
            continue;
        }
        if (!isset(DICTADO_RECETA_NUMEROS[$token])) {
            $salida[] = $token;
            continue;
        }
        $valor = 0;
        $consumidos = 0;
        $j = $i;
        while ($j < $total && $consumidos < 6) {
            $actual = $tokens[$j];
            if ($actual === 'y' && $j + 1 < $total && isset(DICTADO_RECETA_NUMEROS[$tokens[$j + 1]])) {
                $j++;
                continue;
            }
            if (!isset(DICTADO_RECETA_NUMEROS[$actual])) {
                break;
            }
            $numero = DICTADO_RECETA_NUMEROS[$actual];
            if ($numero === 1000) {
                $valor = ($valor === 0 ? 1 : $valor) * 1000;
            } else {
                $valor += $numero;
            }
            $consumidos++;
            $j++;
        }
        if ($consumidos > 0) {
            $salida[] = (string) $valor;
            $i = $j - 1;
        } else {
            $salida[] = $token;
        }
    }
    return implode(' ', $salida);
}

/** Nombre base del medicamento: quita dosis y forma ("Paracetamol 500 mg tabletas" → "paracetamol"). */
function recetaDictadoBaseNombre(string $nombre): string
{
    $plano = recetaDictadoNormalizar($nombre);
    if ($plano === '') {
        return '';
    }
    $base = [];
    foreach (explode(' ', $plano) as $token) {
        if ($base !== [] && (preg_match('/^\d/u', $token) || in_array($token, DICTADO_RECETA_FORMAS, true))) {
            break;
        }
        $base[] = $token;
    }
    return $base ? implode(' ', $base) : $plano;
}

/** Frases con las que el dictado puede nombrar un medicamento (nombre base, presentaciones y marcas). */
function recetaDictadoSinonimos(array $fila): array
{
    $nombre = trim((string) ($fila['nombre'] ?? ''));
    if ($nombre === '') {
        return [];
    }
    $partes = preg_split('/[\/|(),;]+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $partes[] = $nombre;
    $sinonimos = [];
    // Nombres comerciales y genéricos alternos del mismo principio activo.
    $agregarAlias = static function (string $termino) use (&$sinonimos): void {
        foreach (DICTADO_RECETA_ALIAS as $clave => $alias) {
            $grupo = array_merge([$clave], $alias);
            if (!in_array($termino, $grupo, true)) {
                continue;
            }
            foreach ($grupo as $variante) {
                if (mb_strlen($variante) >= 3) {
                    $sinonimos[$variante] = true;
                }
            }
        }
    };
    foreach ($partes as $parte) {
        $base = recetaDictadoBaseNombre(trim($parte));
        if (mb_strlen($base) < 3 || in_array($base, DICTADO_RECETA_IGNORAR, true)) {
            continue;
        }
        $sinonimos[$base] = true;
        $agregarAlias($base);
        // El dictado a veces solo dice una palabra del nombre (p. ej. "Hartmann" o "clavulánico").
        foreach (explode(' ', $base) as $palabra) {
            if (mb_strlen($palabra) >= 5
                && !in_array($palabra, DICTADO_RECETA_IGNORAR, true)
                && !in_array($palabra, DICTADO_RECETA_FORMAS, true)) {
                $sinonimos[$palabra] = true;
                $agregarAlias($palabra);
            }
        }
    }
    return array_keys($sinonimos);
}

/** Posición (bytes) de la frase en el texto plano; null si no aparece como palabra completa. */
function recetaDictadoBuscar(string $plano, string $frase): ?int
{
    if ($plano === '' || $frase === '') {
        return null;
    }
    $patron = '/(?<![\p{L}\p{N}])' . str_replace(' ', '\s+', preg_quote($frase, '/')) . '(?![\p{L}\p{N}])/u';
    return preg_match($patron, $plano, $m, PREG_OFFSET_CAPTURE) ? (int) $m[0][1] : null;
}

/** Clave fonética del español: acerca lo que el dictado escribe a como suena el medicamento. */
function recetaDictadoClaveFonetica(string $palabra): string
{
    $p = recetaDictadoNormalizar($palabra);
    if ($p === '') {
        return '';
    }
    $p = strtr($p, [
        'ph' => 'f', 'qu' => 'k', 'll' => 'y', 'ñ' => 'n',
        'v' => 'b', 'z' => 's',
        'ge' => 'je', 'gi' => 'ji', 'ce' => 'se', 'ci' => 'si',
        'x' => 'j', 'h' => '',
    ]);
    $p = str_replace(['c', 'q'], 'k', $p);
    $p = (string) preg_replace('/[^a-z]/', '', $p);
    // Las letras repetidas no cambian el sonido ("metformina"/"metformmina").
    return (string) preg_replace('/(.)\1+/u', '$1', $p);
}

/** ¿La palabra dictada se parece lo suficiente a la del catálogo? */
function recetaDictadoPalabraSimilar(string $dicha, string $catalogo): bool
{
    if ($dicha === $catalogo) {
        return true;
    }
    $ld = mb_strlen($dicha);
    $lc = mb_strlen($catalogo);
    if ($ld < 5 || $lc < 5 || abs($ld - $lc) > 2) {
        return false;
    }
    if (recetaDictadoClaveFonetica($dicha) === recetaDictadoClaveFonetica($catalogo)) {
        return true;
    }
    // Uno o dos caracteres de diferencia según el largo (errores de una letra del dictado).
    $maxima = ($ld >= 8 && $lc >= 8) ? 2 : 1;
    return levenshtein($dicha, $catalogo) <= $maxima;
}

/**
 * Busca una frase admitiendo errores de dictado. Devuelve la posición en bytes del primer
 * parecido razonable, o null. Se usa solo cuando la búsqueda exacta no encontró nada.
 */
function recetaDictadoBuscarAproximado(string $plano, string $frase): ?int
{
    // Cada concentración del mismo medicamento repite la frase: se memoriza el resultado
    // para no repetir el recorrido completo en cada renglón del catálogo.
    static $cache = [];
    $clave = md5($plano . "\0" . $frase);
    if (array_key_exists($clave, $cache)) {
        return $cache[$clave];
    }
    if ($plano === '' || $frase === '') {
        return $cache[$clave] = null;
    }
    $partes = preg_split('/\s+/u', $frase, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = preg_split('/\s+/u', $plano, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [];
    $n = count($partes);
    $total = count($tokens);
    if (!$n || $total < $n) {
        return $cache[$clave] = null;
    }
    // Una primera palabra muy corta produce demasiados falsos positivos.
    if (mb_strlen($partes[0]) < 4) {
        return $cache[$clave] = null;
    }
    for ($i = 0; $i + $n <= $total; $i++) {
        if (mb_strlen($tokens[$i][0]) < 4) {
            continue;
        }
        $coincide = true;
        for ($j = 0; $j < $n; $j++) {
            if (!recetaDictadoPalabraSimilar($partes[$j], $tokens[$i + $j][0])) {
                $coincide = false;
                break;
            }
        }
        if ($coincide) {
            return $cache[$clave] = (int) $tokens[$i][1];
        }
    }
    return $cache[$clave] = null;
}

/** Unidad hablada → unidad corta de la receta. */
function recetaDictadoUnidad(string $unidad): string
{
    $u = recetaDictadoNormalizar($unidad);
    return match (true) {
        str_starts_with($u, 'miligram') || $u === 'mg' => 'mg',
        str_starts_with($u, 'microgram') || $u === 'mcg' => 'mcg',
        str_starts_with($u, 'gram') || $u === 'g' => 'g',
        str_starts_with($u, 'mililitr') || $u === 'ml' => 'ml',
        str_contains($u, 'internacional') || $u === 'ui' => 'UI',
        default => $unidad,
    };
}

/** Etiqueta singular de una forma contable. */
function recetaDictadoFormaEtiqueta(string $forma): string
{
    $f = recetaDictadoNormalizar($forma);
    return match (true) {
        str_starts_with($f, 'tablet') => 'tableta',
        str_starts_with($f, 'comprim') => 'comprimido',
        str_starts_with($f, 'capsul') => 'cápsula',
        str_starts_with($f, 'grage') => 'gragea',
        str_starts_with($f, 'gota') => 'gota',
        str_starts_with($f, 'sobre') => 'sobre',
        str_starts_with($f, 'ampollet') => 'ampolleta',
        str_starts_with($f, 'parche') => 'parche',
        str_starts_with($f, 'aplicacion') => 'aplicación',
        str_starts_with($f, 'inhalacion') => 'inhalación',
        str_starts_with($f, 'nebuliz') => 'nebulización',
        str_starts_with($f, 'supositor') => 'supositorio',
        str_starts_with($f, 'ovul') => 'óvulo',
        default => $f,
    };
}

/** Plural de una forma contable. */
function recetaDictadoFormaPlural(string $forma): string
{
    return match ($forma) {
        'tableta' => 'tabletas',
        'comprimido' => 'comprimidos',
        'cápsula' => 'cápsulas',
        'gragea' => 'grageas',
        'gota' => 'gotas',
        'sobre' => 'sobres',
        'ampolleta' => 'ampolletas',
        'parche' => 'parches',
        'aplicación' => 'aplicaciones',
        'inhalación' => 'inhalaciones',
        'nebulización' => 'nebulizaciones',
        'supositorio' => 'supositorios',
        'óvulo' => 'óvulos',
        default => $forma,
    };
}

/** Número sin decimales innecesarios. */
function recetaDictadoCantidadTexto(float $cantidad): string
{
    $cantidad = round($cantidad, 1);
    if (abs($cantidad - round($cantidad)) < 0.001) {
        return (string) (int) round($cantidad);
    }
    return number_format($cantidad, 1, '.', '');
}

/** "1 tableta", "2 tabletas", "media tableta". */
function recetaDictadoCantidadForma(float $cantidad, string $forma): string
{
    if (abs($cantidad - 0.5) < 0.001) {
        return 'media ' . $forma;
    }
    return recetaDictadoCantidadTexto($cantidad) . ' ' . ($cantidad == 1 ? $forma : recetaDictadoFormaPlural($forma));
}

/** Quita "surtir 7 ampolletas" del texto de la dosis: eso es cantidad a dispensar, no dosis. */
function recetaDictadoSinCantidadSurtida(string $texto): string
{
    return (string) preg_replace(
        '/\b(?:cantidad(?:\s+a\s+surtir)?|surtir|surtame|dispensar|dispensa|en\s+total|total\s+a\s+surtir)'
        . '\s*(?:de)?\s*\d+\s*'
        . '(?:tabletas?|comprimidos?|capsulas?|grageas?|cajas?|frascos?|sobres?|ampolletas?|piezas?|ml|mililitros?)\b/u',
        ' ',
        $texto
    );
}

/** Texto de la dosis a partir de sus partes (formas contables y medida). */
function recetaDictadoDosisTexto(array $dosis): string
{
    $textoForma = $dosis['formas'] !== null
        ? recetaDictadoCantidadForma((float) $dosis['formas'], (string) $dosis['forma'])
        : '';
    $textoMedida = $dosis['medida'] !== null
        ? recetaDictadoCantidadTexto((float) $dosis['medida']) . ' ' . $dosis['unidad_medida']
        : '';
    if ($textoForma !== '' && $textoMedida !== '') {
        return $textoForma . ' de ' . $textoMedida;
    }
    return $textoForma !== '' ? $textoForma : $textoMedida;
}

/** Dosis dictada: medida (500 mg, 5 ml), número de formas (2 tabletas) y su texto. */
function recetaDictadoDosisDicha(string $segmento): array
{
    $texto = recetaDictadoSinCantidadSurtida(recetaDictadoNumeros($segmento));
    $resultado = ['texto' => '', 'formas' => null, 'forma' => '', 'medida' => null, 'unidad_medida' => ''];

    $formas = '(tabletas?|comprimidos?|capsulas?|grageas?|gotas?|sobres?|ampolletas?|parches?|aplicaciones?|inhalaciones?|nebulizaciones?|supositorios?|ovulos?)';
    if (preg_match('/\bmedia\s+' . $formas . '\b/u', $texto, $m)) {
        $resultado['formas'] = 0.5;
        $resultado['forma'] = recetaDictadoFormaEtiqueta($m[1]);
    } elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*' . $formas . '\b/u', $texto, $m)) {
        $resultado['formas'] = (float) str_replace(',', '.', $m[1]);
        $resultado['forma'] = recetaDictadoFormaEtiqueta($m[2]);
    }

    $unidades = '(mg|miligramos?|g|gramos?|mcg|microgramos?|ml|mililitros?|ui|unidades?\s+internacionales?)';
    // En "suspensión 7.5 g, 5 ml cada 8 horas" la dosis es la última medida dictada.
    if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*' . $unidades . '\b/u', $texto, $medidas, PREG_SET_ORDER) && $medidas) {
        $ultima = $medidas[count($medidas) - 1];
        $resultado['medida'] = (float) str_replace(',', '.', $ultima[1]);
        $resultado['unidad_medida'] = recetaDictadoUnidad($ultima[2]);
    } elseif (preg_match('/\bmedio\s+(gramo|mililitro|litro)\b/u', $texto, $m)) {
        $resultado['medida'] = 0.5;
        $resultado['unidad_medida'] = recetaDictadoUnidad($m[1]);
    }

    $resultado['texto'] = recetaDictadoDosisTexto($resultado);
    return $resultado;
}

/** Frecuencia dictada y su equivalente en tomas por día (para calcular la cantidad). */
function recetaDictadoFrecuenciaDe(string $segmento): array
{
    $texto = recetaDictadoNumeros($segmento);

    if (preg_match('/\bcada\s+media\s+hora\b/u', $texto)) {
        return ['texto' => 'Cada 30 minutos', 'por_dia' => 48.0];
    }
    if (preg_match('/\bcada\s+(\d+)\s+a\s+(\d+)\s+horas?\b/u', $texto, $m)) {
        $menor = min((int) $m[1], (int) $m[2]);
        return $menor > 0
            ? ['texto' => 'Cada ' . $m[1] . ' a ' . $m[2] . ' horas', 'por_dia' => 24 / $menor]
            : ['texto' => '', 'por_dia' => null];
    }
    if (preg_match('/\bcada\s+(\d+)\s*(horas?|h)\b/u', $texto, $m)) {
        $horas = (int) $m[1];
        if ($horas > 0 && $horas <= 72) {
            return [
                'texto' => $horas === 1 ? 'Cada hora' : 'Cada ' . $horas . ' horas',
                'por_dia' => 24 / $horas,
            ];
        }
    }
    if (preg_match('/\bcada\s+(segundo|tercer|cuarto)\s+dia\b/u', $texto, $m)) {
        $cada = ['segundo' => 2, 'tercer' => 3, 'cuarto' => 4][$m[1]];
        return ['texto' => 'Cada ' . $cada . ' días', 'por_dia' => 1 / $cada];
    }
    if (preg_match('/\bcada\s+(\d+)\s+dias?\b/u', $texto, $m)) {
        $dias = (int) $m[1];
        return $dias > 0
            ? ['texto' => 'Cada ' . $dias . ' días', 'por_dia' => 1 / $dias]
            : ['texto' => '', 'por_dia' => null];
    }
    if (preg_match('/\bcada\s+(\d+)\s+semanas?\b/u', $texto, $m)) {
        $semanas = (int) $m[1];
        return $semanas > 0
            ? ['texto' => 'Cada ' . $semanas . ' semanas', 'por_dia' => 1 / ($semanas * 7)]
            : ['texto' => '', 'por_dia' => null];
    }
    if (preg_match('/\bcada\s+(\d+)\s+(?:mes|meses)\b/u', $texto, $m)) {
        $meses = (int) $m[1];
        return $meses > 0
            ? ['texto' => 'Cada ' . $meses . ' meses', 'por_dia' => 1 / ($meses * 30)]
            : ['texto' => '', 'por_dia' => null];
    }
    if (preg_match('/\b(\d+)\s+a\s+(\d+)\s+(?:vez|veces)\s+(?:al|por|en\s+el|en\s+la)\s+dia\b/u', $texto, $m)) {
        $mayor = max((int) $m[1], (int) $m[2]);
        return ['texto' => 'De ' . $m[1] . ' a ' . $m[2] . ' veces al día', 'por_dia' => (float) $mayor];
    }
    if (preg_match('/\b(\d+)\s+(?:vez|veces)\s+(?:al|por|en\s+el|en\s+la)\s+dia\b/u', $texto, $m)) {
        $veces = (int) $m[1];
        if ($veces > 0 && $veces <= 12) {
            return [
                'texto' => $veces === 1 ? 'Una vez al día' : $veces . ' veces al día',
                'por_dia' => (float) $veces,
            ];
        }
    }
    if (preg_match('/\b(\d+)\s+(?:vez|veces)\s+(?:a\s+la|por|en\s+la)\s+semana\b/u', $texto, $m)) {
        $veces = (int) $m[1];
        if ($veces > 0 && $veces <= 7) {
            return ['texto' => 'Cada ' . $veces . ' por semana', 'por_dia' => $veces / 7];
        }
    }
    if (preg_match('/\b(\d+)\s+(?:vez|veces)\s+(?:al|por|en\s+el)\s+mes\b/u', $texto, $m)) {
        $veces = (int) $m[1];
        if ($veces > 0 && $veces <= 31) {
            return ['texto' => 'Cada ' . $veces . ' por mes', 'por_dia' => $veces / 30];
        }
    }
    if (preg_match('/\b(?:una\s+vez\s+al\s+dia|diario|diariamente|cada\s+dia)\b/u', $texto)) {
        return ['texto' => 'Una vez al día', 'por_dia' => 1.0];
    }
    if (preg_match('/\b(?:al|por|en\s+el|en\s+la)\s+dia\b/u', $texto)) {
        return ['texto' => 'Una vez al día', 'por_dia' => 1.0];
    }
    if (preg_match('/\b(?:por\s+la\s+)?manana\s+y\s+(?:la\s+)?noche\b/u', $texto)) {
        return ['texto' => 'Por la mañana y la noche', 'por_dia' => 2.0];
    }
    return ['texto' => '', 'por_dia' => null];
}

/** Duración dictada y su equivalente en días (para calcular la cantidad). */
function recetaDictadoDuracionDe(string $segmento): array
{
    $texto = recetaDictadoNumeros($segmento);
    if (preg_match('/\b(?:durante|por|de)\s+(\d+)\s*(dias?|semanas?|(?:mes|meses))\b/u', $texto, $m)) {
        $cantidad = (int) $m[1];
        $unidad = recetaDictadoNormalizar($m[2]);
        if ($cantidad > 0) {
            if (str_starts_with($unidad, 'dia')) {
                return ['texto' => $cantidad === 1 ? '1 día' : $cantidad . ' días', 'dias' => (float) $cantidad];
            }
            if (str_starts_with($unidad, 'semana')) {
                return [
                    'texto' => $cantidad === 1 ? '1 semana' : $cantidad . ' semanas',
                    'dias' => (float) ($cantidad * 7),
                ];
            }
            return [
                'texto' => $cantidad === 1 ? '1 mes' : $cantidad . ' meses',
                'dias' => (float) ($cantidad * 30),
            ];
        }
    }
    if (preg_match('/\b(?:durante|por|de)\s+(\d+)\s+horas?\b/u', $texto, $m)) {
        $horas = (int) $m[1];
        if ($horas > 0) {
            return ['texto' => $horas . ' horas', 'dias' => $horas / 24];
        }
    }
    if (preg_match('/\bindefinid[oa]mente?\b|\bde\s+por\s+vida\b|\btiempo\s+indefinido\b/u', $texto)) {
        return ['texto' => 'Indefinido', 'dias' => null];
    }
    return ['texto' => '', 'dias' => null];
}

/** Vía canónica del catálogo (conserva la forma en que está configurada). */
function recetaDictadoViaCanonica(string $via): string
{
    $plano = recetaDictadoNormalizar($via);
    if ($plano === '') {
        return '';
    }
    $disponibles = function_exists('viasAdministracionDisponibles')
        ? viasAdministracionDisponibles()
        : VIAS_ADMINISTRACION;
    foreach ($disponibles as $opcion) {
        if (recetaDictadoNormalizar((string) $opcion) === $plano) {
            return (string) $opcion;
        }
    }
    foreach (DICTADO_RECETA_VIAS as $alias => $canonica) {
        if (str_contains($plano, $alias)) {
            foreach ($disponibles as $opcion) {
                if (recetaDictadoNormalizar((string) $opcion) === recetaDictadoNormalizar($canonica)) {
                    return (string) $opcion;
                }
            }
        }
    }
    return '';
}

/** Vía dicha junto al medicamento ("vía oral", "intravenosa"…). */
function recetaDictadoViaDe(string $segmento): string
{
    $plano = recetaDictadoNormalizar($segmento);
    foreach (DICTADO_RECETA_VIAS as $alias => $canonica) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/u', $plano)) {
            $via = recetaDictadoViaCanonica($canonica);
            if ($via !== '') {
                return $via;
            }
        }
    }
    return '';
}

/** Vía oficial del catálogo; si falta, se deduce de la forma farmacéutica cuando no hay duda. */
function recetaDictadoViaPorForma(array $fila): string
{
    $via = recetaDictadoViaCanonica((string) ($fila['via'] ?? ''));
    if ($via !== '') {
        return $via;
    }
    $texto = recetaDictadoNormalizar(
        (string) ($fila['forma_farmaceutica'] ?? '') . ' '
        . (string) ($fila['presentacion'] ?? '') . ' '
        . (string) ($fila['nombre'] ?? '')
    );
    if (str_contains($texto, 'inyect') || str_contains($texto, 'ampollet')) {
        return '';
    }
    $pistas = [
        'sublingual' => 'Sublingual',
        'oftalm' => 'Oftálmica', 'colirio' => 'Oftálmica',
        'otic' => 'Ótica', 'oido' => 'Ótica',
        'nasal' => 'Nasal', 'inhal' => 'Inhalada', 'aerosol' => 'Inhalada', 'nebuliz' => 'Inhalada',
        'supositor' => 'Rectal', 'rectal' => 'Rectal',
        'ovul' => 'Vaginal', 'vaginal' => 'Vaginal',
        'crema' => 'Tópica', 'pomada' => 'Tópica', 'unguento' => 'Tópica', 'gel' => 'Tópica',
        'topica' => 'Tópica',
    ];
    foreach ($pistas as $pista => $canonica) {
        if (str_contains($texto, $pista)) {
            $via = recetaDictadoViaCanonica($canonica);
            if ($via !== '') {
                return $via;
            }
        }
    }
    if (preg_match('/\b(tableta|comprimido|capsula|gragea|jarabe|suspension|elixir|solucion|polvo|sobre|emulsion)\b/u', $texto)) {
        return recetaDictadoViaCanonica('Oral');
    }
    return '';
}

/** Concentración del catálogo (valor y unidad) para compararla con lo dictado. */
function recetaDictadoConcentracionDe(array $fila): array
{
    $plano = recetaDictadoNormalizar((string) ($fila['concentracion'] ?? ''));
    if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(mg|miligramos?|g|gramos?|mcg|microgramos?|ml|mililitros?|ui)\b/u', $plano, $m)) {
        return ['valor' => (float) str_replace(',', '.', $m[1]), 'unidad' => recetaDictadoUnidad($m[2])];
    }
    return ['valor' => null, 'unidad' => ''];
}

/** Presentación oficial: forma farmacéutica · concentración. */
function recetaDictadoPresentacion(array $fila): string
{
    $forma = trim((string) ($fila['forma_farmaceutica'] ?? ''));
    $concentracion = trim((string) ($fila['concentracion'] ?? ''));
    if ($forma !== '' && $concentracion !== '') {
        $texto = $forma . ' · ' . $concentracion;
    } elseif ($concentracion !== '') {
        $texto = $concentracion;
    } elseif (trim((string) ($fila['presentacion'] ?? '')) !== '') {
        $texto = trim((string) $fila['presentacion']);
    } else {
        $texto = $forma;
    }
    return mb_substr($texto, 0, 120);
}

/** Elige, entre las concentraciones del mismo medicamento, la que coincide con lo dictado. */
function recetaDictadoElegirFila(array $filas, string $segmento, string $frase): ?array
{
    $dosis = recetaDictadoDosisDicha($segmento);
    $viaDicha = recetaDictadoViaDe($segmento);
    $textoForma = recetaDictadoNormalizar($segmento);
    $mejor = null;
    $mejorPuntos = PHP_INT_MIN;

    foreach ($filas as $fila) {
        $puntos = 0;
        $baseFila = recetaDictadoBaseNombre((string) ($fila['nombre'] ?? ''));
        if ($baseFila === $frase) {
            $puntos += 30;
        } elseif (recetaDictadoPalabraSimilar($baseFila, $frase)) {
            $puntos += 15;   // coincidencia aproximada: todavía puede ser el medicamento correcto
        }

        $concentracion = recetaDictadoConcentracionDe($fila);
        if ($concentracion['valor'] !== null && $dosis['medida'] !== null) {
            $valorCatalogo = $concentracion['valor'];
            $unidadCatalogo = $concentracion['unidad'];
            $valorDicho = $dosis['medida'];
            $unidadDicha = $dosis['unidad_medida'];
            $convertibles = in_array($unidadCatalogo, ['g', 'mg', 'mcg'], true)
                && in_array($unidadDicha, ['g', 'mg', 'mcg'], true);
            if ($unidadCatalogo !== $unidadDicha && $convertibles) {
                $valorCatalogo = $unidadCatalogo === 'g' ? $valorCatalogo * 1000 : ($unidadCatalogo === 'mcg' ? $valorCatalogo / 1000 : $valorCatalogo);
                $valorDicho = $unidadDicha === 'g' ? $valorDicho * 1000 : ($unidadDicha === 'mcg' ? $valorDicho / 1000 : $valorDicho);
                $unidadCatalogo = $unidadDicha = 'mg';
            }
            if ($unidadCatalogo === $unidadDicha) {
                if (abs($valorCatalogo - $valorDicho) < 0.001) {
                    $puntos += 100;
                } else {
                    $puntos -= 20;
                }
            } else {
                $puntos -= 5;   // concentración en ml frente a una dosis en mg, por ejemplo
            }
        } elseif ($concentracion['valor'] === null && $dosis['medida'] !== null) {
            $puntos -= 5;
        }

        $viaFila = recetaDictadoViaPorForma($fila);
        if ($viaDicha !== '' && $viaFila !== '' && $viaDicha === $viaFila) {
            $puntos += 25;
        }
        // La vía configurada en el catálogo oficial pesa más que la deducida de la forma.
        if ($viaDicha !== '' && trim((string) ($fila['via'] ?? '')) !== ''
            && recetaDictadoViaCanonica((string) $fila['via']) === $viaDicha) {
            $puntos += 8;
        }

        $forma = recetaDictadoNormalizar(
            (string) ($fila['forma_farmaceutica'] ?? '') . ' ' . (string) ($fila['presentacion'] ?? '')
        );
        foreach (['tableta', 'comprimido', 'capsula', 'gragea', 'jarabe', 'suspension', 'solucion',
                  'gotas', 'crema', 'gel', 'inyectable', 'sobre', 'parche', 'inhalador', 'supositorio', 'ovulo'] as $pista) {
            if (str_contains($forma, $pista) && preg_match('/\b' . $pista . '/u', $textoForma)) {
                $puntos += 10;
                break;
            }
        }
        if (preg_match('/\binhalacion(?:es)?\b/u', $textoForma)
            && preg_match('/aerosol|inhalador|inhalacion/u', $forma)) {
            $puntos += 20;
        }
        if (preg_match('/\bnebulizacion(?:es)?\b/u', $textoForma) && str_contains($forma, 'nebuliz')) {
            $puntos += 20;
        }

        if (trim((string) ($fila['clave_compendio'] ?? '')) !== '') {
            $puntos += 4;
        }
        if (trim((string) ($fila['forma_farmaceutica'] ?? '')) !== '') {
            $puntos += 2;
        }

        if ($mejor === null || $puntos > $mejorPuntos
            || ($puntos === $mejorPuntos && (int) $fila['id'] < (int) $mejor['id'])) {
            $mejor = $fila;
            $mejorPuntos = $puntos;
        }
    }
    return $mejor;
}

/** Dosis final: lo dictado manda; si no dictó dosis, se usa la concentración oficial. */
function recetaDictadoDosisFinal(array $dosis, array $fila): string
{
    $concentracion = trim((string) ($fila['concentracion'] ?? ''));
    $texto = $dosis['texto'];
    if ($texto === '') {
        return mb_substr($concentracion, 0, 60);
    }
    if ($dosis['medida'] === null && $dosis['formas'] !== null && $concentracion !== '') {
        $texto .= ' (' . $concentracion . ')';
    }
    return mb_substr(trim($texto), 0, 60);
}

/** Forma contable que se deduce del catálogo (tableta, cápsula…). */
function recetaDictadoFormaDeCatalogo(array $fila): string
{
    $texto = recetaDictadoNormalizar(
        (string) ($fila['forma_farmaceutica'] ?? '') . ' ' . (string) ($fila['presentacion'] ?? '')
    );
    foreach (['tableta', 'comprimido', 'capsula', 'gragea', 'gota', 'sobre', 'ampolleta', 'parche', 'supositorio', 'ovulo', 'nebuliz'] as $forma) {
        if (str_contains($texto, $forma)) {
            return recetaDictadoFormaEtiqueta($forma);
        }
    }
    return '';
}

/** Etiqueta singular y plural de una cantidad explícita ("surtir 2 cajas"). */
function recetaDictadoUnidadContable(string $unidad): array
{
    return match (true) {
        str_starts_with(recetaDictadoNormalizar($unidad), 'tablet') => ['tableta', 'tabletas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'comprim') => ['comprimido', 'comprimidos'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'capsul') => ['cápsula', 'cápsulas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'grage') => ['gragea', 'grageas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'sobre') => ['sobre', 'sobres'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'ampollet') => ['ampolleta', 'ampolletas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'caja') => ['caja', 'cajas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'frasco') => ['frasco', 'frascos'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'pieza') => ['pieza', 'piezas'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'ml') => ['ml', 'ml'],
        str_starts_with(recetaDictadoNormalizar($unidad), 'mililitr') => ['ml', 'ml'],
        default => ['dosis', 'dosis'],
    };
}

/** Cantidad a surtir: la explícita del dictado o la calculada con tomas y duración. */
function recetaDictadoCantidad(string $segmento, array $dosis, array $frecuencia, array $duracion, array $fila): string
{
    $texto = recetaDictadoNumeros($segmento);
    if (preg_match(
        '/\b(?:cantidad(?:\s+a\s+surtir)?|surtir|surtame|dispensar|dispensa|en\s+total|total\s+a\s+surtir)'
        . '\s*(?:de)?\s*(\d+)\s*'
        . '(tabletas?|comprimidos?|capsulas?|grageas?|cajas?|frascos?|sobres?|ampolletas?|piezas?|ml|mililitros?)\b/u',
        $texto,
        $m
    )) {
        $cantidad = (float) $m[1];
        if ($cantidad > 0) {
            [$singular, $plural] = recetaDictadoUnidadContable($m[2]);
            return recetaDictadoCantidadTexto($cantidad) . ' ' . ($cantidad == 1 ? $singular : $plural);
        }
    }

    if ($frecuencia['por_dia'] === null || $duracion['dias'] === null) {
        return '';
    }
    $porToma = 1.0;
    $etiqueta = '';
    if ($dosis['formas'] !== null && $dosis['formas'] > 0) {
        $porToma = $dosis['formas'];
        $etiqueta = $dosis['forma'];
    } elseif ($dosis['medida'] !== null && $dosis['unidad_medida'] === 'ml') {
        $porToma = $dosis['medida'];
        $etiqueta = 'ml';
    } else {
        $etiqueta = recetaDictadoFormaDeCatalogo($fila);
    }

    $total = $porToma * (float) $frecuencia['por_dia'] * (float) $duracion['dias'];
    if (!is_finite($total) || $total <= 0 || $total > 100000) {
        return '';
    }
    $total = ceil($total - 0.0001);
    if ($etiqueta === 'ml') {
        return recetaDictadoCantidadTexto($total) . ' ml';
    }
    if ($etiqueta !== '') {
        return recetaDictadoCantidadTexto($total) . ' ' . ($total == 1 ? $etiqueta : recetaDictadoFormaPlural($etiqueta));
    }
    return recetaDictadoCantidadTexto($total) . ' dosis';
}

/**
 * Medicamentos del catálogo reconocidos en el dictado, en el orden en que se mencionaron.
 * Cada renglón trae los campos que usa la receta de pacientes/nuevo_registro.php.
 */
function medicamentosDeDictado(string $texto, ?int $institucion = null): array
{
    if (trim($texto) === '' || !function_exists('db')) {
        return [];
    }
    $institucion ??= function_exists('institucionId') ? institucionId() : null;
    $institucion = (int) $institucion;
    if ($institucion <= 0) {
        return [];
    }

    $plano = recetaDictadoNormalizar($texto);
    if ($plano === '') {
        return [];
    }

    $tokens = [];
    foreach (explode(' ', $plano) as $token) {
        if (preg_match('/^\d/u', $token) || mb_strlen($token) < 3 || in_array($token, DICTADO_RECETA_IGNORAR, true)) {
            continue;
        }
        $tokens[$token] = true;
    }
    $tokens = array_keys($tokens);
    usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    $tokens = array_slice($tokens, 0, 8);
    if (!$tokens) {
        // Nombres como "Agua inyectable" solo tienen palabras que normalmente se descartan.
        $respaldo = [];
        foreach (explode(' ', $plano) as $token) {
            if (preg_match('/^\d/u', $token) || mb_strlen($token) < 3
                || in_array($token, ['cada', 'hora', 'horas', 'dia', 'dias', 'por', 'para', 'con',
                    'una', 'uno', 'del', 'las', 'los', 'que', 'miligramo', 'miligramos', 'gramo',
                    'gramos', 'tableta', 'tabletas', 'capsula', 'capsulas'], true)) {
                continue;
            }
            $respaldo[$token] = true;
        }
        $tokens = array_slice(array_keys($respaldo), 0, 3);
    }
    if (!$tokens) {
        return [];
    }

    $condiciones = [];
    $parametros = ['inst' => $institucion];
    foreach ($tokens as $i => $token) {
        $coincidencias = [];
        foreach (['nombre', 'presentacion', 'concentracion', 'forma_farmaceutica', 'clave'] as $j => $columna) {
            $parametro = "t{$i}_{$j}";
            $coincidencias[] = "$columna LIKE :$parametro";
            $parametros[$parametro] = '%' . $token . '%';
        }
        // El dictado suele cambiar el final de la palabra ("omeprasol"): el prefijo la rescata
        // y luego la comparación aproximada confirma que se trata del mismo medicamento.
        if (mb_strlen($token) >= 6) {
            $parametro = "t{$i}_pref";
            $coincidencias[] = "nombre LIKE :$parametro";
            $parametros[$parametro] = mb_substr($token, 0, 4) . '%';
        }
        $condiciones[] = '(' . implode(' OR ', $coincidencias) . ')';
    }

    try {
        $consulta = db()->prepare(
            'SELECT id, clave, clave_compendio, nombre, forma_farmaceutica, presentacion, concentracion, via
               FROM medicamentos
              WHERE institucion_id = :inst AND activo = 1 AND (' . implode(' OR ', $condiciones) . ')
              ORDER BY nombre, id
              LIMIT 800'
        );
        $consulta->execute($parametros);
        $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    if (!$filas) {
        return [];
    }

    // Agrupa por la frase que nombró el dictado: todas las concentraciones del mismo
    // medicamento quedan juntas y luego se elige la que coincide con lo que dijo el médico.
    $grupos = [];
    foreach ($filas as $fila) {
        $mejor = null;
        foreach (recetaDictadoSinonimos($fila) as $frase) {
            $posicion = recetaDictadoBuscar($plano, $frase);
            $exacta = $posicion !== null;
            if (!$exacta) {
                $posicion = recetaDictadoBuscarAproximado($plano, $frase);
            }
            if ($posicion === null) {
                continue;
            }
            $peso = strlen($frase) + 40 * substr_count($frase, ' ');
            if ($exacta) {
                $peso += 200;   // la coincidencia exacta manda sobre la aproximada
            }
            if ($mejor === null || $peso > $mejor['peso']
                || ($peso === $mejor['peso'] && $posicion < $mejor['posicion'])) {
                $mejor = ['posicion' => $posicion, 'frase' => $frase, 'peso' => $peso, 'exacta' => $exacta];
            }
        }
        if ($mejor === null) {
            continue;
        }
        $clave = $mejor['frase'];
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'posicion' => $mejor['posicion'],
                'frase' => $clave,
                'peso' => $mejor['peso'],
                'exacta' => $mejor['exacta'],
                'filas' => [],
            ];
        }
        $grupos[$clave]['posicion'] = min($grupos[$clave]['posicion'], $mejor['posicion']);
        $grupos[$clave]['peso'] = max($grupos[$clave]['peso'], $mejor['peso']);
        $grupos[$clave]['exacta'] = $grupos[$clave]['exacta'] || $mejor['exacta'];
        $grupos[$clave]['filas'][] = $fila;
    }
    if (!$grupos) {
        return [];
    }

    // Si dos frases se traslapan ("amoxicilina" y "amoxicilina con ácido clavulánico"),
    // gana la más específica para no repetir el mismo renglón.
    $ordenados = array_values($grupos);
    usort($ordenados, static function (array $a, array $b): int {
        return $a['posicion'] <=> $b['posicion'] ?: $b['peso'] <=> $a['peso'];
    });
    $aceptados = [];
    $finPrevio = -1;
    foreach ($ordenados as $grupo) {
        $fin = $grupo['posicion'] + strlen($grupo['frase']);
        if ($grupo['posicion'] < $finPrevio) {
            continue;
        }
        $aceptados[] = $grupo;
        $finPrevio = $fin;
        if (count($aceptados) >= 6) {
            break;
        }
    }

    // "Amoxicilina con ácido clavulánico": el nombre genérico y su combinación se detectan
    // seguidos; se conserva solo el más específico para no recetar el mismo principio dos veces.
    $aceptados = array_values(array_filter($aceptados, static function (array $grupo) use ($aceptados): bool {
        foreach ($aceptados as $otro) {
            if ($otro['posicion'] === $grupo['posicion'] && $otro['frase'] === $grupo['frase']) {
                continue;
            }
            $distancia = $otro['posicion'] - ($grupo['posicion'] + strlen($grupo['frase']));
            if ($distancia < 0 || $distancia > 40) {
                continue;
            }
            foreach ($otro['filas'] as $fila) {
                $base = recetaDictadoBaseNombre((string) ($fila['nombre'] ?? ''));
                if ($base !== '' && preg_match(
                    '/(?<![\p{L}\p{N}])' . preg_quote($grupo['frase'], '/') . '(?![\p{L}\p{N}])/u',
                    $base
                )) {
                    return false;
                }
            }
        }
        return true;
    }));

    $resultado = [];
    $vistos = [];
    foreach ($aceptados as $indice => $grupo) {
        $siguiente = $aceptados[$indice + 1]['posicion'] ?? null;
        $largo = $siguiente !== null ? max(40, $siguiente - $grupo['posicion']) : 280;
        $segmento = substr($plano, $grupo['posicion'], min($largo, 320));

        // El médico a veces dicta la dosis antes del nombre ("dos tabletas de 500 mg de paracetamol").
        $preInicio = max(0, $grupo['posicion'] - 80);
        if ($indice > 0) {
            $anterior = $aceptados[$indice - 1];
            $preInicio = max($preInicio, $anterior['posicion'] + strlen($anterior['frase']));
        }
        $pre = trim(substr($plano, $preInicio, $grupo['posicion'] - $preInicio));
        $contexto = $pre !== '' ? $pre . ' ' . $segmento : $segmento;
        // No se toma la dosis previa si el texto anterior ya trae instrucciones de otra receta.
        $preUtil = $pre !== '' && !preg_match('/\b(?:cada|por|durante|horas?|dias?|semanas?|(?:mes|meses))\b/u', $pre);

        $dosisPost = recetaDictadoDosisDicha($segmento);
        $contextoElegir = $dosisPost['texto'] === '' && $preUtil ? $contexto : $segmento;
        $fila = recetaDictadoElegirFila($grupo['filas'], $contextoElegir, $grupo['frase']);
        if (!$fila) {
            continue;
        }
        $id = (int) $fila['id'];
        if (isset($vistos[$id])) {
            continue;
        }
        $vistos[$id] = true;

        $nombre = trim((string) ($fila['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }

        $dosis = $dosisPost;
        if ($preUtil) {
            $dosisPrevia = recetaDictadoDosisDicha($pre);
            if ($dosis['medida'] === null && $dosisPrevia['medida'] !== null) {
                $dosis['medida'] = $dosisPrevia['medida'];
                $dosis['unidad_medida'] = $dosisPrevia['unidad_medida'];
            }
            if ($dosis['formas'] === null && $dosisPrevia['formas'] !== null) {
                $dosis['formas'] = $dosisPrevia['formas'];
                $dosis['forma'] = $dosisPrevia['forma'];
            }
            $dosis['texto'] = recetaDictadoDosisTexto($dosis);
        }

        $frecuencia = recetaDictadoFrecuenciaDe($segmento);
        if ($frecuencia['texto'] === '' && $pre !== '') {
            $frecuencia = recetaDictadoFrecuenciaDe($pre);
        }
        $duracion = recetaDictadoDuracionDe($segmento);
        if ($duracion['texto'] === '' && $pre !== '') {
            $duracion = recetaDictadoDuracionDe($pre);
        }
        $via = recetaDictadoViaDe($segmento);
        if ($via === '' && $preUtil) {
            $via = recetaDictadoViaDe($pre);
        }

        $resultado[] = [
            'id'              => $id,
            'nombre'          => $nombre,
            'clave_compendio' => (string) ($fila['clave_compendio'] ?? ''),
            'presentacion'    => recetaDictadoPresentacion($fila),
            'dosis'           => recetaDictadoDosisFinal($dosis, $fila),
            'via'             => $via ?: recetaDictadoViaPorForma($fila),
            'periodicidad'    => $frecuencia['texto'],
            'duracion'        => $duracion['texto'],
            'cantidad'        => recetaDictadoCantidad($contexto, $dosis, $frecuencia, $duracion, $fila),
            'fecha_inicio'    => date('Y-m-d'),
        ];
    }
    return $resultado;
}

/**
 * Corrección del texto transcrito contra el catálogo: el STT escribe los medicamentos
 * como suenan ("omeprasol", "losartan", "metformmina") y aquí se sustituyen por el nombre
 * real del catálogo institucional. Se aplica al texto completo antes de mostrarlo y de
 * extraer campos, de modo que el médico ve —y el formulario recibe— nombres correctos.
 */
function recetaDictadoCorregirTexto(string $texto, ?int $institucion = null): string
{
    if (trim($texto) === '' || !function_exists('db')) {
        return $texto;
    }
    $institucion ??= function_exists('institucionId') ? institucionId() : null;
    if ((int) $institucion <= 0) {
        return $texto;
    }

    // Nombres del catálogo y sus variantes habladas → forma correcta, por clave fonética.
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            $consulta = db()->prepare(
                'SELECT nombre FROM medicamentos WHERE institucion_id = :inst AND activo = 1 ORDER BY nombre'
            );
            $consulta->execute(['inst' => (int) $institucion]);
            foreach ($consulta->fetchAll(PDO::FETCH_COLUMN) as $nombreCatalogo) {
                // Nombre con su ortografía real (acentos incluidos): los sinónimos ya vienen
                // sin acentos y no servirían para corregir "losartan" → "Losartán".
                $baseCatalogo = mb_strtolower(trim((string) preg_replace('/\s*\d.*$/su', '', (string) $nombreCatalogo)), 'UTF-8');
                $palabras = [];
                foreach (preg_split('/\s+/u', $baseCatalogo, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabraBase) {
                    $palabras[] = [$palabraBase, true];
                }
                foreach (recetaDictadoSinonimos(['nombre' => (string) $nombreCatalogo]) as $variante) {
                    foreach (explode(' ', $variante) as $palabraAlias) {
                        $palabras[] = [$palabraAlias, false];
                    }
                }
                foreach ($palabras as [$palabra, $esCatalogo]) {
                    if (mb_strlen($palabra) < 5 || preg_match('/^\d/u', $palabra)) {
                        continue;
                    }
                    $clave = recetaDictadoClaveFonetica($palabra);
                    if ($clave === '') {
                        continue;
                    }
                    // "losartan" y "losartán" suenan igual: gana la ortografía del catálogo.
                    if (!isset($mapa[$clave]) || ($esCatalogo && !$mapa[$clave]['catalogo'])) {
                        $mapa[$clave] = ['palabra' => $palabra, 'catalogo' => $esCatalogo];
                    }
                }
            }
        } catch (Throwable) {
            $mapa = [];
        }
    }
    if (!$mapa) {
        return $texto;
    }

    return (string) preg_replace_callback('/[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{5,}/u', static function (array $m) use ($mapa): string {
        $dicha = recetaDictadoNormalizar($m[0]);
        if ($dicha === '' || mb_strlen($dicha) < 5 || preg_match('/\d/u', $dicha)
            || in_array($dicha, DICTADO_RECETA_IGNORAR, true)
            || in_array($dicha, DICTADO_RECETA_FORMAS, true)
            || isset(DICTADO_RECETA_NUMEROS[$dicha])) {
            return $m[0];
        }
        $entrada = $mapa[recetaDictadoClaveFonetica($dicha)] ?? null;
        if ($entrada === null) {
            return $m[0];
        }
        $correcta = $entrada['palabra'];
        if ($correcta === $dicha) {
            return $m[0];
        }
        // Dos reglas para corregir, ambas conservadoras:
        //  1) suena igual y el largo es parecido ("omeprasol" → "omeprazol", "losartan" → "losartán");
        //  2) una sola letra de diferencia en palabras largas ("metformmina" → "metformina").
        $foneticaIgual = recetaDictadoClaveFonetica($correcta) === recetaDictadoClaveFonetica($dicha);
        $largoDicha = mb_strlen($dicha);
        $largoCorrecta = mb_strlen($correcta);
        $aceptar = ($foneticaIgual && abs($largoDicha - $largoCorrecta) <= 2)
            || (!$foneticaIgual && levenshtein($dicha, $correcta) <= 1 && $largoDicha >= 7 && $largoCorrecta >= 7);
        if (!$aceptar) {
            return $m[0];
        }
        $primera = mb_substr($m[0], 0, 1, 'UTF-8');
        $mayuscula = mb_strtoupper($primera, 'UTF-8') === $primera && mb_strtolower($primera, 'UTF-8') !== $primera;
        return $mayuscula ? mb_convert_case($correcta, MB_CASE_TITLE, 'UTF-8') : $correcta;
    }, $texto);
}
