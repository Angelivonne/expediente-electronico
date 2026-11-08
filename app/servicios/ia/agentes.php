<?php
const AGENT_SHAPES = ['circle','pebble','squircle','capsule','triangle','hexagon','cloud','droplet'];
const AGENT_AHUES = [354,26,43,63,120,147,170,205,243,283];
function agentHue(string $slug): int {
    $h = abs(crc32($slug));
    return AGENT_AHUES[$h % count(AGENT_AHUES)];
}
function agentSvg(string $forma, int $hue, string $expresion = '', int $size = 48): string {
    $c = "hsl($hue, 70%, 62%)";
    $c2 = "hsl($hue, 60%, 50%)";
    $body = '';
    if ($forma === 'triangle') {
        $body = "<polygon points='50,12 88,84 12,84' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'hexagon') {
        $pts = '';
        for ($i = 0; $i < 6; $i++) {
            $a = deg2rad(60 * $i - 90);
            $x = 50 + 40 * cos($a);
            $y = 50 + 40 * sin($a);
            $pts .= ($i ? ' ' : '') . round($x,1) . ',' . round($y,1);
        }
        $body = "<polygon points='$pts' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'squircle') {
        $body = "<rect x='14' y='14' width='72' height='72' rx='28' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'capsule') {
        $body = "<rect x='14' y='30' width='72' height='40' rx='20' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'cloud') {
        $body = "<path d='M22,68 C12,68 8,56 18,50 C20,38 36,32 46,40 C56,32 72,36 78,48 C86,52 86,68 74,68 Z' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'droplet') {
        $body = "<path d='M50,10 C38,28 18,46 18,64 C18,82 32,94 50,94 C68,94 82,82 82,64 C82,46 62,28 50,10 Z' fill='$c' stroke='$c2' stroke-width='3'/>";
    } elseif ($forma === 'pebble') {
        $body = "<path d='M22,64 C20,42 38,16 62,20 C82,23 94,44 88,62 C82,82 36,94 22,64 Z' fill='$c' stroke='$c2' stroke-width='3'/>";
    } else {
        $body = "<circle cx='50' cy='50' r='40' fill='$c' stroke='$c2' stroke-width='3'/>";
    }
    $ojos = "<circle cx='38' cy='50' r='4' fill='#222'/><circle cx='62' cy='50' r='4' fill='#222'/>";
    $boca = "<path d='M43,63 Q50,68 57,63' stroke='#222' stroke-width='3' fill='none' stroke-linecap='round'/>";
    if ($expresion === 'feliz') {
        $ojos = "<path d='M32,50 Q38,44 44,50' stroke='#222' stroke-width='3' fill='none'/><path d='M56,50 Q62,44 68,50' stroke='#222' stroke-width='3' fill='none'/>";
        $boca = "<path d='M42,62 Q50,72 58,62' stroke='#222' stroke-width='3' fill='none' stroke-linecap='round'/>";
    } elseif ($expresion === 'triste') {
        $boca = "<path d='M43,70 Q50,63 57,70' stroke='#222' stroke-width='3' fill='none' stroke-linecap='round'/>";
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 100 100">' . $body . $ojos . $boca . '</svg>';
}
function agentsList(int $usuarioId): array {
    $s = db()->prepare("SELECT * FROM agentes WHERE usuario_creador=? AND estado!='eliminado' ORDER BY updated_at DESC");
    $s->execute([$usuarioId]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['hue'] = agentHue($r['slug']);
        $r['herramientas'] = json_decode($r['herramientas'] ?? '[]', true) ?: [];
    }
    return $rows;
}
function agentGet(int $id, int $usuarioId): ?array {
    $s = db()->prepare("SELECT * FROM agentes WHERE id=? AND usuario_creador=? AND estado!='eliminado' LIMIT 1");
    $s->execute([$id,$usuarioId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['hue'] = agentHue($r['slug']);
    $r['herramientas'] = json_decode($r['herramientas'] ?? '[]', true) ?: [];
    return $r;
}
function agentCreate(array $d, int $usuarioId, ?int $institucionId): int {
    $nombre = trim($d['nombre'] ?? 'Agente');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($d['slug'] ?? $nombre ?: 'agente')));
    $slug = trim($slug,'-');
    if ($slug === '') $slug = 'agente-' . substr(bin2hex(random_bytes(4)),0,8);
    $shape = in_array($d['shape'] ?? 'circle', AGENT_SHAPES, true) ? $d['shape'] : 'circle';
    $prov = in_array($d['proveedor'] ?? 'anthropic', ['anthropic','openai'], true) ? $d['proveedor'] : 'anthropic';
    $s = db()->prepare("INSERT INTO agentes (usuario_creador, institucion_id, nombre, slug, description, shape, color_hue, proveedor, servicio, modelo, api_url, api_clave, esfuerzo, herramientas, system_prompt, instrucciones, estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'creado')");
    $s->execute([$usuarioId,$institucionId,$nombre,$slug,trim($d['description'] ?? ''), $shape, null, $prov, trim($d['servicio'] ?? 'anthropic'), trim($d['modelo'] ?? ''), trim($d['api_url'] ?? ''), trim($d['api_clave'] ?? ''), in_array($d['esfuerzo'] ?? 'medium',['low','medium','high'],true) ? $d['esfuerzo'] : 'medium', json_encode(array_values(array_filter((array)($d['herramientas'] ?? [])))), trim($d['system_prompt'] ?? ''), trim($d['instrucciones'] ?? '')]);
    return (int)db()->lastInsertId();
}
function agentSetState(int $id, int $usuarioId, string $estado): bool {
    $s = db()->prepare("UPDATE agentes SET estado=? WHERE id=? AND usuario_creador=? AND estado!='eliminado'");
    $s->execute([$estado,$id,$usuarioId]);
    return $s->rowCount() > 0;
}
function agentMessage(int $agenteId, int $usuarioId, ?int $institucionId, string $rol, string $contenido, string $herramienta = ''): void {
    db()->prepare("INSERT INTO agentes_conversaciones (agente_id, usuario_id, institucion_id, rol, contenido, herramienta) VALUES (?,?,?,?,?,?)")->execute([$agenteId,$usuarioId,$institucionId,$rol,$contenido,$herramienta]);
}
function agentHistory(int $agenteId, int $limite = 50): array {
    $s = db()->prepare("SELECT rol, contenido, herramienta, creado_en FROM agentes_conversaciones WHERE agente_id=? ORDER BY id ASC LIMIT ?");
    $s->execute([$agenteId, max(1,min(200,$limite))]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function agentConfig(array $agente): array {
    return [
        'proveedor' => $agente['proveedor'] ?? 'anthropic',
        'modelo' => $agente['modelo'] ?? '',
        'url' => $agente['api_url'] ?? '',
        'clave' => descifrar($agente['api_clave'] ?? null) ?? '',
    ];
}
function agentSystemPrompt(array $agente): string {
    $base = sistemaAsistente();
    $extra = trim($agente['system_prompt'] ?? '');
    $instr = trim($agente['instrucciones'] ?? '');
    $txt = $base . "\n\nEres el agente seccionado \"" . ($agente['nombre'] ?? '') . "\". Tu respuesta debe seguir tus reglas. ";
    if ($extra) $txt .= "\nREGLAS: $extra";
    if ($instr) $txt .= "\nINSTRUCCIONES: $instr";
    return $txt;
}
