<?php
/**
 * Funciones de uso general.
 */

/** Escapa texto para imprimirlo en HTML. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---------- Anti-corrupción de texto (mojibake) ----------
   Cuando un dato entra por una ruta que interpreta el UTF-8 como Latin-1 y lo vuelve a guardar
   como UTF-8, «í» queda como «Ã­», «á» como «Ã¡», «°» como «Â°», etc. sanoTexto() lo detecta y
   lo devuelve en su forma correcta; si el texto está sano no lo toca. */
const MOJIBAKE_MAPA = [
    'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã¼' => 'ü', 'Ã±' => 'ñ',
    'Ã ' => 'à', 'Ã¨' => 'è', 'Ã¬' => 'ì', 'Ã²' => 'ò', 'Ã¹' => 'ù', 'Ã§' => 'ç', 'Ã‡' => 'Ç',
    'Ã¢' => 'â', 'Ãª' => 'ê', 'Ã´' => 'ô', 'Ã£' => 'ã', 'Ãµ' => 'õ', 'Ã¤' => 'ä', 'Ã¶' => 'ö',
    'Ã‰' => 'É', 'Ã“' => 'Ó', 'Ãš' => 'Ú', 'Ã‘' => 'Ñ', 'Ãœ' => 'Ü', 'Ã…' => 'Å', 'Ãˆ' => 'È',
    'ÃŒ' => 'Ì', 'ÃŠ' => 'Ê', 'Ã”' => 'Ô',
    'Â¡' => '¡', 'Â¿' => '¿', 'Â«' => '«', 'Â»' => '»', 'Âº' => 'º', 'Âª' => 'ª', 'Â°' => '°', 'Â·' => '·',
    'Â—' => '—', 'Â–' => '–', 'Â‘' => '‘', 'Â’' => '’', 'Â“' => '“', 'Â”' => '”', 'Ã—' => '×', 'Ã·' => '÷',
    // Triple codificación típica de textos que pasaron por Windows-1252 dos veces:
    'Ã¢â‚¬â„¢' => '’', 'Ã¢â‚¬Å“' => '“', 'Ã¢â‚¬Â' => '”', 'Ã¢â‚¬â€œ' => '“', 'Ã¢â‚¬â€' => '—',
    'Ã¢â‚¬Ëœ' => '‘', 'Ã¢â‚¬Â¦' => '…', 'ÃƒÂ±' => 'ñ', 'ÃƒÂ©' => 'é', 'ÃƒÂ­' => 'í', 'ÃƒÂ³' => 'ó', 'ÃƒÂº' => 'ú', 'ÃƒÂ¡' => 'á',
];

/** true si el texto trae secuencias típicas de UTF-8 doble codificado («Ã­», «Â°»…). */
function textoConMojibake(string $texto): bool
{
    return (bool) preg_match('/Ã|Â[\x80-\xBF]/u', $texto);
}

/** Devuelve el texto sin mojibake; si está sano lo devuelve tal cual (nunca inventa cambios). */
function sanoTexto(string $texto): string
{
    if ($texto === '' || !textoConMojibake($texto)) {
        return $texto;
    }
    // 1) Si TODO el texto es UTF-8 doble codificado, la conversión completa lo arregla de una vez
    //    (incluye mayúsculas acentuadas y caracteres invisibles).
    $reparado = @iconv('UTF-8', 'ISO-8859-1', $texto);
    if ($reparado !== false && $reparado !== '' && mb_check_encoding($reparado, 'UTF-8') && !textoConMojibake($reparado)) {
        return $reparado;
    }
    // 2) Hay texto sano mezclado (comillas, guiones): solo sustituyo los pares artefacto conocidos.
    for ($i = 0; $i < 3; $i++) {
        $reparado = strtr($texto, MOJIBAKE_MAPA);
        if ($reparado === $texto) {
            break;
        }
        $texto = $reparado;
    }
    return $texto;
}

