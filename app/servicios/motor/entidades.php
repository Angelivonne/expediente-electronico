<?php
/**
 * Motor único de datos — registro de entidades.
 *
 * Una entidad es cualquier cosa que se lista y se edita igual: pacientes, medicamentos, diagnósticos,
 * catálogos, notas clínicas. Aquí solo se describe *qué es*, *dónde vive* y *cómo se ve*; el cómo
 * (leer, escribir, validar, pintar) lo resuelven app/servicios/motor/almacen.php y las vistas.
 *
 * Reglas:
 *   - Un campo se declara una vez y sirve para tabla, ficha, formulario y herramientas de la IA.
 *   - El almacén se declara con un adaptador ('tabla', 'pagina', 'documento'); los datos no se mueven.
 *   - La vista de tabla se declara en 'vista' (columnas en orden + grupos); los cálculos son de la
 *     pantalla, no de los datos.
 *   - Nada de motores paralelos: si una pantalla necesita algo, se agrega aquí.
 */

/** Entidades disponibles (clave => definición). */
function entidadesMotor(): array
{
    static $entidades = null;
    if ($entidades !== null) {
        return $entidades;
    }

    /* Facturas disponibles para vincular un movimiento (opciones del campo factura_id). */
    $opcionesFacturas = [];
    if (function_exists('db')) {
        try {
            $consultaFacturas = db()->prepare('SELECT id, folio, total FROM finanzas_facturas WHERE institucion_id = ? ORDER BY fecha DESC, id DESC LIMIT 300');
            $consultaFacturas->execute([function_exists('institucionId') ? institucionId() : null]);
            foreach ($consultaFacturas as $filaFactura) {
                $opcionesFacturas[(string) $filaFactura['id']] = $filaFactura['folio'] . ' · $' . number_format((float) $filaFactura['total'], 2, '.', ',');
            }
        } catch (Throwable $e) {
            $opcionesFacturas = [];
        }
    }

    /* Servicios activos de esta clínica para movimientos; la lista queda vacía si falta la migración. */
    $opcionesServicios = [];
    $servicioMovimientoDisponible = false;
    if (function_exists('db') && function_exists('institucionId') && institucionId() !== null) {
        try {
            $qColumnaServicio = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finanzas_movimientos' AND COLUMN_NAME = 'servicio_id'");
            $servicioMovimientoDisponible = (int) $qColumnaServicio->fetchColumn() > 0;
            $consultaServicios = db()->prepare('SELECT id, nombre FROM servicios_clinicos WHERE institucion_id = ? AND activo = 1 ORDER BY nombre LIMIT 300');
            $consultaServicios->execute([institucionId()]);
            foreach ($consultaServicios as $filaServicio) {
                $opcionesServicios[(string) $filaServicio['id']] = $filaServicio['nombre'];
            }
        } catch (Throwable $e) {
            $opcionesServicios = [];
        }
    }

    /* Profesionales para vincular un movimiento (opciones del campo profesional_id). */
    $opcionesProfesionales = [];
    if (function_exists('db')) {
        try {
            $consultaProfesionales = db()->prepare("SELECT id, nombre FROM usuarios WHERE institucion_id = ? AND activo = 1 AND rol <> 'paciente' ORDER BY nombre");
            $consultaProfesionales->execute([function_exists('institucionId') ? institucionId() : null]);
            foreach ($consultaProfesionales as $filaProfesional) {
                $opcionesProfesionales[(string) $filaProfesional['id']] = $filaProfesional['nombre'];
            }
        } catch (Throwable $e) {
            $opcionesProfesionales = [];
        }
    }

    /* Establecimientos para vincular un movimiento (opciones del campo establecimiento_id). */
    $opcionesEstablecimientos = [];
    if (function_exists('db')) {
        try {
            foreach (db()->query("SELECT id, nombre FROM catalogo_establecimientos WHERE activo = 1 ORDER BY nombre") as $filaEstablecimiento) {
                $opcionesEstablecimientos[(string) $filaEstablecimiento['id']] = $filaEstablecimiento['nombre'];
            }
        } catch (Throwable $e) {
            $opcionesEstablecimientos = [];
        }
    }

    $entidades = [
        'pacientes' => [
            'titulo'       => 'Pacientes',
            'singular'     => 'Paciente',
            'icono'        => 'usuarios',
            'permiso'      => 'editar_paciente',
            'almacen'      => ['tipo' => 'tabla', 'tabla' => 'pacientes', 'institucion' => true, 'baja' => 'eliminado_en'],
            'extras'       => 'extras',
            'folio'        => ['prefijo' => 'EXP-', 'longitud' => 6],
            // Campos de perfil que viven dentro de extras['_perfil'] (los captura el formato largo)
            'perfil'       => [
                'estado_civil' => 40, 'ocupacion' => 100, 'escolaridad' => 100, 'afiliacion_institucional' => 120,
                'grupo_etnico' => 60, 'lengua_materna' => 60,
                'tutor_representante' => 160, 'antecedentes_heredofamiliares' => 4000,
                'antecedentes_personales_patologicos' => 4000, 'antecedentes_personales_no_patologicos' => 4000,
                'inmunizaciones' => 4000, 'antecedentes_ginecoobstetricos' => 4000, 'habitos_sustancias' => 4000,
            ],
            'titulo_sql'   => "CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)",
            'busqueda'     => ['folio', 'curp', 'nombre', 'primer_apellido', 'segundo_apellido', 'telefono', 'correo'],
            'busqueda_sql' => ["CONCAT_WS(' ', nombre, primer_apellido, segundo_apellido)", 'curp', 'folio'],
            'orden'        => 'creado_en DESC, id DESC',
            'campos'       => [
                'folio'                  => ['texto' => 'Folio', 'tipo' => 'texto', 'solo_lectura' => true, 'ancho' => 120],
                'nombre'                 => ['texto' => 'Nombre(s)', 'tipo' => 'texto', 'req' => true, 'max' => 80],
                'primer_apellido'        => ['texto' => 'Apellido paterno', 'tipo' => 'texto', 'req' => true, 'max' => 60],
                'segundo_apellido'       => ['texto' => 'Apellido materno', 'tipo' => 'texto', 'max' => 60],
                'fecha_nacimiento'       => ['texto' => 'Fecha de nacimiento', 'tipo' => 'fecha', 'req' => true, 'ancho' => 150],
                'sexo'                   => ['texto' => 'Sexo', 'tipo' => 'opcion', 'req' => true, 'opciones' => defined('SEXOS') ? SEXOS : [], 'ancho' => 120, 'icono' => 'seleccion'],
                'curp'                   => ['texto' => 'CURP', 'tipo' => 'curp', 'ancho' => 190],
                'entidad_nacimiento'     => ['texto' => 'Entidad de nacimiento', 'tipo' => 'opcion', 'opciones' => defined('ENTIDADES') ? ENTIDADES : [], 'ancho' => 190, 'icono' => 'ubicacion'],
                'nacionalidad'           => ['texto' => 'Nacionalidad', 'tipo' => 'texto', 'max' => 40, 'ancho' => 140],
                'telefono'               => ['texto' => 'Teléfono', 'tipo' => 'texto', 'max' => 20, 'ancho' => 140, 'icono' => 'telefono'],
                'correo'                 => ['texto' => 'Correo electrónico', 'tipo' => 'correo', 'max' => 120, 'ancho' => 220, 'icono' => 'mensaje'],
                'domicilio'              => ['texto' => 'Domicilio', 'tipo' => 'texto', 'max' => 200, 'ancho' => 260, 'icono' => 'casa'],
                'municipio_estado'       => ['texto' => 'Municipio / Estado', 'tipo' => 'texto', 'max' => 120, 'ancho' => 200, 'icono' => 'ubicacion'],
                'contacto_emergencia'    => ['texto' => 'Contacto de emergencia', 'tipo' => 'texto', 'max' => 120, 'ancho' => 210, 'icono' => 'usuario'],
                'telefono_emergencia'    => ['texto' => 'Tel. emergencia', 'tipo' => 'texto', 'max' => 20, 'ancho' => 150, 'icono' => 'telefono'],
                'alergias'               => ['texto' => 'Alergias', 'tipo' => 'texto', 'max' => 255, 'ancho' => 200],
                'grupo_sanguineo'        => ['texto' => 'Grupo sanguíneo', 'tipo' => 'opcion', 'opciones' => defined('GRUPOS_SANGUINEOS') ? array_combine(GRUPOS_SANGUINEOS, GRUPOS_SANGUINEOS) : [], 'ancho' => 130, 'icono' => 'gota'],
                'padecimientos_cronicos' => ['texto' => 'Padecimientos crónicos', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240],
                'medicamentos_actuales'  => ['texto' => 'Medicamentos actuales', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240, 'icono' => 'pildora'],
                'motivo_consulta'        => ['texto' => 'Motivo de consulta', 'tipo' => 'texto', 'max' => 2000, 'ancho' => 260],
                'confirmacion_datos'     => ['texto' => 'Confirmado por el paciente', 'tipo' => 'casilla', 'solo_lectura' => true, 'ancho' => 200, 'icono' => 'escudo'],
                'creado_en'              => ['texto' => 'Fecha de registro', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 150, 'icono' => 'calendario'],
            ],
            'vista' => [
                'grupos'   => ['Identificación' => 8, 'Contacto y domicilio' => 6, 'Antecedentes' => 5, 'Registro' => 2],
                'columnas' => [
                    ['clave' => 'nombre_completo', 'titulo' => 'Nombre completo', 'calculo' => 'nombre_completo', 'icono' => 'aa', 'ancho' => 260],
                    ['clave' => 'folio', 'titulo' => 'Folio', 'icono' => 'texto', 'ancho' => 120],
                    ['clave' => 'curp', 'titulo' => 'CURP', 'icono' => 'texto', 'ancho' => 190],
                    ['clave' => 'fecha_nacimiento', 'titulo' => 'Fecha de nacimiento', 'calculo' => 'fecha_nacimiento', 'icono' => 'calendario', 'ancho' => 150],
                    ['clave' => 'edad', 'titulo' => 'Edad', 'calculo' => 'edad', 'icono' => 'numero', 'ancho' => 80, 'orden' => 'numero'],
                    ['clave' => 'sexo', 'titulo' => 'Sexo', 'icono' => 'seleccion', 'ancho' => 120],
                    ['clave' => 'entidad_nacimiento', 'titulo' => 'Entidad de nacimiento', 'icono' => 'ubicacion', 'ancho' => 190],
                    ['clave' => 'nacionalidad', 'titulo' => 'Nacionalidad', 'icono' => 'texto', 'ancho' => 140],
                    ['clave' => 'telefono', 'titulo' => 'Teléfono', 'icono' => 'telefono', 'ancho' => 140],
                    ['clave' => 'correo', 'titulo' => 'Correo electrónico', 'icono' => 'mensaje', 'ancho' => 220],
                    ['clave' => 'domicilio', 'titulo' => 'Domicilio', 'icono' => 'casa', 'ancho' => 260],
                    ['clave' => 'municipio_estado', 'titulo' => 'Municipio / Estado', 'icono' => 'ubicacion', 'ancho' => 200],
                    ['clave' => 'contacto_emergencia', 'titulo' => 'Contacto de emergencia', 'icono' => 'usuario', 'ancho' => 210],
                    ['clave' => 'telefono_emergencia', 'titulo' => 'Tel. emergencia', 'icono' => 'telefono', 'ancho' => 150],
                    ['clave' => 'alergias', 'titulo' => 'Alergias', 'icono' => 'texto', 'ancho' => 200],
                    ['clave' => 'grupo_sanguineo', 'titulo' => 'Grupo sanguíneo', 'icono' => 'gota', 'ancho' => 130],
                    ['clave' => 'padecimientos_cronicos', 'titulo' => 'Padecimientos crónicos', 'icono' => 'texto', 'ancho' => 240],
                    ['clave' => 'medicamentos_actuales', 'titulo' => 'Medicamentos actuales', 'icono' => 'pildora', 'ancho' => 240],
                    ['clave' => 'motivo_consulta', 'titulo' => 'Motivo de consulta', 'icono' => 'texto', 'ancho' => 260],
                    ['clave' => 'confirmacion_datos', 'titulo' => 'Confirmado por el paciente', 'calculo' => 'confirmacion', 'icono' => 'escudo', 'ancho' => 200],
                    ['clave' => 'creado_en', 'titulo' => 'Fecha de registro', 'calculo' => 'registro', 'icono' => 'calendario', 'ancho' => 150],
                ],
            ],
        ],
        'medicamentos' => [
            'titulo'    => 'Medicamentos',
            'singular'  => 'Medicamento',
            'icono'     => 'pildora',
            'permiso'   => 'ver_expediente',
            'almacen'   => ['tipo' => 'tabla', 'tabla' => 'medicamentos', 'institucion' => true],
            'titulo_sql'=> 'nombre',
            'busqueda'  => ['clave', 'nombre', 'presentacion', 'concentracion', 'via', 'laboratorio'],
            'orden'     => 'nombre, clave',
            'campos'    => [
                'clave'              => ['texto' => 'Clave', 'tipo' => 'texto', 'req' => true, 'max' => 30, 'ancho' => 100],
                'clave_compendio'    => ['texto' => 'Clave Compendio', 'tipo' => 'texto', 'max' => 20, 'ancho' => 140],
                'nombre'             => ['texto' => 'Nombre', 'tipo' => 'texto', 'req' => true, 'max' => 160, 'ancho' => 220, 'icono' => 'pildora'],
                'forma_farmaceutica' => ['texto' => 'Forma farmacéutica', 'tipo' => 'texto', 'max' => 60, 'ancho' => 160],
                'presentacion'       => ['texto' => 'Presentación', 'tipo' => 'texto', 'max' => 80, 'ancho' => 120, 'icono' => 'tarjeta'],
                'concentracion'      => ['texto' => 'Concentración', 'tipo' => 'texto', 'max' => 40, 'ancho' => 120, 'icono' => 'numero'],
                'via'                => ['texto' => 'Vía de administración', 'tipo' => 'texto', 'max' => 40, 'ancho' => 120],
                'laboratorio'        => ['texto' => 'Laboratorio', 'tipo' => 'texto', 'max' => 120, 'ancho' => 150],
                'activo'             => ['texto' => 'Activo', 'tipo' => 'casilla', 'ancho' => 80],
            ],
        ],
        'cie10' => [
            'titulo'    => 'Diagnósticos CIE-10',
            'singular'  => 'Diagnóstico',
            'icono'     => 'documento',
            'permiso'   => 'ver_expediente',
            'almacen'   => ['tipo' => 'tabla', 'tabla' => 'cie10', 'institucion' => true],
            'titulo_sql'=> "CONCAT(codigo, ' · ', descripcion)",
            'busqueda'  => ['codigo', 'descripcion', 'capitulo'],
            'orden'     => 'codigo',
            'campos'    => [
                'codigo'      => ['texto' => 'Código', 'tipo' => 'texto', 'req' => true, 'max' => 7, 'ancho' => 100],
                'descripcion' => ['texto' => 'Descripción', 'tipo' => 'texto', 'req' => true, 'max' => 200, 'ancho' => 300, 'icono' => 'documento'],
                'capitulo'    => ['texto' => 'Capítulo', 'tipo' => 'texto', 'max' => 80, 'ancho' => 200],
                'activo'      => ['texto' => 'Activo', 'tipo' => 'casilla', 'ancho' => 80],
            ],
        ],
        // Historial clínico: solo lectura (NOM-004 5.10: una nota no se modifica una vez guardada)
        'historial' => [
            'titulo'        => 'Historial clínico',
            'singular'      => 'Registro clínico',
            'icono'         => 'documento',
            'permiso'       => 'historial',
            'solo_lectura'  => true,
            'almacen'       => ['tipo' => 'tabla', 'tabla' => 'historial_clinico', 'institucion' => true],
            'titulo_sql'    => 'titulo',
            'busqueda'      => ['titulo', 'autor_nombre', 'tipo'],
            'orden'         => 'fecha DESC, id DESC',
            'campos'        => [
                'paciente_id'  => ['texto' => 'Paciente', 'tipo' => 'numero', 'ancho' => 90],
                'tipo'         => ['texto' => 'Tipo', 'tipo' => 'texto', 'ancho' => 130,
                                   'opciones' => defined('TIPOS_REGISTRO') ? array_combine(array_keys(TIPOS_REGISTRO), array_map(fn ($t) => $t['corto'], TIPOS_REGISTRO)) : []],
                'fecha'        => ['texto' => 'Fecha y hora', 'tipo' => 'fecha_hora', 'ancho' => 160],
                'titulo'       => ['texto' => 'Título', 'tipo' => 'texto', 'ancho' => 320],
                'autor_nombre' => ['texto' => 'Profesional', 'tipo' => 'texto', 'ancho' => 200],
                'creado_en'    => ['texto' => 'Registrado', 'tipo' => 'fecha_hora', 'ancho' => 160],
            ],
        ],
        // Comentarios breves del equipo sobre cualquier registro (no sustituyen notas clínicas)
        'comentarios' => [
            'titulo'   => 'Comentarios',
            'singular' => 'Comentario',
            'icono'    => 'chat',
            'permiso'  => 'ver_expediente',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'comentarios', 'institucion' => true],
            'busqueda' => ['texto'],
            'orden'    => 'creado_en DESC, id DESC',
            'campos'   => [
                'entidad'     => ['texto' => 'Entidad', 'tipo' => 'texto', 'req' => true, 'max' => 40],
                'registro_id' => ['texto' => 'Registro', 'tipo' => 'numero', 'req' => true],
                'usuario_id'  => ['texto' => 'Autor', 'tipo' => 'numero'],
                'texto'       => ['texto' => 'Comentario', 'tipo' => 'texto', 'req' => true, 'max' => 1000],
                'creado_en'   => ['texto' => 'Fecha', 'tipo' => 'fecha_hora', 'solo_lectura' => true],
                'resuelto_en' => ['texto' => 'Resuelto', 'tipo' => 'texto', 'max' => 19],
            ],
        ],        // Versiones: estado anterior de cada cambio, para auditar y restaurar (solo lectura)
        'versiones' => [
            'titulo'   => 'Versiones de registros',
            'singular' => 'Versión',
            'icono'    => 'documento',
            'permiso'  => 'historial',
            'solo_lectura' => true,
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'registro_versiones', 'institucion' => true],
            'busqueda' => ['entidad'],
            'orden'    => 'id DESC',
            'campos'   => [
                'entidad'     => ['texto' => 'Entidad', 'tipo' => 'texto'],
                'registro_id' => ['texto' => 'Registro', 'tipo' => 'numero'],
                'usuario_id'  => ['texto' => 'Usuario', 'tipo' => 'numero'],
                'datos'       => ['texto' => 'Datos anteriores', 'tipo' => 'texto'],
                'creado_en'   => ['texto' => 'Fecha', 'tipo' => 'fecha_hora', 'solo_lectura' => true],
            ],
        ],

        // Finanzas de la clínica: mismos componentes y mismo motor, sin tablas ni pantallas paralelas
        'finanzas_movimientos' => [
            'titulo'   => 'Movimientos de finanzas',
            'singular' => 'Movimiento',
            'icono'    => 'documento',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_movimientos', 'institucion' => true],
            'titulo_sql' => "CONCAT_WS(' · ', tipo, concepto)",
            'busqueda' => ['concepto', 'descripcion', 'referencia', 'tipo', 'estado'],
            'orden'    => 'fecha DESC, id DESC',
            'campos'   => [
                'tipo'        => ['texto' => 'Tipo', 'tipo' => 'opcion', 'req' => true, 'opciones' => ['ingreso' => 'Ingreso', 'gasto' => 'Gasto'], 'ancho' => 100],
                'fecha'       => ['texto' => 'Fecha', 'tipo' => 'fecha', 'req' => true, 'ancho' => 120],
                'vence_en'    => ['texto' => 'Vence', 'tipo' => 'fecha', 'ancho' => 120],
                'concepto'    => ['texto' => 'Concepto', 'tipo' => 'texto', 'req' => true, 'max' => 120, 'ancho' => 220],
                'servicio_id' => ['texto' => 'Servicio asociado', 'tipo' => 'opcion', 'opciones' => $opcionesServicios, 'ancho' => 160],
                'descripcion' => ['texto' => 'Descripción', 'tipo' => 'texto', 'max' => 255, 'ancho' => 260],
                'monto'       => ['texto' => 'Monto', 'tipo' => 'numero', 'req' => true, 'min' => 0.01, 'max' => 9999999999.99, 'ancho' => 120],
                'metodo'      => ['texto' => 'Método', 'tipo' => 'opcion', 'opciones' => ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'otro' => 'Otro'], 'ancho' => 130],
                'estado'      => ['texto' => 'Estado', 'tipo' => 'opcion', 'opciones' => ['pagado' => 'Pagado', 'pendiente' => 'Pendiente', 'cancelado' => 'Cancelado'], 'ancho' => 120],
                'paciente_id' => ['texto' => 'Paciente', 'tipo' => 'opcion', 'opciones' => [], 'relacion_dinamica' => true, 'ancho' => 220],
                'profesional_id' => ['texto' => 'Profesional', 'tipo' => 'opcion', 'opciones' => $opcionesProfesionales, 'ancho' => 180],
                'establecimiento_id' => ['texto' => 'Establecimiento', 'tipo' => 'opcion', 'opciones' => $opcionesEstablecimientos, 'ancho' => 220],
                'factura_id'  => ['texto' => 'Factura', 'tipo' => 'opcion', 'opciones' => $opcionesFacturas, 'ancho' => 180],
                'referencia'  => ['texto' => 'Referencia', 'tipo' => 'texto', 'max' => 80, 'ancho' => 140],
                'creado_en'   => ['texto' => 'Registrado', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 160],
            ],
            'vista' => [
                'grupos' => ['Movimiento' => 5, 'Pago' => 3, 'Vínculo' => 2, 'Registro' => 1],
                'columnas' => [
                    ['clave' => 'fecha', 'titulo' => 'Fecha', 'icono' => 'calendario', 'ancho' => 120],
                    ['clave' => 'vence_en', 'titulo' => 'Vence', 'icono' => 'calendario', 'ancho' => 120],
                    ['clave' => 'tipo', 'titulo' => 'Tipo', 'icono' => 'seleccion', 'ancho' => 100],
                    ['clave' => 'concepto', 'titulo' => 'Concepto', 'icono' => 'texto', 'ancho' => 220],
                    ['clave' => 'descripcion', 'titulo' => 'Descripción', 'icono' => 'texto', 'ancho' => 260],
                    ['clave' => 'monto', 'titulo' => 'Monto', 'icono' => 'numero', 'ancho' => 120],
                    ['clave' => 'metodo', 'titulo' => 'Método', 'icono' => 'documento', 'ancho' => 130],
                    ['clave' => 'estado', 'titulo' => 'Estado', 'icono' => 'seleccion', 'ancho' => 120],
                    ['clave' => 'paciente_id', 'titulo' => 'Paciente', 'icono' => 'usuarios', 'ancho' => 90],
                    ['clave' => 'referencia', 'titulo' => 'Referencia', 'icono' => 'texto', 'ancho' => 140],
                    ['clave' => 'creado_en', 'titulo' => 'Registrado', 'icono' => 'calendario', 'ancho' => 160],
                ],
            ],
        ],
        'finanzas_conceptos' => [
            'titulo'   => 'Conceptos de finanzas',
            'singular' => 'Concepto',
            'icono'    => 'tabla',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_conceptos', 'institucion' => false],
            'titulo_sql' => 'nombre',
            'busqueda' => ['nombre', 'clave'],
            'orden'    => 'tipo, nombre',
            'campos'   => [
                'clave'  => ['texto' => 'Clave', 'tipo' => 'texto', 'req' => true, 'max' => 40],
                'nombre' => ['texto' => 'Nombre', 'tipo' => 'texto', 'req' => true, 'max' => 120],
                'tipo'   => ['texto' => 'Tipo', 'tipo' => 'opcion', 'opciones' => ['ingreso' => 'Ingreso', 'gasto' => 'Gasto']],
                'activo' => ['texto' => 'Activo', 'tipo' => 'casilla'],
            ],
        ],
        'servicios_clinicos' => [
            'titulo'   => 'Servicios clínicos',
            'singular' => 'Servicio clínico',
            'icono'    => 'tabla',
            'permiso'  => 'configurar',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'servicios_clinicos', 'institucion' => true],
            'titulo_sql' => 'nombre',
            'busqueda' => ['nombre', 'descripcion'],
            'orden'    => 'nombre',
            'campos'   => [
                'nombre' => ['texto' => 'Servicio', 'tipo' => 'texto', 'req' => true, 'max' => 120, 'ancho' => 220],
                'descripcion' => ['texto' => 'Descripción', 'tipo' => 'texto', 'max' => 255, 'ancho' => 260],
                'precio' => ['texto' => 'Precio', 'tipo' => 'numero', 'req' => true, 'min' => 0, 'max' => 9999999999.99, 'ancho' => 120],
                'activo' => ['texto' => 'Activo', 'tipo' => 'casilla', 'ancho' => 80],
            ],
            'vista' => [
                'grupos' => ['Servicio' => 3, 'Estado' => 1],
                'columnas' => [
                    ['clave' => 'nombre', 'titulo' => 'Servicio', 'icono' => 'texto', 'ancho' => 220],
                    ['clave' => 'descripcion', 'titulo' => 'Descripción', 'icono' => 'texto', 'ancho' => 260],
                    ['clave' => 'precio', 'titulo' => 'Precio', 'icono' => 'numero', 'ancho' => 120],
                    ['clave' => 'activo', 'titulo' => 'Activo', 'icono' => 'seleccion', 'ancho' => 80],
                ],
            ],
        ],
        'finanzas_cierres' => [
            'titulo'   => 'Cierres de caja',
            'singular' => 'Cierre de caja',
            'icono'    => 'documento',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_cierres', 'institucion' => true],
            'titulo_sql' => "CONCAT('Cierre ', fecha)",
            'busqueda' => ['notas', 'fecha'],
            'orden'    => 'fecha DESC, id DESC',
            'campos'   => [
                'fecha'         => ['texto' => 'Fecha', 'tipo' => 'fecha', 'req' => true, 'ancho' => 120],
                'efectivo'      => ['texto' => 'Efectivo', 'tipo' => 'numero', 'min' => 0, 'max' => 9999999999.99, 'ancho' => 100],
                'tarjeta'       => ['texto' => 'Tarjeta', 'tipo' => 'numero', 'min' => 0, 'max' => 9999999999.99, 'ancho' => 100],
                'transferencia' => ['texto' => 'Transferencia', 'tipo' => 'numero', 'min' => 0, 'max' => 9999999999.99, 'ancho' => 120],
                'cheque'        => ['texto' => 'Cheque', 'tipo' => 'numero', 'min' => 0, 'max' => 9999999999.99, 'ancho' => 100],
                'otro'          => ['texto' => 'Otro', 'tipo' => 'numero', 'min' => 0, 'max' => 9999999999.99, 'ancho' => 90],
                'total'         => ['texto' => 'Total', 'tipo' => 'numero', 'req' => true, 'min' => 0, 'max' => 9999999999.99, 'ancho' => 110],
                'diferencia_total' => ['texto' => 'Diferencia', 'tipo' => 'numero', 'solo_lectura' => true, 'ancho' => 110],
                'notas'         => ['texto' => 'Notas', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240],
                'creado_en'     => ['texto' => 'Registrado', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 160],
            ],
            'vista' => [
                'grupos' => ['Cierre' => 9, 'Registro' => 1],
                'columnas' => [
                    ['clave' => 'fecha', 'titulo' => 'Fecha', 'icono' => 'calendario', 'ancho' => 120],
                    ['clave' => 'efectivo', 'titulo' => 'Efectivo', 'icono' => 'numero', 'ancho' => 100],
                    ['clave' => 'tarjeta', 'titulo' => 'Tarjeta', 'icono' => 'numero', 'ancho' => 100],
                    ['clave' => 'transferencia', 'titulo' => 'Transferencia', 'icono' => 'numero', 'ancho' => 120],
                    ['clave' => 'cheque', 'titulo' => 'Cheque', 'icono' => 'numero', 'ancho' => 100],
                    ['clave' => 'otro', 'titulo' => 'Otro', 'icono' => 'numero', 'ancho' => 90],
                    ['clave' => 'total', 'titulo' => 'Total', 'icono' => 'numero', 'ancho' => 110],
                    ['clave' => 'diferencia_total', 'titulo' => 'Diferencia', 'icono' => 'numero', 'ancho' => 110],
                    ['clave' => 'notas', 'titulo' => 'Notas', 'icono' => 'texto', 'ancho' => 240],
                    ['clave' => 'creado_en', 'titulo' => 'Registrado', 'icono' => 'calendario', 'ancho' => 160],
                ],
            ],
        ],

        // Facturación (control fiscal; el timbrado lo hace el PAC externo)
        'finanzas_facturas' => [
            'titulo'   => 'Facturas',
            'singular' => 'Factura',
            'icono'    => 'documento',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_facturas', 'institucion' => true],
            'titulo_sql' => "CONCAT(folio, ' · ', COALESCE(razon_social, ''))",
            'busqueda' => ['folio', 'rfc', 'razon_social', 'uuid', 'notas'],
            'orden'    => 'fecha DESC, id DESC',
            'campos'   => [
                'folio'        => ['texto' => 'Folio', 'tipo' => 'texto', 'req' => true, 'max' => 40, 'ancho' => 130],
                'fecha'        => ['texto' => 'Fecha', 'tipo' => 'fecha', 'req' => true, 'ancho' => 120],
                'paciente_id'  => ['texto' => 'Paciente', 'tipo' => 'numero', 'ancho' => 90],
                'rfc'          => ['texto' => 'RFC', 'tipo' => 'texto', 'max' => 13, 'ancho' => 130],
                'razon_social' => ['texto' => 'Razón social', 'tipo' => 'texto', 'max' => 160, 'ancho' => 240],
                'uso_cfdi'     => ['texto' => 'Uso CFDI', 'tipo' => 'opcion', 'opciones' => ['G01' => 'G01 Adquisición de mercancías', 'G03' => 'G03 Gastos en general', 'D01' => 'D01 Honorarios médicos', 'D10' => 'D10 Pagos por servicios educativos', 'P01' => 'P01 Por definir', 'S01' => 'S01 Sin efectos fiscales', 'CP01' => 'CP01 Pagos'], 'ancho' => 200],
                'metodo_pago'  => ['texto' => 'Método', 'tipo' => 'opcion', 'opciones' => ['PUE' => 'PUE Pago en una exhibición', 'PPD' => 'PPD Pago en parcialidades'], 'ancho' => 220],
                'forma_pago'   => ['texto' => 'Forma de pago', 'tipo' => 'opcion', 'opciones' => ['01' => '01 Efectivo', '02' => '02 Cheque', '03' => '03 Transferencia', '04' => '04 Tarjeta de crédito', '28' => '28 Tarjeta de débito', '99' => '99 Otro'], 'ancho' => 170],
                'total'        => ['texto' => 'Total', 'tipo' => 'numero', 'req' => true, 'min' => 0.01, 'max' => 9999999999.99, 'ancho' => 120],
                'estado'       => ['texto' => 'Estado', 'tipo' => 'opcion', 'opciones' => ['borrador' => 'Borrador', 'emitida' => 'Emitida', 'cancelada' => 'Cancelada'], 'solo_lectura' => true, 'ancho' => 120],
                'uuid'         => ['texto' => 'UUID fiscal', 'tipo' => 'texto', 'max' => 40, 'ancho' => 260],
                'notas'        => ['texto' => 'Notas', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240],
                'creado_en'    => ['texto' => 'Registrada', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 160],
            ],
            'vista' => [
                'grupos' => ['Factura' => 4, 'Pago' => 2, 'Fiscal' => 3, 'Registro' => 2],
                'columnas' => [
                    ['clave' => 'folio', 'titulo' => 'Folio', 'icono' => 'texto', 'ancho' => 130],
                    ['clave' => 'fecha', 'titulo' => 'Fecha', 'icono' => 'calendario', 'ancho' => 120],
                    ['clave' => 'rfc', 'titulo' => 'RFC', 'icono' => 'texto', 'ancho' => 130],
                    ['clave' => 'razon_social', 'titulo' => 'Razón social', 'icono' => 'texto', 'ancho' => 240],
                    ['clave' => 'total', 'titulo' => 'Total', 'icono' => 'numero', 'ancho' => 120],
                    ['clave' => 'estado', 'titulo' => 'Estado', 'icono' => 'seleccion', 'ancho' => 120],
                    ['clave' => 'metodo_pago', 'titulo' => 'Método', 'icono' => 'documento', 'ancho' => 220],
                    ['clave' => 'uso_cfdi', 'titulo' => 'Uso CFDI', 'icono' => 'texto', 'ancho' => 200],
                    ['clave' => 'uuid', 'titulo' => 'UUID fiscal', 'icono' => 'texto', 'ancho' => 260],
                    ['clave' => 'creado_en', 'titulo' => 'Registrada', 'icono' => 'calendario', 'ancho' => 160],
                ],
            ],
        ],

        // Presupuestos y recurrentes de finanzas (mismo motor, mismas tablas y componentes)
        'finanzas_presupuestos' => [
            'titulo'   => 'Presupuestos',
            'singular' => 'Presupuesto',
            'icono'    => 'documento',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_presupuestos', 'institucion' => true],
            'titulo_sql' => "CONCAT(tipo, ' · ', COALESCE(NULLIF(concepto, ''), 'total'))",
            'busqueda' => ['concepto', 'notas'],
            'orden'    => 'anio DESC, mes DESC, tipo, concepto',
            'campos'   => [
                'anio'      => ['texto' => 'Año', 'tipo' => 'numero', 'req' => true, 'entero' => true, 'min' => 1900, 'max' => 9999, 'ancho' => 90],
                'mes'       => ['texto' => 'Mes', 'tipo' => 'numero', 'req' => true, 'entero' => true, 'min' => 1, 'max' => 12, 'ancho' => 80],
                'tipo'      => ['texto' => 'Tipo', 'tipo' => 'opcion', 'req' => true, 'opciones' => ['ingreso' => 'Ingreso', 'gasto' => 'Gasto'], 'ancho' => 100],
                'concepto'  => ['texto' => 'Concepto', 'tipo' => 'texto', 'max' => 120, 'ancho' => 200],
                'monto'     => ['texto' => 'Presupuesto', 'tipo' => 'numero', 'req' => true, 'min' => 0, 'max' => 9999999999.99, 'ancho' => 130],
                'notas'     => ['texto' => 'Notas', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240],
                'creado_en' => ['texto' => 'Registrado', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 160],
            ],
            'vista' => [
                'grupos' => ['Periodo' => 2, 'Presupuesto' => 3, 'Registro' => 2],
                'columnas' => [
                    ['clave' => 'anio', 'titulo' => 'Año', 'icono' => 'numero', 'ancho' => 90],
                    ['clave' => 'mes', 'titulo' => 'Mes', 'icono' => 'numero', 'ancho' => 80],
                    ['clave' => 'tipo', 'titulo' => 'Tipo', 'icono' => 'seleccion', 'ancho' => 100],
                    ['clave' => 'concepto', 'titulo' => 'Concepto', 'icono' => 'texto', 'ancho' => 200],
                    ['clave' => 'monto', 'titulo' => 'Presupuesto', 'icono' => 'numero', 'ancho' => 130],
                    ['clave' => 'notas', 'titulo' => 'Notas', 'icono' => 'texto', 'ancho' => 240],
                    ['clave' => 'creado_en', 'titulo' => 'Registrado', 'icono' => 'calendario', 'ancho' => 160],
                ],
            ],
        ],
        'finanzas_recurrentes' => [
            'titulo'   => 'Recurrentes',
            'singular' => 'Recurrente',
            'icono'    => 'documento',
            'permiso'  => 'finanzas',
            'almacen'  => ['tipo' => 'tabla', 'tabla' => 'finanzas_recurrentes', 'institucion' => true],
            'titulo_sql' => 'concepto',
            'busqueda' => ['concepto', 'notas'],
            'orden'    => 'activo DESC, concepto',
            'campos'   => [
                'concepto'     => ['texto' => 'Concepto', 'tipo' => 'texto', 'req' => true, 'max' => 120, 'ancho' => 220],
                'tipo'         => ['texto' => 'Tipo', 'tipo' => 'opcion', 'req' => true, 'opciones' => ['ingreso' => 'Ingreso', 'gasto' => 'Gasto'], 'ancho' => 100],
                'monto'        => ['texto' => 'Monto', 'tipo' => 'numero', 'req' => true, 'ancho' => 120],
                'metodo'       => ['texto' => 'Método', 'tipo' => 'opcion', 'opciones' => ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'otro' => 'Otro'], 'ancho' => 130],
                'dia_del_mes'  => ['texto' => 'Día del mes', 'tipo' => 'numero', 'req' => true, 'entero' => true, 'min' => 1, 'max' => 31, 'ancho' => 110],
                'activo'       => ['texto' => 'Activo', 'tipo' => 'casilla', 'ancho' => 90],
                'notas'        => ['texto' => 'Notas', 'tipo' => 'texto', 'max' => 255, 'ancho' => 240],
                'creado_en'    => ['texto' => 'Registrado', 'tipo' => 'fecha_hora', 'solo_lectura' => true, 'ancho' => 160],
            ],
            'vista' => [
                'grupos' => ['Recurrente' => 5, 'Estado' => 1, 'Registro' => 2],
                'columnas' => [
                    ['clave' => 'concepto', 'titulo' => 'Concepto', 'icono' => 'texto', 'ancho' => 220],
                    ['clave' => 'tipo', 'titulo' => 'Tipo', 'icono' => 'seleccion', 'ancho' => 100],
                    ['clave' => 'monto', 'titulo' => 'Monto', 'icono' => 'numero', 'ancho' => 120],
                    ['clave' => 'metodo', 'titulo' => 'Método', 'icono' => 'documento', 'ancho' => 130],
                    ['clave' => 'dia_del_mes', 'titulo' => 'Día del mes', 'icono' => 'numero', 'ancho' => 110],
                    ['clave' => 'activo', 'titulo' => 'Activo', 'icono' => 'casilla', 'ancho' => 90],
                    ['clave' => 'notas', 'titulo' => 'Notas', 'icono' => 'texto', 'ancho' => 240],
                    ['clave' => 'creado_en', 'titulo' => 'Registrado', 'icono' => 'calendario', 'ancho' => 160],
                ],
            ],
        ],
    ];


    // Catálogos de referencia: se listan y editan con el mismo motor. Su diseño no se inventa:
    // se lee de la propia tabla (clave o código, nombre, descripción, activo y sus llaves).
    require_once __DIR__ . '/../../datos/catalogos_sistema.php';
    foreach (catalogosSistema() as $clave => $catalogo) {
        if (isset($entidades[$clave])) {
            continue;
        }
        $campos = camposCatalogoMotor((string) $catalogo['tabla']);
        $titulo = isset($campos['nombre']) ? 'c.nombre' : (isset($campos['valor']) ? 'c.valor' : (isset($campos['codigo']) ? 'c.codigo' : 'c.clave'));
        $entidades[$clave] = [
            'titulo'       => $catalogo['titulo'],
            'singular'     => $catalogo['titulo'],
            'icono'        => 'tabla',
            'permiso'      => 'ver_expediente',
            'almacen'      => ['tipo' => 'tabla', 'tabla' => $catalogo['tabla'], 'institucion' => !empty($catalogo['institucion'])],
            'desde'        => $catalogo['desde'],
            'busqueda_sql' => $catalogo['busqueda'],
            'orden'        => $catalogo['orden'],
            'columnas_sql' => $catalogo['columnas'],
            'titulo_sql'   => $titulo,
            'campos'       => $campos,
        ];
    }

    if (!$servicioMovimientoDisponible) {
        unset($entidades['finanzas_movimientos']['campos']['servicio_id']);
    }
    return $entidades;
}

