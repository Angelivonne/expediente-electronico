<?php
/**
 * Catálogos de los registros del historial clínico (NOM-004-SSA3-2012).
 * Los usan pacientes/nuevo_registro.php (formularios), pacientes/historial.php (validación y guardado),
 * app/vistas/componentes/historial_detalle.php (detalle de cualquier entrada) y pacientes/evento.php (documento).
 */

/** Tipos de registro: clave del ENUM historial_clinico.tipo => textos, icono y numeral de la norma. */
const TIPOS_REGISTRO = [
    'consulta' => [
        'corto' => 'Consulta', 'plural' => 'Consultas', 'documento' => 'Nota de consulta', 'icono' => 'documento', 'relleno' => 'documento',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.2',
        'descripcion' => 'Evolución, exploración física, signos vitales, diagnósticos, tratamiento y pronóstico.',
    ],
    'urgencias' => [
        'corto' => 'Urgencias', 'plural' => 'Urgencias', 'documento' => 'Nota de urgencias', 'icono' => 'alerta', 'relleno' => 'reloj',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 7.1',
        'descripcion' => 'Atención inicial: interrogatorio, exploración y estado mental, diagnósticos, tratamiento y destino.',
    ],
    'interconsulta' => [
        'corto' => 'Interconsulta', 'plural' => 'Interconsultas', 'documento' => 'Nota de interconsulta', 'icono' => 'usuarios', 'relleno' => 'usuarios',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.3',
        'descripcion' => 'Servicio que solicita y servicio consultado, criterios diagnósticos, plan de estudios y sugerencias.',
    ],
    'referencia' => [
        'corto' => 'Referencia', 'plural' => 'Referencias', 'documento' => 'Nota de referencia / traslado', 'icono' => 'flecha_derecha', 'relleno' => 'casa',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 6.4',
        'descripcion' => 'Establecimiento que envía y que recibe, motivo de envío, resumen clínico y terapéutica empleada.',
    ],
    'egreso' => [
        'corto' => 'Egreso', 'plural' => 'Egresos', 'documento' => 'Nota de egreso', 'icono' => 'salir', 'relleno' => 'lista',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 8.9',
        'descripcion' => 'Motivo del egreso, diagnósticos finales, resumen de la evolución, pendientes y recomendaciones.',
    ],
    'receta' => [
        'corto' => 'Receta', 'plural' => 'Recetas', 'documento' => 'Receta médica', 'icono' => 'pildora', 'relleno' => 'editar',
        'grupo' => 'Prescripción y estudios', 'norma' => 'NOM-004 6.2.6',
        'descripcion' => 'Medicamentos con presentación, dosis, vía, frecuencia y duración; datos de quien prescribe.',
    ],
    'estudio' => [
        'corto' => 'Estudio', 'plural' => 'Estudios', 'documento' => 'Resultado de estudio', 'icono' => 'barras', 'relleno' => 'buscar',
        'grupo' => 'Prescripción y estudios', 'norma' => 'NOM-004 6.2.4',
        'descripcion' => 'Laboratorio o gabinete: resultado, interpretación y valores fuera de rango.',
    ],
    'hospitalizacion' => [
        'corto' => 'Hospitalización', 'plural' => 'Hospitalizaciones', 'documento' => 'Nota de hospitalización', 'icono' => 'clinico', 'relleno' => 'carpeta',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 8',
        'descripcion' => 'Ingreso, servicio y cama, diagnósticos de ingreso y egreso, evolución y estado al egreso.',
    ],
    'consentimiento' => [
        'corto' => 'Consentimiento', 'plural' => 'Consentimientos', 'documento' => 'Carta de consentimiento informado', 'icono' => 'escudo_ok', 'relleno' => 'escudo',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 10.1',
        'descripcion' => 'Procedimiento, riesgos, beneficios y alternativas; quién otorga el consentimiento y testigos.',
    ],
    'incapacidad' => [
        'corto' => 'Incapacidad', 'plural' => 'Incapacidades', 'documento' => 'Incapacidad temporal', 'icono' => 'reloj', 'relleno' => 'calendario',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'LGS 79 · RIS 29',
        'descripcion' => 'Incapacidad temporal por enfermedad o accidente: periodo, días y diagnóstico con CIE-10. Requiere facultad del prescriptor.',
    ],
    'preoperatoria' => [
        'corto' => 'Preoperatoria', 'plural' => 'Preoperatorias', 'documento' => 'Nota preoperatoria', 'icono' => 'documento', 'relleno' => 'documento',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 8.5',
        'descripcion' => 'Fecha de cirugía, diagnóstico, plan quirúrgico, tipo de intervención, riesgo y cuidados preoperatorios.',
    ],
    'transoperatoria' => [
        'corto' => 'Transoperatoria', 'plural' => 'Transoperatorias', 'documento' => 'Nota transoperatoria', 'icono' => 'clinico', 'relleno' => 'carpeta',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 8.8',
        'descripcion' => 'Operación planeada y realizada, diagnósticos, técnica, hallazgos, sangrado, complicaciones y estado final.',
    ],
    'postoperatoria' => [
        'corto' => 'Postoperatoria', 'plural' => 'Postoperatorias', 'documento' => 'Nota postoperatoria', 'icono' => 'clinico', 'relleno' => 'lista',
        'grupo' => 'Hospitalización y documentos', 'norma' => 'NOM-004 8.8',
        'descripcion' => 'Evolución postoperatoria inmediata, plan, complicaciones y pronóstico después de la cirugía.',
    ],
    'enfermeria' => [
        'corto' => 'Enfermería', 'plural' => 'Enfermería', 'documento' => 'Nota de enfermería', 'icono' => 'corazon', 'relleno' => 'corazon',
        'grupo' => 'Notas de enfermería', 'norma' => 'NOM-004 9.1',
        'descripcion' => 'Valoración, cuidados, signos vitales, medicamentos y observaciones de enfermería.',
    ],
    'resumen_clinico' => [
        'corto' => 'Resumen clínico', 'plural' => 'Resúmenes clínicos', 'documento' => 'Resumen clínico', 'icono' => 'documento', 'relleno' => 'documento',
        'grupo' => 'Notas médicas', 'norma' => 'NOM-004 4.10',
        'descripcion' => 'Padecimiento actual, diagnósticos, tratamientos, evolución, pronóstico y estudios.',
    ],
];