/** Aplica sanoTexto a todos los textos de una estructura (arreglos anidados). */
function saneaProfundo(mixed $valor): mixed
{
    if (is_string($valor)) {
        return sanoTexto($valor);
    }
    if (is_array($valor)) {
        $salida = [];
        foreach ($valor as $k => $v) {
            $salida[is_string($k) ? sanoTexto($k) : $k] = saneaProfundo($v);
        }
        return $salida;
    }
    return $valor;
}

/** URL de un archivo de assets con su versión (fecha de modificación) para que el navegador no use una copia vieja. */
function recurso(string $ruta): string
{
    $archivo = __DIR__ . "/../../assets/" . $ruta;
    return URL_BASE . "/assets/" . $ruta . (is_file($archivo) ? "?v=" . filemtime($archivo) : "");
}

function redirigir(string $ruta): never
{
    header('Location: ' . URL_BASE . $ruta);
    exit;
}

/* ---------- Archivos: escritura atómica (sin archivos a medias tras un apagón) ---------- */
/**
 * Escribe un archivo completo o no lo toca: primero un temporal en la misma carpeta (con fsync para que
 * llegue al disco) y luego rename, que en NTFS reemplaza el destino de una sola vez. Si se va la luz a la
 * mitad, queda el archivo anterior intacto (o ninguno), nunca uno truncado.
 * $soloSiNoExiste evita pisar uno que otra petición acaba de crear (p. ej. la llave de cifrado).
 */
function escribirArchivoAtomico(string $ruta, string $contenido, bool $soloSiNoExiste = false): bool
{
    $carpeta = dirname($ruta);
    if (!is_dir($carpeta) && !@mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
        return false;
    }
    $temporal = $carpeta . '/.' . basename($ruta) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $f = @fopen($temporal, 'xb');
    if (!$f) {
        return false;
    }
    $escrito = fwrite($f, $contenido);
    $ok = $escrito === strlen($contenido) && fflush($f) && fsync($f);
    fclose($f);
    if ($ok && $soloSiNoExiste) {
        // link() falla si el destino ya existe: así dos peticiones simultáneas no se pisan
        $ok = @link($temporal, $ruta);
        @unlink($temporal);
        return $ok || is_file($ruta);
    }
    // En Windows rename falla si otro proceso tiene abierto el destino en ese instante: se reintenta
    for ($intento = 0; $ok && $intento < 5; $intento++) {
        if (@rename($temporal, $ruta)) {
            return true;
        }
        usleep(40000);
    }
    @unlink($temporal);
    return false;
}

/** Guarda un archivo subido (move_uploaded_file no hace fsync) con escritura atómica. */
function guardarSubida(string $temporalPhp, string $destino): bool
{
    if (!is_uploaded_file($temporalPhp)) {
        return false;
    }
    $contenido = file_get_contents($temporalPhp);
    return $contenido !== false && escribirArchivoAtomico($destino, $contenido);
}

/* ---------- CSRF ---------- */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function campoCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . tokenCsrf() . '">';
}

