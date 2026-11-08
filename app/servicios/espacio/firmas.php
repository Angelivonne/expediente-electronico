<?php
/**
 * Firmas digitales de las notas (NOM-024 6.9: integridad y autenticidad del expediente).
 *
 * Cada firma sella la versión exacta de la nota: identidad del firmante (nombre, cédula
 * profesional y especialidad) + sello de tiempo + huella SHA-256 del contenido. Mientras una
 * nota tenga una firma vigente no se puede editar; para editarla hay que romper el sello con
 * un motivo, que queda registrado (bitácora e historial de firmas).
 */

/** Firmas vigentes (no revocadas) de una página, con los datos del firmante. */
function firmasVigentes(int $paginaId): array
{
    $c = db()->prepare(
        'SELECT f.id, f.nombre, f.cedula_profesional, f.especialidad, f.version,
                f.contenido_hash, f.firmado_en, u.id AS usuario_id, u.foto
           FROM firmas f LEFT JOIN usuarios u ON u.id = f.usuario_id
          WHERE f.pagina_id = ? AND f.revocada_en IS NULL
          ORDER BY f.id'
    );
    $c->execute([$paginaId]);
    return $c->fetchAll();
}

/** ¿Tiene la página al menos una firma vigente? (bloquea edición) */
function notaFirmada(int $paginaId): bool
{
    $c = db()->prepare('SELECT 1 FROM firmas WHERE pagina_id = ? AND revocada_en IS NULL LIMIT 1');
    $c->execute([$paginaId]);
    return (bool) $c->fetchColumn();
}

/** Huella SHA-256 del contenido guardado de una página (lo que se sella). */
function hashContenidoPagina(string $contenido): string
{
    return hash('sha256', (string) $contenido);
}

/**
 * Sella la versión actual de la nota. Devuelve la firma creada o ['ok' => false, 'error'].
 * Requiere cédula profesional registrada (NOM-024: el firmante se identifica con su cédula).
 */
function firmarPagina(array $pagina, int $usuarioId): array
{
    $u = db()->prepare('SELECT nombre, cedula_profesional, especialidad FROM usuarios WHERE id = ?');
    $u->execute([$usuarioId]);
    $firmante = $u->fetch();
    if (!$firmante || trim((string) $firmante['cedula_profesional']) === '') {
        return ['ok' => false, 'error' => 'Para firmar necesitas registrar tu cédula profesional en tu perfil.'];
    }
    $contenido = (string) $pagina['contenido'];
    $hash      = hashContenidoPagina($contenido);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO firmas (pagina_id, usuario_id, version, contenido_hash, cedula_profesional, especialidad, nombre)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $pagina['id'], $usuarioId, (int) $pagina['version'], $hash,
            trim((string) $firmante['cedula_profesional']),
            $firmante['especialidad'] !== null && $firmante['especialidad'] !== '' ? $firmante['especialidad'] : null,
            $firmante['nombre'],
        ]);
        $firmaId = (int) $pdo->lastInsertId();
        registrarBitacora('paginas', (int) $pagina['id'], 'actualizar', 'firma',
            null, 'cédula ' . trim((string) $firmante['cedula_profesional']) . ' · ' . $hash);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'No se pudo firmar la nota. Inténtalo de nuevo.'];
    }

    return ['ok' => true, 'id' => $firmaId];
}

/**
 * Rompe el sello de una firma: la revoca con motivo y vuelve a dejar editable la nota.
 * Solo la puede romper quien firmó o alguien con permiso "configurar".
 */
