<?php
/**
 * Extracción de campos del expediente a partir de texto dictado.
 *
 * Puerto PHP de extraer_campos() del servicio local de voz (app/servicios/ia/servicio_stt.py),
 * para que el dictado en MODO TEXTO (Web Speech API del navegador) llene el formulario de
 * registro sin depender del transcriptor local. Se añadieron los patrones explícitos que
 * faltaban: apellido paterno/materno hablado, entidad de nacimiento (mapeada al catálogo
 * ENTIDADES), nacionalidad, estado civil, ocupación y escolaridad.
 *
 * La entrada SIEMPRE pasa por sanoTexto() (anti-mojibake) antes de los patrones.
 */

/** Meses hablados → número (incluye la variante "setiembre"). */
const DICTADO_MESES = [
    'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
    'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
    'noviembre' => 11, 'diciembre' => 12,
];

/** Alias hablados de entidades federativas → clave RENAPO. */
const DICTADO_ENTIDAD_ALIAS = [
    'cdmx' => 'DF', 'distrito federal' => 'DF', 'ciudad de mexico' => 'DF',
    'edomex' => 'MC', 'estado de mexico' => 'MC', 'edo mex' => 'MC',
    'baja california sur' => 'BS', 'baja california' => 'BC',
    'san luis potosi' => 'SP', 'quintana roo' => 'QR', 'nuevo leon' => 'NL',
];

/** Gentilicios frecuentes; el resultado usa una forma canónica estable en el expediente. */
const DICTADO_NACIONALIDAD_ALIAS = [
    'mexicano' => 'Mexicana', 'mexicana' => 'Mexicana',
    'estadounidense' => 'Estadounidense', 'norteamericano' => 'Estadounidense', 'norteamericana' => 'Estadounidense',
    'canadiense' => 'Canadiense', 'guatemalteco' => 'Guatemalteca', 'guatemalteca' => 'Guatemalteca',
    'beliceno' => 'Beliceña', 'belicena' => 'Beliceña', 'hondureno' => 'Hondureña', 'hondurena' => 'Hondureña',
    'salvadoreno' => 'Salvadoreña', 'salvadorena' => 'Salvadoreña', 'nicaraguense' => 'Nicaragüense',
    'costarricense' => 'Costarricense', 'panameno' => 'Panameña', 'panamena' => 'Panameña',
    'cubano' => 'Cubana', 'cubana' => 'Cubana', 'dominicano' => 'Dominicana', 'dominicana' => 'Dominicana',
    'haitiano' => 'Haitiana', 'haitiana' => 'Haitiana', 'puertorriqueno' => 'Puertorriqueña', 'puertorriquena' => 'Puertorriqueña',
    'colombiano' => 'Colombiana', 'colombiana' => 'Colombiana', 'venezolano' => 'Venezolana', 'venezolana' => 'Venezolana',
    'ecuatoriano' => 'Ecuatoriana', 'ecuatoriana' => 'Ecuatoriana', 'peruano' => 'Peruana', 'peruana' => 'Peruana',
    'boliviano' => 'Boliviana', 'boliviana' => 'Boliviana', 'chileno' => 'Chilena', 'chilena' => 'Chilena',
    'argentino' => 'Argentina', 'argentina' => 'Argentina', 'paraguayo' => 'Paraguaya', 'paraguaya' => 'Paraguaya',
    'uruguayo' => 'Uruguaya', 'uruguaya' => 'Uruguaya', 'brasileno' => 'Brasileña', 'brasilena' => 'Brasileña',
    'espanol' => 'Española', 'espanola' => 'Española', 'portugues' => 'Portuguesa', 'portuguesa' => 'Portuguesa',
    'frances' => 'Francesa', 'francesa' => 'Francesa', 'italiano' => 'Italiana', 'italiana' => 'Italiana',
    'aleman' => 'Alemana', 'alemana' => 'Alemana', 'britanico' => 'Británica', 'britanica' => 'Británica',
    'ruso' => 'Rusa', 'rusa' => 'Rusa', 'ucraniano' => 'Ucraniana', 'ucraniana' => 'Ucraniana',
    'chino' => 'China', 'china' => 'China', 'japones' => 'Japonesa', 'japonesa' => 'Japonesa',
    'surcoreano' => 'Surcoreana', 'surcoreana' => 'Surcoreana', 'coreano' => 'Coreana', 'coreana' => 'Coreana',
    'indio' => 'India', 'india' => 'India', 'australiano' => 'Australiana', 'australiana' => 'Australiana',
    'danes' => 'Danesa', 'danesa' => 'Danesa', 'sueco' => 'Sueca', 'sueca' => 'Sueca',
    'noruego' => 'Noruega', 'noruega' => 'Noruega', 'islandes' => 'Islandesa', 'islandesa' => 'Islandesa',
    'neerlandes' => 'Neerlandesa', 'neerlandesa' => 'Neerlandesa', 'holandes' => 'Holandesa', 'holandesa' => 'Holandesa',
    'belga' => 'Belga', 'suizo' => 'Suiza', 'suiza' => 'Suiza', 'austriaco' => 'Austriaca', 'austriaca' => 'Austriaca',
    'griego' => 'Griega', 'griega' => 'Griega', 'polaco' => 'Polaca', 'polaca' => 'Polaca',
    'rumano' => 'Rumana', 'rumana' => 'Rumana', 'turco' => 'Turca', 'turca' => 'Turca',
    'marroqui' => 'Marroquí', 'egipcio' => 'Egipcia', 'egipcia' => 'Egipcia',
    'sudafricano' => 'Sudafricana', 'sudafricana' => 'Sudafricana', 'nigeriano' => 'Nigeriana', 'nigeriana' => 'Nigeriana',
    'keniano' => 'Keniana', 'keniana' => 'Keniana', 'filipino' => 'Filipina', 'filipina' => 'Filipina',
    'tailandes' => 'Tailandesa', 'tailandesa' => 'Tailandesa', 'indonesio' => 'Indonesia', 'indonesia' => 'Indonesia',
    'vietnamita' => 'Vietnamita', 'pakistani' => 'Pakistaní', 'bangladesi' => 'Bangladesí',
    'israeli' => 'Israelí', 'palestino' => 'Palestina', 'palestina' => 'Palestina',
    'sirio' => 'Siria', 'siria' => 'Siria', 'iraqui' => 'Iraquí', 'irani' => 'Iraní',
    'saudi' => 'Saudí', 'senegales' => 'Senegalesa', 'etiope' => 'Etíope',
];

/** Profesiones y ocupaciones que se pueden reconocer sin etiqueta cuando hay verbo copulativo. */
const DICTADO_OFICIOS = 'abogad[oa]|administrador[oa]|agricultor[oa]|alba[nñ]il|ama\s+de\s+casa|amo\s+de\s+casa|arquitect[oa]|artesano[oa]|artista|auxiliar|barbero|bombero|cajer[oa]|carpinter[oa]|chofer|cirujan[oa]|comerciante|conductor[oa]|contador(?:a)?|cocinero[oa]|dentista|dise[nñ]ador[oa]|docente|electricista|emplead[oa]|enfermer[oa]|estilista|estudiante|fontanero[oa]|guardia|ingeniero[oa]|jubilad[oa]|maestr[oa]|m[eé]dic[oa]|mec[aá]nic[oa]|m[uú]sic[oa]|nutri[oó]log[oa]|obrer[oa]|odont[oó]log[oa]|param[eé]dic[oa]|peluquer[oa]|pensionad[oa]|pescador[oa]|polic[ií]a|profesor[oa]|psic[oó]log[oa]|qu[ií]mic[oa]|repartidor[oa]|secretari[oa]|taxista|t[eé]cnic[oa]|vendedor[oa]|veterinari[oa]';

/**
 * Etiquetas habladas del expediente: [patrón, campo del formulario].
 * El campo null solo delimita la sección anterior (su valor lo extrae un patrón específico).
 * El orden importa: las etiquetas más específicas van antes que las generales.
 */
const DICTADO_ETIQUETAS = [
    ['antecedentes\s+personales\s+no\s+patol[oó]gicos?', 'antecedentes_personales_no_patologicos'],
    ['antecedentes\s+personales\s+patol[oó]gicos?', 'antecedentes_personales_patologicos'],
    ['antecedentes\s+heredofamiliares?|antecedentes\s+familiares', 'antecedentes_heredofamiliares'],
    ['antecedentes\s+ginecoobst[eé]tricos?|ginecoobst[eé]tricos?', 'antecedentes_ginecoobstetricos'],
    ['medicamentos?\s+actuales?', 'medicamentos_actuales'],
    ['padecimientos?\s+cr[oó]nicos?|enfermedades?\s+cr[oó]nicas?', 'padecimientos_cronicos'],
    ['grupo\s+[eé]tnico', 'grupo_etnico'],
    ['lengua\s+materna', 'lengua_materna'],
    ['afiliaci[oó]n\s+institucional', 'afiliacion_institucional'],
    ['tutor(?:a)?(?:\s+o\s+representante)?|representante\s+legal', 'tutor_representante'],
    ['inmunizaciones?|esquema\s+de\s+inmunizaci[oó]n|cartilla\s+de\s+vacunaci[oó]n', 'inmunizaciones'],
    ['h[aá]bitos?(?:\s+y\s+consumo\s+de\s+sustancias)?', 'habitos_sustancias'],
    ['alergias?', 'alergias'],
    ['motivo\s+de\s+(?:la\s+)?(?:consulta|atenci[oó]n|ingreso|env[ií]o|interconsulta|incapacidad)', 'motivo_consulta'],
    // Secciones de las notas clínicas (NOM-004): cada una llena su propia casilla.
    ['impresi[oó]n\s+diagn[oó]stica', 'diagnostico'],
    ['diagn[oó]sticos?\s+(?:probable|definitivo|final|de\s+ingreso|de\s+egreso|principal|secundario|diferencial(?:es)?)', 'diagnostico'],
    ['padecimiento\s+actual|enfermedad\s+actual|padecimiento\s+que\s+presenta', 'padecimiento_actual'],
    ['resumen\s+(?:del\s+)?interrogatorio|interrogatorio', 'padecimiento_actual'],
    ['resumen\s+cl[ií]nico', 'padecimiento_actual'],
    ['a\s+la\s+exploraci[oó]n|exploraci[oó]n\s+f[ií]sica|examen\s+f[ií]sico|exploraci[oó]n\s+cl[ií]nica|exploraci[oó]n', 'exploracion_fisica'],
    ['estado\s+mental', 'estado_mental'],
    ['resultados?(?:\s+relevantes)?(?:\s+de\s+estudios)?', 'resultados'],
    ['criterios\s+diagn[oó]sticos?', 'criterios'],
    ['plan\s+de\s+estudios', 'plan_estudios'],
    ['sugerencias(?:\s+diagn[oó]sticas\s+y\s+de\s+tratamiento)?', 'sugerencias'],
    ['plan\s+y\s+tratamiento|plan\s+de\s+tratamiento|plan\s+de\s+manejo(?:\s+y\s+tratamiento)?|plan\s+terap[eé]utico|tratamiento\s+a\s+seguir', 'plan_tratamiento'],
    ['manejo\s+durante\s+la\s+estancia', 'manejo'],
    ['terap[eé]utica\s+empleada', 'terapeutica'],
    ['causas?\s+de\s+(?:la\s+)?defunci[oó]n', 'causas_defuncion'],
    ['problemas?\s+cl[ií]nicos?\s+pendientes?', 'pendientes'],
    ['recomendaciones?(?:\s+para\s+la\s+vigilancia\s+ambulatoria)?', 'recomendaciones'],
    ['(?:atenci[oó]n\s+de\s+)?factores?\s+de\s+riesgo', 'factores_riesgo'],
    ['nota\s+de\s+evoluci[oó]n|evoluci[oó]n\s+del\s+paciente', 'nota_evolucion'],
    ['observaciones', 'nota_evolucion'],
    ['pron[oó]stico', 'pronostico'],
    ['diagn[oó]stico', 'diagnostico'],
    ['evoluci[oó]n', 'nota_evolucion'],
    ['plan\b', 'plan_tratamiento'],
    ['tratamiento', 'plan_tratamiento'],
    ['contacto\s+de\s+emergencia', 'contacto_emergencia'],
    ['tel[eé]fono\s+de\s+emergencia', null],
    ['fecha\s+de\s+nacimiento', null],
    ['entidad\s+de\s+nacimiento', null],
    ['apellido\s+paterno|primer\s+apellido', null],
    ['apellido\s+materno|segundo\s+apellido', null],
    ['nombre\s+completo|nombre\s+del\s+paciente', null],
    ['nombres?', null],
    ['sexo', null],
    ['curp', null],
    ['nacionalidad', null],
    ['estado\s+civil', null],
    ['ocupaci[oó]n', 'ocupacion'],
    ['escolaridad', 'escolaridad'],
    ['grupo\s+sangu[ií]neo', null],
    ['tel[eé]fono', null],
    ['correo(?:\s+electr[oó]nico)?', null],
    ['domicilio|direcci[oó]n', 'domicilio'],
    ['municipio(?:\s*\/\s*estado)?|alcald[ií]a|delegaci[oó]n', 'municipio_estado'],
];

