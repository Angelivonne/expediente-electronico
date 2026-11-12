<?php
/**
 * CASO DE LA FERIA — "El caso de Ana" (en la clínica FEMECI real, con las cuentas reales).
 * Crea SOLO: la paciente ficticia Ana, su historial con fechas pasadas, la receta,
 * la modificación posterior (el encubrimiento) y los accesos de investigación.
 * No crea cuentas nuevas y no borra nada real; el modo «eliminar» solo quita lo que este
 * script dejó registrado en la tabla configuracion (clave 'feria_caso').
 *
 *   php feria_caso.php crear      monta el caso
 *   php feria_caso.php eliminar   borra SOLO lo que este caso dejó
 *   php feria_caso.php guion      reimprime el guion
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/nucleo/seguridad.php';
configurarErrores();
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app/nucleo/funciones.php';

/* ============================================================
   REPARTO (cuentas reales del sistema). Para cambiar quién hace
   qué, edita solo estos correos y vuelve a correr crear|eliminar.
   ============================================================ */
const FERIA_CULPABLE      = 'joseluis@test.com';      // el doctor que recetó la dosis alta (el que narra)
const FERIA_PRENATAL      = 'vania@test.com';         // doctora del control prenatal (la buena)
const FERIA_PISTA         = 'angelzamora@test.com';   // acceso nocturno (pista falsa)
const FERIA_INVESTIGADORA = 'danna@test.com';         // quien investiga el caso
const FERIA_CLAVE_CONFIG  = 'feria_caso';

$pdo = db();

function feriaIds(): array
{
    $c = db()->prepare("SELECT valor FROM configuracion WHERE clave = '" . FERIA_CLAVE_CONFIG . "' LIMIT 1");
    $c->execute();
    $v = json_decode((string) $c->fetchColumn(), true);
    return is_array($v) ? $v : [];
}

/* ---------- eliminar: borra únicamente lo registrado por este caso ---------- */
if (($argv[1] ?? '') === 'eliminar') {
    $ids = feriaIds();
    if (!$ids) {
        exit("No hay caso de feria registrado.\n");
    }
    $pdo->beginTransaction();
    if (!empty($ids['bitacora_ids'])) {
        $marcas = implode(',', array_fill(0, count($ids['bitacora_ids']), '?'));
        $pdo->prepare("DELETE FROM bitacora WHERE id IN ($marcas)")->execute($ids['bitacora_ids']);
    }
    if (!empty($ids['historial_ids'])) {
        $marcas = implode(',', array_fill(0, count($ids['historial_ids']), '?'));
        $pdo->prepare("DELETE FROM historial_clinico WHERE id IN ($marcas) AND paciente_id = ?")->execute([...$ids['historial_ids'], $ids['paciente_id']]);
    }
    if (!empty($ids['version_id'])) {
        $pdo->prepare('DELETE FROM registro_versiones WHERE id = ?')->execute([$ids['version_id']]);
    }
    $pdo->prepare('DELETE FROM pacientes WHERE id = ?')->execute([$ids['paciente_id']]);
    $pdo->prepare("DELETE FROM configuracion WHERE clave = '" . FERIA_CLAVE_CONFIG . "'")->execute();
    $pdo->commit();
    exit("Caso de la feria eliminado (paciente, historial, bitácora del caso y versión). Nada más fue tocado.\n");
}

/* ---------- guion ---------- */
function imprimirGuion(array $reparto, array $pac, array $crono): void
{
    echo "\n";
    echo "======================================================================\n";
    echo "  EL CASO DE ANA — GUION PARA LA FERIA DE CIENCIAS\n";
    echo "======================================================================\n";
    echo "  Paciente: {$pac['nombre']} · Folio {$pac['folio']} · ver.php?id={$pac['id']}\n";
    echo "\n  REPARTO (cuentas reales del sistema):\n";
    echo "   · Doctora del prenatal (la buena): {$reparto['prenatal']}\n";
    echo "   · El doctor que recetó la dosis alta: {$reparto['culpable']}\n";
    echo "   · Acceso nocturno (pista falsa): {$reparto['pista']}\n";
    echo "   · Quien investiga: {$reparto['investigadora']}\n";
    echo "\n  CRONOLOGÍA REGISTRADA EN LA BITÁCORA:\n";
    foreach ($crono as $linea) {
        echo "   {$linea}\n";
    }
    echo "\n  CLÍMAX CON LA IA (Asistente, con «Acceso al expediente» activado):\n";
    echo "   · «¿Qué medicamentos se recetaron a esta paciente y quién los recetó?»\n";
    echo "   · «¿Quién ha consultado y quién ha MODIFICADO este expediente? ¿Qué cambió?»\n";
    echo "\n  EL SECRETO (solo lo sabes tú): la receta visible está editada\n";
    echo "  («cada 8 horas por 5 días»), pero la bitácora guarda el valor original\n";
    echo "  («cada 4 horas por 20 días») con usuario, IP y hora exacta.\n\n";
}

