<?php
/**
 * Catálogos del sistema: una sola definición para el menú de Catálogos, las páginas del espacio
 * (expedientes electrónicos) y los visores.
 *
 * El diseño de las tablas NO se inventa aquí: son las tablas del proyecto
 * (`database/migracion_catalogos_admin.sql` y `database/esquema.sql`), con sus columnas
 * (clave o código, nombre, descripción, activo…) y sus llaves a otros catálogos
 * (entidades, municipios, localidades, unidades). Este archivo solo describe cómo mostrarlas.
 *
 * Cada catálogo tiene:
 *   titulo       Nombre con el que vive su página en el espacio de trabajo.
 *   descripcion  Qué contiene y para qué lo usa el expediente.
 *   tabla        Tabla real que guarda los datos.
 *   institucion  true si la tabla se filtra por institución (las de la clínica);
 *                false en los catálogos nacionales compartidos (entidades, municipios…).
 *   desde        FROM con su alias `c` (incluye las uniones para mostrar nombres legibles).
 *   columnas     [expresión, alias, título, ancho, tipo] para el visor.
 *   busqueda     Expresiones por las que busca el visor.
 *   orden        Expresión de orden.
 *   editor       Pantalla que edita la tabla (si existe una dedicada).
 *   campos       Campos capturables (los usa el editor de catálogos), si aplica.
 *
 * Regla: un catálogo, una fuente de verdad (su tabla). La página del espacio solo la muestra.
 */