/**
 * Etiquetas que solo abren una sección al inicio de una cláusula (después de punto, coma,
 * punto y coma o al principio del dictado). Así "con diagnóstico de diabetes" no parte la
 * nota en dos, pero "Diagnóstico: hipertensión" sí llena la casilla.
 */
const DICTADO_ESTRICTAS = [
    'alergias', 'padecimientos_cronicos', 'medicamentos_actuales', 'inmunizaciones',
    'padecimiento_actual', 'exploracion_fisica', 'diagnostico', 'plan_tratamiento',
    'nota_evolucion', 'pronostico', 'estado_mental', 'resultados', 'criterios', 'plan_estudios',
    'manejo', 'sugerencias', 'terapeutica', 'causas_defuncion', 'pendientes',
    'recomendaciones', 'factores_riesgo',
];

/** Parentescos dichos al nombrar el contacto de emergencia ("a su esposa, Laura…"). */
const DICTADO_PARENTESCOS = 'espos[oa]|c[oó]nyuge|pareja|madre|padre|hij[oa]|herman[oa]|t[ií][oa]|sobrin[oa]|prim[oa]|abuel[oa]|suegr[oa]|yerno|nuera|amig[oa]|vecin[oa]|familiar|conocid[oa]|colega|tutor(?:a)?|representante';

/**
 * Conectores con los que la redacción clínica formal introduce una sección
 * ("En cuanto a inmunizaciones…", "Respecto a sus antecedentes…", "Como antecedentes…").
 * Se incluyen en la marca de sección para que la sección anterior termine antes del conector.
 */
const DICTADO_INTRO_SECCION = '(?:en\s+cuanto\s+a(?:l|los|las)?|respecto\s+(?:a|de)|con\s+respecto\s+a|en\s+relaci[oó]n\s+(?:a|con)|en\s+lo\s+(?:que\s+)?(?:respecta|referente|tocante|hace)\s+a|por\s+lo\s+que\s+(?:respecta|toca|hace|ve)\s+a|referente\s+a|con\s+referencia\s+a|acerca\s+de|en\s+materia\s+de|en\s+el\s+(?:apartado|rubro|tema|caso)\s+de|en\s+torno\s+a|en\s+lo\s+concerniente\s+a|como|sobre|en|a)';

/** Campos de una sola respuesta: su valor se corta en el primer punto (no abarca varias frases). */
const DICTADO_UNA_FRASE = ['grupo_etnico', 'lengua_materna', 'ocupacion', 'escolaridad',
    'afiliacion_institucional', 'tutor_representante', 'contacto_emergencia',
    'municipio_estado', 'domicilio', 'motivo_consulta'];

/** Preposición/artículo inicial que sobra al capturar un valor ("al IMSS", "de bachillerato"). */
const DICTADO_PREFIJO_SOBRANTE = '/^(?:al|a\s+la|a\s+el|del|de\s+la|de\s+los|de\s+las|de\s+el|de|con|en|la|el|los|las|su|mi|un|una)\s+/iu';

/** Conjunción final que sobra ("bachillerato concluido y…"). */
const DICTADO_SUFIJO_SOBRANTE = '/[\s.,;:]*(?:\s+(?:y|e|con|as[ií]\s+como|que|quien|cuyo|cuya))[\s.,;:]*$/iu';


/**
 * Padecimientos crónicos frecuentes: permiten reconocer "con diabetes e hipertensión"
 * sin exigir un verbo ("tiene", "padece").
 */
const DICTADO_CRONICOS = 'diabetes|diab[eé]tic[oa]|hipertensi[oó]n|hipertens[oa]|presi[oó]n\s+arterial\s+alta|asma|asm[aá]tic[oa]|epoc|enfermedad\s+pulmonar|fibrosis\s+pulmonar|hipotiroidismo|hipertiroidismo|trastorno\s+tiroideo|insuficiencia\s+(?:renal|card[ií]aca|hep[aá]tica)|enfermedad\s+renal\s+cr[oó]nica|cardiopat[ií]a|arritmia|enfermedad\s+cardiovascular|artritis|artrosis|epilepsia|c[aá]ncer|vih|sida|tuberculosis|obesidad|sobrepeso|dislipidemia|hiperlipidemia|colesterol\s+(?:alto|elevado)|cirrosis|hepatitis\s+cr[oó]nica|hepatopat[ií]a|depresi[oó]n|ansiedad|trastorno\s+bipolar|trastorno\s+de\s+ansiedad|anemia|lupus|gota|psoriasis|glaucoma|cataratas?|osteoporosis|fibromialgia|migra[ñn]a|parkinson|alzheimer|esclerosis|dermatitis|rinitis|gastritis|colitis|reflujo|s[ií]ndrome\s+de\s+ovario\s+poliqu[ií]stico';

/** Quita acentos para comparaciones insensibles (entidades, meses). */
function dictadoSinAcentos(string $texto): string
{
    $t = mb_strtolower($texto, 'UTF-8');
    $mapa = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];
    return strtr($t, $mapa);
}

/** Devuelve una nacionalidad canónica si el fragmento contiene un gentilicio inequívoco. */
function dictadoNacionalidadDe(string $fragmento): ?string
{
    $plano = dictadoSinAcentos($fragmento);
    foreach (DICTADO_NACIONALIDAD_ALIAS as $alias => $canonica) {
        if (preg_match('/(?<![a-z])' . preg_quote($alias, '/') . '(?![a-z])/u', $plano)) {
            return $canonica;
        }
    }
    return null;
}

/** Capitaliza cada palabra (nombres, domicilios). */
function dictadoCapitalizar(string $texto): string
{
    return mb_convert_case(trim($texto), MB_CASE_TITLE, 'UTF-8');
}

/** Capitaliza la primera letra solamente (frases clínicas). */
function dictadoFrase(string $texto): string
{
    $t = trim($texto);
    if ($t === '') {
        return '';
    }
    $primera = mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8');
    return $primera . mb_substr($t, 1, null, 'UTF-8');
}

/** Del fragmento dado, devuelve los 10 dígitos del teléfono formateados o null. */
function dictadoTelefonoDe(string $fragmento): ?string
{
    if (!preg_match_all('/\d[\d\s]{8,16}\d/u', $fragmento, $m)) {
        return null;
    }
    foreach ($m[0] as $corrido) {
        $d = preg_replace('/\D/', '', $corrido);
        if (strlen($d) === 10) {
            return substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6);
        }
    }
    return null;
}