if (($argv[1] ?? '') === 'guion') {
    $ids = feriaIds();
    if (!$ids) {
        exit("No hay caso creado. Usa: php feria_caso.php crear\n");
    }
    $pac = $pdo->query("SELECT id, folio, CONCAT(nombre, ' ', primer_apellido, ' ', segundo_apellido) AS nombre FROM pacientes WHERE id = " . (int) $ids['paciente_id'])->fetch(PDO::FETCH_ASSOC);
    $reparto = [];
    $qNombre = $pdo->prepare('SELECT nombre FROM usuarios WHERE correo = ?');
    foreach (['culpable' => FERIA_CULPABLE, 'prenatal' => FERIA_PRENATAL, 'pista' => FERIA_PISTA, 'investigadora' => FERIA_INVESTIGADORA] as $rol => $correo) {
        $qNombre->execute([$correo]);
        $reparto[$rol] = (string) ($qNombre->fetchColumn() ?: $correo);
    }
    $crono = array_map(
        static fn ($b) => $b['creado_en'] . '  ' . $b['accion'] . '  ' . ($b['campo'] ?? ''),
        iterator_to_array($pdo->query('SELECT accion, campo, creado_en FROM bitacora WHERE id IN (' . implode(',', $ids['bitacora_ids']) . ') ORDER BY creado_en'))
    );
    imprimirGuion($reparto, $pac, $crono);
    exit;
}

if (($argv[1] ?? '') !== 'crear') {
    fwrite(STDERR, "Uso: php feria_caso.php crear|eliminar|guion\n");
    exit(1);
}
if (feriaIds()) {
    exit("El caso ya existe. Usa «eliminar» antes de volver a crearlo.\n");
}

/* ---------- Cuentas del reparto (existentes) ---------- */
$usuarioPorCorreo = static function (string $correo) use ($pdo): array {
    $q = $pdo->prepare('SELECT id, nombre, rol, cedula_profesional, institucion_id FROM usuarios WHERE correo = ? AND activo = 1');
    $q->execute([$correo]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        exit("No se encontró la cuenta activa: {$correo}\n");
    }
    return $u;
};
$culpable      = $usuarioPorCorreo(FERIA_CULPABLE);
$prenatal      = $usuarioPorCorreo(FERIA_PRENATAL);
$pista         = $usuarioPorCorreo(FERIA_PISTA);
$investigadora = $usuarioPorCorreo(FERIA_INVESTIGADORA);
$inst          = (int) $prenatal['institucion_id'];

/* ---------- La paciente ---------- */
$embarazada = date('Y-m-d H:i:s', strtotime('-180 days 09:15'));
$pdo->prepare(
    'INSERT INTO pacientes (institucion_id, folio, curp, nombre, primer_apellido, segundo_apellido, fecha_nacimiento, sexo, entidad_nacimiento,
     nacionalidad, telefono, correo, domicilio, municipio_estado, contacto_emergencia, telefono_emergencia, alergias, grupo_sanguineo,
     padecimientos_cronicos, motivo_consulta, confirmacion_datos, creado_en)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
)->execute([
    $inst, 'TMP-FERIA', 'FORA020315MMCRRB07', 'Ana Lucía', 'Flores', 'Rivera', '2002-03-15', 'M', 'MC',
    'Mexicana', '5512345678', 'ana.flores@correo.test', 'Calle de las Rosas 22, Col. Jardines', 'Naucalpan, Estado de México',
    'Raúl Rivera (pareja)', '5587654321', 'Ninguna conocida', 'O+', '', 'Primera visita de control prenatal', $embarazada,
]);
$pacienteId = (int) $pdo->lastInsertId();
$folio = sprintf('EXP-%06d', $pacienteId);
$pdo->prepare('UPDATE pacientes SET folio = ? WHERE id = ?')->execute([$folio, $pacienteId]);

