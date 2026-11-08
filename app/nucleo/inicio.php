<?php
/**
 * Arranque común: cada página lo incluye antes que cualquier otra cosa.
 *
 * Antes de incluirlo, una página puede definir:
 *   PAGINA_PUBLICA  true para no exigir sesión (pantallas de acceso).
 *   RESPUESTA_JSON  true si responde JSON (sin sesión devuelve 401 en lugar de redirigir).
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/seguridad.php';

// configurarErrores();
enviarCabecerasSeguridad();
header('Content-Type: text/html; charset=UTF-8');
// Forzar encoding UTF-8 en toda la aplicación
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/../vistas/plantilla/iconos.php';
require_once __DIR__ . '/../datos/catalogos.php';
require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/institucion.php';

iniciarSesionSegura();

// Acceso directo (config ACCESO_AUTOMATICO): entra como administrador sin clave ni código de verificación.
// Al terminar la sesión por inactividad vuelve a entrar sola, así que nunca verás la pantalla de acceso.
if (!usuarioActual() && (defined('ACCESO_AUTOMATICO') && ACCESO_AUTOMATICO) && !defined('PAGINA_PUBLICA')) {
    $auto = db()->prepare("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 AND rol = 'administrador' ORDER BY id LIMIT 1");
    $auto->execute();
    if ($cuentaAuto = $auto->fetch()) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['usuario']    = ['id' => (int) $cuentaAuto['id'], 'nombre' => $cuentaAuto['nombre'], 'rol' => $cuentaAuto['rol']];
        $_SESSION['inicio']     = time();
        $_SESSION['regenerada'] = time();
        $_SESSION['ultimo_uso'] = time();
        db()->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?')->execute([(int) $cuentaAuto['id']]);
    }
}

// En cada petición se confirma que la cuenta sigue activa y se toma su rol actual:
// si el administrador la desactiva o le cambia el rol, la sesión abierta lo refleja de inmediato.
if (usuarioActual()) {
    $vigente = db()->prepare('SELECT nombre, rol, foto, paciente_id, institucion_id FROM usuarios WHERE id = ? AND activo = 1');
    $vigente->execute([usuarioActual()['id']]);
    $cuenta = $vigente->fetch();
    if (!$cuenta) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['usuario']['rol']    = $cuenta['rol'];
        $_SESSION['usuario']['nombre'] = $cuenta['nombre'];
        $_SESSION['usuario']['foto']   = $cuenta['foto'];
        $_SESSION['usuario']['paciente_id'] = $cuenta['paciente_id'] !== null ? (int) $cuenta['paciente_id'] : null;
        $_SESSION['usuario']['institucion_id'] = $cuenta['institucion_id'] !== null ? (int) $cuenta['institucion_id'] : null;
    }
}

// Una cuenta de paciente solo abre su portal y su propio expediente (seguridad.php)
restringirPortalPaciente();

// Control de acceso (NOM-024 6.6.3): todo el expediente requiere sesión iniciada.
if (!defined('PAGINA_PUBLICA') && !usuarioActual()) {
    if (defined('RESPUESTA_JSON')) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Tu sesión terminó. Inicia sesión de nuevo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirigir('/acceso/');
}

// Respaldo diario perezoso: si hoy no hay respaldo, se lanza en segundo plano (app/servicios/sistema/respaldos.php)
require_once __DIR__ . '/../servicios/sistema/respaldos.php';
if (usuarioActual()) {
    programarRespaldoPerezoso();
}

// Varias instituciones: sin institución solo se abre el asistente "Registra tu clínica" (institucion.php)
exigirInstitucion();

// Durabilidad: los POST JSON con clave X-Idempotencia no se aplican dos veces (cola sin conexión del navegador)
require_once __DIR__ . '/idempotencia.php';
if (defined('RESPUESTA_JSON') && usuarioActual() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_SERVER['HTTP_X_IDEMPOTENCIA'])) {
    atenderIdempotencia();
}


