<?php
/**
 * Redacción clínica del dictado: pasa el habla tal como se dice al tal como se escribe.
 *
 * Tres trabajos, en el mismo orden en que los aplica dictadoRedactarOracion():
 *
 *   1. Jerga mexicana → término médico, DENTRO de la frase ("me duele la cabeza" se
 *      convierte en "cefalea" sin borrar el resto: "desde hace tres días" se conserva).
 *      Es la versión en línea de SINTOMAS_MEXICANOS del servicio de voz (servicio_stt.py),
 *      que allá reemplaza el campo completo y aquí respeta la oración.
 *
 *   2. Habla del paciente (primera persona, presente) → tercera persona clínica con el
 *      tiempo verbal que usa la nota: "tengo diabetes" → "refiere diabetes",
 *      "me duele la cadera" → "dolor en la cadera", "empecé con tos" → "inició con tos",
 *      "no puedo dormir" → "insomnio". Las preguntas no se tocan.
 *
 *   3. Expresiones de tiempo → forma de la nota clínica: "desde hace tres días" →
 *      "de tres días de evolución". Al final cada oración va con mayúscula y punto.
 *
 * También enruta el dictado que no dijo ninguna etiqueta ("me duele la cabeza…", sin
 * "motivo de consulta:") a la casilla narrativa que le corresponde: los patrones del
 * extractor (camposDeDictado) solo llenan casillas cuando se nombra la sección.
 */

require_once __DIR__ . '/dictado.php';

/**
 * Jerga mexicana → término clínico. Frases, no palabras sueltas: cada entrada se reemplaza
 * como frase completa y las más largas se revisan primero ("me duele la cabeza" antes de
 * "dolor de cabeza"). Solo entradas que funcionan dentro de una oración.
 */
