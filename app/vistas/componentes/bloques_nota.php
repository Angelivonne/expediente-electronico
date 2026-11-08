<?php
/**
 * Cuerpo libre de una entrada clínica con el mismo motor de bloques de las notas.
 * El editor (assets/js/espacio.js) envía los bloques en el campo "cuerpo"; aquí se validan
 * y se pintan con el mismo marcado .bloque, para que la nota se vea igual en el expediente
 * y en el documento imprimible. Solo texto: la nota clínica no admite HTML ni archivos.
 */

const BLOQUES_NOTA_CLINICA = ['p', 'h1', 'h2', 'h3', 'lista', 'numerada', 'tarea', 'cita', 'separador'];

/** Campos de paciente que se editan con el panel de nota y guardan bloques en extras['_bloques']. */
const CAMPOS_NOTA_PACIENTE = ['motivo_consulta', 'antecedentes_heredofamiliares', 'antecedentes_personales_patologicos', 'antecedentes_personales_no_patologicos', 'inmunizaciones', 'antecedentes_ginecoobstetricos', 'habitos_sustancias'];

/**
 * Actualiza los bloques de un campo clínico dentro de extras; devuelve true si hay cambio que persistir.
 * Sin bloques en el envío, el texto plano manda: si cambió, el formato previo se descarta.
 */
function sincronizarBloquesCampo(array &$extras, string $campo, string $valor, mixed $bloques, string $anterior): bool
{
    $previos = $extras['_bloques'][$campo] ?? null;
    if ($bloques !== null) {
        $limpios = sanearBloquesNota($bloques);
        $nuevo = $limpios ? json_encode($limpios, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) : null;
    } elseif ($valor !== $anterior) {
        $nuevo = null;
    } else {
        return false;
    }
    if ($nuevo === $previos) {
        return false;
    }
    if ($nuevo === null) {
        unset($extras['_bloques'][$campo]);
    } else {
        $extras['_bloques'][$campo] = $nuevo;
    }
    if (empty($extras['_bloques'])) {
        unset($extras['_bloques']);
    }
    return true;
}

/** Limpia los bloques recibidos del formulario (JSON o arreglo) y descarta lo que no sea texto válido. */
function sanearBloquesNota($valor): array
{
    if (is_string($valor)) {
        $valor = json_decode($valor, true);
    }
    if (!is_array($valor)) {
        return [];
    }
    $bloques = [];
    $ids = [];
    foreach (array_slice($valor, 0, 120) as $b) {
        if (!is_array($b) || !is_string($b['t'] ?? null) || !in_array($b['t'], BLOQUES_NOTA_CLINICA, true)) {
            continue;
        }
        $t = $b['t'];
        $x = is_string($b['x'] ?? null) ? mb_substr(trim(sanoTexto($b['x'])), 0, 4000) : '';
        if ($t !== 'separador' && $x === '') {
            continue;
        }
        $bloque = ['t' => $t, 'x' => $x];
        if ($t === 'tarea') {
            $bloque['c'] = !empty($b['c']);
        }
        // Identificador corto (enlaces #b-…) y color de la paleta: se conservan si son válidos
        if (is_string($b['i'] ?? null) && preg_match('/^[a-z0-9]{8}$/', $b['i']) && !isset($ids[$b['i']])) {
            $bloque['i'] = $b['i'];
            $ids[$b['i']] = true;
        }
        $paleta = ['gris', 'cafe', 'naranja', 'amarillo', 'verde', 'azul', 'morado', 'rosa', 'rojo'];
        if (is_string($b['color'] ?? null) && in_array(str_replace('fondo_', '', $b['color']), $paleta, true) && $t !== 'separador') {
            $bloque['color'] = $b['color'];
        }
        $bloques[] = $bloque;
    }
    return $bloques;
}

/** Pinta los bloques como los muestra el editor de notas (mismo marcado y clases). */
function renderBloquesNota(array $bloques): string
{
    if (!$bloques) {
        return '';
    }
    $html = '<div class="bloques-nota">';
    $n = 0;
    foreach ($bloques as $b) {
        $t = (string) ($b['t'] ?? 'p');
        $x = (string) ($b['x'] ?? '');
        $n = $t === 'numerada' ? $n + 1 : 0;
        $attrs = ' class="bloque" data-t="' . e($t) . '"' . ($n ? ' data-n="' . $n . '"' : '');
        if (!empty($b['color']) && is_string($b['color'])) {
            $attrs .= ' data-color="' . e($b['color']) . '"';
        }
        if ($t === 'tarea') {
            $marcada = !empty($b['c']);
            $html .= '<div' . $attrs . ' data-c="' . ($marcada ? '1' : '0') . '">'
                   . '<span class="bloque__casilla" role="checkbox" aria-checked="' . ($marcada ? 'true' : 'false') . '"></span>'
                   . '<div class="bloque__texto">' . e($x) . '</div></div>';
            continue;
        }
        if ($t === 'separador') {
            $html .= '<div' . $attrs . '><hr class="bloque__separador"></div>';
            continue;
        }
        $html .= '<div' . $attrs . '><div class="bloque__texto">' . e($x) . '</div></div>';
    }
    return $html . '</div>';
}
