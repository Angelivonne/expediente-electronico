<?php
/**
 * Firma electrónica de una entrada del historial clínico (NOM-024).
 *
 * Sella el contenido del registro con una huella SHA-256 y guarda quién firmó (nombre y cédula).
 * La huella se calcula sobre los datos inmutables del registro, para poder verificarla después.
 */

/** Cadena canónica de la entrada del historial (mismo orden siempre). */
function huellaRegistroClinico(array $registro): string
{
    $canonico = [
        'id'          => (int) ($registro['id'] ?? 0),
        'paciente_id' => (int) ($registro['paciente_id'] ?? 0),
        'tipo'        => (string) ($registro['tipo'] ?? ''),
        'fecha'       => (string) ($registro['fecha'] ?? ''),
        'titulo'      => (string) ($registro['titulo'] ?? ''),
        'detalle'     => (string) ($registro['detalle'] ?? ''),
        'autor_id'    => (int) ($registro['autor_id'] ?? 0),
        'autor'       => (string) ($registro['autor_nombre'] ?? ''),
        'creado_en'   => (string) ($registro['creado_en'] ?? ''),
    ];
    return hash('sha256', json_encode($canonico, JSON_UNESCAPED_UNICODE));
}

/** Firma vigente de una entrada (o null). */
function firmaDeRegistroClinico(int $historialId): ?array
{
    $consulta = db()->prepare('SELECT * FROM firmas_clinicas WHERE historial_id = ? AND revocada_en IS NULL LIMIT 1');
    $consulta->execute([$historialId]);
    $firma = $consulta->fetch(PDO::FETCH_ASSOC);
    return $firma ?: null;
}

/** Firma la entrada con el usuario actual. Devuelve ['ok'=>bool, 'error'=>string, 'firma'=>array]. */
function firmarRegistroClinico(array $registro, int $usuarioId): array
{
    if (firmaDeRegistroClinico((int) $registro['id'])) {
        return ['ok' => false, 'error' => 'Esta entrada ya está firmada.'];
    }
    $usuario = db()->prepare('SELECT nombre, cedula_profesional FROM usuarios WHERE id = ?');
    $usuario->execute([$usuarioId]);
    $usuario = $usuario->fetch(PDO::FETCH_ASSOC) ?: [];
    $cedula = trim((string) ($usuario['cedula_profesional'] ?? ''));
    if ($cedula === '') {
        return ['ok' => false, 'error' => 'Registra tu cédula profesional antes de firmar.'];
    }
    $huella = huellaRegistroClinico($registro);
    try {
        db()->prepare(
            'INSERT INTO firmas_clinicas (historial_id, institucion_id, usuario_id, usuario_nombre, cedula, huella, firmado_en)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([(int) $registro['id'], institucionId(), $usuarioId, (string) $usuario['nombre'], $cedula, $huella]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'No se pudo firmar la entrada.'];
    }
    registrarBitacora('pacientes', (int) $registro['paciente_id'], 'firmar', 'historial_' . $registro['tipo'], null, 'firma ' . substr($huella, 0, 16));
    return ['ok' => true, 'firma' => firmaDeRegistroClinico((int) $registro['id'])];
}

/** true si la huella guardada coincide con el contenido actual del registro. */
function firmaRegistroClinicoValida(array $registro, array $firma): bool
{
    return hash_equals((string) ($firma['huella'] ?? ''), huellaRegistroClinico($registro));
}

/** Revoca la firma vigente (quien firmó o un administrador). Devuelve ['ok'=>bool,'error'=>string]. */
function revocarFirmaClinica(int $historialId, int $usuarioId, string $motivo, bool $esAdministrador): array
{
    $firma = firmaDeRegistroClinico($historialId);
    if (!$firma) {
        return ['ok' => false, 'error' => 'La entrada no está firmada.'];
    }
    if (trim($motivo) === '') {
        return ['ok' => false, 'error' => 'Escribe el motivo de la revocación.'];
    }
    if ((int) $firma['usuario_id'] !== $usuarioId && !$esAdministrador) {
        return ['ok' => false, 'error' => 'Solo quien firmó o un administrador puede revocar la firma.'];
    }
    db()->prepare('UPDATE firmas_clinicas SET revocada_en = NOW(), motivo_revocacion = ? WHERE id = ? AND revocada_en IS NULL')
        ->execute([mb_substr($motivo, 0, 255), (int) $firma['id']]);
    registrarBitacora('pacientes', (int) ($firma['historial_id'] ?? $historialId), 'revocar', 'firma_clinica', null, mb_substr($motivo, 0, 200));
    return ['ok' => true];
}
