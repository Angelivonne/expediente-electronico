<?php
/**
 * Preferencias de presentación privadas de cada cuenta.
 */
function preferenciasVisuales(int $usuarioId): array
{
    $consulta = db()->prepare(
        'SELECT tema, densidad, tamano_texto FROM preferencias_usuario WHERE usuario_id = ?'
    );
    $consulta->execute([$usuarioId]);
    $preferencias = $consulta->fetch(PDO::FETCH_ASSOC);

    return $preferencias ?: ['tema' => 'oscuro', 'densidad' => 'comoda', 'tamano_texto' => 'normal'];
}

function guardarPreferenciasVisuales(int $usuarioId, array $entrada): void
{
    $tema = $entrada['tema'] ?? '';
    $densidad = $entrada['densidad'] ?? '';
    $tamano = $entrada['tamano_texto'] ?? '';
    if (!in_array($tema, ['oscuro', 'claro', 'sistema'], true)
        || !in_array($densidad, ['comoda', 'compacta'], true)
        || !in_array($tamano, ['normal', 'grande'], true)) {
        throw new InvalidArgumentException('Selecciona opciones válidas para la apariencia.');
    }

    db()->prepare(
        'INSERT INTO preferencias_usuario (usuario_id, tema, densidad, tamano_texto)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE tema = VALUES(tema), densidad = VALUES(densidad), tamano_texto = VALUES(tamano_texto)'
    )->execute([$usuarioId, $tema, $densidad, $tamano]);
}
