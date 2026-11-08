<?php
/**
 * Panel "Registro clínico" que se despliega junto a la ficha lateral del paciente.
 * Se comparte entre el expediente y la vista de una nota para que el menú sea el mismo.
 */
function selectorRegistroLateral(int $pacienteId): string
{
    if (!puede('historial')) {
        return '';
    }

    ob_start();
    ?>
    <aside class="registro-selector-lateral" id="registro-selector-<?= $pacienteId ?>" data-registro-selector-panel aria-label="Elegir tipo de registro" hidden>
      <header><div><h2>Registro clínico</h2><p>Elige el documento que vas a crear.</p></div><button type="button" data-cerrar-registro-selector aria-label="Cerrar">×</button></header>
      <?php foreach (array_unique(array_column(TIPOS_REGISTRO, 'grupo')) as $grupo): ?>
        <section><h3><?= e($grupo) ?></h3><ul>
          <?php foreach (TIPOS_REGISTRO as $tipoRegistro => $datosTipo): if ($datosTipo['grupo'] !== $grupo) continue; ?>
            <li><a href="<?= URL_BASE ?>/pacientes/nuevo_registro.php?paciente=<?= $pacienteId ?>&amp;tipo=<?= e($tipoRegistro) ?>"><i class="registro-selector-lateral__icono"><?= iconoRelleno($datosTipo['relleno']) ?></i><span><?= e($datosTipo['documento']) ?></span><?= icono('chevron') ?></a></li>
          <?php endforeach; ?>
        </ul></section>
      <?php endforeach; ?>
    </aside>
    <?php
    return ob_get_clean();
}
