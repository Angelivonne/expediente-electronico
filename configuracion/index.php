<?php
/**
 * Configuración: un solo lugar para todo lo que se ajusta, con navegación por grupos a la izquierda y filas
 * "título y descripción | control" a la derecha (como el resto de la app, estilo de la app).
 *   ?s=cuenta | notificaciones | memoria | clinica | personas | formatos | respaldos | correo | asistente
 * Las secciones grandes (personas, respaldos, formatos, correo) viven en su propia página y aquí se resumen
 * con su estado y el acceso directo; lo demás se edita aquí mismo.
 */
require_once __DIR__ . '/../app/nucleo/inicio.php';
require_once __DIR__ . '/../app/servicios/cuenta/instituciones.php';
require_once __DIR__ . '/../app/servicios/cuenta/cedula.php';
require_once __DIR__ . '/../app/servicios/cuenta/smtp.php';
require_once __DIR__ . '/../app/servicios/cuenta/preferencias.php';
require_once __DIR__ . '/../app/servicios/clinica/flujo.php';
require_once __DIR__ . '/../app/servicios/sistema/respaldos.php';

$usuario   = usuarioActual();
$usuarioId = (int) $usuario['id'];
$esAdmin   = puede('configurar');
$institucion = institucionActual();

require_once __DIR__ . '/../app/vistas/componentes/configuracion_marco.php';
// Secciones que se editan aquí mismo; Personas, Respaldos y Correo tienen su página dentro del mismo marco
$claves = array_values(array_intersect(array_column(seccionesConfiguracion(), 1), ['cuenta', 'apariencia', 'notificaciones', 'memoria', 'clinica', 'flujo', 'formatos', 'asistente', 'personas', 'respaldos', 'correo']));
$s = in_array($_GET['s'] ?? '', $claves, true) ? $_GET['s'] : 'cuenta';
$errores = [];