/** Definición de todos los catálogos, armada con el diseño real de cada tabla. */
function catalogosSistema(): array
{
    static $catalogo = null;
    if ($catalogo !== null) {
        return $catalogo;
    }

    /**
     * Catálogo con el diseño estándar del proyecto: clave (o código), nombre, descripción y activo.
     * $extra añade columnas propias de esa tabla (símbolo, familia, nivel, tipo, dirección…).
     */
    $estandar = function (string $titulo, string $tabla, bool $institucion, array $extra = []): array {
        $colClave = $extra['columna_clave'] ?? 'clave';
        $columnas = [
            ['c.' . $colClave, $colClave, $colClave === 'codigo' ? 'Código' : 'Clave', 110, 'texto'],
            ['c.nombre', 'nombre', 'Nombre', 300, 'texto'],
        ];
        $busqueda = ['c.' . $colClave, 'c.nombre'];
        if (($extra['descripcion'] ?? true)) {
            $columnas[] = ['c.descripcion', 'descripcion', 'Descripción', 0, 'texto'];
            $busqueda[] = 'c.descripcion';
        }
        foreach ($extra['columnas'] ?? [] as $col) {
            $columnas[] = $col;
            if (!empty($col[5])) {
                $busqueda[] = $col[0];
            }
        }
        $columnas[] = ['c.activo', 'activo', 'Activo', 80, 'casilla'];

        return [
            'titulo'      => $titulo,
            'tabla'       => $tabla,
            'institucion' => $institucion,
            'desde'       => $extra['desde'] ?? "`$tabla` c",
            'columnas'    => $columnas,
            'busqueda'    => $busqueda,
            'orden'       => $extra['orden'] ?? 'c.nombre, c.' . $colClave,
        ];
    };

    $catalogo = [
        // ---- Catálogos con editor propio (los usa el formulario clínico) ----
        'cie10' => [
            'titulo'      => 'Diagnósticos CIE-10',
            'descripcion' => 'Diagnósticos con clave oficial. Es el catálogo que sugiere el formulario de consulta al escribir un diagnóstico.',
            'tabla'       => 'cie10',
            'institucion' => true,
            'desde'       => 'cie10 c',
            'columnas'    => [
                ['c.codigo', 'codigo', 'Código', 110, 'texto'],
                ['c.descripcion', 'descripcion', 'Descripción', 520, 'texto'],
                ['c.capitulo', 'capitulo', 'Capítulo', 260, 'texto'],
                ['c.activo', 'activo', 'Activo', 90, 'casilla'],
            ],
            'busqueda'    => ['c.codigo', 'c.descripcion', 'c.capitulo'],
            'orden'       => 'c.codigo',
            'editor'      => '/cie10/',
        ],
        'medicamentos' => [
            'titulo'      => 'Medicamentos',
            'descripcion' => 'Medicamentos con clave, presentación y vía. Es el catálogo que sugiere el formulario de receta.',
            'tabla'       => 'medicamentos',
            'institucion' => true,
            'desde'       => 'medicamentos c',
            'columnas'    => [
                ['c.clave', 'clave', 'Clave', 100, 'texto'],
                ['c.nombre', 'nombre', 'Nombre', 340, 'texto'],
                ['c.presentacion', 'presentacion', 'Presentación', 220, 'texto'],
                ['c.concentracion', 'concentracion', 'Concentración', 130, 'texto'],
                ['c.via', 'via', 'Vía', 110, 'texto'],
                ['c.activo', 'activo', 'Activo', 80, 'casilla'],
            ],
            'busqueda'    => ['c.clave', 'c.nombre', 'c.presentacion', 'c.concentracion', 'c.via', 'c.laboratorio'],
            'orden'       => 'c.nombre',
            'editor'      => '/medicamentos/',
        ],

        // ---- Catálogos clínicos y farmacéuticos de la clínica (con institucion_id) ----
        'catalogo_procedimientos' => $estandar('Procedimientos médicos', 'catalogo_procedimientos', true),
        'catalogo_especialidades' => $estandar('Especialidades médicas', 'catalogo_especialidades', true),
        'catalogo_tipos_consulta' => $estandar('Tipos de consulta', 'catalogo_tipos_consulta', true, ['descripcion' => false]),
        'catalogo_tipos_atencion' => $estandar('Tipos de atención', 'catalogo_tipos_atencion', true, ['descripcion' => false]),
        'catalogo_principios_activos' => $estandar('Principios activos', 'catalogo_principios_activos', true),
        'catalogo_formas_farmaceuticas' => $estandar('Formas farmacéuticas', 'catalogo_formas_farmaceuticas', true),
        'catalogo_vias_admin' => $estandar('Vías de administración', 'catalogo_vias_admin', true, ['descripcion' => false]),
        'catalogo_categorias_farmaco' => $estandar('Categorías farmacológicas', 'catalogo_categorias_farmaco', true),
        'catalogo_unidades_medida' => $estandar('Unidades de medida', 'catalogo_unidades_medida', true, [
            'descripcion' => false,
            'columnas'    => [['c.simbolo', 'simbolo', 'Símbolo', 100, 'texto']],
        ]),
        // Concentraciones: el diseño no tiene "nombre"; su dato principal es el valor y su unidad
        'catalogo_concentraciones' => [
            'titulo'      => 'Concentraciones',
            'tabla'       => 'catalogo_concentraciones',
            'institucion' => true,
            'desde'       => 'catalogo_concentraciones c LEFT JOIN catalogo_unidades_medida u ON u.id = c.unidad_id',
            'columnas'    => [
                ['c.valor', 'valor', 'Valor', 160, 'texto'],
                ['IFNULL(u.nombre, \'\')', 'unidad', 'Unidad', 200, 'texto'],
                ['IFNULL(u.simbolo, \'\')', 'simbolo', 'Símbolo', 110, 'texto'],
                ['c.activo', 'activo', 'Activo', 80, 'casilla'],
            ],
            'busqueda'    => ['c.valor', 'u.nombre', 'u.simbolo'],
            'orden'       => 'c.valor',
        ],

        // ---- Catálogos nacionales (compartidos por todas las instituciones) ----
        'catalogo_entidades' => $estandar('Entidades federativas', 'catalogo_entidades', false, ['descripcion' => false]),
        'catalogo_municipios' => $estandar('Municipios', 'catalogo_municipios', false, [
            'descripcion' => false,
            'desde'       => 'catalogo_municipios c LEFT JOIN catalogo_entidades e ON e.id = c.entidad_id',
            'columnas'    => [['IFNULL(e.nombre, \'\')', 'entidad', 'Entidad', 200, 'texto']],
            'orden'       => 'c.nombre',
        ]),
        'catalogo_localidades' => $estandar('Localidades', 'catalogo_localidades', false, [
            'descripcion' => false,
            'desde'       => 'catalogo_localidades c LEFT JOIN catalogo_municipios m ON m.id = c.municipio_id',
            'columnas'    => [['IFNULL(m.nombre, \'\')', 'municipio', 'Municipio', 200, 'texto']],
            'orden'       => 'c.nombre',
        ]),
        // Códigos postales: el diseño solo tiene código, localidad y activo (sin nombre)
        'catalogo_codigos_postales' => [
            'titulo'      => 'Códigos postales',
            'tabla'       => 'catalogo_codigos_postales',
            'institucion' => false,
            'desde'       => 'catalogo_codigos_postales c LEFT JOIN catalogo_localidades l ON l.id = c.localidad_id',
            'columnas'    => [
                ['c.codigo', 'codigo', 'Código', 120, 'texto'],
                ['IFNULL(l.nombre, \'\')', 'localidad', 'Localidad', 0, 'texto'],
                ['c.activo', 'activo', 'Activo', 80, 'casilla'],
            ],
            'busqueda'    => ['c.codigo', 'l.nombre'],
            'orden'       => 'c.codigo',
        ],
        'catalogo_nacionalidades' => $estandar('Nacionalidades', 'catalogo_nacionalidades', false, ['descripcion' => false]),
        'catalogo_lenguas' => $estandar('Lenguas indígenas', 'catalogo_lenguas', false, [
            'descripcion' => false,
            'columnas'    => [['c.familia', 'familia', 'Familia', 200, 'texto']],
        ]),
        'catalogo_religiones' => $estandar('Religiones', 'catalogo_religiones', false, ['descripcion' => false]),
        'catalogo_establecimientos' => $estandar('Establecimientos de salud', 'catalogo_establecimientos', false, [
            'descripcion' => false,
            'columnas'    => [
                ['c.tipo', 'tipo', 'Tipo', 140, 'texto'],
                ['c.direccion', 'direccion', 'Dirección', 0, 'texto'],
            ],
        ]),
        'catalogo_clues' => $estandar('CLUES', 'catalogo_clues', false, [
            'descripcion' => false,
            'desde'       => 'catalogo_clues c
                              LEFT JOIN catalogo_entidades e ON e.id = c.entidad_id
                              LEFT JOIN catalogo_municipios m ON m.id = c.municipio_id',
            'columnas'    => [
                ['IFNULL(e.nombre, \'\')', 'entidad', 'Entidad', 180, 'texto'],
                ['IFNULL(m.nombre, \'\')', 'municipio', 'Municipio', 180, 'texto'],
            ],
            'orden'       => 'c.nombre',
        ]),
        'catalogo_tipos_personal' => $estandar('Tipos de personal', 'catalogo_tipos_personal', false),
        'catalogo_formacion_academica' => $estandar('Formación académica', 'catalogo_formacion_academica', false, [
            'descripcion' => false,
            'columnas'    => [['c.nivel', 'nivel', 'Nivel', 160, 'texto']],
        ]),
        'catalogo_material_curacion' => $estandar('Material de curación', 'catalogo_material_curacion', false),
        'catalogo_instrumental' => $estandar('Instrumental y equipo médico', 'catalogo_instrumental', false),
        'catalogo_cif' => $estandar('Clasificación CIF', 'catalogo_cif', false, ['columna_clave' => 'codigo']),
        'catalogo_auxiliares_diagnostico' => $estandar('Auxiliares de diagnóstico', 'catalogo_auxiliares_diagnostico', false),
        'catalogo_osteosintesis' => $estandar('Osteosíntesis y endoprótesis', 'catalogo_osteosintesis', false),
        'catalogo_nutriologia' => $estandar('Nutriología', 'catalogo_nutriologia', false),
        'catalogo_insumos_salud_publica' => $estandar('Insumos para salud pública', 'catalogo_insumos_salud_publica', false),
        'catalogo_acupuntura' => $estandar('Acupuntura', 'catalogo_acupuntura', false),
        'catalogo_herbolarios' => $estandar('Medicamentos herbolarios', 'catalogo_herbolarios', false),
        'catalogo_homeopaticos' => $estandar('Medicamentos homeopáticos', 'catalogo_homeopaticos', false),
        'catalogo_snomed' => $estandar('SNOMED CT', 'catalogo_snomed', false, ['columna_clave' => 'codigo']),
        'catalogo_loinc' => $estandar('LOINC', 'catalogo_loinc', false, ['columna_clave' => 'codigo']),
        'catalogo_cie11' => $estandar('CIE-11', 'catalogo_cie11', false, ['columna_clave' => 'codigo']),
    ];


    /* Agrupación por módulos normativos: A–F. Cada catálogo pertenece a una sola sección. */
    $secciones = [
        'A' => ['titulo' => 'A. Identificación y demografía', 'descripcion' => 'Datos de identificación del paciente y del establecimiento.', 'catalogos' => [
            'catalogo_entidades', 'catalogo_municipios', 'catalogo_localidades', 'catalogo_codigos_postales',
            'catalogo_nacionalidades', 'catalogo_lenguas', 'catalogo_religiones',
        ]],
        'B' => ['titulo' => 'B. Terminologías clínicas', 'descripcion' => 'Diagnósticos, procedimientos y clasificaciones de atención.', 'catalogos' => [
            'cie10', 'catalogo_cie11', 'catalogo_procedimientos', 'catalogo_cif', 'catalogo_snomed',
            'catalogo_loinc', 'catalogo_especialidades', 'catalogo_tipos_consulta', 'catalogo_tipos_atencion',
        ]],
        'C' => ['titulo' => 'C. Farmacia y terapéutica', 'descripcion' => 'Medicamentos y todo lo necesario para prescribir y surtir.', 'catalogos' => [
            'medicamentos', 'catalogo_principios_activos', 'catalogo_formas_farmaceuticas',
            'catalogo_concentraciones', 'catalogo_vias_admin', 'catalogo_unidades_medida',
            'catalogo_categorias_farmaco', 'catalogo_auxiliares_diagnostico',
        ]],
        'D' => ['titulo' => 'D. Establecimientos y personal', 'descripcion' => 'Unidades de salud y personal que firma o atiende.', 'catalogos' => [
            'catalogo_clues', 'catalogo_establecimientos', 'catalogo_tipos_personal', 'catalogo_formacion_academica',
        ]],
        'E' => ['titulo' => 'E. Insumos y dispositivos', 'descripcion' => 'Insumos, material de curación, instrumental y dispositivos.', 'catalogos' => [
            'catalogo_insumos_salud_publica', 'catalogo_material_curacion', 'catalogo_instrumental', 'catalogo_osteosintesis',
        ]],
        'F' => ['titulo' => 'F. Medicinas complementarias', 'descripcion' => 'Terapias y medicamentos complementarios.', 'catalogos' => [
            'catalogo_homeopaticos', 'catalogo_herbolarios', 'catalogo_acupuntura', 'catalogo_nutriologia',
        ]],
    ];
    /* Descripción breve de cada catálogo (la que se muestra en la sección). */
    $descripciones = [
        'catalogo_procedimientos' => 'Procedimientos médicos y quirúrgicos con su clave oficial.',
        'catalogo_especialidades' => 'Especialidades médicas reconocidas para la referencia y la interconsulta.',
        'catalogo_tipos_consulta' => 'Tipo de consulta: primera vez, seguimiento, urgencia u otra.',
        'catalogo_tipos_atencion' => 'Tipo de atención: ambulatoria, hospitalaria u otra.',
        'catalogo_principios_activos' => 'Sustancia activa del medicamento.',
        'catalogo_formas_farmaceuticas' => 'Presentación del medicamento: tableta, jarabe, solución, etc.',
        'catalogo_vias_admin' => 'Vía por la que se administra el medicamento.',
        'catalogo_categorias_farmaco' => 'Grupo terapéutico al que pertenece el medicamento.',
        'catalogo_unidades_medida' => 'Unidades para dosificar y surtir.',
        'catalogo_concentraciones' => 'Concentración del principio activo.',
        'catalogo_entidades' => 'Entidades federativas de México (clave INEGI).',
        'catalogo_municipios' => 'Municipios por entidad federativa.',
        'catalogo_localidades' => 'Localidades por municipio.',
        'catalogo_codigos_postales' => 'Códigos postales por localidad.',
        'catalogo_nacionalidades' => 'Nacionalidades para la identificación del paciente.',
        'catalogo_lenguas' => 'Lenguas indígenas para el registro de la lengua materna.',
        'catalogo_religiones' => 'Religión declarada por el paciente.',
        'catalogo_establecimientos' => 'Establecimientos de salud de la institución.',
        'catalogo_clues' => 'Clave única de establecimientos de salud (CLUES).',
        'catalogo_tipos_personal' => 'Tipo de personal que atiende o firma.',
        'catalogo_formacion_academica' => 'Formación académica del personal.',
        'catalogo_material_curacion' => 'Material de curación usado en la atención.',
        'catalogo_instrumental' => 'Instrumental y equipo médico.',
        'catalogo_cif' => 'Clasificación Internacional del Funcionamiento (CIF).',
        'catalogo_auxiliares_diagnostico' => 'Estudios auxiliares de diagnóstico.',
        'catalogo_osteosintesis' => 'Material de osteosíntesis y endoprótesis.',
        'catalogo_nutriologia' => 'Insumos y conceptos de nutriología.',
        'catalogo_insumos_salud_publica' => 'Insumos para salud pública.',
        'catalogo_acupuntura' => 'Puntos y conceptos de acupuntura.',
        'catalogo_herbolarios' => 'Medicamentos herbolarios.',
        'catalogo_homeopaticos' => 'Medicamentos homeopáticos.',
        'catalogo_snomed' => 'Terminología clínica SNOMED CT.',
        'catalogo_loinc' => 'Observaciones de laboratorio LOINC.',
        'catalogo_cie11' => 'Clasificación Internacional de Enfermedades, 11.ª revisión.',
    ];
    foreach ($descripciones as $llave => $texto) {
        if (isset($catalogo[$llave]) && empty($catalogo[$llave]['descripcion'])) {
            $catalogo[$llave]['descripcion'] = $texto;
        }
    }

    /* Fuente, versión y norma de cada catálogo (metadatos de referencia). */
    $fuentes = [
        'cie10' => ['SSA · CIE-10', 'CIE-10', 'NOM-004-SSA3-2012'],
        'medicamentos' => ['CSG · Cuadro Básico de Medicamentos', 'CBCM', 'NOM-004-SSA3-2012'],
        'catalogo_entidades' => ['INEGI', 'INEGI', 'NOM-024-SSA3-2012'],
        'catalogo_municipios' => ['INEGI', 'INEGI', 'NOM-024-SSA3-2012'],
        'catalogo_localidades' => ['INEGI', 'INEGI', 'NOM-024-SSA3-2012'],
        'catalogo_codigos_postales' => ['SEPOMEX', 'SEPOMEX', 'NOM-024-SSA3-2012'],
        'catalogo_clues' => ['SSA · CLUES', 'CLUES', 'NOM-024-SSA3-2012'],
        'catalogo_cie11' => ['OMS · CIE-11', 'CIE-11', 'NOM-024-SSA3-2012'],
        'catalogo_snomed' => ['SNOMED International', 'SNOMED CT', 'NOM-024-SSA3-2012'],
        'catalogo_loinc' => ['Regenstrief Institute', 'LOINC', 'NOM-024-SSA3-2012'],
        'catalogo_cif' => ['OMS · CIF', 'CIF', 'NOM-004-SSA3-2012'],
        'catalogo_procedimientos' => ['SSA · CIE-9-MC', 'CIE-9-MC', 'NOM-004-SSA3-2012'],
        'catalogo_especialidades' => ['CONACEM', 'CONACEM', 'NOM-004-SSA3-2012'],
        'catalogo_lenguas' => ['INALI', 'INALI', 'NOM-024-SSA3-2012'],
        'catalogo_religiones' => ['INEGI', 'INEGI', 'NOM-024-SSA3-2012'],
        'catalogo_nacionalidades' => ['INEGI', 'INEGI', 'NOM-024-SSA3-2012'],
        'catalogo_vias_admin' => ['CSG · Cuadro Básico', 'CBCM', 'NOM-004-SSA3-2012'],
        'catalogo_unidades_medida' => ['CSG · Cuadro Básico', 'CBCM', 'NOM-004-SSA3-2012'],
        'catalogo_formas_farmaceuticas' => ['CSG · Cuadro Básico', 'CBCM', 'NOM-004-SSA3-2012'],
        'catalogo_principios_activos' => ['CSG · Cuadro Básico', 'CBCM', 'NOM-004-SSA3-2012'],
        'catalogo_concentraciones' => ['CSG · Cuadro Básico', 'CBCM', 'NOM-004-SSA3-2012'],
    ];
    foreach ($catalogo as $llave => &$definicion) {
        $meta = $fuentes[$llave] ?? null;
        if ($meta === null) {
            $meta = !empty($definicion['institucion'])
                ? ['Institución', '—', 'NOM-004-SSA3-2012']
                : ['Catálogo nacional', '—', 'NOM-024-SSA3-2012'];
        }
        $definicion['fuente']  = $meta[0];
        $definicion['version'] = $meta[1];
        $definicion['norma']   = $meta[2];
    }
    unset($definicion);

    foreach ($secciones as $clave => $seccion) {
        foreach ($seccion['catalogos'] as $llave) {
            if (isset($catalogo[$llave])) {
                $catalogo[$llave]['seccion'] = $clave;
            }
        }
    }

    return $catalogo;
}

