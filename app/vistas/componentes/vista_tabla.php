<?php
/**
 * Tabla única del motor: pinta cualquier entidad con el mismo marcado que ya usa la app
 * (bd-tabla, celdas editables con data-campo, atributos de fila y acciones).
 *
 * Espera una variable $tabla:
 *   columnas       columnas de columnasVista()
 *   filas          filas normalizadas (id, titulo y campos)
 *   atributos      atributos del <table> (p. ej. data-actualizar y data-catalogo)
 *   fila_atributos callable($fila, $i): atributos del <tr> (data-id, style…)
 *   celda          callable($columna, $fila, $i): HTML de la celda (opcional; si no, genérico)
 *   acciones       callable($fila, $i): HTML de la celda de acciones (opcional)
 *   grupos         [texto del grupo => número de columnas] (opcional)
 */
$columnasMotor      = $tabla['columnas'] ?? [];
$filasMotor         = $tabla['filas'] ?? [];
$atributosMotor     = $tabla['atributos'] ?? [];
$filaAtributosMotor = $tabla['fila_atributos'] ?? null;
$celdaMotor         = $tabla['celda'] ?? null;
$celdasMotor        = $tabla['celdas'] ?? [];
$accionesMotor      = $tabla['acciones'] ?? null;
$gruposMotor        = $tabla['grupos'] ?? [];

/** Atributos HTML a partir de un arreglo (nombres y valores controlados por el motor). */
$atributosHtml = function (array $atributos): string {
    $partes = [];
    foreach ($atributos as $nombre => $valor) {
        if (!preg_match('/^[a-zA-Z0-9_:-]+$/', (string) $nombre) || $valor === null || $valor === false) {
            continue;
        }
        $partes[] = $valor === true ? $nombre : $nombre . '="' . e((string) $valor) . '"';
    }
    return $partes ? ' ' . implode(' ', $partes) : '';
};

/** Texto visible de una celda según el tipo de campo. */
$textoCeldaMotor = function (array $columna, array $fila): string {
    $valor = $fila[$columna['clave']] ?? null;
    return match ($columna['tipo']) {
        'casilla' => $valor ? 'Sí' : 'No',
        'fecha'   => $valor ? fechaCorta((string) $valor) : '',
        'opcion'  => isset($columna['opciones'][(string) $valor]) ? (string) $columna['opciones'][(string) $valor] : (string) ($valor ?? ''),
        default   => (string) ($valor ?? ''),
    };
};
?>
<div class="bd__marco">
  <table class="bd-tabla"<?= $atributosHtml($atributosMotor) ?>>
    <colgroup>
      <?php foreach ($columnasMotor as $columna): ?>
        <col style="width: <?= (int) $columna['ancho'] ?>px">
      <?php endforeach; ?>
    </colgroup>
    <thead>
      <?php if ($gruposMotor): ?>
        <tr class="bd-tabla__grupos">
          <?php foreach ($gruposMotor as $grupo => $cuantas): ?>
            <th scope="colgroup" colspan="<?= (int) $cuantas ?>"><?= e((string) $grupo) ?></th>
          <?php endforeach; ?>
        </tr>
      <?php endif; ?>
      <tr>
        <?php foreach ($columnasMotor as $columna): ?>
          <th scope="col" data-orden="<?= e($columna['orden']) ?>" data-clave="<?= e($columna['clave']) ?>"<?= $columna['editable'] ? ' data-editor="' . e($columna['editor']) . '"' : '' ?>>
            <button type="button"><?= icono($columna['icono']) ?> <span><?= e($columna['titulo']) ?></span></button>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($filasMotor as $i => $fila): ?>
        <tr<?= $atributosHtml($filaAtributosMotor ? (array) $filaAtributosMotor($fila, $i) : []) ?>>
          <?php foreach ($columnasMotor as $columna): ?>
            <?php if (isset($celdasMotor[$columna['clave']])): ?>
              <?= $celdasMotor[$columna['clave']]($columna, $fila, $i) ?>
            <?php elseif (!empty($columna['calculo']) && is_callable($columna['calculo'])): ?>
              <?php
                $textoCalculo = (string) $columna['calculo']($fila);
                $valorCalculo = isset($columna['valor']) && is_callable($columna['valor']) ? (string) $columna['valor']($fila) : null;
              ?>
              <td<?= !empty($columna['clase']) ? ' class="' . e($columna['clase']) . '"' : '' ?><?= $valorCalculo !== null ? ' data-valor="' . e($valorCalculo) . '"' : '' ?>><?= e($textoCalculo) ?></td>
            <?php elseif ($celdaMotor): ?>
              <?= $celdaMotor($columna, $fila, $i) ?>
            <?php elseif ($columna['editable'] && !empty($columna['campo'])): ?>
              <?php $texto = $textoCeldaMotor($columna, $fila); ?>
              <td data-campo="<?= e($columna['campo']) ?>" data-valor="<?= e($columna['tipo'] === 'casilla' ? (($fila[$columna['clave']] ?? false) ? '1' : '0') : (string) ($fila[$columna['clave']] ?? '')) ?>" title="<?= e($texto) ?>"><?= e($texto) ?></td>
            <?php else: ?>
              <td title="<?= e($textoCeldaMotor($columna, $fila)) ?>"><?= e($textoCeldaMotor($columna, $fila)) ?></td>
            <?php endif; ?>
          <?php endforeach; ?>
          <?= $accionesMotor ? $accionesMotor($fila, $i) : '' ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