// Memoria de la IA: borrar un recuerdo o todos (solo los propios)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $s === 'memoria') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        if (isset($_POST['olvidar_todo'])) {
            db()->prepare('DELETE FROM asistente_memoria WHERE usuario_id = ?')->execute([$usuarioId]);
            mensajeFlash('exito', 'La IA olvidó todo lo que tenía en memoria.');
        } else {
            db()->prepare('DELETE FROM asistente_memoria WHERE id = ? AND usuario_id = ?')->execute([(int) ($_POST['olvidar'] ?? 0), $usuarioId]);
            mensajeFlash('exito', 'Recuerdo borrado.');
        }
        redirigir('/configuracion/?s=memoria');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $s === 'apariencia') {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        try {
            guardarPreferenciasVisuales($usuarioId, $_POST);
            mensajeFlash('exito', 'Preferencias de apariencia guardadas.');
            redirigir('/configuracion/?s=apariencia');
        } catch (InvalidArgumentException $e) {
            $errores['general'] = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $s === 'flujo' && $esAdmin) {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        try {
            $etapas = leerEtapasFlujoTexto(is_string($_POST['etapas'] ?? null) ? $_POST['etapas'] : '');
            guardarConfig('flujo_etapas', json_encode($etapas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            mensajeFlash('exito', 'Etapas de atención guardadas.');
            redirigir('/configuracion/?s=flujo');
        } catch (InvalidArgumentException $e) {
            $errores['general'] = $e->getMessage();
        }
    }
}

// Guardar los datos de la clínica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $s === 'clinica' && $esAdmin && $institucion) {
    if (!csrfValido()) {
        $errores['general'] = 'La sesión expiró. Vuelve a intentarlo.';
    } else {
        $resultado = actualizarInstitucion($_POST, (int) $institucion['id'], $usuarioId);
        if (!empty($resultado['ok'])) {
            mensajeFlash('exito', 'Datos de la clínica guardados.');
            redirigir('/configuracion/?s=clinica');
        }
        $errores = $resultado['errores'];
    }
}

$c = db()->prepare('SELECT id, nombre, foto, correo, rol, cedula_profesional, cedula_estado, especialidad, ultimo_acceso FROM usuarios WHERE id = ?');
$c->execute([$usuarioId]);
$cuenta = $c->fetch();

$tituloPagina   = 'Configuración';
$seccionActiva  = 'configuracion';
$clavePresencia = 'configuracion:' . $s;
$migas          = [['texto' => 'Configuración']];
$claseContenido = 'pagina--configuracion';
$mostrarBanner  = false;
$notificacion   = $errores ? ['tipo' => 'error', 'texto' => reset($errores)] : tomarFlash();
$fila = fn (string $titulo, string $descripcion, string $control) =>
    '<div class="conf-fila" data-ajuste><div class="conf-fila__texto"><div class="conf-fila__titulo">' . e($titulo) . '</div><div class="conf-fila__descripcion">' . e($descripcion) . '</div></div><div class="conf-fila__control">' . $control . '</div></div>';
$enlace = fn (string $ruta, string $texto = 'Abrir') => '<a class="boton boton--secundario" href="' . URL_BASE . $ruta . '">' . e($texto) . '</a>';
require __DIR__ . '/../app/vistas/plantilla/encabezado.php';
?>

<?php abrirMarcoConfiguracion($s); ?>
  <?php if ($s === 'cuenta'): ?>
    <h1 class="configuracion__titulo">Mi cuenta</h1>
    <p class="configuracion__subtitulo">Tus datos de acceso y la seguridad de tu cuenta</p>
    <h2 class="configuracion__seccion">Datos</h2>
    <?= $fila('Foto de perfil', 'JPG, PNG o WebP · máximo 5 MB. Se utiliza en las vistas del equipo.', '<label class="cuenta__foto-control">' . avatar((int) $cuenta['id'], (string) $cuenta['nombre'], $cuenta['foto'] ?: null, 'cuenta__avatar') . '<span>Cambiar foto</span><input type="file" accept="image/jpeg,image/png,image/webp" data-foto-perfil hidden></label>') ?>
    <?= $fila('Correo', 'Con él inicias sesión y recibes los códigos de verificación.', '<span class="conf-fila__valor">' . e($cuenta['correo']) . '</span>') ?>
    <?= $fila('Rol', ROLES[$cuenta['rol']]['descripcion'] ?? '', '<span class="conf-fila__valor">' . e(ROLES[$cuenta['rol']]['nombre'] ?? ucfirst($cuenta['rol'])) . '</span>') ?>
    <?php if ($cuenta['cedula_profesional']): ?>
      <?= $fila('Cédula profesional', CEDULA_ESTADOS[$cuenta['cedula_estado'] ?? 'pendiente'] ?? '', '<span class="conf-fila__valor">' . e($cuenta['cedula_profesional']) . '</span>') ?>
    <?php endif; ?>
    <h2 class="configuracion__seccion">Seguridad</h2>
    <?= $fila('Contraseña', 'Al cambiarla se cierran tus sesiones en otros equipos.', $enlace('/cuenta/', 'Cambiar contraseña')) ?>
    <form method="post" action="<?= URL_BASE ?>/cuenta/" class="conf-formulario"><?= campoCsrf() ?><input type="hidden" name="accion" value="sesiones">
      <?= $fila('Sesiones abiertas', 'Cierra la sesión en los equipos que ya no usas. Esta sesión sigue abierta.', '<button type="submit" class="boton boton--secundario">Cerrar las demás</button>') ?>
    </form>
    <?= $fila('Último acceso', 'Fecha y hora de tu último inicio de sesión.', '<span class="conf-fila__valor">' . ($cuenta['ultimo_acceso'] ? e(fechaCorta($cuenta['ultimo_acceso'])) . ' · ' . e(substr($cuenta['ultimo_acceso'], 11, 5)) : '—') . '</span>') ?>

  <?php elseif ($s === 'notificaciones'): ?>
    <h1 class="configuracion__titulo">Notificaciones</h1>
    <p class="configuracion__subtitulo">Qué avisos recibes y dónde</p>
    <?= $fila('Aura de citas', 'Un borde de luz delgado alrededor de la pantalla cuando se acerca un paciente que te toca atender: aqua a 30 min, ámbar a 10 min y rojo cuando ya empezó o es urgente. Se guarda en este navegador.', '<button type="button" class="cal-opcion conf-interruptor" role="switch" aria-checked="true" data-aura-alternar aria-label="Aura de citas"><i class="interruptor" aria-hidden="true"></i></button>') ?>
    <div data-push-ajustes-pagina></div>

  <?php elseif ($s === 'apariencia'): ?>
    <?php $apariencia = preferenciasVisuales($usuarioId); ?>
    <h1 class="configuracion__titulo">Apariencia</h1>
    <p class="configuracion__subtitulo">Ajusta el contraste y la densidad de la interfaz para tu cuenta. Se aplica en tus dispositivos.</p>
    <form method="post" class="conf-formulario">
      <?= campoCsrf() ?>
      <?php
        $selectApariencia = static function (string $campo, array $opciones, string $actual): string {
            $html = '<select class="conf-fila__selector" name="' . e($campo) . '">';
            foreach ($opciones as $valor => $etiqueta) {
                $html .= '<option value="' . e($valor) . '"' . ($actual === $valor ? ' selected' : '') . '>' . e($etiqueta) . '</option>';
            }
            return $html . '</select>';
        };
      ?>
      <?= $fila('Tema', 'Oscuro, claro o seguir el ajuste del sistema operativo.', $selectApariencia('tema', ['oscuro' => 'Oscuro', 'claro' => 'Claro', 'sistema' => 'Según mi dispositivo'], (string) $apariencia['tema'])) ?>
      <?= $fila('Densidad', 'La vista compacta muestra más filas; la cómoda facilita la lectura.', $selectApariencia('densidad', ['comoda' => 'Cómoda', 'compacta' => 'Compacta'], (string) $apariencia['densidad'])) ?>
      <?= $fila('Tamaño del texto', 'Aumenta la tipografía de la interfaz sin cambiar los datos clínicos.', $selectApariencia('tamano_texto', ['normal' => 'Normal', 'grande' => 'Grande'], (string) $apariencia['tamano_texto'])) ?>
      <div class="conf-formulario__acciones"><button class="boton boton--primario" type="submit">Guardar preferencias</button></div>
    </form>

  <?php elseif ($s === 'flujo' && $esAdmin): ?>
    <?php $etapasFlujoActuales = etapasFlujo(); ?>
    <h1 class="configuracion__titulo">Etapas de atención</h1>
    <p class="configuracion__subtitulo">Define las columnas del tablero operativo de tu clínica. Los movimientos ya registrados se conservan; no puedes quitar una etapa que tenga pacientes activos.</p>
    <form method="post" class="conf-formulario">
      <?= campoCsrf() ?>
      <label class="configuracion__seccion" for="flujo-etapas">Una etapa por línea · clave|nombre</label>
      <textarea id="flujo-etapas" name="etapas" class="conf-fila__entrada--ancha" rows="12" spellcheck="false"><?= e(isset($_POST['etapas']) && is_string($_POST['etapas']) ? $_POST['etapas'] : textoEtapasFlujo($etapasFlujoActuales)) ?></textarea>
      <p class="pagina__descripcion">La clave es estable y no lleva espacios (por ejemplo, <code>observacion</code>). Conserva <code>registro</code> como primera etapa y <code>egreso</code> como última. “Hospitalización”, “Cirugía” y “Recuperación” son estados de seguimiento; la documentación clínica y la asignación de camas siguen en sus módulos especializados.</p>
      <div class="conf-formulario__acciones"><button class="boton boton--primario" type="submit">Guardar etapas</button></div>
    </form>

  <?php elseif ($s === 'clinica'): ?>
    <h1 class="configuracion__titulo">General</h1>
    <p class="configuracion__subtitulo">Datos de la clínica: aparecen en el aviso de privacidad, los documentos impresos y las recetas</p>
    <?php if (!$institucion): ?>
      <?= $fila('Clínica', 'Tu cuenta aún no tiene una clínica registrada.', $enlace('/institucion/', 'Registrar clínica')) ?>
    <?php else: $v = fn ($c) => e((string) ($_POST[$c] ?? $institucion[$c] ?? '')); ?>
      <form method="post" class="conf-formulario" novalidate>
        <?= campoCsrf() ?>
        <h2 class="configuracion__seccion">Identificación</h2>
        <?= $fila('Nombre', 'Como aparece en el encabezado de los documentos.', '<input class="conf-fila__entrada" name="nombre" value="' . $v('nombre') . '" maxlength="160" required>') ?>
        <?php $opciones = ''; foreach (TIPOS_INSTITUCION as $k => $t) { $opciones .= '<option value="' . $k . '"' . (($institucion['tipo'] ?? '') === $k ? ' selected' : '') . '>' . e(is_array($t) ? ($t['nombre'] ?? $k) : $t) . '</option>'; } ?>
        <?= $fila('Tipo', 'Consultorio, clínica u hospital.', '<select name="tipo" class="conf-fila__selector">' . $opciones . '</select>') ?>
        <?= $fila('CLUES', 'Clave Única de Establecimientos de Salud (11 caracteres), si la tiene.', '<input class="conf-fila__entrada" name="clues" value="' . $v('clues') . '" maxlength="11" spellcheck="false">') ?>
        <?= $fila('RFC', 'Para los recibos de cobro, si factura.', '<input class="conf-fila__entrada" name="rfc" value="' . $v('rfc') . '" maxlength="13" spellcheck="false">') ?>
        <?= $fila('Razón social', 'Denominación del propietario, cuando hay (NOM-004).', '<input class="conf-fila__entrada conf-fila__entrada--ancha" name="razon_social" value="' . $v('razon_social') . '" maxlength="160">') ?>
        <?php $logoActual = logoInstitucionUrl(); ?>
        <?= $fila('Logo', 'Se muestra en el encabezado de las notas y de los documentos impresos.', '<div class="conf-logo" data-logo-clinica>'
            . '<span class="conf-logo__vista">' . ($logoActual ? '<img src="' . e($logoActual) . '" alt="Logo de la clínica">' : '<span class="conf-logo__vacio">Sin logo</span>') . '</span>'
            . '<span class="conf-logo__acciones">'
            . '<button type="button" class="boton boton--secundario" data-logo-elegir>' . ($logoActual ? 'Cambiar logo' : 'Subir logo') . '</button>'
            . '<button type="button" class="boton boton--fantasma" data-logo-quitar' . ($logoActual ? '' : ' hidden') . '>Quitar</button>'
            . '</span>'
            . '<input type="file" accept="image/png,image/jpeg,image/webp" hidden data-logo-archivo>'
            . '</div>') ?>
        <h2 class="configuracion__seccion">Ubicación</h2>
        <?= $fila('Domicilio', 'Calle, número, colonia y municipio.', '<input class="conf-fila__entrada conf-fila__entrada--ancha" name="domicilio" value="' . $v('domicilio') . '" maxlength="250" required>') ?>
        <?php $opcionesE = ''; foreach (ENTIDADES as $k => $t) { if ($k === 'NE') continue; $opcionesE .= '<option value="' . $k . '"' . (($institucion['entidad'] ?? '') === $k ? ' selected' : '') . '>' . e($t) . '</option>'; } ?>
        <?= $fila('Entidad federativa', 'Estado donde está la clínica.', '<select name="entidad" class="conf-fila__selector">' . $opcionesE . '</select>') ?>
        <?= $fila('Teléfono', 'Aparece en el aviso de privacidad para ejercer los derechos ARCO.', '<input class="conf-fila__entrada" name="telefono" value="' . $v('telefono') . '" maxlength="30" inputmode="tel">') ?>
        <div class="configuracion__acciones"><button type="submit" class="boton boton--secundario">Guardar cambios</button></div>
      </form>
    <?php endif; ?>

  <?php elseif ($s === 'personas'): ?>
    <h1 class="configuracion__titulo">Personas y permisos</h1>
    <p class="configuracion__subtitulo">Quién trabaja en la clínica y qué puede hacer cada rol</p>
    <?php $n = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE institucion_id <=> ? AND activo = 1 AND rol <> \'paciente\''); $n->execute([institucionId()]); ?>
    <?= $fila('Personal', $n->fetchColumn() . ' persona(s) con cuenta activa. Invita, cambia roles o desactiva cuentas.', $enlace('/configuracion/personas.php', 'Administrar')) ?>
    <?= $fila('Códigos de acceso', 'Genera códigos para que alguien cree su cuenta con un rol ya asignado.', $enlace('/configuracion/personas.php#codigos', 'Ver códigos')) ?>
    <?= $fila('Permisos por rol', 'Qué puede ver y hacer médico, enfermería y recepción.', $enlace('/configuracion/personas.php#permisos', 'Editar permisos')) ?>

  <?php elseif ($s === 'formatos'): ?>
    <h1 class="configuracion__titulo">Importar y formatos</h1>
    <p class="configuracion__subtitulo">Traer bases de datos de otros sistemas y formatos institucionales (IMSS, DGIS)</p>
    <?= $fila('Importar', 'CSV, Excel, SQL, HL7 y FHIR. Las columnas se acomodan solas.', $enlace('/datos/', 'Importar')) ?>
    <?= $fila('Exportar', 'Pacientes o bases de datos en el formato que necesites.', $enlace('/datos/#exportar', 'Exportar')) ?>
    <?= $fila('Perfiles de formato', 'Cómo se lee y escribe el formato de cada institución; edítalos si cambian, sin tocar el código.', $enlace('/datos/#formatos', 'Ver perfiles')) ?>
    <?= $fila('Historial de conversiones', 'Registro de cada importación y exportación (NOM-024).', $enlace('/datos/#historial', 'Ver historial')) ?>

  <?php elseif ($s === 'respaldos'): $ultimo = fechaUltimoRespaldo(); ?>
    <h1 class="configuracion__titulo">Respaldos</h1>
    <p class="configuracion__subtitulo">Copias diarias de la base de datos para no perder información</p>
    <?= $fila('Último respaldo', 'Se hace solo cada día; también puedes respaldar cuando quieras.', '<span class="conf-fila__valor">' . ($ultimo ? e(fechaCorta($ultimo)) : 'Aún no hay') . '</span>') ?>
    <?= $fila('Respaldos e integridad', 'Descarga, verifica o restaura un respaldo y revisa la integridad de las tablas.', $enlace('/configuracion/respaldos.php', 'Administrar')) ?>

  <?php elseif ($s === 'correo'): $smtp = configuracionSmtp(); ?>
    <h1 class="configuracion__titulo">Correo</h1>
    <p class="configuracion__subtitulo">Correo saliente para códigos, recuperación de contraseña, invitaciones y recordatorios</p>
    <?= $fila('Servidor de correo', $smtp ? 'Activo: los correos salen por ' . $smtp['servidor'] . '.' : 'Sin configurar: los correos se guardan como archivos en el servidor y no llegan a nadie.', $enlace('/configuracion/correo.php', $smtp ? 'Cambiar' : 'Configurar')) ?>

  <?php elseif ($s === 'memoria'): require_once __DIR__ . '/../app/servicios/ia/herramientas_extra.php'; $recuerdos = memoriaAsistente($usuarioId); ?>
    <h1 class="configuracion__titulo">Memoria de la IA</h1>
    <p class="configuracion__subtitulo">Lo que el asistente recuerda de ti entre conversaciones. Pídele «recuerda que…» y lo guardará; solo tú lo ves.</p>
    <?php if (!$recuerdos): ?>
      <div class="seccion__vacio">Aún no recuerda nada. Prueba diciéndole: «Recuerda que mis notas de evolución van en formato SOAP».</div>
    <?php else: ?>
      <?php foreach ($recuerdos as $r): ?>
        <?= $fila($r['texto'], 'Desde el ' . fechaCorta($r['fecha']), '<form method="post">' . campoCsrf() . '<button type="submit" class="boton boton--secundario" name="olvidar" value="' . (int) $r['id'] . '">Olvidar</button></form>') ?>
      <?php endforeach; ?>
      <form method="post" class="conf-fila__pie"><?= campoCsrf() ?><button type="submit" class="boton boton--peligro" name="olvidar_todo" value="1" data-confirmar="¿Borrar todo lo que recuerda la IA?">Olvidar todo</button></form>
    <?php endif; ?>

  <?php elseif ($s === 'asistente'): ?>
    <h1 class="configuracion__titulo">Asistente de IA y sincronización</h1>
    <p class="configuracion__subtitulo">Proveedor y modelo del asistente, y envío al servidor central</p>
    <?= $fila('Asistente de IA', 'Proveedor, clave de acceso, modelo y comportamiento del asistente.', '<button type="button" class="boton boton--secundario" data-abrir-ajustes-ia>Abrir ajustes</button>') ?>
    <?= $fila('Sincronización', 'Dirección del servidor central, token y estado del envío. Está en los mismos ajustes.', '<button type="button" class="boton boton--secundario" data-abrir-ajustes-ia>Abrir ajustes</button>') ?>
  <?php endif; ?>
<?php cerrarMarcoConfiguracion(); ?>

<?php require __DIR__ . '/../app/vistas/plantilla/pie.php'; ?>
