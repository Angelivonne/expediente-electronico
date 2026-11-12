<?php
/**
 * Valores de configuración del sistema guardados en la tabla `configuracion`.
 * Los secretos se cifran con AES-256-GCM; la llave vive en privado/llave.bin (bloqueado a la web).
 * Cada institución tiene su propia configuración (permisos, textos, portadas, asistente de IA…):
 * la clave primaria es (institucion_id, clave); 0 son los valores de antes de las instituciones.
 */

const ARCHIVO_LLAVE = __DIR__ . '/../../privado/llave.bin';

function config(string $clave, ?string $porDefecto = null): ?string
{
    // Caché por petición; guardarConfig() la actualiza para que lo guardado se vea en la misma petición
    $cache = &$GLOBALS['__cache_config'];
    $cache ??= [];
    if (!array_key_exists($clave, $cache)) {
        // El valor de la institución; si no tiene uno propio, el que existía antes de las instituciones (0),
        // así lo configurado entonces (clave de la IA, permisos, textos) sigue funcionando
        try {
            $consulta = db()->prepare('SELECT valor FROM configuracion WHERE institucion_id IN (?, 0) AND clave = ? ORDER BY institucion_id DESC LIMIT 1');
            $consulta->execute([institucionId() ?? 0, $clave]);
            $valor = $consulta->fetchColumn();
            $cache[$clave] = $valor === false ? null : $valor;
        } catch (Throwable $e) {
            // Fallback si la columna institucion_id no existe todavía en la tabla configuracion
            try {
                $consulta = db()->prepare('SELECT valor FROM configuracion WHERE clave = ? LIMIT 1');
                $consulta->execute([$clave]);
                $valor = $consulta->fetchColumn();
                $cache[$clave] = $valor === false ? null : $valor;
            } catch (Throwable $e2) {
                $cache[$clave] = null;
            }
        }
    }
    return $cache[$clave] ?? $porDefecto;
}

function guardarConfig(string $clave, ?string $valor): void
{
    try {
        db()->prepare(
            'INSERT INTO configuracion (institucion_id, clave, valor, actualizado_por) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
        )->execute([institucionId() ?? 0, $clave, $valor, usuarioActual()['id'] ?? null]);
    } catch (Throwable $e) {
        db()->prepare(
            'INSERT INTO configuracion (clave, valor, actualizado_por) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
        )->execute([$clave, $valor, usuarioActual()['id'] ?? null]);
    }
    $GLOBALS['__cache_config'][$clave] = $valor;
}

function llaveCifrado(): string
{
    static $llaveCache = null;
    if ($llaveCache !== null) {
        return $llaveCache;
    }

    if (is_file(ARCHIVO_LLAVE)) {
        $llave = @file_get_contents(ARCHIVO_LLAVE);
        if ($llave !== false && strlen($llave) === 32) {
            return $llaveCache = $llave;
        }
    }

    // Semilla determinista basada en el entorno para garantizar persistencia entre despliegues
    $semilla = (string) (getenv('LLAVE_CIFRADO') ?: getenv('DB_CLAVE') ?: 'Clínica_expediente_secreto_seguro_2026');
    $llaveDeterminista = hash('sha256', 'Clínica_SALT_' . $semilla, true);

    $dir = dirname(ARCHIVO_LLAVE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents(ARCHIVO_LLAVE, $llaveDeterminista);
    return $llaveCache = $llaveDeterminista;
}

function cifrar(string $texto): string
{
    $iv = random_bytes(12);
    $cifrado = openssl_encrypt($texto, 'aes-256-gcm', llaveCifrado(), OPENSSL_RAW_DATA, $iv, $etiqueta);
    return base64_encode($iv . $etiqueta . $cifrado);
}

function descifrar(?string $guardado): ?string
{
    if (!$guardado) {
        return null;
    }

    // Si ya es una clave de API en texto plano (ej. sk-..., AIza..., gsk_...)
    if (preg_match('/^(sk-|AIza|gsk_|pplx-|xai-)/', $guardado)) {
        return $guardado;
    }

    $datos = base64_decode($guardado, true);
    if ($datos === false || strlen($datos) < 29) {
        return $guardado;
    }

    $iv = substr($datos, 0, 12);
    $tag = substr($datos, 12, 16);
    $ciphertext = substr($datos, 28);

    // Intento 1: llave primaria
    $texto = @openssl_decrypt($ciphertext, 'aes-256-gcm', llaveCifrado(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($texto !== false) {
        return $texto;
    }

    // Intento 2: llave derivada de la contraseña de BD como respaldo
    $semilla = (string) (getenv('LLAVE_CIFRADO') ?: getenv('DB_CLAVE') ?: 'Clínica_expediente_secreto_seguro_2026');
    $llaveAlt = hash('sha256', 'Clínica_SALT_' . $semilla, true);
    $texto = @openssl_decrypt($ciphertext, 'aes-256-gcm', $llaveAlt, OPENSSL_RAW_DATA, $iv, $tag);
    if ($texto !== false) {
        return $texto;
    }

    // Si no se puede descifrar pero es texto imprimible (posible clave no cifrada), retornarla
    if (ctype_print($guardado) && strlen($guardado) >= 10 && strlen($guardado) <= 200) {
        return $guardado;
    }

    return null;
}

function esAdministrador(): bool
{
    return (usuarioActual()['rol'] ?? '') === 'administrador';
}

/** Texto de la interfaz que el administrador puede editar (títulos y subtítulos de las secciones). */
function textoEditable(string $clave, string $porDefecto): string
{
    $guardado = config('texto_' . $clave);
    return $guardado !== null && $guardado !== '' ? $guardado : $porDefecto;
}

/** Atributos para que un título sea editable en su lugar (solo el administrador). */
function atributosEditable(string $clave, string $porDefecto): string
{
    return puede('configurar')
        ? ' data-texto-editable="' . e($clave) . '" data-placeholder="' . e($porDefecto) . '" contenteditable="plaintext-only" spellcheck="true"'
        : '';
}