const DICTADO_JERGA = [
    // Cefalea
    'me duele la cabeza' => 'cefalea', 'dolor de cabeza' => 'cefalea', 'dolor en la cabeza' => 'cefalea',
    'me zumba la cabeza' => 'cefalea', 'dolor de cráneo' => 'cefalea', 'dolor en el cráneo' => 'cefalea',
    'me duele la frente' => 'cefalea frontal', 'dolor de frente' => 'cefalea frontal', 'dolor en la frente' => 'cefalea frontal',
    'me duele la sien' => 'cefalea temporal', 'dolor de sien' => 'cefalea temporal', 'dolor en la sien' => 'cefalea temporal',
    'me duelen las sienes' => 'cefalea temporal',
    'me duele la nuca' => 'cefalea occipital', 'dolor de nuca' => 'cefalea occipital', 'dolor en la nuca' => 'cefalea occipital',
    'jaqueca' => 'migraña', 'dolor de medio lado' => 'hemicránea', 'me duele medio lado de la cabeza' => 'hemicránea',
    // Abdomen
    'dolor de estómago' => 'dolor abdominal', 'dolor en el estómago' => 'dolor abdominal', 'me duele el estómago' => 'dolor abdominal',
    'dolor de panza' => 'dolor abdominal', 'dolor en la panza' => 'dolor abdominal', 'me duele la panza' => 'dolor abdominal',
    'dolor de pansa' => 'dolor abdominal', 'dolor en la pansa' => 'dolor abdominal', 'me duele la pansa' => 'dolor abdominal',
    'le duele la panza' => 'dolor abdominal', 'le duele la pansa' => 'dolor abdominal',
    'dolor de barriga' => 'dolor abdominal', 'dolor en la barriga' => 'dolor abdominal', 'me duele la barriga' => 'dolor abdominal',
    'dolor de vientre' => 'dolor abdominal', 'me duele el vientre' => 'dolor abdominal', 'dolor en el vientre' => 'dolor abdominal',
    'me duele el abdomen' => 'dolor abdominal', 'dolor en el abdomen' => 'dolor abdominal',
    'cólico estomacal' => 'cólico abdominal', 'me retuerce el estómago' => 'cólico abdominal', 'me retuerce la panza' => 'cólico abdominal',
    'me retuerce' => 'cólico abdominal',
    // Torácico
    'dolor de pecho' => 'dolor torácico', 'dolor en el pecho' => 'dolor torácico', 'me duele el pecho' => 'dolor torácico',
    'dolor de tórax' => 'dolor torácico', 'me duele el tórax' => 'dolor torácico',
    'me duele el corazón' => 'dolor precordial', 'dolor al corazón' => 'dolor precordial',
    'me duele el pecho al respirar' => 'dolor pleurítico',
    // Garganta
    'dolor de garganta' => 'odinofagia', 'dolor en la garganta' => 'odinofagia', 'me duele la garganta' => 'odinofagia',
    'me duele al tragar' => 'odinofagia', 'dolor al tragar' => 'odinofagia', 'me raspa la garganta' => 'faringitis',
    'garganta roja' => 'faringitis', 'garganta irritada' => 'faringitis', 'garganta inflamada' => 'faringitis',
    'garganta con pus' => 'amigdalitis', 'amígdalas inflamadas' => 'amigdalitis', 'anginas' => 'amigdalitis',
    // Oído
    'dolor de oído' => 'otalgia', 'dolor en el oído' => 'otalgia', 'me duele el oído' => 'otalgia',
    'me duele el oído izquierdo' => 'otalgia izquierda', 'me duele el oído derecho' => 'otalgia derecha',
    'me zumba el oído' => 'acúfenos', 'zumbido en el oído' => 'acúfenos', 'zumbido de oídos' => 'acúfenos',
    'se me tapó el oído' => 'hipoacusia', 'oído tapado' => 'hipoacusia', 'no oigo bien' => 'hipoacusia',
    // Dental
    'dolor de muela' => 'odontalgia', 'me duele la muela' => 'odontalgia', 'dolor de muelas' => 'odontalgia',
    'me duelen las muelas' => 'odontalgia',
    'dolor de diente' => 'odontalgia', 'me duele el diente' => 'odontalgia', 'dolor de muela del juicio' => 'odontalgia',
    'me duele la encía' => 'gingivitis', 'encía inflamada' => 'gingivitis', 'encía sangrante' => 'gingivorragia',
    'sangrado de encías' => 'gingivorragia',
    // Espalda y cuello
    'dolor de espalda' => 'lumbalgia', 'dolor en la espalda' => 'lumbalgia', 'me duele la espalda' => 'lumbalgia',
    'dolor de cintura' => 'lumbalgia', 'dolor en la cintura' => 'lumbalgia', 'me duele la cintura' => 'lumbalgia',
    'dolor de riñón' => 'lumbalgia', 'me duele el riñón' => 'lumbalgia',
    'dolor de columna' => 'lumbalgia', 'dolor en la columna' => 'lumbalgia',
    'dolor de cuello' => 'cervicalgia', 'dolor en el cuello' => 'cervicalgia', 'me duele el cuello' => 'cervicalgia',
    'me duele la cervical' => 'cervicalgia', 'dolor de la cervical' => 'cervicalgia',
    // Extremidades y articulaciones
    'dolor de articulaciones' => 'artralgia', 'dolor en las articulaciones' => 'artralgia', 'me duelen las articulaciones' => 'artralgia',
    'dolor articular' => 'artralgia', 'me duelen las coyunturas' => 'artralgia', 'dolor en las coyunturas' => 'artralgia',
    'me duelen los huesos' => 'ostealgia', 'dolor de huesos' => 'dolor óseo', 'dolor en los huesos' => 'dolor óseo',
    'dolor de rodilla' => 'gonalgia', 'me duele la rodilla' => 'gonalgia', 'me duelen las rodillas' => 'gonalgia bilateral',
    'dolor de cadera' => 'coxalgia', 'me duele la cadera' => 'coxalgia',
    'dolor de hombro' => 'omalgia', 'me duele el hombro' => 'omalgia',
    'dolor de codo' => 'epicondilitis', 'me duele el codo' => 'epicondilitis',
    'dolor de muñeca' => 'dolor en muñeca', 'me duele la muñeca' => 'dolor en muñeca',
    'dolor de talón' => 'talalgia', 'me duele el talón' => 'talalgia',
    'dolor de pie' => 'podalgia', 'me duele el pie' => 'podalgia',
    // Ojo
    'veo borroso' => 'visión borrosa', 'veo doble' => 'diplopía', 'ojo rojo' => 'conjuntivitis', 'ojo irritado' => 'conjuntivitis',
    'me arde el ojo' => 'conjuntivitis', 'me pica el ojo' => 'prurito ocular', 'ojo lloroso' => 'epífora',
    'me duele el ojo' => 'oftalmalgia', 'dolor de ojo' => 'oftalmalgia', 'dolor en el ojo' => 'oftalmalgia',
    // Generales
    'calentura' => 'fiebre', 'temperatura alta' => 'fiebre', 'me subió la temperatura' => 'fiebre',
    'cuerpo cortado' => 'mialgias', 'el cuerpo cortado' => 'mialgias', 'calofríos' => 'escalofríos', 'los calos' => 'escalofríos',
    'gripa' => 'infección respiratoria aguda', 'gripe' => 'infección respiratoria aguda', 'catarro' => 'infección respiratoria aguda',
    'me tapa la nariz' => 'congestión nasal', 'tapado de la nariz' => 'congestión nasal', 'moqueo' => 'rinorrea',
    'falta de aire' => 'disnea', 'me falta el aire' => 'disnea', 'no puedo respirar' => 'disnea', 'me ahogo' => 'disnea',
    'me cuesta respirar' => 'disnea', 'me suenan las bronquias' => 'sibilancias', 'me silba el pecho' => 'sibilancias',
    'flema' => 'esputo',
    // Digestivo
    'agruras' => 'pirosis', 'me arde el estómago' => 'pirosis', 'acidez estomacal' => 'pirosis',
    'ganas de vomitar' => 'náusea', 'quiero vomitar' => 'náusea', 'estómago suelto' => 'diarrea', 'el estómago suelto' => 'diarrea',
    'chorrillo' => 'diarrea', 'me dio cámaras' => 'diarrea', 'me dan cámaras' => 'diarrea', 'me dio una cámara' => 'diarrea',
    'empacho' => 'dispepsia', 'me empacha' => 'dispepsia', 'no hago bien la digestión' => 'dispepsia',
    'se me quita el hambre' => 'inapetencia', 'no tengo hambre' => 'inapetencia', 'no me da hambre' => 'inapetencia',
    // Piel
    'comezón' => 'prurito', 'me pica el cuerpo' => 'prurito generalizado', 'me pica la piel' => 'prurito',
    'ronchas' => 'urticaria', 'sarpullido' => 'erupción cutánea', 'hinchazón' => 'edema', 'me hincho' => 'edema',
    'me inflamo' => 'edema', 'moretones' => 'equimosis', 'me salen moretes' => 'equimosis',
    // Neuro
    'hormigueos' => 'parestesias', 'hormigueo' => 'parestesias', 'se me duermen las manos' => 'parestesias',
    'se me duermen los pies' => 'parestesias', 'se me duerme el brazo' => 'parestesias',
    'me da vueltas la cabeza' => 'vértigo', 'me desmayé' => 'pérdida de la consciencia', 'desmayo' => 'síncope',
    'no puedo dormir' => 'insomnio', 'no duermo bien' => 'insomnio', 'me cuesta dormir' => 'insomnio',
    'me tiembla el cuerpo' => 'temblor', 'se me olvidan las cosas' => 'fallas de memoria', 'me muevo lento' => 'bradicinesia',
    // Urológico
    'me arde orinar' => 'disuria', 'ardor al orinar' => 'disuria', 'dolor al orinar' => 'disuria',
    'me levanto a orinar de noche' => 'nicturia', 'sangre en la orina' => 'hematuria',
    'piedras en el riñón' => 'nefrolitiasis', 'arena en el riñón' => 'nefrolitiasis',
    // Gineco y otros
    'me baja la regla' => 'menstruación', 'me llega la regla' => 'menstruación', 'atraso menstrual' => 'amenorrea',
    'me sale sangre por la nariz' => 'epistaxis', 'sangre de la nariz' => 'epistaxis', 'me sale sangre' => 'sangrado',
    'bajo de peso' => 'pérdida de peso', 'se me fue el peso' => 'pérdida de peso',
    'me late fuerte el corazón' => 'palpitaciones', 'me late el pecho' => 'palpitaciones',
    'me hinchan los pies' => 'edema en miembros inferiores', 'los pies hinchados' => 'edema en miembros inferiores',
    'torcedura' => 'esguince', 'los nervios' => 'nerviosismo', 'decaída' => 'astenia', 'decaimiento' => 'astenia',
    'me siento decaído' => 'astenia', 'cansancio' => 'fatiga', 'agotamiento' => 'astenia',
];