/** Reconstruye un correo HABLADO ("juan arroba gmail punto com") desde el fragmento. */
function dictadoCorreoHablado(string $texto): ?string
{
    if (!preg_match("/correos?\s+(?:electr[oó]nico|electronico)\s*(?:es|ser[ií]a|son)?\s*[:;,]?\s*|correos?\s+(?:es|ser[ií]a)\s*[:;,]?\s*|email\s*(?:es|ser[ií]a)?\s*[:;,]?\s*/iu", $texto, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $frag = substr($texto, (int) $m[0][1] + strlen((string) $m[0][0]));
    $frag = trim((string) preg_replace("/gui[oó]n\s+bajo|guion\s+bajo/iu", '_', $frag));
    $frag = (string) preg_replace("/gui[oó]n\s+medio|guion\s+medio/iu", '-', $frag);
    if (!preg_match_all('/arroba|punto|@|[a-z0-9._%+-]+/iu', $frag, $toks)) {
        return null;
    }
    $tlds = ['com', 'mx', 'net', 'org', 'gob', 'edu', 'es', 'cl', 'me', 'io', 'info', 'biz', 'tv'];
    $acumulado = '';
    foreach ($toks[0] as $tok) {
        $tok = mb_strtolower($tok, 'UTF-8');
        if ($tok === 'arroba' || $tok === '@') {
            if (strpos($acumulado, '@') !== false) {
                return null;
            }
            $acumulado .= '@';
        } elseif ($tok === 'punto') {
            $acumulado .= '.';
        } else {
            $acumulado .= $tok;
        }
        if (mb_strlen($acumulado) > 64 || !preg_match('/^[a-z0-9._%+-]*(@[a-z0-9.-]*)?$/', $acumulado)) {
            return null;
        }
        $revisar = rtrim($acumulado, '.');
        if (strpos($revisar, '@') !== false && preg_match('/\.[a-z]{2,6}$/', $revisar)
            && in_array(substr($revisar, (int) strrpos($revisar, '.') + 1), $tlds, true)) {
            return $revisar;
        }
    }
    return null;
}

/** Busca la entidad federativa hablada en el fragmento y devuelve su clave RENAPO. */
function dictadoEntidadDe(string $fragmento): ?string
{
    $plano = dictadoSinAcentos($fragmento);
    foreach (DICTADO_ENTIDAD_ALIAS as $alias => $clave) {
        if (strpos($plano, $alias) !== false) {
            return $clave;
        }
    }
    foreach (ENTIDADES as $clave => $nombre) {
        if ($clave === 'NE') {
            continue; // "nacido en el extranjero" no se dice como entidad
        }
        $nombrePlano = dictadoSinAcentos((string) $nombre);
        if ($nombrePlano !== '' && strpos($plano, $nombrePlano) !== false) {
            return $clave;
        }
    }
    return null;
}

/** Partículas que unen nombres y apellidos ("María de los Ángeles", "de la Fuente"). */
const DICTADO_PARTICULAS = ['de', 'del', 'la', 'las', 'los', 'da', 'das', 'do', 'dos', 'van', 'von'];

/** Nombres de pila frecuentes en México: ayudan a distinguir "José Luis Pérez" de "José Luis Pérez". */
const DICTADO_NOMBRES_COMUNES = [
    'abigail', 'abraham', 'abril', 'adela', 'adrian', 'agustin', 'alberto', 'alejandro', 'alejandra', 'alfonso', 'alfredo', 'alicia', 'alonso', 'alvaro', 'amelia',
    'ana', 'andrea', 'andres', 'angel', 'angela', 'angeles', 'antonio', 'araceli', 'armando', 'arturo', 'aurora', 'beatriz', 'benito', 'bernardo', 'brenda', 'bruno',
    'camila', 'carlos', 'carmen', 'carolina', 'cecilia', 'cesar', 'claudia', 'constanza', 'consuelo', 'cristina', 'daniel', 'daniela',
    'david', 'diana', 'diego', 'dolores', 'eduardo', 'elena', 'elizabeth', 'emiliano', 'emilio', 'enrique', 'erik',
    'esperanza', 'esteban', 'estela', 'esther', 'eugenia', 'eva', 'fabiola', 'fabian', 'felipe', 'fernanda', 'frida',
    'fernando', 'francisco', 'gabriel', 'gabriela', 'gerardo', 'gloria', 'gonzalo', 'graciela',
    'gael', 'guadalupe', 'guillermo', 'hector', 'hugo', 'ignacio', 'ines', 'irene', 'isabel', 'itzel', 'ivan',
    'jaime', 'javier', 'jesus', 'joaquin', 'jorge', 'jose', 'josefina', 'juan', 'juana', 'julia', 'karen', 'karla',
    'julio', 'laura', 'leonardo', 'leticia', 'lidia', 'liliana', 'lorena', 'lourdes', 'lucia',
    'luciano', 'luis', 'luisa', 'magdalena', 'manuel', 'margarita', 'maria', 'mariana', 'maribel',
    'mario', 'marta', 'martin', 'mateo', 'mauricio', 'mayra', 'mercedes', 'miguel', 'mireya', 'monica',
    'natalia', 'nayeli', 'nicolas', 'noel', 'norma', 'octavio', 'olga', 'omar', 'oscar', 'pablo', 'patricia',
    'paola', 'paula', 'pedro', 'perla', 'rafael', 'ramon', 'raul', 'rebeca', 'regina', 'renata', 'ricardo', 'roberto',
    'rodrigo', 'rosa', 'rosario', 'ruben', 'salvador', 'samuel', 'sandra', 'santiago', 'sara',
    'sebastian', 'sergio', 'silvia', 'sofia', 'sonia', 'susana', 'teresa', 'tomas', 'valentin', 'valeria',
    'veronica', 'vicente', 'victor', 'virginia', 'ximena', 'yolanda',
];

/** ¿La palabra es una partícula de nombre ("de", "la", "los")? */
function dictadoEsParticula(string $palabra): bool
{
    return in_array(mb_strtolower($palabra, 'UTF-8'), DICTADO_PARTICULAS, true);
}

/** Escribe un nombre propio: cada palabra con mayúscula, salvo las partículas interiores. */
function dictadoNombrePropio(string $texto): string
{
    $partes = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($partes as $i => $parte) {
        $minus = mb_strtolower($parte, 'UTF-8');
        $partes[$i] = $i > 0 && dictadoEsParticula($minus)
            ? $minus
            : mb_convert_case($minus, MB_CASE_TITLE, 'UTF-8');
    }
    return implode(' ', $partes);
}

/**
 * Reparte una secuencia hablada en nombre, apellido paterno y apellido materno.
 * Convención mexicana: lo último son apellidos; los nombres compuestos se conservan.
 * Devuelve [nombre, paterno, materno] con el texto tal como llegó.
 */
function dictadoPartirNombre(array $partes): array
{
    $partes = array_values(array_filter(array_map(
        static fn ($p) => trim((string) $p),
        $partes
    ), static fn ($p) => $p !== ''));
    $total = count($partes);
    if ($total === 0) {
        return ['', '', ''];
    }
    if ($total === 1) {
        return [$partes[0], '', ''];
    }

    $indices = [];
    foreach ($partes as $i => $parte) {
        if (!dictadoEsParticula($parte)) {
            $indices[] = $i;
        }
    }
    $palabras = count($indices);
    if ($palabras <= 1) {
        return [implode(' ', $partes), '', ''];
    }

    // Dos nombres de pila comunes seguidos, sin otro apellido, no se parten en nombre y apellido.
    if ($palabras === 2
        && in_array(dictadoSinAcentos($partes[$indices[0]]), DICTADO_NOMBRES_COMUNES, true)
        && in_array(dictadoSinAcentos($partes[$indices[1]]), DICTADO_NOMBRES_COMUNES, true)) {
        return [implode(' ', $partes), '', ''];
    }

    $apellidos = $palabras >= 4 ? 2 : 1;
    if ($palabras === 3) {
        $segundo = $indices[1];
        $particulaAntes = $segundo > 0 && dictadoEsParticula($partes[$segundo - 1]);
        $dosNombres = !$particulaAntes
            && in_array(dictadoSinAcentos($partes[$indices[0]]), DICTADO_NOMBRES_COMUNES, true)
            && in_array(dictadoSinAcentos($partes[$indices[1]]), DICTADO_NOMBRES_COMUNES, true);
        $apellidos = $particulaAntes || $dosNombres ? 1 : 2;
    }

    $corte = $indices[$palabras - $apellidos];
    // Con dos apellidos, las partículas pegadas al primero forman parte de él ("de la Fuente").
    if ($apellidos >= 2) {
        while ($corte > 0 && dictadoEsParticula($partes[$corte - 1])) {
            $corte--;
        }
    }

    $nombre = array_slice($partes, 0, $corte);
    $apellido = array_slice($partes, $corte);
    // Si el nombre termina en partícula, esa partícula abre el apellido ("José María de la Cruz").
    while ($nombre && dictadoEsParticula((string) end($nombre))) {
        array_unshift($apellido, array_pop($nombre));
    }

    $indicesApellido = [];
    foreach ($apellido as $i => $parte) {
        if (!dictadoEsParticula($parte)) {
            $indicesApellido[] = $i;
        }
    }
    if (count($indicesApellido) <= 1) {
        return [implode(' ', $nombre), implode(' ', $apellido), ''];
    }
    $ultimo = (int) end($indicesApellido);
    return [
        implode(' ', $nombre),
        implode(' ', array_slice($apellido, 0, $ultimo)),
        implode(' ', array_slice($apellido, $ultimo)),
    ];
}

/** Reparte una secuencia de apellidos: el último es el materno ("de la Fuente Hernández"). */
function dictadoPartirApellidos(array $partes): array
{
    $partes = array_values(array_filter(array_map(
        static fn ($p) => trim((string) $p),
        $partes
    ), static fn ($p) => $p !== ''));
    if (!$partes) {
        return ['', ''];
    }
    $indices = [];
    foreach ($partes as $i => $parte) {
        if (!dictadoEsParticula($parte)) {
            $indices[] = $i;
        }
    }
    if (count($indices) <= 1) {
        return [implode(' ', $partes), ''];
    }
    $ultimo = (int) end($indices);
    return [implode(' ', array_slice($partes, 0, $ultimo)), implode(' ', array_slice($partes, $ultimo))];
}

/**
 * Extrae las secciones que el médico dicta con su etiqueta ("Antecedentes heredofamiliares,
 * madre con diabetes", "Hábitos: tabaquismo negativo"): el valor es todo lo dicho hasta la
 * siguiente etiqueta conocida, así las comas internas no cortan el texto.
 */
function dictadoSeccionesClinicas(string $texto): array
{
    $partes = [];
    foreach (DICTADO_ETIQUETAS as $etiqueta) {
        $partes[] = '(' . $etiqueta[0] . ')';
    }
    // Conector introductorio opcional delante de la etiqueta: al incluirlo en la marca, la
    // sección anterior termina antes del conector (sin arrastrar "En cuanto a", "Respecto a sus"…).
    $intro = '(?:' . DICTADO_INTRO_SECCION . '(?:\s+(?:sus?|los?|las?|el|la|mi|su|del|de\s+la|de\s+los|de\s+las|de\s+el))?\s+)?';
    $patron = '/' . $intro . '(?:' . implode('|', $partes) . ')\b(?:\s+(?:es|son)\b)?\s*[:,;.]?\s*/iu';
    if (!preg_match_all($patron, $texto, $todos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
        return [];
    }

    $marcas = [];
    foreach ($todos as $m) {
        $indice = null;
        foreach (DICTADO_ETIQUETAS as $i => $etiqueta) {
            if (($m[$i + 1][0] ?? null) !== null) {
                $indice = $i;
                break;
            }
        }
        if ($indice === null) {
            continue;
        }
        $marcas[] = [
            'inicio' => $m[0][1],
            'fin'    => $m[0][1] + strlen($m[0][0]),
            'campo'  => DICTADO_ETIQUETAS[$indice][1],
        ];
    }

    $resultado = [];
    $negables = ['alergias', 'padecimientos_cronicos', 'medicamentos_actuales', 'inmunizaciones'];
    $estrictas = array_values(array_unique(array_merge($negables, DICTADO_ESTRICTAS)));
    foreach ($marcas as $i => $marca) {
        $siguiente = $marcas[$i + 1]['inicio'] ?? strlen($texto);
        if ($marca['campo'] === null || $marca['fin'] >= $siguiente || isset($resultado[$marca['campo']])) {
            continue;
        }
        // En las etiquetas ambiguas ("no tiene alergias", "con diagnóstico de diabetes") la
        // etiqueta solo vale si abre una cláusula. En las demás da igual: en una conversación
        // normal la etiqueta puede venir pegada a la frase anterior.
        $antes = rtrim(substr($texto, 0, $marca['inicio']));
        if (in_array($marca['campo'], $estrictas, true)
            && $antes !== '' && !preg_match('/[.;:\n,]$/u', $antes)) {
            continue;
        }
        $valor = trim(substr($texto, $marca['fin'], $siguiente - $marca['fin']), " \t\n\r\0\x0B.,;:");
        // Muletillas del habla ("pues…", "este…", "o sea…") al inicio del valor.
        $valor = (string) preg_replace('/^(?:pues|bueno|este|eh|em|o\s+sea|y)\s+/iu', '', $valor);
        // Verbo de habla inicial ("refiere esquema de vacunación…", "menciona que…"): no es contenido.
        $valor = (string) preg_replace('/^(?:refiere|menciona|indica|se[ñn]ala|comenta|reporta|dice|manifiesta|refiri[oó]|mencion[oó]|expresa|present[aó])\s+(?:que\s+)?/iu', '', $valor);
        // Restos de la frase siguiente que quedaron antes de su etiqueta ("español su").
        $valor = (string) preg_replace('/(?:\s+(?:su|mi|el|la|los|las|del|de\s+la|de\s+el))[\s.,;:]*$/iu', '', $valor);
        $valor = trim($valor, " \t\n\r\0\x0B.,;:");
        $unaFrase = in_array($marca['campo'], DICTADO_UNA_FRASE, true);
        // Campos de una sola respuesta: el valor termina en el primer punto (no abarca varias frases).
        if ($unaFrase) {
            $valor = trim((string) preg_split('/\.\s+|\s+(?=(?:Proporciona|Designa|Refiere|Niega|Reporta|Actualmente|Reside|Grupo\s+sangu[ií]neo|No\s+requiere|Estado\s+civil)\b)/u', $valor)[0]);
            $valor = (string) preg_replace(DICTADO_PREFIJO_SOBRANTE, '', $valor);
            $valor = (string) preg_replace(DICTADO_SUFIJO_SOBRANTE, '', $valor);
        }
        $valor = trim($valor, " \t\n\r\0\x0B.,;:");
        if (mb_strlen($valor, 'UTF-8') < 2) {
            continue;
        }
        // "No requiere tutor ni representante legal": el valor arranca con la negación o la partícula.
        if ($marca['campo'] === 'tutor_representante') {
            $contextoTutor = mb_strtolower(substr($antes, -45), 'UTF-8');
            if (preg_match('/^(?:ni|no|sin|ningun)/iu', $valor)
                || preg_match('/(?:no\s+requiere|no\s+necesita|no\s+tiene|no\s+aplica|no\s+exige|niega|carece)\b/iu', $contextoTutor)) {
                continue;
            }
        }
        // "Alergias: niega", "Inmunizaciones: ninguna" no deben llenar el campo.
        if (in_array($marca['campo'], $negables, true) && preg_match('/^(?:no|niega|ninguna?|sin)\b/iu', $valor)) {
            continue;
        }
        if ($marca['campo'] === 'alergias') {
            $valor = (string) preg_replace('/^(?:a\s+)?(?:el|la|los|las|al)\s+/iu', '', $valor);
        }
        // Campos de una sola respuesta: en conversación ("español, trabaja como chofer")
        // el valor termina en la coma o en el siguiente atributo, no al final del párrafo.
        if (in_array($marca['campo'], ['grupo_etnico', 'lengua_materna', 'ocupacion', 'escolaridad',
            'afiliacion_institucional', 'tutor_representante', 'contacto_emergencia'], true)) {
            $valor = trim((string) preg_split('/[,;.]|\s+(?=(?:y\s+es|es\s+derechohabiente|trabaja|trabajo|estudia|estudi[oó]|tiene|padece|vive|naci[oó]|su|mi)\b)/iu', $valor)[0]);
        }
        if ($marca['campo'] === 'afiliacion_institucional') {
            if (preg_match('/^(?:no|ninguna?|sin|no\s+(?:tiene|cuenta))\b/iu', $valor)) {
                $valor = 'Ninguna';
            } else {
                $plano = dictadoSinAcentos($valor);
                $mapa = ['imss bienestar' => 'IMSS-Bienestar', 'imss' => 'IMSS', 'issste' => 'ISSSTE',
                    'issfam' => 'ISSFAM', 'insabi' => 'INSABI', 'insbien' => 'INSABI', 'pemex' => 'PEMEX',
                    'sedena' => 'SEDENA', 'semar' => 'SEMAR', 'seguro popular' => 'Seguro Popular'];
                foreach ($mapa as $clave => $canon) {
                    if (strpos($plano, $clave) !== false) {
                        $valor = $canon;
                        break;
                    }
                }
            }
        }
        if (in_array($marca['campo'], ['grupo_etnico', 'lengua_materna'], true)
            && preg_match('/^(?:no|no\s+s[eé]|ninguna?)$/iu', $valor)) {
            $valor = 'No especificado';
        }
        if (mb_strlen($valor, 'UTF-8') < 2) {
            continue;
        }
        $resultado[$marca['campo']] = dictadoFrase($valor);
    }
    return $resultado;
}

/**
 * Extrae los campos del expediente que el dictado menciona.
 * Devuelve un mapa nombre_de_campo_del_formulario => valor (solo los reconocidos).
 */
function camposDeDictado(string $texto): array
{
    $campos = [];
    $texto = sanoTexto($texto);
    $t = mb_strtolower(trim($texto), 'UTF-8');
    if ($t === '') {
        return $campos;
    }

    // El habla dicta etiqueta y valor de muchas formas: "Nombre, Ángel", "nombre es Ángel",
    // "Nombre: Ángel", "apellido materno. Adame". El separador debe aceptar todas.
    $separaEtiqueta = '(?:\s+(?:es|son)\s+|\s*[:;.,]\s*|\s+)';

    // Secciones dichas con etiqueta (antecedentes, hábitos, inmunizaciones…).
    $campos = dictadoSeccionesClinicas($texto);

    /* ---------- CURP ---------- */
    if (preg_match('/\b([A-Z][A-Z]{3}\d{6}[HM][A-Z]{5}[A-Z0-9]\d)\b/u', mb_strtoupper($texto, 'UTF-8'), $m)) {
        $campos['curp'] = $m[1];
    }

    /* ---------- Teléfono ---------- */
    if (preg_match('/(?:tel[ée]fono|celular|n[úu]mero|whatsapp)(?!\s+de\s+emergencia)[^0-9]*(\d[\d\s]{8,16}\d)/iu', $t, $m, PREG_OFFSET_CAPTURE)) {
        $tel = dictadoTelefonoDe($m[0][0]);
        if ($tel !== null) {
            $inicio = $m[0][1];
            $contexto = substr($t, max(0, $inicio - 60), min(60, $inicio));
            if (preg_match('/emergencia/iu', $contexto)) {
                $campos['telefono_emergencia'] = $tel;
            } else {
                $campos['telefono'] = $tel;
            }
        }
    }
    if (!isset($campos['telefono']) && preg_match('/\b(\d{2}\s?\d{4}\s?\d{4})\b/u', $t, $m, PREG_OFFSET_CAPTURE)) {
        $inicio = $m[1][1];
        $contexto = substr($t, max(0, $inicio - 40), min(40, $inicio));
        $tel = dictadoTelefonoDe($m[1][0]);
        if ($tel !== null) {
            if (preg_match('/emergencia/iu', $contexto)) {
                $campos['telefono_emergencia'] = $tel;
            } else {
                $campos['telefono'] = $tel;
            }
        }
    }

    /* ---------- Correo (hablado o escrito) ---------- */
    $correo = dictadoCorreoHablado($t);
    if ($correo !== null) {
        $campos['correo'] = $correo;
    } elseif (preg_match('/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/u', $texto, $m)) {
        $campos['correo'] = mb_strtolower($m[1], 'UTF-8');
    }

    /* ---------- Fecha de nacimiento ---------- */
    $mesesAlt = implode('|', array_keys(DICTADO_MESES));
    // Una fecha aislada puede ser la de inicio de síntomas; solo se captura junto a una pista de nacimiento.
    $fechaHablada = '(\d{1,2})\s*(?:de\s+)?(' . $mesesAlt . ')\s*(?:de\s+|del\s+)?(\d{4})';
    $contextoNacimiento = '(?:fecha\s+(?:de\s+)?nacimiento|fecha\s+natal|cumplea[nñ]os|nac[ií](?:[oó])?|nacid[oa])';
    if (preg_match('/\b' . $contextoNacimiento . '[^.;\n]{0,70}?\b' . $fechaHablada . '/iu', $t, $m)) {
        $d = (int) $m[1];
        $mes = DICTADO_MESES[mb_strtolower($m[2], 'UTF-8')];
        $a = (int) $m[3];
        if ($d >= 1 && $d <= 31 && $mes >= 1 && $mes <= 12 && $a >= 1900 && $a <= (int) date('Y')) {
            $campos['fecha_nacimiento'] = sprintf('%04d-%02d-%02d', $a, $mes, $d);
        }
    }
    if (!isset($campos['fecha_nacimiento']) && preg_match('/\b' . $contextoNacimiento . '[^.;\n]{0,70}?\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/iu', $t, $m)) {
        $d = (int) $m[1];
        $mes = (int) $m[2];
        $a = (int) $m[3];
        if ($d >= 1 && $d <= 31 && $mes >= 1 && $mes <= 12 && $a >= 1900 && $a <= (int) date('Y')) {
            $campos['fecha_nacimiento'] = sprintf('%04d-%02d-%02d', $a, $mes, $d);
        }
    }

    // La edad aproximada nunca se convierte en fecha de nacimiento: no conocemos el día ni el mes.

    /* ---------- Sexo ----------
       El reconocedor a veces recorta la palabra ("masculino" → "culino"): se aceptan
       esas variantes para no perder el dato en una conversación normal. */
    if (!isset($campos['sexo'])) {
        if (preg_match('/\b(hombre|var[oó]n|masculin[oa]?|mascu(?:lino)?|culino|se[ñn]or|ni[ñn]o|don)\b/iu', $t)) {
            $campos['sexo'] = 'H';
        } elseif (preg_match('/\b(mujer|femenin[oa]?|feme(?:nino)?|se[ñn]ora|ni[ñn]a|do[ñn]a|chica)\b/iu', $t)) {
            $campos['sexo'] = 'M';
        }
    }

    /* ---------- Grupo sanguíneo ---------- */
    if (preg_match('/\b(O|A|B|AB)\s*(positivo|negativo|\+|-)\b/iu', $t, $m)) {
        $grupo = mb_strtoupper($m[1], 'UTF-8') . (mb_strtolower($m[2], 'UTF-8') === 'positivo' || $m[2] === '+' ? '+' : '-');
        if (in_array($grupo, ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'], true)) {
            $campos['grupo_sanguineo'] = $grupo;
        }
    }

    /* ---------- Signos vitales ----------
       Se aceptan las formas habladas: "tensión 120 sobre 80", "TA 120/80", "pulso 78",
       "temperatura 36.8", "saturación 96 por ciento", "peso 72.5", "talla 170". */
    $numero = static fn (string $valor): float => (float) str_replace(',', '.', $valor);
    $enRango = static fn (float $valor, float $min, float $max): bool => $valor >= $min && $valor <= $max;
    if (preg_match('/\b(?:tensi[oó]n\s+arterial|presi[oó]n\s+arterial|tensi[oó]n|presi[oó]n|\bta\b)\s*(?:de\s+)?(\d{2,3})\s*(?:\/|sobre|entre|-|,|\s+)\s*(\d{2,3})/iu', $t, $m)) {
        if ($enRango((float) $m[1], 50, 260) && $enRango((float) $m[2], 30, 160)) {
            $campos['ta_sistolica'] = (string) (int) $m[1];
            $campos['ta_diastolica'] = (string) (int) $m[2];
        }
    }
    if (preg_match('/\b(?:frecuencia\s+card[ií]aca|frecuencia\s+cardiaca|\bfc\b|pulso|pulsaciones)\s*(?:de\s+)?(\d{2,3})/iu', $t, $m)
        && $enRango((float) $m[1], 20, 250)) {
        $campos['frecuencia_cardiaca'] = (string) (int) $m[1];
    }
    if (preg_match('/\b(?:frecuencia\s+respiratoria|\bfr\b|respiraciones(?:\s+por\s+minuto)?)\s*(?:de\s+)?(\d{1,2})/iu', $t, $m)
        && $enRango((float) $m[1], 5, 80)) {
        $campos['frecuencia_respiratoria'] = (string) (int) $m[1];
    }
    if (preg_match('/\b(?:temperatura|temp)\s*(?:de\s+)?(\d{2,3}(?:[.,]\d)?)/iu', $t, $m)
        && $enRango($numero($m[1]), 30, 45)) {
        $campos['temperatura'] = rtrim(rtrim(number_format($numero($m[1]), 1, '.', ''), '0'), '.');
    }
    if (preg_match('/\b(?:saturaci[oó]n(?:\s+de\s+ox[ií]geno)?|oxigenaci[oó]n|spo2|sat\s*o2)\s*(?:de\s+)?(\d{2,3})/iu', $t, $m)
        && $enRango((float) $m[1], 50, 100)) {
        $campos['saturacion_o2'] = (string) (int) $m[1];
    }
    if (preg_match('/\b(?:glucosa|az[uú]car(?:\s+en\s+sangre)?)\s*(?:de\s+)?(\d{2,3})/iu', $t, $m)
        && $enRango((float) $m[1], 20, 800)) {
        $campos['glucosa'] = (string) (int) $m[1];
    }
    if (preg_match('/\b(?:peso)\s*(?:de\s+)?(\d{1,3}(?:[.,]\d)?)/iu', $t, $m)
        && $enRango($numero($m[1]), 0.5, 400)) {
        $campos['peso'] = rtrim(rtrim(number_format($numero($m[1]), 1, '.', ''), '0'), '.');
    }
    if (preg_match('/\b(?:talla|altura|estatura|mide)\s*(?:de\s+)?(\d{1,3}(?:[.,]\d+)?)/iu', $t, $m)) {
        $talla = $numero($m[1]);
        if ($talla > 0 && $talla < 3) {
            $talla *= 100;   // dictada en metros ("uno punto setenta y cinco")
        }
        if ($enRango($talla, 30, 250)) {
            $campos['talla'] = rtrim(rtrim(number_format($talla, 1, '.', ''), '0'), '.');
        }
    }

    /* ---------- Nombre y apellidos ---------- */
    $palabraNombre = '[A-Za-záéíóúñÁÉÍÓÚÑ]+';
    $particulaNombre = '(?:de|del|la|las|los|da|das|do|dos|van|von)';
    $frenoNombre = '(?:es|son|ser[ií]a|y|e|con|que|mi|sus|tiene|tengo|trabajo|trabaja|vivo|vive|habla|dice|comenta|menciona|indica|reporta|refiere|se[nñ]ala|idioma|comprende|naci[oó]?|nacid[oa]|toma|padece|niega|niego|no|tel[ée]fono|celular|correo|curp|domicilio|municipio|motivo|a[ñn]os|edad|al[eé]rgic[oa]s?|sexo|fecha|estado|ocupaci[oó]n|escolaridad|nacionalidad|apellidos?|nombre|se[ñn]or(?:a)?|el|la|los|las|masculino|femenino|hombre|mujer|paciente|presenta|acude|viene|registra|registro|registrado|identifica|designa|proporciona|reside|radica|habita|utiliza|consume|ingiri[oó]|tom[oó]|diab[eé]tic[oa]|hipertens[oa]|asm[aá]tic[oa]|embarazada|sano|sana|' . DICTADO_OFICIOS . '|' . implode('|', array_map('preg_quote', array_keys(DICTADO_NACIONALIDAD_ALIAS))) . ')';
    $capturaNombre = $palabraNombre . '(?:\s+(?!' . $frenoNombre . '\b)(?:' . $particulaNombre . '\s+)?' . $palabraNombre . '){0,6}';
    $capturaApellido = '(?:' . $particulaNombre . '\s+)?' . $palabraNombre . '(?:\s+(?!' . $frenoNombre . '\b)(?:' . $particulaNombre . '\s+)?' . $palabraNombre . '){0,2}';

    // Redacción clínica formal: "se registra paciente … de nombre Roberto Daniel Martínez Salazar",
    // "paciente llamado …", "se identifica como …". Manda sobre el disparador genérico.
    if (!isset($campos['nombre'])
        && preg_match('/(?:de\s+nombre|con\s+(?:el\s+)?nombre|bajo\s+el\s+nombre|llamad[oa]|denominad[oa]|se\s+llama|se\s+identifica(?:\s+como)?|su\s+nombre\s+es|el\s+nombre\s+del\s+paciente\s+es|nombre\s+del\s+paciente)\s*(?:el|la|don|do[ñn]a|se[ñn]or(?:a)?)?\s*[:,;]?\s*(?!' . $frenoNombre . '\b)(?!' . $particulaNombre . '\b)(' . $capturaNombre . ')/iu', $texto, $m)) {
        $partes = preg_split('/\s+/u', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        [$nombre, $paterno, $materno] = dictadoPartirNombre($partes);
        foreach (['nombre' => $nombre, 'primer_apellido' => $paterno, 'segundo_apellido' => $materno] as $clave => $valor) {
            if ($valor !== '') {
                $campos[$clave] = dictadoNombrePropio($valor);
            }
        }
    }

    // Explícito: "apellido paterno es Ruiz", "primer apellido, Ruiz", "paterno: Ruiz".
    if (!isset($campos['primer_apellido'])
        && preg_match('/(?:apellido\s+paterno|primer\s+apellido|paterno)' . $separaEtiqueta . '(?!' . $frenoNombre . '\b)(' . $capturaApellido . ')/iu', $texto, $m)) {
        $campos['primer_apellido'] = dictadoNombrePropio($m[1]);
    }
    // Explícito: "apellido materno es López", "segundo apellido, López", "materno: López".
    if (!isset($campos['segundo_apellido'])
        && preg_match('/(?:apellido\s+materno|segundo\s+apellido|materno)' . $separaEtiqueta . '(?!' . $frenoNombre . '\b)(' . $capturaApellido . ')/iu', $texto, $m)) {
        $campos['segundo_apellido'] = dictadoNombrePropio($m[1]);
    }
    // "apellidos: Pérez García" o "apellido paterno y materno Pérez García".
    if ((!isset($campos['primer_apellido']) || !isset($campos['segundo_apellido']))
        && preg_match('/(?:apellidos|apellido\s+paterno\s+y\s+(?:el\s+)?materno|paterno\s+y\s+(?:el\s+)?materno)' . $separaEtiqueta . '(' . $capturaApellido . ')/iu', $texto, $m)) {
        [$paterno, $materno] = dictadoPartirApellidos(preg_split('/\s+/u', trim($m[1])) ?: []);
        if (!isset($campos['primer_apellido']) && $paterno !== '') {
            $campos['primer_apellido'] = dictadoNombrePropio($paterno);
        }
        if (!isset($campos['segundo_apellido']) && $materno !== '') {
            $campos['segundo_apellido'] = dictadoNombrePropio($materno);
        }
    }

    // Nombre completo dicho con un disparador: "me llamo…", "paciente…", "nombre completo…".
    $disparadorNombre = '(?:nombre\s+completo|nombre\s+de\s+pila|nombre\s+del\s+paciente|nombres?|me\s+llamo|mi\s+nombre\s+es|se\s+llama|soy|paciente|se[ñn]or(?:a)?|don|do[ñn]a|recibo\s+a|atiendo\s+a|atendemos\s+a|tenemos\s+a|recibimos\s+a|se\s+presenta\s+(?:como|con\s+el\s+nombre\s+de)|el\s+joven|la\s+joven|el\s+ni[ñn]o|la\s+ni[ñn]a|a\s+nombre\s+de)';
    if (!isset($campos['nombre'])
        && preg_match('/' . $disparadorNombre . $separaEtiqueta . '(?!' . $frenoNombre . '\b)(?!' . $particulaNombre . '\b)(' . $capturaNombre . ')/iu', $texto, $m)) {
        $partes = preg_split('/\s+/u', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sobrantes = ['se', 'llama', 'llamo', 'es', 'nombre', 'nombres', 'mi', 'soy', 'paciente',
            'el', 'la', 'los', 'las', 'senor', 'senora', 'don', 'dona', 'recibo', 'atiendo', 'a',
            'joven', 'nino', 'nina', 'completo', 'de', 'pila', 'del'];
        while ($partes && in_array(dictadoSinAcentos($partes[0]), $sobrantes, true)) {
            array_shift($partes);
        }
        $finales = ['y', 'e', 'con', 'que', 'tiene', 'tengo', 'toma', 'padece', 'refiere',
            'presenta', 'acude', 'viene', 'masculino', 'femenino', 'hombre', 'mujer', 'anos', 'edad'];
        while ($partes && in_array(dictadoSinAcentos((string) end($partes)), $finales, true)) {
            array_pop($partes);
        }
        [$nombre, $paterno, $materno] = dictadoPartirNombre($partes);

        // Si el "materno" explícito repite el nombre de pila y el apellido paterno
        // ("materno, Arnulfo Zamora" tras "... Zamora Adame"), es una repetición mal
        // etiquetada por el reconocedor: manda el reparto del nombre completo.
        if (isset($campos['segundo_apellido']) && $materno !== '') {
            $dicho = array_map('dictadoSinAcentos', preg_split('/\s+/u', trim($campos['segundo_apellido']), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $deNombre = array_map('dictadoSinAcentos', preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            if (array_intersect($dicho, $deNombre) !== []
                && $paterno !== '' && in_array(dictadoSinAcentos($paterno), $dicho, true)) {
                unset($campos['segundo_apellido']);
            }
        }

        // Con "Nombre: José Luis" + apellidos etiquetados aparte, todo lo dicho tras la
        // etiqueta son nombres de pila: no hay que repartirlo como nombre y apellido.
        if ((isset($campos['primer_apellido']) || isset($campos['segundo_apellido']))
            && str_starts_with(dictadoSinAcentos(ltrim($m[0])), 'nombre')) {
            $nombre = implode(' ', $partes);
            $paterno = '';
            $materno = '';
        }

        // Los apellidos explícitos mandan; si el reparto había puesto otro en ese lugar, se recorre.
        if (isset($campos['primer_apellido'])) {
            if ($paterno !== '' && dictadoSinAcentos($paterno) !== dictadoSinAcentos($campos['primer_apellido']) && $materno === '') {
                $materno = $paterno;
            }
            $paterno = $campos['primer_apellido'];
        }
        if (isset($campos['segundo_apellido'])) {
            if ($materno !== '' && dictadoSinAcentos($materno) !== dictadoSinAcentos($campos['segundo_apellido']) && $paterno === '') {
                $paterno = $materno;
            }
            $materno = $campos['segundo_apellido'];
        }
        foreach (['nombre' => $nombre, 'primer_apellido' => $paterno, 'segundo_apellido' => $materno] as $clave => $valor) {
            if (!isset($campos[$clave]) && $valor !== '') {
                $campos[$clave] = dictadoNombrePropio($valor);
            }
        }
    }

    /* ---------- Entidad de nacimiento ----------
       Solo con preposición de lugar explícita ("nacido en", "originario de"): si el texto dice
       "nacido el 14 de agosto" sin lugar, no se debe adivinar con el estado del domicilio. */
    if (!isset($campos['entidad_nacimiento'])
        && preg_match('/\b(?:entidad\s+de\s+nacimiento\s*(?:es|:)?|nacid[oa]\s+en|naci[oó]\s+en|nac[ií]\s+en|originari[oa]\s+de)\s*(.{0,80})/iu', $texto, $m)) {
        $clave = dictadoEntidadDe($m[1]);
        if ($clave !== null) {
            $campos['entidad_nacimiento'] = $clave;
        }
    }

    /* ---------- Nacionalidad ----------
       Admite etiqueta y demónimo en una frase común: "es guatemalteca", "paciente colombiano". */
    $gentilicios = implode('|', array_map(static fn(string $alias): string => preg_quote($alias, '/'), array_keys(DICTADO_NACIONALIDAD_ALIAS)));
    $textoSinAcentos = dictadoSinAcentos($t);
    if (preg_match('/nacionalidad' . $separaEtiqueta . '(?!' . $frenoNombre . '\b)([A-Za-záéíóúñÁÉÍÓÚÑ]+(?:\s+[A-Za-záéíóúñÁÉÍÓÚÑ]+){0,2})/iu', $texto, $m)) {
        $valorNacionalidad = trim((string) preg_split('/\s+(?:y|e|pero|nacid[oa]|vive|reside|trabaja)\b/iu', $m[1])[0]);
        $campos['nacionalidad'] = dictadoNacionalidadDe($valorNacionalidad) ?? dictadoCapitalizar($valorNacionalidad);
    } elseif (preg_match('/\b(?:es\s+(?:de\s+)?(?:nacionalidad\s+)?|soy\s+|paciente\s+|persona\s+|se\s+identifica\s+como\s+)(?:' . $gentilicios . ')\b/iu', $textoSinAcentos, $m)
        || preg_match('/[,;]\s*(?:nacionalidad\s+)?(' . $gentilicios . ')\b/iu', $textoSinAcentos, $m)) {
        $campos['nacionalidad'] = dictadoNacionalidadDe($m[0]) ?? dictadoCapitalizar($m[0]);
    }

    /* ---------- Estado civil ---------- */
    if (preg_match('/estado\s+civil' . $separaEtiqueta . '(?!' . $frenoNombre . '\b)([A-Za-záéíóúñÁÉÍÓÚÑ]+(?:\s+[A-Za-záéíóúñÁÉÍÓÚÑ]+){0,2})/iu', $texto, $m)) {
        $campos['estado_civil'] = dictadoCapitalizar($m[1]);
    } elseif (preg_match('/\b(solter[oa]|casad[oa]|divorciad[oa]|viud[oa]|uni[oó]n\s+libre)\b/iu', $t, $m)) {
        $campos['estado_civil'] = dictadoCapitalizar($m[1]);
    }

    /* ---------- Ocupación ----------
       Formas frecuentes: "trabaja de chofer", "se desempeña como…", "es enfermera". */
    if (!isset($campos['ocupacion'])) {
        $disparadorOficio = '(?:ocupaci[oó]n' . $separaEtiqueta
            . '|trabaj[oa]\s+(?:como|de)\s+|labor[oa]\s+como\s+|se\s+desempe[nñ]a\s+como\s+|se\s+dedica\s+a\s+|(?:su\s+)?(?:profesi[oó]n|oficio)\s+(?:es|de)\s+)';
        if (preg_match('/' . $disparadorOficio . '(?:el|la|un|una)?\s*(' . DICTADO_OFICIOS . ')(?=\b|[,;.]|$)/iu', $texto, $m)
            || preg_match('/\b(?:es)\s+(?:el|la|un|una)?\s*(' . DICTADO_OFICIOS . ')(?=\b|[,;.]|$)/iu', $texto, $m)) {
            $campos['ocupacion'] = dictadoFrase(trim($m[1]));
        } elseif (preg_match('/' . $disparadorOficio . '(?:el|la|un|una)?\s*([\p{L}][\p{L}\s\'-]{1,65}?)(?=[,;.]|\s+(?:y\s+)?(?:vive|reside|habita|estudia|tiene|padece|refiere|presenta|actualmente|desde)\b|$)/iu', $texto, $m)) {
            $valorOcupacion = trim((string) preg_replace('/^(?:el|la|un|una)\s+/iu', '', $m[1]));
            if (mb_strlen($valorOcupacion, 'UTF-8') >= 2) {
                $campos['ocupacion'] = dictadoFrase($valorOcupacion);
            }
        }
    }

    /* ---------- Escolaridad ---------- */
    if (!isset($campos['escolaridad'])
        && preg_match('/(?:escolaridad' . $separaEtiqueta . '|estudios\s+de\s+|(?:tiene|termin[oó]|curs[oó]|estudi[oó]|lleg[oó]\s+a)\s+)((?:primaria|secundaria|preparatoria|bachillerato|liceo|licenciatura|maestr[ií]a|doctorado|posgrado|carrera(?:\s+t[eé]cnica)?|universidad)(?:\s+(?:terminad[oa]|completa|incompleta|truncad[oa]|t[eé]cnica))?)/iu', $texto, $m)) {
        $campos['escolaridad'] = dictadoFrase($m[1]);
    }

    /* ---------- Grupo étnico y lengua materna (redacción formal) ----------
       "Refiere pertenecer al grupo étnico mestizo y tener el español como lengua materna":
       el valor puede ir ANTES de la etiqueta, así que se busca con patrones propios que mandan. */
    if (preg_match('/(?:grupo\s+[eé]tnico|etnia|se\s+identifica\s+como|pertenece(?:r)?\s+al\s+grupo\s+[eé]tnico)\s+(?:es\s+|:\s*)?([a-záéíóúñ]{3,30})/iu', $t, $m)) {
        $campos['grupo_etnico'] = dictadoCapitalizar($m[1]);
    }
    if (preg_match('/(?:el|la)\s+([a-záéíóúñ]{3,25})\s+como\s+lengua\s+materna|lengua\s+materna[\s,;:]*(?:es[\s,;:]*)?([a-záéíóúñ]{3,25})|(?:habla|idioma\s+materno)\s+(?:el\s+)?([a-záéíóúñ]{3,25})/iu', $t, $m)) {
        $lengua = $m[1] ?: ($m[2] ?: ($m[3] ?? ''));
        if ($lengua !== '') {
            $campos['lengua_materna'] = dictadoCapitalizar($lengua);
        }
    }

    /* ---------- Alergias ---------- */
    if (preg_match('/(?:al[ée]rgic[oa]s?\s+(?:a\s+)?(?:el|la|los|las|al)?\s*|alergi[oa]\s+(?:a\s+)?(?:el|la|los|las|al)?\s*)([^.;\n]+)/iu', $t, $m)) {
        $valor = trim($m[1]);
        // "alergia a la penicilina y diagnóstico de hipertensión": la alergia termina antes del "y diagnóstico".
        $valor = trim((string) preg_split('/\s+y\s+(?:diagn[oó]stico|antecedente|presenta|tiene|padece|refiere|tratamiento|en\s+tratamiento|consumo|niega|con\s+diagn[oó]stico)\b/iu', $valor)[0]);
        $valor = (string) preg_replace('/^(?:el|la|los|las|al)\s+/iu', '', $valor);
        $valor = (string) preg_replace('/\s+y\s+a\s+(?:la|el|los|las)\s+/iu', ', ', $valor);
        $valor = (string) preg_replace('/\s+y\s+(?:al|a\s+el)\s+/iu', ', ', $valor);
        $valor = dictadoFrase($valor);
        if (isset($campos['alergias']) && $valor !== '') {
            $previos = array_values(array_filter(array_map('trim', explode(',', $campos['alergias'])), static fn($v) => $v !== ''));
            if (!in_array(dictadoSinAcentos($valor), array_map('dictadoSinAcentos', $previos), true)) {
                $campos['alergias'] = implode(', ', array_merge($previos, [$valor]));
            }
        } elseif ($valor !== '') {
            $campos['alergias'] = $valor;
        }
    }

    /* ---------- Padecimientos crónicos ----------
       Acepta primera y tercera persona: "tengo diabetes", "el paciente tiene diabetes". */
    if (!isset($campos['padecimientos_cronicos'])) {
        $disparadorPadecimiento = '(?:(?:el|la)\s+paciente|mi\s+paciente|el\s+usuario|la\s+persona)\s+(?:tiene|presenta|padece|refiere|cuenta\s+con)'
            . '|(?:yo\s+)?(?:tengo|padezco|presento|refiero)'
            . '|(?:t[úu]\s+)?(?:tienes|padeces|presentas|refieres)'
            . '|(?<!no\s)(?<!niega\s)(?:tiene|padece|presenta|refiere)'
            . '|(?:est[aá]\s+diagnosticad[oa]|fue\s+diagnosticad[oa]|diagn[oó]stico\s+de|dx\s+de)';
        if (preg_match_all('/(?:' . $disparadorPadecimiento . ')\s+(?:diagnosticad[oa]s?\s+de\s+|diagn[oó]stico\s+de\s+|dx\s+de\s+)?([^.;\n]+)/iu', $texto, $todos, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($todos as $m) {
                $valor = trim($m[1][0]);
                $antes = substr($texto, 0, $m[0][1]);
                $clausula = preg_match('/(?:^|[.;,\n])([^.;,\n]*)$/u', $antes, $coincidencia)
                    ? $coincidencia[1]
                    : $antes;
                if (preg_match('/\b(?:madre|padre|herman[oa]s?|abuel[oa]s?|t[ií]os?|sobrin[oa]s?|primos?|familiar(?:es)?|familia)\b/iu', $clausula)) {
                    continue;
                }
                $valor = (string) preg_replace('/\s+y\s+(?:padezco|padece|presento|presenta|tengo|tiene)\s+/iu', ', ', $valor);
                $valor = trim((string) preg_split('/(?:,|\s+y|\s+e)\s*(?=(?:toma|toman|refiere|presenta|niega|acude|viene|consulta|el\s+paciente|la\s+paciente|no\s+tiene|tiene|padece|usa|utiliza|recibe)\b)/iu', $valor)[0]);
                // "refiere alergia a…" no es un padecimiento crónico; "refiere padre con…" es familiar.
                if (preg_match('/^alergi[oa]/iu', $valor)
                    || preg_match('/^(?:padre|madre|herman[oa]|abuel[oa]|t[ií][oa]|sobrin[oa]|familiar|familia)/iu', $valor)) {
                    continue;
                }
                if (preg_match('/^\d+\s*a[ñn]os\b/iu', $valor)
                    || preg_match('/^(?:dolor|fiebre|tos|cefalea|n[aá]usea|v[oó]mito|diarrea|mareo|disnea|sangrado|rash|prurito|ardor)\b/iu', $valor)
                    || preg_match('/^(?:una?|dos|tres|\d+)\s+(?:tableta|comprimido|c[aá]psula|gota|ml|mililitro|litro)/iu', $valor)
                    || !preg_match('/\b(?:' . DICTADO_CRONICOS . ')\b/iu', $valor)) {
                    continue;
                }
                $campos['padecimientos_cronicos'] = dictadoFrase($valor);
                break;
            }
        }
        // "con diabetes e hipertensión", sin verbo; se excluye el contexto familiar y negado.
        if (!isset($campos['padecimientos_cronicos'])
            && preg_match_all('/\b(?:' . DICTADO_CRONICOS . ')\b[^.;,\n]*/iu', $texto, $todas, PREG_OFFSET_CAPTURE)) {
            foreach ($todas[0] as [$candidato, $posicion]) {
                $antes = substr($texto, max(0, $posicion - 60), min(60, $posicion));
                $cola = preg_match('/[.;,\n]([^.;,\n]*)$/u', $antes, $mm) ? $mm[1] : $antes;
                if (preg_match('/(?:madre|padre|hermano|hermana|abuelo|abuela|t[ií]o|t[ií]a|familiar|familia|heredofamiliares?)/iu', $cola)
                    || preg_match('/\b(?:no|niega|niegan|sin)\b/iu', $cola)) {
                    continue;
                }
                $campos['padecimientos_cronicos'] = dictadoFrase(trim($candidato));
                break;
            }
        }
    }

    /* Los antecedentes de familiares van a su propio campo, nunca a los padecimientos del paciente. */
    if (!isset($campos['antecedentes_heredofamiliares'])
        && preg_match_all('/\b(?:madre|padre|herman[oa]s?|abuel[oa]s?|t[ií]os?|sobrin[oa]s?|primos?|familiar(?:es)?|familia)\b[^.;\n]{0,90}\b(?:' . DICTADO_CRONICOS . ')\b[^.;\n]*/iu', $texto, $familiares)) {
        $antecedentes = array_map(static fn(string $fragmento): string => dictadoFrase(trim($fragmento, " \t\n\r\0\x0B,;:.")), $familiares[0]);
        $campos['antecedentes_heredofamiliares'] = implode('; ', array_values(array_unique($antecedentes)));
    }

    /* ---------- Medicamentos actuales ----------
       "toma metformina", "el paciente toma…", "me recetaron…"; conserva comas internas. */
    if (!isset($campos['medicamentos_actuales'])
        && preg_match('/(?:(?:(?:el|la)\s+paciente|mi\s+paciente|el\s+usuario|la\s+persona)\s+(?:toma|est[aá]\s+tomando|usa|utiliza|recibe)|(?:yo\s+)?(?:tomo|estoy\s+tomando|me\s+recetaron|le\s+recetaron|usa|utiliza)|(?:t[úu]\s+)?(?:tomas|est[aá]s\s+tomando|usas|utilizas)|(?<!no\s)(?<!niega\s)toma)\s+([^.;\n]+)/iu', $texto, $m)) {
        $valor = trim($m[1]);
        $valor = trim((string) preg_split('/,\s*(?=(?:refiere|presenta|niega|acude|viene|el\s+paciente|la\s+paciente|no\s+tiene|tiene|padece)\b)/iu', $valor)[0]);
        if (!preg_match('/\b(?:agua|litros?|vasos?)\b/iu', $valor)) {
            $campos['medicamentos_actuales'] = dictadoFrase($valor);
        }
    }

    /* ---------- Motivo de consulta ---------- */
    if (!isset($campos['motivo_consulta'])
        && preg_match('/(?:motivo\s+de\s+consulta|viene\s+por|acude\s+por|consulta\s+por|\bmotivo\b\s*:)\s*(?:es|:)?\s*[,;.]?\s*(.+?)(?:\.|$)/iu', $t, $m)) {
        $campos['motivo_consulta'] = dictadoFrase($m[1]);
    }
    if (!isset($campos['motivo_consulta'])) {
        $sintomasAgudos = '(?:dolor(?:\s+(?:de|en\s+el|en\s+la|abdominal|tor[aá]cico|lumbar|articular))?|fiebre|calentura|tos|cefalea|jaqueca|n[aá]usea|ganas\s+de\s+vomitar|v[oó]mito|diarrea|chorrillo|est[oó]mago\s+suelto|mareo|v[eé]rtigo|disnea|falta\s+de\s+aire|sangrado|rash|prurito|comez[oó]n|ardor|agruras|acidez|malestar|debilidad|fatiga|cansancio|escalofr[ií]os|congesti[oó]n|mocos|gripe|gripa|catarro|sarpullido|ronchas|cuerpo\s+cortado)';
        $disparadorSintoma = '(?:refiere|presenta|inicia|comienza|tiene|tengo|siento|siente|me\s+siento|me\s+dio|anda\s+con|viene\s+con|acude\s+por|consulta\s+por|se\s+queja\s+de)';
        if (preg_match('/\b(?:me|le|nos)\s+duele(?:n)?\s+(?:mucho\s+)?(?:el|la|los|las)?\s*(?:cabeza|garganta|pecho|abdomen|est[oó]mago|panza|barriga|espalda|cintura|brazo|pierna|rodilla|cuerpo|o[ií]do|muela|diente|hombro|cuello|cadera|ri[nñ][oó]n)\b[^.;,\n]*/iu', $texto, $m)) {
            $campos['motivo_consulta'] = dictadoFrase(trim($m[0]));
        } elseif (preg_match('/' . $disparadorSintoma . '\s+(?:desde\s+|por\s+|con\s+)?(' . $sintomasAgudos . '\b[^.;,\n]*)/iu', $texto, $m)) {
            $campos['motivo_consulta'] = dictadoFrase(trim($m[1]));
        } elseif (preg_match('/^\s*(' . $sintomasAgudos . '\b[^.;,\n]*)/iu', $texto, $m)) {
            $campos['motivo_consulta'] = dictadoFrase(trim($m[1]));
        }
    }

    /* ---------- Hábitos y consumo de sustancias ---------- */
    if (!isset($campos['habitos_sustancias'])
        && preg_match('/(?:(?:no|niega|niego)\s+)?(?:fuma|tabaquismo|alcohol(?:ismo)?|bebe\s+licor|drogas?|marihuana|coca[ií]na|consumo\s+de\s+sustancias)[^.;]*/iu', $texto, $m)) {
        $campos['habitos_sustancias'] = dictadoFrase(trim($m[0]));
    }

    /* ---------- Inmunizaciones (cuando no se dijo la etiqueta "inmunizaciones") ---------- */
    if (!isset($campos['inmunizaciones'])
        && preg_match('/((?:esquema\s+de\s+)?(?:vacunaci[oó]n|vacunas?|inmunizaci[oó]n)[^.;\n]{2,140})/iu', $texto, $m)) {
        $valor = trim($m[1]);
        if (!preg_match('/^(?:no|niega|ninguna?|sin)\b/iu', $valor)
            && !preg_match('/\b(?:no|niega)\s+(?:esquema|vacunas?|vacunaci)/iu', $valor)) {
            $campos['inmunizaciones'] = dictadoFrase($valor);
        }
    }

    /* ---------- Afiliación institucional ----------
       Solo si el contexto habla de afiliación/derechohabiencia: "su ocupación, Pemex"
       es el trabajo del paciente, no su seguro médico. */
    if (!isset($campos['afiliacion_institucional'])
        && preg_match_all('/\b(IMSS(?:\s+Bienestar)?|ISSSTE|ISSFAM|INSABI|PEMEX|SEDENA|SEMAR|Seguro\s+Popular)\b/iu', $texto, $todas, PREG_OFFSET_CAPTURE)) {
        $instituciones = [
            'imss' => 'IMSS', 'imss bienestar' => 'IMSS-Bienestar', 'issste' => 'ISSSTE',
            'issfam' => 'ISSFAM', 'insabi' => 'INSABI', 'pemex' => 'PEMEX',
            'sedena' => 'SEDENA', 'semar' => 'SEMAR', 'seguro popular' => 'Seguro Popular',
        ];
        foreach ($todas[0] as [$institucion, $posicion]) {
            $antes = substr($texto, max(0, $posicion - 90), min(90, $posicion));
            if (!preg_match('/(?:afiliaci[oó]n|afiliad[oa]|derechohabiente|asegurad[oa]|cotiza|seguro|servicio\s+m[eé]dico|tengo|tiene|soy|estoy\s+en|est[aá]\s+en)\s*(?:el|la|del|de\s+la|de\s+el)?\s*$/iu', $antes)) {
                continue;
            }
            $clave = dictadoSinAcentos($institucion);
            $campos['afiliacion_institucional'] = $instituciones[$clave] ?? dictadoCapitalizar($institucion);
            break;
        }
    }

    /* ---------- Domicilio ----------
       "Reside en calle Encino número 245, colonia Las Flores, municipio de Torreón": se captura
       hasta "municipio/alcaldía/delegación" o el punto, para no perder la colonia. */
    if (!isset($campos['domicilio'])
        && preg_match('/(?:mi\s+direcci[oó]n\s+es|mi\s+domicilio\s+es|con\s+domicilio\s+en|domiciliado\s+en|reside\s+en|radica\s+en|habita\s+en|vive\s+en|viviendo\s+en|habitamos\s+en|habito\s+en|domicilio\s*[:\s]|direcci[oó]n\s*[:\s])\s*(.+?)(?=\s+municipio\b|\s+alcald[ií]a\b|\s+delegaci[oó]n\b|\.|$)/iu', $t, $m)) {
        $valorDomicilio = trim($m[1], " \t\n\r\0\x0B.,;:");
        $valorDomicilio = (string) preg_replace('/^(?:la|el|mi|su)\s+(?=(?:calle|avenida|av\.?|colonia|col\.?|fraccionamiento|barrio|privada|unidad|prolongaci[oó]n)\b)/iu', '', $valorDomicilio);
        $campos['domicilio'] = dictadoCapitalizar($valorDomicilio);
    } elseif (!isset($campos['domicilio']) && preg_match('/(?:en\s+la\s+)?calle\s+(.+?)(?=\s+municipio\b|\s+alcald[ií]a\b|\s+delegaci[oó]n\b|\.|$)/iu', $t, $m)) {
        $campos['domicilio'] = 'Calle ' . dictadoCapitalizar(trim($m[1], " \t\n\r\0\x0B.,;:"));
    }

    /* ---------- Municipio / alcaldía ---------- */
    if (!isset($campos['municipio_estado'])
        && preg_match('/(?:municipios?|alcald[ií]a|delegaci[oó]n)\s*[,;:]?\s*(?:de\s+)?([^.\n]{3,80})/iu', $t, $m)) {
        $campos['municipio_estado'] = dictadoFrase(rtrim(trim($m[1])));
    }

    /* ---------- Contacto y teléfono de emergencia ---------- */
    // Formal: "Designa como contacto de emergencia a su esposa, Laura Fernanda López Ruiz, con número telefónico 871 555 0267."
    if (preg_match('/contacto\s+(?:de\s+)?emergencia\s+(?:a\s+)?(?:su\s+|mi\s+|al\s+|a\s+la\s+)?(?:(?:el|la)\s+)?((?:' . DICTADO_PARENTESCOS . ')\s*,\s*)?([A-ZÁÉÍÓÚÑ][A-Za-zÁÉÍÓÚÑáéíóúñ]*(?:\s+[A-Za-zÁÉÍÓÚÑáéíóúñ]+){1,5})/u', $texto, $m)) {
        $parentesco = trim($m[1] ?? '', ", \t");
        $nombreContacto = dictadoNombrePropio(trim($m[2]));
        $campos['contacto_emergencia'] = $parentesco !== '' ? $nombreContacto . ' (' . $parentesco . ')' : $nombreContacto;
    }
    // Teléfono dicho dentro de la misma frase del contacto de emergencia.
    if (!isset($campos['telefono_emergencia'])
        && preg_match('/contacto\s+(?:de\s+)?emergencia[^.]*?(\d[\d\s]{8,16}\d)/iu', $texto, $m)) {
        $tel = dictadoTelefonoDe($m[1]);
        if ($tel !== null) {
            $campos['telefono_emergencia'] = $tel;
        }
    }
    if (!isset($campos['contacto_emergencia'])
        && preg_match('/(?:contacto\s+de\s+emergencia|contacto\s+emergencia)\s*[,;:]?\s*(.+?)(?:\.|,|$)/iu', $t, $m)) {
        $campos['contacto_emergencia'] = dictadoCapitalizar($m[1]);
    }
    if (!isset($campos['telefono_emergencia'])
        && preg_match('/(?:tel[ée]fono\s+de\s+emergencia|tel\s+emergencia)\s*[,;:]?\s*(.+?)(?:\.|,|$)/iu', $t, $m)) {
        $tel = dictadoTelefonoDe($m[1]);
        if ($tel !== null) {
            $campos['telefono_emergencia'] = $tel;
        }
    }

    return $campos;
}

/**
 * Tipos de error que conviene revisar antes de guardar: dosis o frecuencia incompletas,
 * medicamentos no reconocidos, contradicciones, datos de identificación dudosos y notas
 * a las que les falta una pieza. No bloquea nada: solo avisa al médico.
 *
 * @param array<int,array<string,string>> $medicamentos Filas devueltas por medicamentosDeDictado().
 * @return array<int,array{tipo:string,mensaje:string,gravedad:string,campo:?string}>
 */
function dictadoAvisos(string $texto, array $campos, array $medicamentos): array
{
    $avisos = [];
    $agregar = static function (string $tipo, string $mensaje, string $gravedad = 'media', ?string $campo = null) use (&$avisos): void {
        foreach ($avisos as $existente) {
            if ($existente['tipo'] === $tipo && $existente['mensaje'] === $mensaje) {
                return;
            }
        }
        $avisos[] = ['tipo' => $tipo, 'mensaje' => $mensaje, 'gravedad' => $gravedad, 'campo' => $campo];
    };

    $t = mb_strtolower($texto, 'UTF-8');

    /* ---------- 1. Medicamentos: dosis, frecuencia y vía ---------- */
    foreach ($medicamentos as $medicamento) {
        $nombre = trim((string) ($medicamento['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $dosis = trim((string) ($medicamento['dosis'] ?? ''));
        $periodicidad = trim((string) ($medicamento['periodicidad'] ?? ''));
        if ($dosis === '' || !preg_match('/\d/u', $dosis)) {
            $agregar('dosis_incompleta', '«' . $nombre . '» no tiene una dosis clara.', 'media', 'medicamentos');
        } elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*(mg|miligramos?|g|gramos?)\b/iu', $dosis, $m)) {
            $cantidad = (float) str_replace(',', '.', $m[1]);
            $enMg = preg_match('/^(?:g|gramos?)$/iu', $m[2]) ? $cantidad * 1000 : $cantidad;
            if ($enMg > 5000) {
                $agregar('dosis_fuera_de_rango', '«' . $nombre . '» tiene una dosis muy alta (' . $dosis . '). Verifícala.', 'alta', 'medicamentos');
            }
        }
        if ($periodicidad === '') {
            $agregar('frecuencia_incompleta', '«' . $nombre . '» no tiene frecuencia (cada cuánto se toma).', 'media', 'medicamentos');
        } elseif (preg_match('/cada\s+(\d+)\s*(minutos?|horas?)/iu', $periodicidad, $m)) {
            $horas = preg_match('/^minuto/iu', $m[2]) ? ((int) $m[1]) / 60 : (int) $m[1];
            if ($horas < 1 || $horas > 24) {
                $agregar('frecuencia_fuera_de_rango', '«' . $nombre . '» tiene una frecuencia poco usual (' . $periodicidad . ').', 'media', 'medicamentos');
            }
        }
    }

    /* ---------- 2. Se habló de receta pero no se reconoció el fármaco ---------- */
    if (!$medicamentos
        && preg_match('/\b(receta|prescrib\w*|medicament\w*|tomar|tome|tabletas?|c[aá]psulas?|jarabe|inyecci[oó]n|miligramos?|mg)\b/iu', $t)) {
        $agregar('medicamento_no_reconocido', 'Se habló de un medicamento, pero no se reconoció en el catálogo. Revísalo.', 'alta', 'medicamentos');
    }

    /* ---------- 3. Contradicciones entre lo dicho y lo capturado ---------- */
    if (isset($campos['alergias']) && preg_match('/\b(?:no\s+tiene\s+alergias?|niega\s+alergias?|sin\s+alergias?)\b/iu', $t)) {
        $agregar('contradiccion', 'Dijiste que no hay alergias, pero la casilla de alergias quedó llena.', 'alta', 'alergias');
    }
    if (isset($campos['padecimientos_cronicos'])
        && preg_match('/\b(?:niega|no\s+tiene|sin)\s+(?:diabetes|hipertensi[oó]n|asma|enfermedad)/iu', $t)) {
        $agregar('contradiccion', 'Hay una negación de padecimiento crónico junto con un padecimiento capturado.', 'media', 'padecimientos_cronicos');
    }

    /* ---------- 4. Signos vitales fuera de rango (aunque no se capturen) ---------- */
    if (preg_match('/\b(?:tensi[oó]n|presi[oó]n|\bta\b)\s*(?:de\s+)?(\d{2,3})\s*(?:\/|sobre|entre)\s*(\d{2,3})/iu', $t, $m)
        && ((int) $m[1] > 260 || (int) $m[2] > 160 || (int) $m[1] < 50 || (int) $m[2] < 30)) {
        $agregar('signo_fuera_de_rango', 'La tensión arterial ' . $m[1] . '/' . $m[2] . ' parece fuera de rango. Verifícala.', 'alta');
    }
    if (preg_match('/\b(?:temperatura|temp)\s*(?:de\s+)?(\d{2,3}(?:[.,]\d)?)/iu', $t, $m)) {
        $temperatura = (float) str_replace(',', '.', $m[1]);
        if ($temperatura < 30 || $temperatura > 45) {
            $agregar('signo_fuera_de_rango', 'La temperatura ' . $m[1] . ' parece fuera de rango. Verifícala.', 'alta');
        }
    }

    /* ---------- 5. Datos de identificación dudosos ---------- */
    if (isset($campos['telefono']) && !preg_match('/^\d{3} \d{3} \d{4}$/', (string) $campos['telefono'])) {
        $agregar('telefono_incompleto', 'El teléfono no tiene 10 dígitos.', 'media', 'telefono');
    }
    if (isset($campos['nombre']) && mb_strlen((string) $campos['nombre'], 'UTF-8') > 60) {
        $agregar('nombre_dudoso', 'El nombre capturado es muy largo; puede haber incluido otra frase.', 'media', 'nombre');
    }
    if (!isset($campos['fecha_nacimiento'])
        && preg_match('/\b(?:edad(?:\s+de)?|tiene|tengo)\s+(?:de\s+)?\d{1,3}\s+a[ñn]os\b/iu', $t)) {
        $agregar('fecha_nacimiento_faltante', 'Se dijo la edad, pero falta la fecha exacta de nacimiento.', 'baja', 'fecha_nacimiento');
    }
    if (isset($campos['curp']) && !preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', (string) $campos['curp'])) {
        $agregar('curp_invalida', 'La CURP no tiene el formato esperado. Revísala.', 'alta', 'curp');
    }

    /* ---------- 6. Nota incompleta ---------- */
    if (!isset($campos['motivo_consulta'])
        && (isset($campos['padecimiento_actual']) || isset($campos['diagnostico']) || isset($campos['plan_tratamiento']))
        && preg_match('/\b(dolor|fiebre|tos|consulta|refiere|acude|viene\s+por)\b/iu', $t)) {
        $agregar('falta_motivo', 'No se detectó el motivo de consulta. Agrégalo si hace falta.', 'baja', 'motivo_consulta');
    }
    if (isset($campos['plan_tratamiento']) && !isset($campos['diagnostico'])) {
        $agregar('falta_diagnostico', 'Hay un plan de tratamiento, pero no se detectó diagnóstico.', 'media', 'diagnostico');
    }

    /* ---------- 7. Transcripción dudosa ---------- */
    if (preg_match('/\b([a-záéíóúñ]{4,})\s+\1\b/iu', $t, $m)) {
        $agregar('texto_repetido', 'Se repitió «' . $m[1] . '» en la transcripción. Revisa esa parte.', 'baja');
    }
    if (trim($texto) !== '' && mb_strlen(trim($texto), 'UTF-8') < 12) {
        $agregar('texto_muy_corto', 'El dictado es muy corto para llenar campos con seguridad.', 'baja');
    }

    $orden = ['alta' => 0, 'media' => 1, 'baja' => 2];
    usort($avisos, static fn (array $a, array $b): int => ($orden[$a['gravedad']] ?? 3) <=> ($orden[$b['gravedad']] ?? 3));
    return array_slice($avisos, 0, 6);
}