/* Bitácora con timestamps retroactivos; se guardan los ids para poder eliminar solo este caso */
$bitacoraIds = [];
$bitacora = $pdo->prepare(
    'INSERT INTO bitacora (tabla, registro_id, accion, campo, valor_anterior, valor_nuevo, usuario_id, institucion_id, ip, creado_en)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$registrar = function (...$args) use ($pdo, $bitacora, &$bitacoraIds): void {
    $bitacora->execute($args);
    $bitacoraIds[] = (int) $pdo->lastInsertId();
};

$historialIds = [];
$historial = $pdo->prepare(
    'INSERT INTO historial_clinico (institucion_id, paciente_id, tipo, fecha, titulo, detalle, autor_id, autor_nombre, autor_cedula)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$nota = function (array $autor, string $ip, string $cuando, string $tipo, string $titulo, array $detalle) use ($pdo, $historial, $registrar, $inst, $pacienteId, &$historialIds): int {
    $historial->execute([
        $inst, $pacienteId, $tipo, $cuando, $titulo, json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $autor['id'], $autor['nombre'], (string) ($autor['cedula_profesional'] ?? ''),
    ]);
    $id = (int) $pdo->lastInsertId();
    $historialIds[] = $id;
    $registrar('historial_clinico', $id, 'crear', null, null, $tipo, $autor['id'], $inst, $ip, $cuando);
    return $id;
};

$registrar('pacientes', $pacienteId, 'crear', null, null, $folio, $prenatal['id'], $inst, '192.168.1.21', $embarazada);

/* ---------- CRONOLOGÍA ---------- */

// 1. Primera consulta prenatal (hace 180 días, todo normal) — la doctora del prenatal
$nota($prenatal, '192.168.1.21', date('Y-m-d H:i:s', strtotime('-180 days 09:30')), 'consulta', 'Control prenatal · primera visita', [
    'motivo' => 'Primera visita de control prenatal',
    'padecimiento' => 'Paciente de 24 años, gesta 1. FUM hace 8 semanas. Niega sangrado, dolor pélvico o fiebre. Presión arterial y peso dentro de parámetros.',
    'exploracion' => 'Consciente, orientada. Abdomen blando, fondo uterino acorde. Sin signos de alarma.',
    'signos' => ['sistolica' => 108, 'diastolica' => 68, 'fc' => 82, 'fr' => 16, 'temperatura' => 36.4, 'spo2' => 99, 'peso' => 58.2, 'talla' => 163],
    'resultados' => 'BH y glucosa en ayuno sin alteraciones; grupo O+, Rh positivo',
    'diagnostico' => ['Embarazo de 8 semanas, sin complicaciones'], 'cie10' => ['Z34.0'],
    'nota' => 'Embarazo de curso normal. Se explica plan de control mensual y signos de alarma.',
    'plan' => 'Ácido fólico 5 mg y sulfato ferroso. Control prenatal mensual. Signos de alarma explicados.',
    'pronostico' => 'Bueno',
]);

// 2. Receta prenatal correcta (contraste con la dosis alta) — hace 178 días
$nota($prenatal, '192.168.1.21', date('Y-m-d H:i:s', strtotime('-178 days 10:00')), 'receta', 'Receta prenatal (suplementos)', [
    'cedula_prescriptor' => (string) ($prenatal['cedula_profesional'] ?? ''), 'institucion' => 'FEMECI', 'establecimiento' => 'FEMECI', 'domicilio' => 'FEMECI',
    'medicamentos' => [
        ['nombre' => 'Ácido fólico', 'presentacion' => 'Tabletas 5 mg', 'dosis' => '5 mg', 'via' => 'Oral', 'periodicidad' => 'cada 24 horas', 'duracion' => '90 días', 'fecha_inicio' => date('Y-m-d', strtotime('-178 days')), 'fecha_fin' => date('Y-m-d', strtotime('-88 days'))],
        ['nombre' => 'Sulfato ferroso', 'presentacion' => 'Tabletas 300 mg', 'dosis' => '300 mg', 'via' => 'Oral', 'periodicidad' => 'cada 24 horas', 'duracion' => '90 días', 'fecha_inicio' => date('Y-m-d', strtotime('-178 days')), 'fecha_fin' => date('Y-m-d', strtotime('-88 days'))],
    ],
]);