/** Campos narrativos: se redactan jerga, persona y tiempo (la voz del paciente se pasa a tercera persona). */
const DICTADO_REDAACCION_NARRATIVOS = [
    'motivo_consulta', 'padecimiento_actual', 'exploracion_fisica', 'nota_evolucion', 'plan_tratamiento',
    'estado_mental', 'resultados', 'criterios', 'sugerencias', 'recomendaciones', 'pronostico', 'manejo',
    'terapeutica', 'pendientes', 'factores_riesgo', 'antecedentes_heredofamiliares',
    'antecedentes_personales_patologicos', 'antecedentes_personales_no_patologicos',
];

/** Campos de renglón o lista: solo jerga y puntuación, sin convertir la persona ("toma metformina" ya es la redacción). */
const DICTADO_REDAACCION_LISTAS = [
    'padecimientos_cronicos', 'medicamentos_actuales', 'alergias', 'habitos_sustancias',
    'inmunizaciones', 'diagnostico', 'causas_defuncion',
];

/** Pistas de que la oración describe al paciente; preguntas directas no se interpretan como hechos. */
const DICTADO_MARCADOR_PACIENTE = '/(?<![a-z])(?:me\s+duele|me\s+duelen|me\s+siento|me\s+dio|me\s+dieron|me\s+empez|empec[eé]|tengo|tomo|siento|ando\s+con|me\s+torc[ií]|me\s+lastim[eé]|me\s+golpe[eé]|me\s+desmay[eé]|me\s+recet|no\s+puedo|soy\b|yo\b|(?:la|el)\s+paciente\s+(?:refiere|reporta|comenta|se[ñn]ala)\b)/iu';

