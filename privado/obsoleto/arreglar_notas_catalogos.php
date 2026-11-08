<?php
/**
 * Arreglar todas las notas de catálogos - contenido limpio y bien formateado
 * Usa los datos que YA están en las tablas SQL
 */

require_once __DIR__ . '/app/nucleo/inicio.php';
require_once __DIR__ . '/app/servicios/espacio/paginas.php';

$pdo = db();
$instId = institucionId();
$usuarioId = (int) usuarioActual()['id'];

echo "============================================================\n";
echo "ARREGLAR NOTAS DE CATÁLOGOS - CONTENIDO LIMPIO\n";
echo "============================================================\n\n";

// Función para crear contenido de tabla desde datos SQL
function crearNotaTabla($titulo, $columnas, $filas) {
    $texto = "CATÁLOGO: {$titulo}\n";
    $texto .= str_repeat("=", 50) . "\n\n";

    $texto .= "Columnas: " . count($columnas) . "\n";
    $texto .= "Filas: " . count($filas) . "\n\n";

    $texto .= "ENCABEZADOS:\n";
    $texto .= implode(" | ", $columnas) . "\n";
    $texto .= str_repeat("-", 80) . "\n\n";

    $texto .= "DATOS:\n";
    foreach ($filas as $fila) {
        $texto .= implode(" | ", $fila) . "\n";
    }

    $texto .= "\n--- Fin de datos ---\n";
    $texto .= "Este catálogo es una nota del sistema. Edita para agregar más datos.\n";

    return json_encode([['t' => 'p', 'x' => $texto]], JSON_UNESCAPED_UNICODE);
}