function revocarFirma(int $firmaId, int $usuarioId, string $motivo): array
{
    $c = db()->prepare(
        'SELECT f.id, f.pagina_id, f.usuario_id, f.nombre, f.cedula_profesional, f.revocada_en
           FROM firmas f WHERE f.id = ?'
    );
    $c->execute([$firmaId]);
    $firma = $c->fetch();
    if (!$firma || $firma['revocada_en'] !== null) {
        return ['ok' => false, 'error' => 'Esa firma ya no existe o ya fue revocada.'];
    }
    if ((int) $firma['usuario_id'] !== $usuarioId && !puede('configurar')) {
        return ['ok' => false, 'error' => 'Solo quien firmó (o un administrador) puede romper el sello.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo) > 255) {
        return ['ok' => false, 'error' => 'Escribe el motivo por el que rompes el sello (máximo 255 caracteres).'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE firmas SET revocada_en = NOW(), revocada_por = ?, motivo_revocacion = ? WHERE id = ?')
            ->execute([$usuarioId, $motivo, $firmaId]);
        registrarBitacora('paginas', (int) $firma['pagina_id'], 'actualizar', 'firma_revocada',
            'cédula ' . $firma['cedula_profesional'], $motivo);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'No se pudo romper el sello. Inténtalo de nuevo.'];
    }
    return ['ok' => true];
}

/**
 * Estado de las firmas de una página para la vista y el cliente.
 *   firmadas:  firmas vigentes con su huella y si el contenido sigue intacto
 *   historial: firmas revocadas (con motivo)
 *   editable:  ¿se puede editar? (ninguna firma vigente)
 *   puede_firmar: el usuario tiene cédula profesional
 */
function estadoFirmas(array $pagina, int $usuarioId): array
{
    $vigentes = firmasVigentes((int) $pagina['id']);
    $hash     = hashContenidoPagina((string) $pagina['contenido']);

    $firmadas = [];
    foreach ($vigentes as $f) {
        $firmadas[] = [
            'id'                 => (int) $f['id'],
            'usuario_id'         => (int) $f['usuario_id'],
            'nombre'             => $f['nombre'],
            'cedula'             => $f['cedula_profesional'],
            'especialidad'       => $f['especialidad'],
            'foto'               => $f['foto'] ? URL_BASE . '/' . $f['foto'] : null,
            'fecha'              => date('c', strtotime($f['firmado_en'])),
            'fecha_corta'        => fechaCorta($f['firmado_en']) . ', ' . hora12($f['firmado_en']),
            'hash'               => $f['contenido_hash'],
            'integra'            => hash_equals($f['contenido_hash'], $hash),   // ¿el contenido sigue igual que al firmar?
            'tuya'               => (int) $f['usuario_id'] === $usuarioId,
        ];
    }

    $hist = db()->prepare(
        'SELECT f.id, f.nombre, f.cedula_profesional, f.firmado_en, f.revocada_en, f.motivo_revocacion,
                u.nombre AS revoco_nombre
           FROM firmas f LEFT JOIN usuarios u ON u.id = f.revocada_por
          WHERE f.pagina_id = ? AND f.revocada_en IS NOT NULL
          ORDER BY f.revocada_en DESC'
    );
    $hist->execute([(int) $pagina['id']]);
    $historial = array_map(fn ($f) => [
        'id'            => (int) $f['id'],
        'nombre'        => $f['nombre'],
        'cedula'        => $f['cedula_profesional'],
        'firmada'       => fechaCorta($f['firmado_en']) . ', ' . hora12($f['firmado_en']),
        'revocada'      => fechaCorta($f['revocada_en']) . ', ' . hora12($f['revocada_en']),
        'revoco'        => $f['revoco_nombre'],
        'motivo'        => $f['motivo_revocacion'],
    ], $hist->fetchAll());

    $u = db()->prepare('SELECT cedula_profesional FROM usuarios WHERE id = ?');
    $u->execute([$usuarioId]);
    $puedeFirmar = trim((string) $u->fetchColumn()) !== '';

    return [
        'firmadas'     => $firmadas,
        'historial'    => $historial,
        'editable'     => $firmadas === [],          // sin firmas vigentes se puede editar
        'puede_firmar' => $puedeFirmar,
        'puede_revocar' => puede('configurar'),
    ];
}