// 3. Control de seguimiento normal — hace 150 días
$nota($prenatal, '192.168.1.21', date('Y-m-d H:i:s', strtotime('-150 days 09:40')), 'consulta', 'Control prenatal · seguimiento (semana 14)', [
    'motivo' => 'Control prenatal de seguimiento',
    'padecimiento' => 'Asintomática. Náuseas leves matutinas controladas con dieta. Sin sangrado ni dolor.',
    'exploracion' => 'Buen estado general. Fondo uterino acorde a gestación.',
    'signos' => ['sistolica' => 110, 'diastolica' => 70, 'fc' => 80, 'fr' => 16, 'temperatura' => 36.3, 'spo2' => 99, 'peso' => 58.9, 'talla' => 163],
    'diagnostico' => ['Embarazo de 14 semanas, curso normal'], 'cie10' => ['Z34.8'],
    'nota' => 'Continúa con suplementos. Próximo control en 4 semanas.',
    'plan' => 'Continuar control mensual. Ultrasonido de tamizaje en semana 20.',
    'pronostico' => 'Bueno',
]);

// 4. LA VISITA FATÍDICA — hace 90 días: la receta de la dosis alta (el doctor que narra)
$recetaOriginal = [
    'cedula_prescriptor' => (string) ($culpable['cedula_profesional'] ?? ''), 'institucion' => 'FEMECI', 'establecimiento' => 'FEMECI', 'domicilio' => 'FEMECI',
    'medicamentos' => [
        ['nombre' => 'Metoclopramida', 'presentacion' => 'Tabletas 10 mg', 'dosis' => '10 mg', 'via' => 'Oral', 'periodicidad' => 'cada 4 horas', 'duracion' => '20 días', 'fecha_inicio' => date('Y-m-d', strtotime('-90 days')), 'fecha_fin' => date('Y-m-d', strtotime('-71 days'))],
    ],
];
$recetaId = $nota($culpable, '192.168.1.24', date('Y-m-d H:i:s', strtotime('-90 days 11:00')), 'receta', 'Receta — Metoclopramida por náuseas del embarazo', $recetaOriginal);

// 5. Consulta clínica del mismo día (contexto de la visita)
$nota($culpable, '192.168.1.24', date('Y-m-d H:i:s', strtotime('-90 days 10:30')), 'consulta', 'Consulta por náuseas y vómito intensos (semana 21)', [
    'motivo' => 'Náuseas y vómito persistentes de una semana de evolución',
    'padecimiento' => 'Paciente de 24 años con embarazo de 21 semanas. Refiere náuseas intensas con vómito de 5-6 episodios diarios que impiden la alimentación adecuada. Niega fiebre, dolor abdominal intenso o sangrado transvaginal.',
    'exploracion' => 'Hidratada, mucosas ligeramente secas. Abdomen blando, fondo uterino acorde. Sin datos de deshidratación severa.',
    'signos' => ['sistolica' => 104, 'diastolica' => 62, 'fc' => 92, 'fr' => 18, 'temperatura' => 36.6, 'spo2' => 98, 'peso' => 57.4, 'talla' => 163],
    'diagnostico' => ['Náuseas y vómito del embarazo, severos'], 'cie10' => ['O21.0'],
    'nota' => 'Se indica tratamiento antiemético. Se recomienda dieta fraccionada y hidratación. Control en una semana.',
    'plan' => 'Metoclopramida oral. Reevaluación en 7 días; si persiste, valorar vía intravenosa.',
    'pronostico' => 'Reservado',
]);

// 6. LA TRAGEDIA — hace 76 días: fallecimiento comunicada a la clínica — la doctora del prenatal
$nota($prenatal, '192.168.1.21', date('Y-m-d H:i:s', strtotime('-76 days 08:15')), 'consulta', 'Constancia de defunción (hospital externo)', [
    'motivo' => 'Notificación de defunción de la paciente',
    'padecimiento' => 'El esposo (Raúl Rivera) notifica que la paciente ingresó la madrugada de ayer a urgencias de un hospital externo con debilidad generalizada, movimientos anormales involuntarios y alteración del estado de alerta, evolucionando a paro cardíaco refractario. No se cuenta con el expediente completo del hospital externo.',
    'exploracion' => 'No aplica (paciente externa; información por referencia familiar).',
    'signos' => ['sistolica' => 0, 'diastolica' => 0, 'fc' => 0, 'fr' => 0, 'temperatura' => 0, 'spo2' => 0],
    'resultados' => 'Pendiente: certificado de defunción y expediente del hospital externo',
    'diagnostico' => ['Defunción. Causa presunta en investigación'], 'cie10' => ['R99'],
    'nota' => 'El caso se comunica a la dirección de la clínica y queda sujeto a investigación oficial. NO se modifica ningún registro previo del expediente.',
    'plan' => 'Aviso a dirección. Resguardo del expediente. Espera de autoridad investigadora.',
    'pronostico' => 'No aplica',
]);