/**
 * Campos capturables de una tabla de catálogo, leídos de su diseño real en la base.
 * Se omiten las columnas técnicas (id, institución, uuid, fechas) y se marca obligatorio
 * lo que la tabla no admite vacío (clave, código o nombre).
 */
function camposCatalogoMotor(string $tabla): array
{
    static $cache = [];
    if (isset($cache[$tabla])) {
        return $cache[$tabla];
    }
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $tabla)) {
        return $cache[$tabla] = [];
    }
    $etiquetas = [
        'codigo' => 'Código', 'clave' => 'Clave', 'nombre' => 'Nombre', 'descripcion' => 'Descripción', 'activo' => 'Activo',
        'valor' => 'Valor', 'simbolo' => 'Símbolo', 'familia' => 'Familia', 'nivel' => 'Nivel', 'tipo' => 'Tipo',
        'direccion' => 'Dirección', 'entidad_id' => 'Entidad', 'municipio_id' => 'Municipio', 'localidad_id' => 'Localidad',
        'unidad_id' => 'Unidad',
    ];
    $campos = [];
    try {
        $consulta = db()->prepare(
            "SELECT COLUMN_NAME nombre, DATA_TYPE tipo, CHARACTER_MAXIMUM_LENGTH largo, IS_NULLABLE nulo
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION"
        );
        $consulta->execute([$tabla]);
        foreach ($consulta->fetchAll() as $columna) {
            $nombre = (string) $columna['nombre'];
            if (in_array($nombre, ['id', 'institucion_id', 'uuid', 'creado_en', 'actualizado_en', 'sinc_modificado_en'], true)) {
                continue;
            }
            $tipo = 'texto';
            if ($columna['tipo'] === 'tinyint') {
                $tipo = 'casilla';
            } elseif (in_array($columna['tipo'], ['int', 'bigint', 'smallint', 'decimal', 'float', 'double'], true)) {
                $tipo = 'numero';
            } elseif (in_array($columna['tipo'], ['date', 'datetime', 'timestamp'], true)) {
                $tipo = 'fecha';
            }
            $definicion = ['texto' => $etiquetas[$nombre] ?? ucfirst(str_replace('_', ' ', $nombre)), 'tipo' => $tipo];
            if ($tipo === 'texto' && (int) $columna['largo'] > 0) {
                $definicion['max'] = (int) $columna['largo'];
            }
            if (in_array($nombre, ['clave', 'codigo', 'nombre'], true) && $columna['nulo'] === 'NO') {
                $definicion['req'] = true;
            }
            $campos[$nombre] = $definicion;
        }
    } catch (Throwable $e) {
        // Sin introspección la entidad solo se lista; no se rompe la pantalla
    }
    return $cache[$tabla] = $campos;
}

/** Definición de una entidad (con su clave incluida) o null. */
function entidadMotor(string $clave): ?array
{
    $entidades = entidadesMotor();
    return isset($entidades[$clave]) ? $entidades[$clave] + ['clave' => $clave] : null;
}

/** Campos capturables de una entidad (sin los de solo lectura). */
function camposMotor(string $clave): array
{
    $entidad = entidadMotor($clave);
    if (!$entidad) {
        return [];
    }
    return array_filter($entidad['campos'], fn ($c) => empty($c['solo_lectura']));
}

/** true si el campo existe en la entidad. */
function campoMotor(string $clave, string $campo): ?array
{
    $entidad = entidadMotor($clave);
    return $entidad['campos'][$campo] ?? null;
}