function csrfValido(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/* ---------- Formularios: un solo envío (doble clic, reenvío del navegador o reintento tras un corte) ---------- */
function campoEnvio(): string
{
    return '<input type="hidden" name="clave_envio" value="' . bin2hex(random_bytes(16)) . '">';
}

/** Si este envío ya se guardó, la dirección a la que se mandó entonces (para no guardarlo dos veces). */
function envioYaProcesado(): ?string
{
    $clave = $_POST['clave_envio'] ?? '';
    return is_string($clave) && preg_match('/^[0-9a-f]{32}$/', $clave) ? ($_SESSION['envios'][$clave] ?? null) : null;
}

function recordarEnvio(string $destino): void
{
    $clave = $_POST['clave_envio'] ?? '';
    if (is_string($clave) && preg_match('/^[0-9a-f]{32}$/', $clave)) {
        $_SESSION['envios'] = array_slice(($_SESSION['envios'] ?? []) + [$clave => $destino], -20, null, true);
    }
}

/* ---------- Sesión ---------- */
/** Usuario con sesión iniciada: ['id', 'nombre', 'rol'] o null. */
function usuarioActual(): ?array
{
    // Una sesión dañada (valor que no es arreglo) cuenta como sin sesión en lugar de tumbar la página
    return is_array($_SESSION['usuario'] ?? null) ? $_SESSION['usuario'] : null;
}

/* ---------- Bitácora de auditoría (NOM-024: trazabilidad) ---------- */
/**
 * $institucionId: institución del movimiento; por defecto la del usuario con sesión
 * (en el registro con código aún no hay sesión y se pasa la del código).
 */
function registrarBitacora(string $tabla, int $registroId, string $accion,
                           ?string $campo = null, ?string $anterior = null, ?string $nuevo = null,
                           ?int $institucionId = null): void
{
    db()->prepare(
        'INSERT INTO bitacora (tabla, registro_id, accion, campo, valor_anterior, valor_nuevo, usuario_id, institucion_id, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $tabla, $registroId, $accion, $campo, $anterior, $nuevo,
        usuarioActual()['id'] ?? null,
        $institucionId ?? (function_exists('institucionId') ? institucionId() : null),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

/* ---------- Mensajes entre redirecciones ---------- */
function mensajeFlash(string $tipo, string $texto): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
}

function tomarFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------- CURP (RENAPO) ---------- */
function curpValida(string $curp): bool
{
    $patron = '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HMX]'
            . '(AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)'
            . '[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/';
    if (!preg_match($patron, $curp)) {
        return false;
    }

    $diccionario = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
    $suma = 0;
    for ($i = 0; $i < 17; $i++) {
        $suma += mb_strpos($diccionario, $curp[$i]) * (18 - $i);
    }
    return (10 - $suma % 10) % 10 === (int) $curp[17];
}

/** Fecha en formato corto en español: "27 sep 2026". */
function fechaCorta(string $fecha): string
{
    static $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $f = new DateTime($fecha);
    return $f->format('j') . ' ' . $meses[(int) $f->format('n') - 1] . ' ' . $f->format('Y');
}

/** Hora para personas, formato de 12 horas con a.m./p.m.: "10:30 a.m.", "1:05 p.m.", "12:40 a.m." (medianoche). */
function hora12(DateTimeInterface|string $momento): string
{
    $f = $momento instanceof DateTimeInterface ? DateTime::createFromInterface($momento) : new DateTime($momento);
    $h = (int) $f->format('G');
    $sufijo = $h < 12 ? 'a.m.' : 'p.m.';
    if ($h === 0) {
        $h = 12;
    } elseif ($h > 12) {
        $h -= 12;
    }
    return $h . ':' . $f->format('i') . ' ' . $sufijo;
}

/** Tiempo transcurrido en español: "hace 5 min", "hace 2 d"; después de un mes, la fecha corta. */
function hace(string $fecha): string
{
    $segundos = time() - strtotime($fecha);
    return match (true) {
        $segundos < 60      => 'hace un momento',
        $segundos < 3600    => 'hace ' . intdiv($segundos, 60) . ' min',
        $segundos < 86400   => 'hace ' . intdiv($segundos, 3600) . ' h',
        $segundos < 604800  => 'hace ' . intdiv($segundos, 86400) . ' d',
        $segundos < 2592000 => 'hace ' . intdiv($segundos, 604800) . ' sem',
        default             => fechaCorta($fecha),
    };
}

/** Edad cumplida en años a partir de una fecha Y-m-d. */
function edad(string $fechaNacimiento): int
{
    return (new DateTime($fechaNacimiento))->diff(new DateTime('today'))->y;
}

/** Patrón para LIKE "contiene": escapa los comodines % y _ que escriba el usuario. */
function patronContiene(string $texto): string
{
    return '%' . addcslashes(mb_substr($texto, 0, 100), '\%_') . '%';
}

/** Separa cada palabra de una consulta sin depender de mayusculas ni puntuacion. */
function terminosBusqueda(string $texto): array
{
    $texto = mb_strtolower(mb_substr($texto, 0, 200), 'UTF-8');
    $texto = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto) ?? '';
    $terminos = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_unique($terminos)), 0, 12);
}