/**
 * Convierte una frase en patrón regex insensible a acentos y a la eñe: "migraña" encuentra
 * "migrana" y "migrana". La usan las reglas de jerga para que el dictado con o sin acentos
 * del transcriptor se reconozca igual.
 */
function dictadoPatronJerga(string $frase): string
{
    $clases = ['a' => '[aáà]', 'e' => '[eéè]', 'i' => '[iíìï]', 'o' => '[oóò]', 'u' => '[uúùü]', 'n' => '[nñ]', 'ñ' => '[nñ]'];
    $letras = preg_split('//u', mb_strtolower(trim($frase), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $patron = '';
    foreach ($letras as $letra) {
        if ($letra === ' ') {
            $patron .= '\s+';
            continue;
        }
        $patron .= $clases[$letra] ?? preg_quote($letra, '/');
    }
    return '(?<![a-z0-9])' . $patron . '(?![a-z0-9])';
}

/**
 * Reemplaza la jerga DENTRO del texto, frase completa por frase completa (la más larga
 * primero, para que "me duele la cabeza al despertar" no la pise "dolor de cabeza").
 * Es el equivalente en línea de normalizar_sintoma() del servicio de voz, que allá
 * reemplazaba el campo completo y borraba el resto de la frase.
 */
function dictadoSinJerga(string $texto): string
{
    static $reglas = null;
    if ($reglas === null) {
        $reglas = [];
        // Solo normaliza sinónimos de síntomas; no convierte lo referido por el paciente
        // en diagnósticos (por ejemplo, "ojo rojo" no equivale a conjuntivitis).
        $sinonimosSeguros = [
            'agruras', 'calentura', 'chorrillo', 'dolor de panza', 'dolor de pansa',
            'dolor en la panza', 'dolor en la pansa', 'el estómago suelto', 'estómago suelto',
            'flema', 'falta de aire', 'ganas de vomitar', 'me cuesta respirar',
            'le duele la panza', 'le duele la pansa', 'me duele la panza', 'me duele la pansa',
            'me falta el aire', 'quiero vomitar',
        ];
        $jerga = array_intersect_key(DICTADO_JERGA, array_flip($sinonimosSeguros));
        uksort($jerga, static fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        foreach ($jerga as $frase => $clinico) {
            $reglas['/' . dictadoPatronJerga($frase) . '/iu'] = $clinico;
        }
    }
    // Cada regla con reemplazo propio: si la jerga venía con mayúscula ("Me duele…"),
    // el término clínico la conserva ("Cefalea…") para no romper el inicio de oración.
    foreach ($reglas as $patron => $clinico) {
        $textoOriginal = $texto;
        $texto = (string) preg_replace_callback(
            $patron,
            static function (array $m) use ($clinico, $textoOriginal): string {
                $coincidencia = (string) $m[0][0];
                $inicio = (int) $m[0][1];
                $anterior = substr($textoOriginal, max(0, $inicio - 32), min(32, $inicio));
                if (preg_match('/\b(?:no|nunca|niega|niego|sin|tampoco)\s+(?:(?:yo|que)\s+)?$/iu', $anterior)) {
                    return $coincidencia;
                }
                return preg_match('/^\p{Lu}/u', $coincidencia) ? dictadoFrase($clinico) : $clinico;
            },
            $texto,
            -1,
            $reemplazos,
            PREG_OFFSET_CAPTURE,
        );
    }
    return (string) preg_replace('/\s{2,}/u', ' ', $texto);
}

/**
 * Habla del paciente → tercera persona clínica. Solo se aplican las reglas cuando la
 * oración es del paciente (DICTADO_MARCADOR_PACIENTE) y nunca en preguntas. Las negaciones
 * ("no tengo diabetes", "no tomo medicamentos") no se convierten.
 */
function dictadoTerceraPersona(string $oracion): string
{
    $reglas = [
        '/(?<![a-z])(?<!no\s)(?<!niego\s)me\s+siento\s+/iu' => 'se siente ',
        '/(?<![a-z])(?<!no\s)(?<!niego\s)(?<!nunca\s)(?:yo\s+)?tengo\s+/iu' => 'refiere ',
        '/(?<![a-z])(?<!no\s)(?:yo\s+)?tomo\s+/iu' => 'toma ',
        '/(?<![a-z])soy\s+/iu' => 'refiere ser ',
        '/(?<![a-z])and[oa]\s+con\s+/iu' => 'presenta ',
        '/(?<![a-z])me\s+recetaron\s+/iu' => 'se le recetó ',
        '/(?<![a-z])me\s+recet[oó]\s+/iu' => 'se le recetó ',
        '/(?<![a-z])me\s+indicaron\s+/iu' => 'se le indicó ',
        '/(?<![a-z])(?:yo\s+)?empec[eé]\s+(?:con\s+)?/iu' => 'inició con ',
        '/(?<![a-z])me\s+empez[oó]\s+(?:con\s+)?/iu' => 'inició con ',
        '/(?<![a-z])me\s+di[oó]\s+/iu' => 'presentó ',
        '/(?<![a-z])me\s+dieron\s+/iu' => 'presentó ',
        '/(?<![a-z])me\s+torc[ií]\s+/iu' => 'refiere que se torció ',
        '/(?<![a-z])me\s+lastim[eé]\s+/iu' => 'refiere que se lastimó ',
        '/(?<![a-z])me\s+golpe[eé]\s+/iu' => 'refiere que se golpeó ',
        '/(?<![a-z])(?<!no\s)(?<!nada\s)me\s+duelen?\s+/iu' => 'dolor en ',
        '/(?<![a-z])le\s+duelen?\s+/iu' => 'dolor en ',
        '/(?<![a-z])((?:refiere|reporta|comenta|se[ñn]ala))\s+que\s+(?=(?:dolor\s+abdominal|cefalea|odinofagia|otalgia|lumbalgia|fiebre|diarrea|n[aá]usea|disnea|prurito|mialgia))/iu' => '$1 ',
        '/(?<![a-z])yo\s+/iu' => '',
        '/(?<![a-z])mi\s+(?=(?:espalda|cabeza|cuerpo|estómago|garganta|panza|barriga|vientre|oído|ojo|ojos|rodilla|rodillas|pierna|piernas|brazo|brazos|pecho|corazón|riñón|cintura|cuello|muela|muelas|diente|dientes|nariz|mano|manos|pie|pies|abdomen|articulación|articulaciones|cadera|hombro|tobillo)\b)/iu' => 'su ',
    ];
    $redactada = $oracion;
    foreach ($reglas as $patron => $cambio) {
        $redactada = (string) preg_replace($patron, $cambio, $redactada);
    }
    return (string) preg_replace('/\s{2,}/u', ' ', $redactada);
}

/** "desde hace tres días" → "de tres días de evolución": el tiempo como lo lleva la nota clínica.
 * El relato se corta en la conjunción: "desde hace tres días y anda con gripa" solo redacta
 * "de tres días de evolución" y la gripa queda como lo que es, otra frase. */
function dictadoTiempoClinico(string $oracion): string
{
    return (string) preg_replace_callback(
        '/\bdesde\s+hace\s+([^,.;:?!¿\n]+?)(?=\s+(?:y|e|pero|además|también|aunque)\b|[,.;:?!¿\n]|$)/iu',
        static fn(array $m): string => 'de ' . trim($m[1]) . ' de evolución',
        $oracion
    );
}

/**
 * Redacta un valor narrativo como oraciones clínicas: jerga, persona (solo si $narrativo y
 * la oración es del paciente), tiempo, mayúscula al inicio y punto al final.
 * Para $narrativo=false (campos de lista) solo se corrige la jerga y la puntuación.
 */
function dictadoRedactarNarracion(string $valor, bool $narrativo): string
{
    $valor = trim(str_replace(["\r\n", "\r"], "\n", $valor));
    if ($valor === '') {
        return '';
    }
    $redactadas = [];
    foreach (preg_split('/\n/u', $valor) ?: [] as $linea) {
        $linea = trim($linea);
        if ($linea === '') {
            continue;
        }
        foreach (preg_split('/(?<=[.!?])\s+/u', $linea) ?: [$linea] as $oracion) {
            $oracion = trim($oracion);
            if ($oracion === '') {
                continue;
            }
            $esPregunta = str_contains($oracion, '?') || str_starts_with($oracion, '¿');
            if (!$esPregunta) {
                $esPaciente = $narrativo && (bool) preg_match(DICTADO_MARCADOR_PACIENTE, $oracion);
                // El punto final de la oración se recorta: el de cada oración lo pone
                // este mismo bloque al final (evita el punto doble al re-dactar texto
                // que ya traía puntuación).
                $oracion = rtrim($oracion, '.');
                if ($esPaciente) {
                    $oracion = (string) preg_replace_callback(
                        '/^\s*(?:no|nunca|tampoco)\s+me\s+duele(?:n)?\s+((?:el|la|los|las)\s+)?(cabeza|garganta|pecho|abdomen|est[oó]mago|pan(?:za|sa)|barriga|espalda|cintura|brazo|pierna|rodilla|cuerpo|o[ií]do|muela|diente|hombro|cuello|cadera|ri[nñ][oó]n)\b/iu',
                        static function (array $m): string {
                            $ubicacion = trim(($m[1] ?? '') . $m[2]);
                            return preg_match('/^(?:abdomen|est[oó]mago|pan(?:za|sa)|barriga|vientre)$/iu', $m[2])
                                ? 'niega dolor abdominal'
                                : 'niega dolor en ' . $ubicacion;
                        },
                        $oracion
                    );
                    $oracion = (string) preg_replace(
                        '/^\s*(?:no|nunca|tampoco)\s+(?:tengo|presento|siento|padezco)\s+(?=(?:dolor|fiebre|calentura|tos|cefalea|jaqueca|n[aá]usea|v[oó]mito|diarrea|mareo|v[eé]rtigo|disnea|sangrado|rash|prurito|comez[oó]n|ardor|agruras|acidez|malestar|debilidad|fatiga|cansancio|escalofr[ií]os|congesti[oó]n|mocos|gripe|gripa|catarro|ronchas|pan(?:za|sa)|barriga)\b)/iu',
                        'niega ',
                        $oracion
                    );
                }
                $oracion = dictadoSinJerga($oracion);
                if ($esPaciente) {
                    $oracion = dictadoTerceraPersona($oracion);
                }
                $oracion = dictadoTiempoClinico($oracion);
            }
            $oracion = dictadoFrase($oracion);
            if (!$esPregunta && !preg_match('/[.!?]$/u', $oracion)) {
                $oracion .= '.';
            }
            if ($oracion !== '') {
                $redactadas[] = $oracion;
            }
        }
    }
    if (!$redactadas) {
        return '';
    }
    return implode(' ', $redactadas);
}

/** Aplica la redacción clínica a los campos ya extraídos, según el tipo de cada uno. */
function dictadoRedactarCampos(array $campos): array
{
    foreach ($campos as $campo => $valor) {
        if (!is_string($valor) || $valor === '') {
            continue;
        }
        if (in_array($campo, DICTADO_REDAACCION_NARRATIVOS, true)) {
            $campos[$campo] = dictadoRedactarNarracion($valor, true);
        } elseif (in_array($campo, DICTADO_REDAACCION_LISTAS, true)) {
            $campos[$campo] = dictadoRedactarNarracion($valor, false);
        }
    }
    return $campos;
}

/**
 * Redacta el texto completo del dictado (el que ve el médico en el panel y el que llega a
 * las notas libres): cada oración con jerga, persona y tiempo corregidos. Las etiquetas con
 * dos puntos ("Padecimiento actual: …") se respetan porque el renglón se redacta completo.
 */
function dictadoRedactarTexto(string $texto): string
{
    if (function_exists('sanoTexto')) {
        $texto = sanoTexto($texto);
    }
    $lineas = preg_split('/\n/u', trim(str_replace(["\r\n", "\r"], "\n", $texto))) ?: [];
    $salida = [];
    foreach ($lineas as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        $salida[] = dictadoRedactarNarracion($linea, true);
    }
    return implode("\n", $salida);
}

/**
 * A qué casilla narrativa va un dictado que NO dijo ninguna etiqueta. Se decide por el
 * vocabulario: exploración → su casilla, indicaciones → plan, relato con síntomas →
 * padecimiento actual (o motivo de consulta si es solo la queja corta, sin relato de tiempo).
 * El resultado es el nombre clínico del campo; el formulario lo resuelve por sus alias.
 */
function dictadoCampoNarrativoDe(string $texto): string
{
    $plano = dictadoSinAcentos(mb_strtolower(trim($texto), 'UTF-8'));

    if (preg_match('/\b(palpac|se\s+palpa|turgencia|abdomen\s+(?:blando|duro|depresible|doloroso|tenso)|pulmones|campos\s+pulmonares|ruidos\s+(?:cardiacos|hidroaereos)|craneocervical|toracabdominal|extremidades\s+(?:superiores|inferiores)|mucosas|hidratad[oa]|faringe|glasgow|se\s+percibe|se\s+observa|buen\s+estado\s+general)/iu', $plano)) {
        return 'exploracion_fisica';
    }
    if (preg_match('/\b(evoluciona|persisten?|continua\s+con|cursa\s+con|mejoria|en\s+relacion\s+a\s+su\s+cuadro|a\s+su\s+ingreso|se\s+incrementa|disminuye\s+la\s+intensidad|de\s+nuevo|reaparece|sin\s+datos\s+de\s+(?:gravedad|alarma))/iu', $plano)) {
        return 'nota_evolucion';
    }
    if (preg_match('/\b(se\s+indica|se\s+prescribe|se\s+ordena|se\s+solicita|se\s+administra|se\s+continua|se\s+recomienda|cada\s+\d+\s+horas|por\s+via\s+(?:oral|intravenosa|intramuscular|subcutanea|topica)|cantidad\s+a\s+surtir|alta\s+(?:medica|por\s+mejor)|se\s+cita|control\s+en\s+\d+|vigilancia\s+ambulatoria|reposo\s+relativo|dieta\s+(?:blanda|blanca|hiposalina|hipocalorica))/iu', $plano)) {
        return 'plan_tratamiento';
    }
    if (preg_match('/\b(impresion\s+diagnostica|cuadro\s+compatible|compatible\s+con|probablemente\s+se\s+trata|se\s+diagnostica|diagnostico\s+(?:probable|de\s+ingreso))/iu', $plano)) {
        return 'diagnostico';
    }
    $conRelato = (bool) preg_match('/\b(desde\s+hace|inici|empezo|anoche|ayer|esta\s+manana|hace\s+\d+|la\s+semana\s+pasada|el\s+mes\s+pasado|aparecio|comenzo|desde\s+el|desde\s+la)\b/iu', $plano);
    $esQueja = (bool) preg_match('/\b(dolor|fiebre|calentura|tos|mareo|vertigo|nausea|vomito|diarrea|estrenimiento|disuria|cefalea|gripa|congestion|debilidad|fatiga|cansancio|inflamacion|hinchazon|comezon|prurito|ronchas|sangrado|sangre|insomnio|ansiedad|decaimiento|escalofrios|muela|herida|golpe|caida)\b/iu', $plano);
    if ($esQueja && !$conRelato && mb_strlen($plano) <= 80) {
        return 'motivo_consulta';
    }
    return 'padecimiento_actual';
}
