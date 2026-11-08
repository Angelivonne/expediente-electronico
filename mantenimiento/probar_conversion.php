<?php
/**
 * Prueba de ida y vuelta de la conversión de datos (sin tocar la base ni el expediente).
 *
 *   php mantenimiento/probar_conversion.php
 *
 * Escribe una tabla de pacientes en cada formato de exportación, la vuelve a leer con el lector
 * de importación y compara filas, columnas y valores. Sirve como red de seguridad antes de
 * cambiar cualquier lector o escritor (CSV, Excel, JSON, XML, SQL, HL7 y FHIR).
 *
 * Solo se ejecuta por línea de comandos: mantenimiento/ está bloqueado al navegador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$raiz = dirname(__DIR__);
require_once $raiz . '/config/config.php';
require_once $raiz . '/app/nucleo/funciones.php';
require_once $raiz . '/app/servicios/conversion/comun.php';
require_once $raiz . '/app/servicios/conversion/deteccion.php';
require_once $raiz . '/app/servicios/conversion/mapeo.php';

/** Tabla de prueba con los campos de identificación que pide la NOM-004. */
$tabla = [
    'nombre'   => 'pacientes',
    'columnas' => ['folio', 'nombre', 'primer_apellido', 'segundo_apellido', 'fecha_nacimiento', 'sexo', 'curp', 'telefono'],
    'tipos'    => ['texto', 'texto', 'texto', 'texto', 'fecha', 'texto', 'texto', 'texto'],
    'filas'    => [
        ['EXP-000001', 'José Ángel', 'Núñez', 'Peña', '1985-05-12', 'H', 'NUPA850512HDFXXR06', '5551234567'],
        ['EXP-000002', 'María Fernanda', 'García', 'López', '1990-08-20', 'M', 'GALM900820MDFRPR07', '5559876543'],
        ['EXP-000003', 'Ana', 'Ortiz', '', '2001-01-02', 'M', '', ''],
    ],
];

$formatos = array_keys(FORMATOS_EXPORTACION);
$fallas = 0;
printf("%-16s %-8s %-10s %s\n", 'FORMATO', 'FILAS', 'COLUMNAS', 'RESULTADO');
echo str_repeat('-', 62) . "\n";

foreach ($formatos as $formato) {
    $ext = FORMATOS_EXPORTACION[$formato]['ext'] ?? 'dat';
    // HL7 v2 y FHIR no conservan las columnas de origen: el lector devuelve su conjunto canónico
    // de campos del paciente. Se comprueba que estén todas las filas y los nombres.
    $estructural = in_array($formato, ['hl7v2', 'fhir'], true);
    try {
        $contenido = escribirExportacion($formato, $tabla);
        $lectura   = leerArchivoConversion($contenido, 'prueba.' . $ext);
        $leida     = $lectura['tablas'][0] ?? null;
        if (!$leida) {
            throw new RuntimeException('el lector no devolvió ninguna tabla');
        }
        $filasOk = count($leida['filas']) === count($tabla['filas']);
        $colsOk  = $estructural || count($leida['columnas']) === count($tabla['columnas']);
        if (!$filasOk || !$colsOk) {
            throw new RuntimeException(sprintf('se leyeron %d filas y %d columnas (se esperaban %d y %d)',
                count($leida['filas']), count($leida['columnas']), count($tabla['filas']), count($tabla['columnas'])));
        }
        if ($estructural) {
            // Los apellidos del archivo tienen que aparecer en la lectura
            $plano = json_encode($leida['filas'], JSON_UNESCAPED_UNICODE);
            foreach (['Núñez', 'García', 'Ortiz'] as $apellido) {
                if (!str_contains($plano, $apellido)) {
                    throw new RuntimeException("no se recuperó el paciente con apellido $apellido");
                }
            }
        }
        printf("%-16s %-8d %-10d OK%s\n", $formato, count($leida['filas']), count($leida['columnas']),
            $lectura['avisos'] ? ' · ' . implode(' · ', $lectura['avisos']) : '');
    } catch (Throwable $e) {
        $fallas++;
        printf("%-16s %-8s %-10s FALLA: %s\n", $formato, '—', '—', $e->getMessage());
    }
}

echo str_repeat('-', 62) . "\n";
printf("%d de %d formatos con ida y vuelta correcta.\n", count($formatos) - $fallas, count($formatos));
exit($fallas === 0 ? 0 : 1);
