<?php
/**
 * Seeder de 100 pacientes con consultas clínicas completas.
 * Puede ejecutarse vía CLI o vía web con ?token=seeder100Clínica
 */
define('PAGINA_PUBLICA', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/nucleo/seguridad.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/funciones.php';
require_once __DIR__ . '/../app/datos/registros_clinicos.php';

// Verificación de acceso
$esCli = PHP_SAPI === 'cli';
$tokenEsperado = 'seeder100Clínica';

if (!$esCli) {
    $tokenRecibido = $_GET['token'] ?? '';
    if (!hash_equals($tokenEsperado, $tokenRecibido)) {
        http_response_code(403);
        die("Acceso denegado. Se requiere token valido.\n");
    }
}

// Configuración de ejecución
@set_time_limit(300);
@ini_set('memory_limit', '256M');

header('Content-Type: text/plain; charset=UTF-8');

echo "=== SEEDER: GENERANDO 100 PACIENTES CONSULTADOS ===\n\n";

$pdo = db();

// 1. Detectar Institución
$institucionId = null;
try {
    $qInst = $pdo->query("SELECT id, nombre FROM instituciones ORDER BY id LIMIT 1");
    if ($inst = $qInst->fetch()) {
        $institucionId = (int) $inst['id'];
        echo "Institución activa: [ID {$institucionId}] {$inst['nombre']}\n";
    } else {
        echo "Sin instituciones registradas (institucion_id = NULL)\n";
    }
} catch (Throwable $e) {
    echo "Institución: tabla no disponible o sin columna ({$e->getMessage()})\n";
}

// 2. Detectar Médico / Autor
$autor = null;
try {
    $qUser = $pdo->prepare("SELECT id, nombre, cedula_profesional FROM usuarios WHERE rol IN ('administrador', 'medico') AND activo = 1 ORDER BY id LIMIT 1");
    $qUser->execute();
    if ($u = $qUser->fetch()) {
        $autor = [
            'id' => (int) $u['id'],
            'nombre' => $u['nombre'],
            'cedula' => $u['cedula_profesional'] ?: '12345678'
        ];
    }
} catch (Throwable $e) {
    // Fallback
}

if (!$autor) {
    // Si no hay médico, buscar cualquier usuario
    $qUser = $pdo->query("SELECT id, nombre, cedula_profesional FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1");
    if ($u = $qUser->fetch()) {
        $autor = [
            'id' => (int) $u['id'],
            'nombre' => $u['nombre'],
            'cedula' => $u['cedula_profesional'] ?: '12345678'
        ];
    } else {
        // Crear usuario médico por defecto
        $pdo->prepare("INSERT INTO usuarios (nombre, correo, cedula_profesional, clave_hash, rol, activo) VALUES (?, ?, ?, ?, 'medico', 1)")
            ->execute(['Dr. Carlos Mendoza Soto', 'dr.mendoza@Clínica.local', '87654321', password_hash('Clínica2026', PASSWORD_DEFAULT)]);
        $autor = [
            'id' => (int) $pdo->lastInsertId(),
            'nombre' => 'Dr. Carlos Mendoza Soto',
            'cedula' => '87654321'
        ];
    }
}
echo "Médico autor de consultas: [ID {$autor['id']}] {$autor['nombre']} (Cédula: {$autor['cedula']})\n\n";

// Función generadora de CURP válida
function generarCurp(string $ap1, string $ap2, string $nombre, string $fecha, string $sexo, string $entidad): string
{
    $limpio = fn ($t) => strtoupper(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'x', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
    [$ap1, $ap2, $nombre] = [$limpio($ap1), $limpio($ap2), $limpio(explode(' ', $nombre)[0])];
    $vocal = preg_match('/[AEIOU]/', substr($ap1, 1), $m) ? $m[0] : 'X';
    $cons  = fn ($t) => preg_match('/[B-DF-HJ-NP-TV-Z]/', substr($t, 1), $m) ? $m[0] : 'X';
    $base  = $ap1[0] . $vocal . ($ap2[0] ?? 'X') . $nombre[0] . date('ymd', strtotime($fecha)) . ($sexo === 'M' ? 'M' : 'H') . $entidad
           . $cons($ap1) . $cons($ap2) . $cons($nombre) . ((int) substr($fecha, 0, 4) >= 2000 ? 'A' : '0');
    $tabla = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $suma = 0;
    foreach (str_split($base) as $i => $c) {
        $pos = mb_strpos($tabla, $c);
        $suma += ($pos !== false ? $pos : 0) * (18 - $i);
    }
    return $base . ((10 - $suma % 10) % 10);
}

// Banco de datos demográficos mexicanos
$nombresH = [
    'Juan Carlos', 'José Luis', 'Miguel Ángel', 'Alejandro', 'Jorge Alberto',
    'Ricardo', 'Roberto', 'Fernando', 'Daniel', 'David',
    'Eduardo', 'Francisco Javier', 'Héctor', 'Mario Alberto', 'Raúl',
    'Guillermo', 'Manuel', 'Arturo', 'Óscar', 'Víctor Hugo',
    'Gabriel', 'Rodrigo', 'Andrés', 'Jaime', 'Enrique',
    'Adrián', 'Sergio', 'Mauricio', 'Pablo', 'Ignacio',
    'César Augusto', 'Hugo', 'Marco Antonio', 'Leonardo', 'Emilio',
    'Diego Armando', 'Sebastián', 'Mateo', 'Santiago', 'Julián',
    'Samuel', 'Benjamín', 'Esteban', 'Iván', 'Felipe',
    'Rubén', 'Gerardo', 'Salvador', 'Joaquín', 'Alfonso'
];

$nombresM = [
    'María Elena', 'Guadalupe', 'Rosa María', 'Ana Luisa', 'Patricia',
    'Carmen', 'Laura Beatriz', 'Silvia', 'Adriana', 'Verónica',
    'Leticia', 'Martha Alicia', 'Teresa', 'Gabriela', 'Claudia',
    'Gloria', 'Yolanda', 'Irma', 'Rocío', 'Lucía',
    'Alejandra', 'Diana Laura', 'Daniela', 'Fernanda', 'Paola',
    'Sofía', 'Mariana', 'Andrea', 'Natalia', 'Valeria',
    'Camila', 'Regina', 'Ximena', 'Renata', 'Paulina',
    'Karla Ivonne', 'Brenda', 'Mónica', 'Sandra Luz', 'Elizabeth',
    'Susana', 'Cecilia', 'Beatriz', 'Lorena', 'Estela',
    'Josefina', 'Blanca Esthela', 'Norma Angélica', 'Aurora', 'Miriam'
];

$apellidos1 = [
    'Hernández', 'García', 'Martínez', 'López', 'González',
    'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz',
    'Flores', 'Gómez', 'Morales', 'Vázquez', 'Reyes',
    'Jiménez', 'Torres', 'Díaz', 'Gutiérrez', 'Mendoza',
    'Aguilar', 'Ortiz', 'Castillo', 'Moreno', 'Romero',
    'Álvarez', 'Méndez', 'Chávez', 'Rivera', 'Juárez',
    'Domínguez', 'Herrera', 'Medina', 'Castro', 'Vargas',
    'Guzmán', 'Velázquez', 'Ramos', 'Contreras', 'Salazar',
    'Silva', 'Delgado', 'Cabrera', 'Guerrero', 'Rojas',
    'Luna', 'Estrada', 'Bautista', 'Soto', 'Cortés'
];

$apellidos2 = [
    'Navarro', 'Ríos', 'Orozco', 'Cervantes', 'Campos',
    'Vega', 'Pacheco', 'Mejía', 'Valdez', 'Fuentes',
    'Lara', 'Carrillo', 'Sandoval', 'Maldonado', 'Miranda',
    'Serrano', 'Acosta', 'Padilla', 'Montes', 'Valencia',
    'Quintero', 'Rosas', 'Salas', 'Molina', 'Villegas',
    'Trejo', 'Navarrete', 'Paredes', 'Gallegos', 'Salinas',
    'Coronado', 'Santillán', 'Zamora', 'Ponce', 'Escobar',
    'Bustos', 'Villanueva', 'Peralta', 'Beltrán', 'Calderón',
    'Franco', 'Arroyo', 'Barajas', 'Montoya', 'Cordero',
    'Cárdenas', 'Hurtado', 'Ochoa', 'Villalobos', 'Ibarra'
];

$entidades = [
    ['clave' => 'DF', 'nombre' => 'Ciudad de México', 'mun' => 'Cuauhtémoc'],
    ['clave' => 'MC', 'nombre' => 'Estado de México', 'mun' => 'Toluca'],
    ['clave' => 'JC', 'nombre' => 'Jalisco', 'mun' => 'Guadalajara'],
    ['clave' => 'NL', 'nombre' => 'Nuevo León', 'mun' => 'Monterrey'],
    ['clave' => 'PL', 'nombre' => 'Puebla', 'mun' => 'Puebla'],
    ['clave' => 'QT', 'nombre' => 'Querétaro', 'mun' => 'Santiago de Querétaro'],
    ['clave' => 'GT', 'nombre' => 'Guanajuato', 'mun' => 'León'],
    ['clave' => 'YU', 'nombre' => 'Yucatán', 'mun' => 'Mérida'],
    ['clave' => 'BC', 'nombre' => 'Baja California', 'mun' => 'Tijuana'],
    ['clave' => 'CO', 'nombre' => 'Coahuila', 'mun' => 'Saltillo'],
    ['clave' => 'SL', 'nombre' => 'San Luis Potosí', 'mun' => 'San Luis Potosí'],
    ['clave' => 'VZ', 'nombre' => 'Veracruz', 'mun' => 'Veracruz'],
];

$calles = [
    'Av. Insurgentes Sur 1245', 'Calle Juárez 452', 'Av. Hidalgo 89', 'Av. Revolución 670',
    'Paseo de la Reforma 334', 'Calle Morelos 112', 'Av. Constitución 405', 'Calle Zaragoza 78',
    'Av. Benito Juárez 56', 'Calzada de Tlalpan 890', 'Av. Cuauhtémoc 321', 'Av. Chapultepec 510',
    'Calle 5 de Mayo 144', 'Av. División del Norte 120', 'Av. Universidad 780', 'Calle Allende 63'
];

$colonias = [
    'Col. Del Valle', 'Col. Roma Norte', 'Col. Centro', 'Col. Condesa', 'Col. Narvarte',
    'Col. Juárez', 'Col. Providencia', 'Col. Obispado', 'Col. Las Américas', 'Col. Jardines'
];

$gruposSanguineos = ['O+', 'O+', 'O+', 'A+', 'A+', 'B+', 'O-', 'A-', 'AB+'];

// 20 Arquetipos Clínicos COMPLETOS con datos diagnósticos, signos, exploración y tratamiento
$arquetipos = [
    [
        'motivo' => 'Control periódico de hipertensión arterial sistémica',
        'cronicos' => 'Hipertensión arterial sistémica (diagnóstico hace 4 años)',
        'meds_actuales' => 'Losartán 50 mg cada 12 horas vía oral',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'I10',
        'cie10_desc' => 'Hipertensión esencial (primaria)',
        'padecimiento' => 'Acude a consulta de control trimestral de cifras tensionales. Refiere adecuada tolerancia al tratamiento con Losartán, sin cefalea, acúfenos, fosfenos ni palpitaciones en los últimos días.',
        'aparatos_sistemas' => 'Cardiovascular: sin dolor precordial ni ortopnea. Respiratorio: sin disnea ni tos. Neurológico: lúcido, sin déficit motor ni sensitivo.',
        'exploracion' => 'Paciente masculino/femenino normolíneo, orientado en tiempo, espacio y persona. Cráneo normocéfalo sin alteraciones. Cuello sin ingurgitación yugular ni soplos carotídeos. Tórax normotórax con ruidos cardíacos rítmicos sin soplos ni galope, campos pulmonares bien ventilados sin estertores. Abdomen blando, depresible, no doloroso, sin visceromegalias. Extremidades íntegras, simétricas, sin edema maleolar.',
        'signos' => ['sistolica' => 124, 'diastolica' => 78, 'fc' => 72, 'fr' => 17, 'temperatura' => 36.5, 'spo2' => 98, 'glucosa' => 92, 'peso' => 74.5, 'talla' => 168],
        'resultados' => 'Química sanguínea y electrolitos séricos recientes (hace 2 semanas): Creatinina 0.9 mg/dL, Urea 28 mg/dL, Potasio 4.2 mEq/L, Sodio 139 mEq/L dentro de parámetros normales.',
        'nota' => 'Paciente en adecuado control tensional bajo esquema actual. Se refuerzan metas de presión arterial (<130/80 mmHg), restricción de sodio en dieta y fomento de actividad física regular aeróbica.',
        'plan' => "1. Continuar Losartán 50 mg vía oral cada 12 horas.\n2. Dieta tipo DASH con restricción de sodio (< 2 g/día).\n3. Actividad física aeróbica 30 minutos al día al menos 5 días a la semana.\n4. Registro de bitácora tensional en domicilio.\n5. Cita de seguimiento en 3 meses con perfil de lípidos y ego de control.",
        'pronostico' => 'Bueno para la vida y para la función con apego terapéutico.'
    ],
    [
        'motivo' => 'Control metabólico de diabetes mellitus tipo 2',
        'cronicos' => 'Diabetes mellitus tipo 2 (diagnóstico hace 6 años)',
        'meds_actuales' => 'Metformina 850 mg cada 12 horas después de alimentos',
        'alergias' => 'Penicilina (urticaria en la infancia)',
        'cie10' => 'E11.9',
        'cie10_desc' => 'Diabetes mellitus tipo 2 sin mención de complicación',
        'padecimiento' => 'Paciente con diagnóstico conocido de DM2 que asiste a valoración de control glucémico. Niega síntomas cardinales de descontrol agudo (poliuria, polidipsia, polifagia o pérdida ponderal). Refiere apego parcial a plan nutricional.',
        'aparatos_sistemas' => 'Gastrointestinal: sin náusea ni meteorismo por metformina. Neurológico: niega parestesias o disestesias distales en miembros inferiores.',
        'exploracion' => 'Consciente, tranquilo, hidratado. Cuello sin acantosis nigricans marcada. Ruidos cardíacos de buen tono e intensidad, campos pulmonares limpios. Abdomen globoso a expensas de panículo adiposo, blando, peristalsis presente. Extremidades: pulsos pedios y tibiales posteriores presentes y simétricos, sensibilidad vibratoria conservada con diapasón 128Hz, sin úlceras ni lesiones en pies.',
        'signos' => ['sistolica' => 120, 'diastolica' => 80, 'fc' => 76, 'fr' => 18, 'temperatura' => 36.6, 'spo2' => 97, 'glucosa' => 118, 'peso' => 82.0, 'talla' => 165],
        'resultados' => 'Hemoglobina glucosilada (HbA1c): 6.9%. Glucosa en ayuno reciente: 114 mg/dL. Microalbuminuria negativa en orina.',
        'nota' => 'Diabetes mellitus tipo 2 en meta de control metabólico (HbA1c < 7.0%). Se estimula a mantener apego y control de peso.',
        'plan' => "1. Continuar Metformina 850 mg vía oral cada 12 horas con los alimentos.\n2. Plan alimentario balanceado bajo en azúcares refinados y grasas saturadas.\n3. Automonitoreo de glucosa capilar en ayuno y postprandial 2 veces por semana.\n4. Cuidado e inspección diaria de pies.\n5. Próxima cita de control en 3 meses con química sanguínea y HbA1c.",
        'pronostico' => 'Favorable con adherencia a plan dietético y farmacológico.'
    ],
    [
        'motivo' => 'Faringoamigdalitis aguda con fiebre y odinofagia',
        'cronicos' => 'Ninguno',
        'meds_actuales' => 'Ninguno',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'J03.9',
        'cie10_desc' => 'Amigdalitis aguda, no especificada',
        'padecimiento' => 'Inicia padecimiento actual hace 48 horas con odinofagia progresiva, sensación de cuerpo extraño faríngeo, astenia, adinamia y picos febriles cuantificados de hasta 38.3°C, acompañados de escalofríos y cefalea holocraneal leve.',
        'aparatos_sistemas' => 'Otorrinolaringológico: odinofagia intensa, disfagia a sólidos. Respiratorio: sin tos productiva ni disnea.',
        'exploracion' => 'Paciente febril al momento de la exploración, facies de dolor. Faringe hiperémica, amígdalas palatinas grado III con exudado blanquecino puntiforme confluente bilateral. Úvula central. Cuello: adenomegalias submandibulares y cervicales anteriores palpables, móviles y dolorosas. Cardiopulmonar sin compromiso.',
        'signos' => ['sistolica' => 110, 'diastolica' => 70, 'fc' => 88, 'fr' => 19, 'temperatura' => 38.2, 'spo2' => 98, 'glucosa' => 88, 'peso' => 68.0, 'talla' => 170],
        'resultados' => 'Criterios de Centor modificados: 4 puntos (exudado amigdalino, adenopatía cervical dolorosa, fiebre > 38°C, ausencia de tos), alta probabilidad de etiología bacteriana por estreptococo del grupo A.',
        'nota' => 'Cuadro clínico compatible con faringoamigdalitis aguda bacteriana exudativa. Se prescribe terapia antimicrobiana y antiinflamatoria.',
        'plan' => "1. Amoxicilina con Ácido Clavulánico 875/125 mg vía oral cada 12 horas por 7 días.\n2. Ibuprofeno 400 mg vía oral cada 8 horas por 3 a 5 días con alimentos.\n3. Hidratación abundante con líquidos claros tibios.\n4. Reposo relativo por 48 horas.\n5. Datos de alarma: estridor, dificultad respiratoria, incapacidad para deglutir líquidos.",
        'pronostico' => 'Bueno para la vida y función; resolución esperada en 5 a 7 días.'
    ],
    [
        'motivo' => 'Lumbalgia aguda de tipo mecánico tras esfuerzo físico',
        'cronicos' => 'Ninguno',
        'meds_actuales' => 'Ninguno',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'M54.5',
        'cie10_desc' => 'Lumbago no especificado',
        'padecimiento' => 'Inició hace 24 horas posterior a carga de objeto pesado en flexión lumbar. Presenta dolor en región lumbar baja de carácter sordo y opresivo, intensidad 7/10 en escala EVA, que empeora con la bipedestación y flexión del tronco y mejora con el reposo en decúbito lateral.',
        'aparatos_sistemas' => 'Músculo-esquelético: limitación funcional para arcos de movilidad lumbar. Neurológico: sin irradiación ciática, sin alteraciones de esfínteres.',
        'exploracion' => 'Marcha antiálgica con tronco discretamente lateralizado. A la palpación: contractura marcada de masa muscular paravertebral lumbar bilateral (L3-S1), dolor a la presión sin irradiación. Maniobra de Lasègue negativa bilateral. Fuerza muscular 5/5 en ambas extremidades pélvicas, reflejos osteotendinosos rotuliano y aquileo conservados simétricos.',
        'signos' => ['sistolica' => 125, 'diastolica' => 82, 'fc' => 78, 'fr' => 18, 'temperatura' => 36.4, 'spo2' => 99, 'glucosa' => 95, 'peso' => 79.0, 'talla' => 174],
        'resultados' => 'Sin banderas rojas de síndrome de cauda equina, neoplasia o infección raquídea. No requiere estudios de imagen en fase aguda.',
        'nota' => 'Lumbalgia aguda de origen mecánico miofascial sin compromiso radicular. Se indica esquema de analgesia combinada, relajante muscular y medidas de higiene postural.',
        'plan' => "1. Ketorolaco / Trometamina 10 mg + Metocarbamol 215 mg vía oral cada 8 horas por 5 días.\n2. Paracetamol 500 mg cada 8 horas de rescate si persiste dolor.\n3. Medios físicos: compresas húmedo-calientes en región lumbar 20 minutos cada 8 horas.\n4. Evitar reposo prolongado en cama; mantener deambulación según tolerancia.\n5. Cita de revaloración en 7 días si no hay mejoría.",
        'pronostico' => 'Favorable; remisión habitual en 1 a 2 semanas.'
    ],
    [
        'motivo' => 'Gastroenteritis aguda con diarrea líquida y cólico abdominal',
        'cronicos' => 'Ninguno',
        'meds_actuales' => 'Ninguno',
        'alergias' => 'Sulfamidas (rash cutáneo)',
        'cie10' => 'A09',
        'cie10_desc' => 'Diarrea y gastroenteritis de presunto origen infeccioso',
        'padecimiento' => 'Inicia hace 36 horas tras consumo de alimentos en vía pública con evacuaciones diarreicas líquidas abundantes en 6 ocasiones, sin moco ni sangre visible, acompañadas de dolor abdominal tipo cólico difuso, náuseas y febrícula no cuantificada.',
        'aparatos_sistemas' => 'Gastrointestinal: hiperperistaltismo, náuseas sin vómito activo. Urinario: orina concentrada por deshidratación leve.',
        'exploracion' => 'Mucosa oral discretamente seca, ojos normotensos sin hundimiento. Ruidos cardíacos rítmicos sin soplos. Abdomen plano, con ruidos hidroaéreos aumentados en frecuencia e intensidad, blando, doloroso a la palpación media difusa sin rebote ni datos de abdomen agudo quirúrgico. Sin masas palpables.',
        'signos' => ['sistolica' => 108, 'diastolica' => 68, 'fc' => 84, 'fr' => 18, 'temperatura' => 37.3, 'spo2' => 98, 'glucosa' => 90, 'peso' => 64.0, 'talla' => 162],
        'resultados' => 'Examen clínico con datos de deshidratación leve (Plan B de rehidratación oral en domicilio).',
        'nota' => 'Gastroenteritis infecciosa aguda con deshidratación leve. Pilar del manejo enfocado en restitución hidroelectrolítica oral.',
        'plan' => "1. Suero Vida Suero Oral: 1 sobre disuelto en 1 litro de agua hervida, tomar a libre demanda (mínimo 200 ml tras cada evacuación líquida).\n2. Butilhioscina 10 mg vía oral cada 8 horas en caso de dolor tipo cólico intenso por 3 días.\n3. Dieta blanda astringente: arroz, zanahoria cocida, manzana cocida, pollo sin grasa. Evitar lácteos, grasas e irritantes.\n4. Probióticos (Lactobacillus reuteri) 1 cápsula al día por 5 días.\n5. Datos de alarma: sangre en evacuaciones, vómito persistente, fiebre > 38.5°C.",
        'pronostico' => 'Excelente pronóstico; autolimitado en 48-72 horas con hidratación correcta.'
    ],
    [
        'motivo' => 'Dispepsia y epigastralgia ardorosa recurrente',
        'cronicos' => 'Gastritis crónica erosiva',
        'meds_actuales' => 'Antiácidos de venta libre ocasionales',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'K29.7',
        'cie10_desc' => 'Gastritis, no especificada',
        'padecimiento' => 'Paciente que refiere dolor epigástrico tipo urente (ardor) de 3 semanas de evolución, que se presenta predominantemente en ayuno prolongado y tras ingesta de café y picante, acompañado de pirosis retroesternal y saciedad temprana.',
        'aparatos_sistemas' => 'Digestivo: pirosis, eructos, sin melena ni hematemesis. Sin disfagia a sólidos ni líquidos.',
        'exploracion' => 'Buen estado general. Cuello sin adenopatías supraclaviculares (signo de Virchow negativo). Tórax normal. Abdomen blando, depresible, con dolor focal a la palpación profunda en epigastrio, sin defensa muscular ni descompresión positiva. Peristalsis normal.',
        'signos' => ['sistolica' => 118, 'diastolica' => 76, 'fc' => 70, 'fr' => 16, 'temperatura' => 36.5, 'spo2' => 99, 'glucosa' => 91, 'peso' => 70.0, 'talla' => 166],
        'resultados' => 'Sin signos de alarma (pérdida ponderal no intencionada, anemia, vómito recurrente).',
        'nota' => 'Síndrome dispéptico secundario a gastritis erosiva / enfermedad ácido péptica. Se inicia inhibidor de bomba de protones a dosis estándar.',
        'plan' => "1. Omeprazol 40 mg vía oral 30 minutos antes del desayuno por 4 semanas.\n2. Sucralfato suspensión 1 g vía oral 1 hora antes de alimentos cada 8 horas por 14 días.\n3. Medidas dietéticas: horarios regulares de comida, supresión de café, alcohol, tabaco, bebidas carbonatadas y picante.\n4. Evitar uso de AINEs sin gastroprotección.\n5. Cita de seguimiento en 4 semanas para evaluar respuesta.",
        'pronostico' => 'Favorable con adherencia a tratamiento y modificaciones dietéticas.'
    ],
    [
        'motivo' => 'Infección aguda de vías urinarias con disuria y polaquiuria',
        'cronicos' => 'Ninguno',
        'meds_actuales' => 'Ninguno',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'N39.0',
        'cie10_desc' => 'Infección del tracto urinario, sitio no especificado',
        'padecimiento' => 'Inicia hace 3 días con disuria de tipo ardor miccional intenso, polaquiuria cada 45 minutos, tenesmo vesical y orina con olor penetrante. Niega dolor lumbar, escalofríos o fiebre cuantificada.',
        'aparatos_sistemas' => 'Genitourinario: disuria, polaquiuria, urgencia miccional. Sin flujo vaginal ni prurito vulvar.',
        'exploracion' => 'Abdomen blando, depresible, dolor leve a la palpación en hipogastrio sobre sínfisis púbica. Puntos ureterales superiores y medios negativos. Puñopercusión lumbar de Giordano negativa bilateral. Sin datos de irritación peritoneal.',
        'signos' => ['sistolica' => 112, 'diastolica' => 72, 'fc' => 74, 'fr' => 17, 'temperatura' => 36.7, 'spo2' => 98, 'glucosa' => 94, 'peso' => 58.0, 'talla' => 158],
        'resultados' => 'EGO tira reactiva en consultorio: Nitritos positivos (+), Esterasa leucocitaria positiva (++), Leucocitos incontables por campo, Bacterias abundantes, Eritrocitos 2-4/campo.',
        'nota' => 'Cistitis aguda no complicada en paciente femenina. Se inicia antibióticoterapia dirigida de primera línea para patógenos urinarios comunes (E. coli).',
        'plan' => "1. Fosfomicina trometamol 3 g granulado vía oral, dosis única nocturna disuelta en agua con vejiga vacía.\n2. Fenazopiridina 100 mg vía oral cada 8 horas por 48 horas (se advierte coloración anaranjada de la orina).\n3. Ingesta hídrica abundante (mínimo 2.5 litros de agua al día).\n4. Medidas higiénicas miccionales postcoitales y técnica correcta de aseo.\n5. Acudir de inmediato si presenta fiebre > 38°C o dolor en fosa renal.",
        'pronostico' => 'Excelente; resolución completa esperada en 72 horas.'
    ],
    [
        'motivo' => 'Rinitis alérgica estacional y congestión nasal bilateral',
        'cronicos' => 'Rinitis alérgica perenne con exacerbación estacional',
        'meds_actuales' => 'Antihistamínico esporádico',
        'alergias' => 'Polvo, ácaros y polen de pastos',
        'cie10' => 'J30.2',
        'cie10_desc' => 'Otra rinitis alérgica estacional',
        'padecimiento' => 'Refiere cuadro de 2 semanas caracterizado por estornudos en salva (5 a 8 consecutivos), rinorrea hialina anterior abundante, prurito nasal, palatino y ocular bilateral, y obstrucción nasal que interfiere con el sueño nocturno.',
        'aparatos_sistemas' => 'Oftálmico: inyección conjuntival leve, lagrimeo. Respiratorio: respiración predominantemente oral durante la noche.',
        'exploracion' => 'Facies alérgica, ojeras tenues ("saludo alérgico" positivo). Rinoscopía anterior: mucosa nasal pálida y edematosa, cornetes inferiores hipertróficos con abundante secreción serosa hialina, sin pólipos visibles. Faringe con empedrado linfoide posterior leve.',
        'signos' => ['sistolica' => 116, 'diastolica' => 74, 'fc' => 68, 'fr' => 16, 'temperatura' => 36.4, 'spo2' => 99, 'glucosa' => 86, 'peso' => 62.0, 'talla' => 164],
        'resultados' => 'Prueba prick previa positiva a Dermatophagoides pteronyssinus y polen.',
        'nota' => 'Rinitis alérgica moderada a severa persistente según clasificación ARIA. Indicación de corticoesteroide intranasal más antihistamínico de segunda generación.',
        'plan' => "1. Furoato de Mometasona spray nasal 50 mcg: 2 disparos en cada fosa nasal cada 24 horas por las mañanas durante 6 semanas.\n2. Levocetirizina 5 mg vía oral cada 24 horas por las noches durante 20 días.\n3. Lavados nasales con solución salina estéril isotónica antes del spray.\n4. Medidas de control ambiental de alérgenos en habitación (fundas antiácaros, ventilación).\n5. Revaloración en 6 semanas.",
        'pronostico' => 'Control sintomático favorable con profilaxis y apego a sprays nasales.'
    ],
    [
        'motivo' => 'Cefalea tensional bilateral opresiva asociada a estrés',
        'cronicos' => 'Cefalea tensional episódica frecuente',
        'meds_actuales' => 'Paracetamol 500 mg ocasional',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'G44.2',
        'cie10_desc' => 'Cefalea debida a tensión',
        'padecimiento' => 'Paciente que acude por cefalea holocraneal de predominio occipito-cervical, de carácter opresivo ("en banda o casco"), intensidad 6/10, no pulsátil, sin náuseas, vómitos, fotofobia ni sonofobia, relacionada con sobrecarga laboral y posturas prolongadas frente a monitor.',
        'aparatos_sistemas' => 'Neurológico: sin déficit focal, sin alteraciones visuales, sin pérdida de memoria.',
        'exploracion' => 'Alerta, cooperador, lenguaje coherente y fluido. Pares craneales I al XII íntegros sin alteraciones. Campimetría por confrontación normal. Fondo de ojo con bordes de papila nítidos sin papiledema. Reflejos osteotendinosos 2/4 simétricos. Contractura dolorosa de músculos trapecios superiores y paravertebrales cervicales.',
        'signos' => ['sistolica' => 122, 'diastolica' => 78, 'fc' => 74, 'fr' => 16, 'temperatura' => 36.6, 'spo2' => 98, 'glucosa' => 93, 'peso' => 71.0, 'talla' => 171],
        'resultados' => 'Criterios diagnósticos de la IHS para cefalea tensional cumplidos. Ausencia total de banderas rojas neurológicas.',
        'nota' => 'Cefalea tensional de intensidad moderada sin signos de focalización ni datos de hipertensión endocraneana. Manejo sintomático y preventivo del estrés muscular.',
        'plan' => "1. Paracetamol 500 mg + Cafeína 65 mg vía oral cada 8 horas al inicio del dolor (máximo 3 días consecutivos).\n2. Medidas de higiene postural frente a pantalla (altura ergonómica de monitor, pausas activas cada 2 horas).\n3. Fomento de técnicas de relajación y actividad física aeróbica 3 veces por semana.\n4. Higiene del sueño (mínimo 7 horas nocturnas continuas).\n5. Seguimiento con diario de cefaleas.",
        'pronostico' => 'Bueno; respuesta favorable a corrección postural y manejo de estrés.'
    ],
    [
        'motivo' => 'Evaluación médica preventiva general y chequeo de salud (Check-up)',
        'cronicos' => 'Ninguno',
        'meds_actuales' => 'Ninguno',
        'alergias' => 'Ninguna conocida',
        'cie10' => 'Z00.0',
        'cie10_desc' => 'Examen médico general',
        'padecimiento' => 'Paciente asintomático que se presenta para evaluación integral anual de estado de salud. Desea conocer su riesgo cardiovascular y recomendaciones para optimizar estilo de vida.',
        'aparatos_sistemas' => 'Interrogatorio por aparatos y sistemas sin manifestaciones patológicas reportadas.',
        'exploracion' => 'Paciente con adecuado estado de nutrición e hidratación. Agudeza visual 20/20 bilateral corregida. Cavidad oral sin caries ni lesiones mucosas. Ruidos cardíacos rítmicos, normofonéticos. Campos pulmonares claros. Abdomen plano, sin dolor ni masas. Extremidades con tono, trofismo y fuerza muscular íntegros.',
        'signos' => ['sistolica' => 114, 'diastolica' => 72, 'fc' => 68, 'fr' => 16, 'temperatura' => 36.5, 'spo2' => 99, 'glucosa' => 87, 'peso' => 65.5, 'talla' => 169],
        'resultados' => 'Biometría hemática completa, química sanguínea de 6 elementos y examen general de orina dentro de límites normales.',
        'nota' => 'Paciente sano sin factores de riesgo cardiovascular identificados al momento. Riesgo a 10 años por escala de Framingham < 2% (bajo riesgo).',
        'plan' => "1. Mantener alimentación balanceada basada en el Plato del Bien Comer.\n2. Realizar actividad física 150 minutos semanales de intensidad moderada.\n3. Aplicación de vacuna anual contra Influenza estacional.\n4. Mantener esquema de vacunación completo para el adulto.\n5. Próximo chequeo preventivo general en 1 año.",
        'pronostico' => 'Excelente para la vida y la función.'
    ]
];

// 3. Preparar inserción de 100 pacientes
$stmtPaciente = $pdo->prepare(
    "INSERT INTO pacientes (
        institucion_id, folio, curp, nombre, primer_apellido, segundo_apellido,
        fecha_nacimiento, sexo, entidad_nacimiento, nacionalidad, telefono, correo,
        domicilio, municipio_estado, contacto_emergencia, telefono_emergencia,
        alergias, grupo_sanguineo, padecimientos_cronicos, medicamentos_actuales,
        motivo_consulta, confirmacion_datos, extras, creado_en
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, 'Mexicana', ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, 1, ?, ?
    )"
);

$stmtHistorial = $pdo->prepare(
    "INSERT INTO historial_clinico (
        institucion_id, paciente_id, tipo, fecha, titulo, detalle,
        autor_id, autor_nombre, autor_cedula, creado_en
    ) VALUES (
        ?, ?, 'consulta', ?, ?, ?,
        ?, ?, ?, ?
    )"
);