/** Vías de administración aceptadas en la receta. */
const VIAS_ADMINISTRACION = ['Oral', 'Sublingual', 'Intravenosa', 'Intramuscular', 'Subcutánea', 'Tópica',
                             'Inhalada', 'Oftálmica', 'Ótica', 'Nasal', 'Rectal', 'Vaginal'];

/** Vías activas de la institución; la lista fija se conserva si aún no configuraron el catálogo. */
function viasAdministracionDisponibles(): array
{
    if (!function_exists('db') || !function_exists('institucionId')) {
        return VIAS_ADMINISTRACION;
    }

    static $cache = [];
    $institucion = (int) institucionId();
    if (array_key_exists($institucion, $cache)) {
        return $cache[$institucion];
    }

    try {
        $consulta = db()->prepare('SELECT nombre FROM catalogo_vias_admin WHERE institucion_id = ? AND activo = 1 ORDER BY nombre');
        $consulta->execute([$institucion]);
        $vias = array_values(array_unique(array_filter(array_map(
            static fn ($via) => trim((string) $via),
            $consulta->fetchAll(PDO::FETCH_COLUMN)
        ), static fn ($via) => $via !== '')));
    } catch (Throwable) {
        $vias = [];
    }

    return $cache[$institucion] = $vias ?: VIAS_ADMINISTRACION;
}

/** Signos vitales: clave => [etiqueta, abreviatura, unidad, mínimo, máximo] (rangos fisiológicamente posibles). */
const SIGNOS_VITALES = [
    'sistolica'   => ['TA sistólica', 'TAS', 'mmHg', 50, 260],
    'diastolica'  => ['TA diastólica', 'TAD', 'mmHg', 30, 160],
    'fc'          => ['Frecuencia cardiaca', 'FC', 'lpm', 20, 250],
    'fr'          => ['Frecuencia respiratoria', 'FR', 'rpm', 5, 80],
    'temperatura' => ['Temperatura', 'T', '°C', 30, 45],
    'spo2'        => ['Saturación de oxígeno', 'SpO₂', '%', 50, 100],
    'glucosa'     => ['Glucosa capilar', 'Glucosa', 'mg/dL', 20, 800],
    'peso'        => ['Peso', 'Peso', 'kg', 0.5, 400],
    'talla'       => ['Talla', 'Talla', 'cm', 30, 250],
];

/** Modalidad de la atención ambulatoria, para diferenciar seguimiento y atención preventiva sin inventar un tipo de nota. */
const MODALIDADES_CONSULTA = [
    'general' => 'Consulta general',
    'especialidad' => 'Consulta de especialidad',
    'domiciliaria' => 'Atención domiciliaria',
    'seguimiento' => 'Seguimiento',
    'preventiva' => 'Atención preventiva',
];

const CATEGORIAS_ESTUDIO   = ['Laboratorio', 'Gabinete', 'Otro'];
const DESTINOS_URGENCIAS   = ['Alta a domicilio', 'Observación', 'Hospitalización', 'Quirófano', 'Referencia a otra unidad'];
const TIPOS_ENVIO          = ['Referencia', 'Traslado', 'Contrarreferencia'];
const MOTIVOS_EGRESO       = ['Curación', 'Mejoría', 'Máximo beneficio', 'Alta voluntaria', 'Traslado a otra unidad', 'Defunción'];
const ESTADOS_EGRESO       = ['Curación', 'Mejoría', 'Sin cambios', 'Alta voluntaria', 'Traslado a otra unidad', 'Defunción'];
const OTORGANTES_CONSENTIMIENTO = ['Paciente', 'Familiar', 'Tutor', 'Representante legal'];

/** Nombre del tipo para mostrar (si llega uno desconocido, se muestra tal cual). */
function nombreTipoRegistro(string $tipo, string $forma = 'corto'): string
{
    return TIPOS_REGISTRO[$tipo][$forma] ?? ucfirst($tipo);
}

/** Índice de masa corporal a partir de peso (kg) y talla (cm), o null si falta alguno. */
function imcDesdeSignos(array $signos): ?float
{
    $peso = (float) ($signos['peso'] ?? 0);
    $talla = (float) ($signos['talla'] ?? 0) / 100;
    return $peso > 0 && $talla > 0 ? round($peso / ($talla * $talla), 1) : null;
}