// 7. EL ENCUBRIMIENTO — hace 60 días a las 23:47: se EDITA la receta tras el fallecimiento
$recetaEncubierta = $recetaOriginal;
$recetaEncubierta['medicamentos'][0]['periodicidad'] = 'cada 8 horas';
$recetaEncubierta['medicamentos'][0]['duracion'] = '5 días';
$recetaEncubierta['medicamentos'][0]['fecha_fin'] = date('Y-m-d', strtotime('-86 days'));
$cuandoEncubrimiento = date('Y-m-d H:i:s', strtotime('-60 days 23:47'));

$pdo->prepare('UPDATE historial_clinico SET detalle = ? WHERE id = ?')
    ->execute([json_encode($recetaEncubierta, JSON_UNESCAPED_UNICODE), $recetaId]);
$registrar(
    'historial_clinico', $recetaId, 'actualizar', 'detalle',
    json_encode($recetaOriginal, JSON_UNESCAPED_UNICODE), json_encode($recetaEncubierta, JSON_UNESCAPED_UNICODE),
    $culpable['id'], $inst, '192.168.1.24', $cuandoEncubrimiento
);
// Copia del estado anterior de la receta, como la deja el sistema en cada guardado
$pdo->prepare('INSERT INTO registro_versiones (institucion_id, entidad, registro_id, usuario_id, datos, creado_en) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([
        $inst, 'historial_clinico', $recetaId, $culpable['id'],
        json_encode(['tipo' => 'receta', 'fecha' => date('Y-m-d H:i:s', strtotime('-90 days 11:00')), 'titulo' => 'Receta — Metoclopramida por náuseas del embarazo', 'detalle' => $recetaOriginal], JSON_UNESCAPED_UNICODE),
        $cuandoEncubrimiento,
    ]);
$versionId = (int) $pdo->lastInsertId();

// 8. Acceso nocturno de la pista falsa — hace 58 días a las 02:15
$registrar('pacientes', $pacienteId, 'acceso', 'consulta_expediente', null, null, $pista['id'], $inst, '192.168.1.31', date('Y-m-d H:i:s', strtotime('-58 days 02:15')));

// 9. Acceso del doctor a su propia receta la madrugada del encubrimiento
$registrar('pacientes', $pacienteId, 'acceso', 'consulta_expediente', null, null, $culpable['id'], $inst, '192.168.1.24', date('Y-m-d H:i:s', strtotime('-60 days 23:41')));

// 10. LLEGA LA INVESTIGACIÓN — hace 14 días
$registrar('pacientes', $pacienteId, 'acceso', 'consulta_expediente', null, null, $investigadora['id'], $inst, '192.168.1.10', date('Y-m-d H:i:s', strtotime('-14 days 10:00')));

/* ---------- Registro del caso para poder eliminarlo limpio ---------- */
$pdo->prepare("INSERT INTO configuracion (institucion_id, clave, valor) VALUES (?, ?, ?)")
    ->execute([$inst, FERIA_CLAVE_CONFIG, json_encode([
        'paciente_id' => $pacienteId, 'receta_id' => $recetaId, 'version_id' => $versionId,
        'historial_ids' => $historialIds, 'bitacora_ids' => $bitacoraIds,
    ], JSON_UNESCAPED_UNICODE)]);

/* ---------- Guion final ---------- */
$reparto = [
    'culpable' => $culpable['nombre'], 'prenatal' => $prenatal['nombre'],
    'pista' => $pista['nombre'], 'investigadora' => $investigadora['nombre'],
];
$crono = array_map(
    static fn ($b) => $b['creado_en'] . '  ' . $b['accion'] . '  ' . ($b['campo'] ?? ''),
    iterator_to_array($pdo->query('SELECT accion, campo, creado_en FROM bitacora WHERE id IN (' . implode(',', $bitacoraIds) . ') ORDER BY creado_en'))
);
imprimirGuion($reparto, ['id' => $pacienteId, 'folio' => $folio, 'nombre' => 'Ana Lucía Flores Rivera'], $crono);
echo "Entra con tu cuenta de siempre y abre:\n";
echo "  http://localhost/clinica/pacientes/ver.php?id={$pacienteId}\n";