$stmtCita = $pdo->prepare(
    "INSERT INTO citas (
        institucion_id, paciente_id, profesional_id, inicio, fin,
        motivo, notas, estado, recordatorio_min, creado_por, creado_en
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, 'atendida', NULL, ?, ?
    )"
);

// Obtener folio base
$maxId = (int) $pdo->query("SELECT IFNULL(MAX(id), 0) FROM pacientes")->fetchColumn();
echo "Último ID existente en pacientes: {$maxId}\n\n";

$creados = 0;
$consultasCreadas = 0;

$pdo->beginTransaction();

try {
    for ($i = 1; $i <= 100; $i++) {
        $esHombre = ($i % 2 === 1);
        $nombre = $esHombre ? $nombresH[($i - 1) % count($nombresH)] : $nombresM[($i - 1) % count($nombresM)];
        $ap1 = $apellidos1[($i * 3 + 7) % count($apellidos1)];
        $ap2 = $apellidos2[($i * 5 + 13) % count($apellidos2)];
        $sexo = $esHombre ? 'H' : 'M';
        
        // Edad variada entre 18 y 76 años
        $edad = 18 + (($i * 7) % 58);
        $anioNac = date('Y') - $edad;
        $mesNac = str_pad((string) ((($i * 3) % 12) + 1), 2, '0', STR_PAD_LEFT);
        $diaNac = str_pad((string) ((($i * 5) % 28) + 1), 2, '0', STR_PAD_LEFT);
        $fechaNac = "{$anioNac}-{$mesNac}-{$diaNac}";
        
        $ent = $entidades[$i % count($entidades)];
        $curp = generarCurp($ap1, $ap2, $nombre, $fechaNac, $sexo, $ent['clave']);
        
        // Teléfono mexicano a 10 dígitos
        $lada = in_array($ent['clave'], ['DF', 'MC']) ? '55' : ($ent['clave'] === 'JC' ? '33' : ($ent['clave'] === 'NL' ? '81' : '442'));
        $telNum = str_pad((string) (20000000 + $i * 12347 % 79999999), 8, '0', STR_PAD_LEFT);
        $telefono = $lada . $telNum;
        $telEmergencia = $lada . str_pad((string) (30000000 + $i * 98765 % 69999999), 8, '0', STR_PAD_LEFT);
        
        // Correo
        $emailUser = strtolower(preg_replace('/[^a-z0-9]/', '', explode(' ', $nombre)[0]) . '.' . preg_replace('/[^a-z0-9]/', '', $ap1) . $i);
        $correo = "{$emailUser}@correo.test";
        
        // Domicilio
        $calle = $calles[$i % count($calles)];
        $colonia = $colonias[$i % count($colonias)];
        $domicilio = "{$calle}, {$colonia}";
        $municipioEstado = "{$ent['mun']}, {$ent['nombre']}";
        
        // Contacto de emergencia
        $parentezco = $esHombre ? 'Esposa' : 'Esposo';
        $nombreEmergencia = ($esHombre ? $nombresM[($i + 5) % count($nombresM)] : $nombresH[($i + 5) % count($nombresH)]) . " {$ap2} ({$parentezco})";
        
        // Arquetipo clínico
        $arq = $arquetipos[($i - 1) % count($arquetipos)];
        
        $grupoSang = $gruposSanguineos[$i % count($gruposSanguineos)];
        
        // Extras de perfil
        $estadosCiviles = ['Soltero(a)', 'Casado(a)', 'Unión libre', 'Divorciado(a)', 'Viudo(a)'];
        $ocupaciones = ['Empleado(a) de oficina', 'Comerciante', 'Docente', 'Ingeniero(a)', 'Contador(a)', 'Técnico(a)', 'Hogar', 'Jubilado(a)'];
        $escolaridades = ['Secundaria', 'Preparatoria / Bachillerato', 'Licenciatura', 'Posgrado'];
        $afiliaciones = ['IMSS', 'ISSSTE', 'Particular', 'INSABI / IMSS-Bienestar'];
        
        $extrasPerfil = [
            '_perfil' => [
                'estado_civil' => $estadosCiviles[$i % count($estadosCiviles)],
                'ocupacion' => $ocupaciones[$i % count($ocupaciones)],
                'escolaridad' => $escolaridades[$i % count($escolaridades)],
                'afiliacion_institucional' => $afiliaciones[$i % count($afiliaciones)],
            ]
        ];
        
        // Fecha de registro: distribuida en los últimos 90 días
        $diasAtras = 90 - (int) ($i * 0.85);
        $fechaRegistro = date('Y-m-d H:i:s', strtotime("-{$diasAtras} days 09:30:00"));
        
        // Folio correlativo
        $folioNum = $maxId + $i;
        $folio = 'EXP-' . str_pad((string) $folioNum, 6, '0', STR_PAD_LEFT);
        
        // Insertar Paciente
        $stmtPaciente->execute([
            $institucionId,
            $folio,
            $curp,
            $nombre,
            $ap1,
            $ap2,
            $fechaNac,
            $sexo,
            $ent['clave'],
            $telefono,
            $correo,
            $domicilio,
            $municipioEstado,
            $nombreEmergencia,
            $telEmergencia,
            $arq['alergias'],
            $grupoSang,
            $arq['cronicos'],
            $arq['meds_actuales'],
            $arq['motivo'],
            json_encode($extrasPerfil, JSON_UNESCAPED_UNICODE),
            $fechaRegistro
        ]);
        
        $pacienteId = (int) $pdo->lastInsertId();
        $creados++;
        
        // Ajustar signos vitales según peso/talla y edad del paciente
        $signos = $arq['signos'];
        if ($esHombre) {
            $signos['talla'] = 168 + (($i * 3) % 15); // 168 - 182 cm
            $signos['peso'] = round(65 + (($i * 7) % 25) + ($signos['talla'] - 170) * 0.5, 1);
        } else {
            $signos['talla'] = 154 + (($i * 3) % 14); // 154 - 167 cm
            $signos['peso'] = round(52 + (($i * 7) % 22) + ($signos['talla'] - 160) * 0.4, 1);
        }
        $tallaM = $signos['talla'] / 100;
        $signos['imc'] = round($signos['peso'] / ($tallaM * $tallaM), 1);
        
        // Armar el detalle JSON de la consulta médica (cumpliendo NOM-004)
        $detalleConsulta = [
            'modalidad_consulta' => ($i % 3 === 0 ? 'Seguimiento' : 'Consulta general'),
            'padecimiento' => $arq['padecimiento'],
            'aparatos_sistemas' => $arq['aparatos_sistemas'],
            'exploracion' => $arq['exploracion'],
            'signos' => $signos,
            'resultados' => $arq['resultados'],
            'diagnostico' => $arq['cie10_desc'],
            'diagnosticos' => [
                [
                    'diagnostico' => $arq['cie10_desc'],
                    'cie10' => $arq['cie10']
                ]
            ],
            'nota' => $arq['nota'],
            'plan' => $arq['plan'],
            'pronostico' => $arq['pronostico'],
            'cuerpo' => [
                ['t' => 'h2', 'x' => 'Padecimiento actual'],
                ['t' => 'p', 'x' => $arq['padecimiento']],
                ['t' => 'h2', 'x' => 'Exploración física'],
                ['t' => 'p', 'x' => $arq['exploracion']],
                ['t' => 'h2', 'x' => 'Diagnóstico'],
                ['t' => 'p', 'x' => $arq['cie10_desc'] . ' (CIE-10: ' . $arq['cie10'] . ')'],
                ['t' => 'h2', 'x' => 'Plan y tratamiento'],
                ['t' => 'p', 'x' => $arq['plan']],
                ['t' => 'h2', 'x' => 'Pronóstico'],
                ['t' => 'p', 'x' => $arq['pronostico']]
            ]
        ];
        
        // Fecha de consulta: el mismo día del registro a las 10:00 o 16:00
        $horaConsulta = ($i % 2 === 0 ? '10:30:00' : '16:00:00');
        $fechaConsulta = date('Y-m-d', strtotime("-{$diasAtras} days")) . ' ' . $horaConsulta;
        $fechaFinConsulta = date('Y-m-d H:i:s', strtotime("{$fechaConsulta} +30 minutes"));
        
        // Insertar en historial_clinico
        $stmtHistorial->execute([
            $institucionId,
            $pacienteId,
            $fechaConsulta,
            $arq['motivo'],
            json_encode($detalleConsulta, JSON_UNESCAPED_UNICODE),
            $autor['id'],
            $autor['nombre'],
            $autor['cedula'],
            $fechaConsulta
        ]);
        $consultasCreadas++;
        
        // Insertar en citas (atendida)
        $stmtCita->execute([
            $institucionId,
            $pacienteId,
            $autor['id'],
            $fechaConsulta,
            $fechaFinConsulta,
            $arq['motivo'],
            'Consulta concluida satisfactoriamente con nota de evolución y tratamiento emitido.',
            $autor['id'],
            $fechaConsulta
        ]);
        
        echo "[$i/100] Creado: {$folio} - {$nombre} {$ap1} {$ap2} ({$edad} años) | Consulta: {$arq['cie10_desc']}\n";
    }
    
    $pdo->commit();
    echo "\n=== PROCESO COMPLETADO EXITOSAMENTE ===\n";
    echo "Total pacientes creados: {$creados}\n";
    echo "Total notas de consulta clínica creadas: {$consultasCreadas}\n";
    echo "Total citas médicas registradas como atendidas: {$consultasCreadas}\n";
    
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nERROR FATAL DURANTE LA EJECUCIÓN:\n" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