// Busca o crea una nota de catálogo por título e institución
function obtenerIdNota($pdo, $titulo, $instId, $usuarioId) {
    $c = $pdo->prepare("SELECT id FROM paginas WHERE tipo = 'nota' AND titulo = ? AND institucion_id = ? LIMIT 1");
    $c->execute([$titulo, $instId]);
    $fila = $c->fetch();
    if ($fila) {
        return (int) $fila['id'];
    }
    // Si no existe, la creamos vacía
    $pdo->prepare("INSERT INTO paginas (padre_id, tipo, titulo, contenido, version, propietario_id, compartida, paciente_id, orden, creado_en, actualizado_en, actualizado_por, eliminado_en, eliminado_por, institucion_id)
        VALUES (NULL, 'nota', ?, '', 1, ?, 0, NULL, 0, NOW(), NOW(), ?, NULL, NULL, ?)")
        ->execute([$titulo, $usuarioId, $usuarioId, $instId]);
    return (int) $pdo->lastInsertId();
}

$notasActualizadas = 0;

// 1. MEDICAMENTOS (2440 registros)
echo "Procesando: medicamentos\n";
$notaId = obtenerIdNota($pdo, 'Medicamentos', $instId, $usuarioId);
if ($notaId) {
    $filas = $pdo->query("SELECT clave_compendio, nombre, forma_farmaceutica, concentracion, via, laboratorio FROM medicamentos WHERE institucion_id = {$instId} ORDER BY nombre LIMIT 100")->fetchAll();
    $contenido = crearNotaTabla("Medicamentos (CNIS 2025)", ['Clave CNIS', 'Nombre', 'Forma farmacéutica', 'Concentración', 'Vía', 'Laboratorio'], $filas);
    $pdo->prepare("UPDATE paginas SET contenido = ?, actualizado_por = ?, actualizado_en = NOW() WHERE id = ?")->execute([$contenido, $usuarioId, $notaId]);
    echo "  OK: {$notaId} - " . count($filas) . " filas\n";
    $notasActualizadas++;
}

// 2. CIE-10 (1841 registros)
echo "Procesando: cie10\n";
$notaId = obtenerIdNota($pdo, 'Diagnósticos CIE-10', $instId, $usuarioId);
if ($notaId) {
    $filas = $pdo->query("SELECT codigo, descripcion, capitulo FROM cie10 WHERE institucion_id = {$instId} ORDER BY codigo LIMIT 100")->fetchAll();
    $contenido = crearNotaTabla("Diagnósticos CIE-10", ['Código CIE-10', 'Descripción', 'Capítulo'], $filas);
    $pdo->prepare("UPDATE paginas SET contenido = ?, actualizado_por = ?, actualizado_en = NOW() WHERE id = ?")->execute([$contenido, $usuarioId, $notaId]);
    echo "  OK: {$notaId} - " . count($filas) . " filas\n";
    $notasActualizadas++;
}

// 3. Demás catálogos con datos existentes
$catalagos = [
    'catalogo_especialidades' => ['Especialidades médicas', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_tipos_consulta' => ['Tipos de consulta', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_tipos_atencion' => ['Tipos de atención', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_procedimientos' => ['Procedimientos médicos', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_categorias_farmaco' => ['Categorías farmacológicas', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_vias_admin' => ['Vías de administración', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_unidades_medida' => ['Unidades de medida', ['Clave', 'Nombre', 'Símbolo'], 'clave, nombre, simbolo'],
    'catalogo_entidades' => ['Entidades federativas', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_nacionalidades' => ['Nacionalidades', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_lenguas' => ['Lenguas indígenas', ['Clave', 'Nombre', 'Familia'], 'clave, nombre, familia'],
    'catalogo_religiones' => ['Religiones', ['Clave', 'Nombre'], 'clave, nombre'],
    'catalogo_establecimientos' => ['Establecimientos de salud', ['Clave', 'Nombre', 'Tipo', 'Dirección'], 'clave, nombre, tipo, direccion'],
    'catalogo_tipos_personal' => ['Tipos de personal', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_formacion_academica' => ['Formación académica', ['Clave', 'Nombre', 'Nivel'], 'clave, nombre, nivel'],
    'catalogo_material_curacion' => ['Material de curación', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_instrumental' => ['Instrumental y equipo médico', ['Clave', 'Nombre', 'Descripción'], 'clave, nombre, descripcion'],
    'catalogo_cif' => ['Clasificación CIF', ['Código', 'Nombre', 'Descripción'], 'codigo, nombre, descripcion'],
];

foreach ($catalagos as $tabla => $info) {
    $titulo = $info[0];
    $columnas = $info[1];
    $colsSql = $info[2];
    echo "Procesando: {$tabla}\n";

    $existe = $pdo->prepare("SELECT id FROM paginas WHERE tipo = 'nota' AND titulo = ? AND institucion_id = ? LIMIT 1");
    $existe->execute([$titulo, $instId]);
    $notaExistente = $existe->fetch();

    if (!$notaExistente) {
        echo "  ERROR: No se encontró la nota para {$titulo}\n";
        continue;
    }

    $notaId = (int) $notaExistente['id'];

    // Verificar que la tabla existe y tiene datos
    $tablaExiste = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $tablaExiste->execute([$tabla]);
    if (!$tablaExiste->fetchColumn()) {
        echo "  ERROR: La tabla {$tabla} no existe\n";
        continue;
    }

    $filas = $pdo->query("SELECT {$colsSql} FROM {$tabla} WHERE institucion_id = {$instId} ORDER BY 1 LIMIT 100")->fetchAll();
    $contenido = crearNotaTabla($titulo, $columnas, $filas);
    $pdo->prepare("UPDATE paginas SET contenido = ?, actualizado_por = ?, actualizado_en = NOW() WHERE id = ?")
        ->execute([$contenido, $usuarioId, $notaId]);

    registrarBitacora('paginas', $notaId, 'actualizar', 'tipo', null, 'Importación CNIS: muestra de datos');

    echo "  Nota actualizada: {$titulo} (id={$notaId})\n";
    echo "  Columnas: " . count($columnas) . ", Filas mostradas: " . count($filas) . "\n\n";

    $notasActualizadas++;
}

echo "\n============================================================\n";
echo "EXTRACCIÓN COMPLETADA\n";
echo "============================================================\n";
echo "Total notas actualizadas: " . $notasActualizadas . "\n";