/** Catálogo por su clave del menú (o null si no existe). */
function catalogoSistema(string $clave): ?array
{
    $catalogo = catalogosSistema();
    return isset($catalogo[$clave]) ? $catalogo[$clave] + ['clave' => $clave] : null;
}

/** Título sin acentos, sin paréntesis, sin signos y en minúsculas: para comparar nombres de página. */
function normalizarTituloCatalogo(string $titulo): string
{
    $t = mb_strtolower(trim($titulo), 'UTF-8');
    $t = strtr($t, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ]);
    $t = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $t);          // "(CNIS 2025)", "(OMS)"…
    $t = preg_replace('/[^a-z0-9]+/u', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** Catálogo cuyo título (o alias) coincide con el nombre de una página. */
function catalogoPorTitulo(string $titulo): ?array
{
    $buscado = normalizarTituloCatalogo($titulo);
    if ($buscado === '') {
        return null;
    }
    foreach (catalogosSistema() as $clave => $catalogo) {
        if (normalizarTituloCatalogo($catalogo['titulo']) === $buscado) {
            return $catalogo + ['clave' => $clave];
        }
    }
    return null;
}

/**
 * Catálogo de una página del espacio. Manda la marca guardada en su contenido
 * ({"origen":"cie10"}); si no la tiene, se reconoce por el título para las páginas ya creadas.
 */
function catalogoDePagina(array $pagina): ?array
{
    if (($pagina['tipo'] ?? '') !== 'base_datos') {
        return null;
    }
    $datos = json_decode((string) ($pagina['contenido'] ?? ''), true);
    $origen = is_array($datos) ? (string) ($datos['origen'] ?? '') : '';
    if ($origen !== '') {
        return catalogoSistema($origen);
    }
    return catalogoPorTitulo((string) ($pagina['titulo'] ?? ''));
}

/** true si el catálogo vive en una tabla real (siempre: los 34 catálogos y los 2 clínicos). */
function catalogoConTabla(?array $catalogo): bool
{
    if (!is_array($catalogo)) {
        return false;
    }
    $tabla = (string) ($catalogo['tabla'] ?? '');
    if (!preg_match('/^[a-z0-9_]{1,64}$/i', $tabla)) {
        return false;
    }
    $consulta = db()->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    $consulta->execute([$tabla]);
    return (bool) $consulta->fetchColumn();
}

/**
 * Renglones de un catálogo para su visor en la página del espacio.
 * Delega en el motor único: los catálogos son entidades más, con su tabla y sus uniones.
 *
 * Devuelve ['filas' => [], 'total' => int, 'pagina' => int, 'paginas' => int, 'por_pagina' => int].
 */
function filasDeCatalogo(array $catalogo, string $busqueda, int $numeroPagina, int $porPagina = 100): array
{
    $clave = (string) ($catalogo['clave'] ?? '');
    $vacio = ['filas' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => $porPagina];
    if ($clave === '') {
        return $vacio;
    }
    require_once __DIR__ . '/../servicios/motor/almacen.php';
    $lista = motorListar($clave, ['q' => $busqueda, 'pagina' => $numeroPagina, 'limite' => $porPagina]);
    return [
        'filas'      => $lista['filas'],
        'total'      => $lista['total'],
        'pagina'     => $lista['pagina'],
        'paginas'    => $lista['paginas'],
        'por_pagina' => $lista['limite'],
    ];
}

/** Secciones normativas con sus catálogos, en orden de presentación. */
function seccionesCatalogo(): array
{
    $catalogo = catalogosSistema();
    static $resumen = null;
    if ($resumen === null) {
        $resumen = [];
        $titulos = [
            'A' => ['Identificación y demografía', 'Datos de identificación del paciente y del establecimiento.'],
            'B' => ['Terminologías clínicas', 'Diagnósticos, procedimientos y clasificaciones de atención.'],
            'C' => ['Farmacia y terapéutica', 'Medicamentos y todo lo necesario para prescribir y surtir.'],
            'D' => ['Establecimientos y personal', 'Unidades de salud y personal que firma o atiende.'],
            'E' => ['Insumos y dispositivos', 'Insumos, material de curación, instrumental y dispositivos.'],
            'F' => ['Medicinas complementarias', 'Terapias y medicamentos complementarios.'],
        ];
        foreach ($titulos as $clave => [$titulo, $descripcion]) {
            $resumen[$clave] = ['titulo' => $clave . '. ' . $titulo, 'descripcion' => $descripcion, 'catalogos' => []];
        }
        foreach ($catalogo as $llave => $c) {
            if (!empty($c['seccion']) && isset($resumen[$c['seccion']])) {
                $resumen[$c['seccion']]['catalogos'][] = $llave;
            }
        }
        foreach ($resumen as $clave => $seccion) {
            if (!$seccion['catalogos']) unset($resumen[$clave]);
        }
    }
    return $resumen;
}