/** Condicion SQL segura: cada termino debe aparecer en alguno de los campos. */
function condicionBusqueda(array $campos, string $texto): array
{
    $terminos = terminosBusqueda($texto);
    if (!$campos || !$terminos) {
        return ['1 = 0', []];
    }

    $grupos = [];
    $parametros = [];
    foreach ($terminos as $termino) {
        $alternativas = [];
        foreach ($campos as $campo) {
            $campo = (string) $campo;
            if (!preg_match('/^[A-Za-z0-9_`().,\' ]{1,160}$/', $campo)) {
                continue;
            }
            $alternativas[] = '(' . $campo . ') LIKE ?';
            $parametros[] = patronContiene($termino);
        }
        if ($alternativas) {
            $grupos[] = '(' . implode(' OR ', $alternativas) . ')';
        }
    }

    return $grupos ? ['(' . implode(' AND ', $grupos) . ')', $parametros] : ['1 = 0', []];
}

/** Normaliza texto local para filtrar resultados que ya estan en memoria. */
function normalizarTextoBuscable(string $texto): string
{
    if (class_exists(\Normalizer::class)) {
        $descompuesto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        if ($descompuesto !== false) {
            $texto = $descompuesto;
        }
    } elseif (function_exists('iconv')) {
        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($transliterado !== false) {
            $texto = $transliterado;
        }
    }
    $texto = preg_replace('/\p{M}+/u', '', $texto) ?? $texto;
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
}

function coincidenTerminosBusqueda(string $texto, array $terminos): bool
{
    $texto = normalizarTextoBuscable($texto);
    foreach ($terminos as $termino) {
        $termino = normalizarTextoBuscable((string) $termino);
        if ($termino !== '' && !str_contains($texto, $termino)) {
            return false;
        }
    }
    return true;
}
/** Tono pastel fijo del avatar de cada persona (el mismo en PHP y en espacio.js). */
const COLORES_AVATAR = ['#dcd6ec', '#cddff3', '#d3e8dc', '#efdccb', '#ead3e0', '#e4dfc7', '#cfe6e6', '#e0d5f1'];

function colorAvatar(int $usuarioId): string
{
    return COLORES_AVATAR[$usuarioId % count(COLORES_AVATAR)];
}

/** Iniciales para el avatar: primera letra del nombre y del primer apellido ("José Luis Pérez" → "JP"). */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/u', trim($nombre)) ?: [''];
    $ultima = count($partes) > 2 ? $partes[count($partes) - 2] : ($partes[1] ?? '');
    return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($ultima, 0, 1));
}

/** Avatar circular: foto de perfil si existe; si no, iniciales sobre su tono pastel. */
function avatar(int $usuarioId, string $nombre, ?string $foto = null, string $clase = ''): string
{
    $contenido = $foto
        ? '<img src="' . URL_BASE . '/' . e($foto) . '" alt="">'
        : e(iniciales($nombre));
    return '<span class="avatar ' . e($clase) . '" style="--avatar: ' . colorAvatar($usuarioId) . '" aria-hidden="true">' . $contenido . '</span>';
}

/**
 * true si lo registrado en alergias en realidad dice que no hay ("ninguna", "niega", "sin alergias").
 * Un negativo no debe pintarse con el color de alerta del expediente ni del documento.
 */
function sinAlergiasRegistradas(?string $alergias): bool
{
    return $alergias === null || trim($alergias) === ''
        || (bool) preg_match('/^\s*(ninguna|niega|negad[oa]s?|sin alergias)/iu', $alergias);
}
