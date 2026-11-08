# Retención del expediente clínico (NOM-004 5.4)

## Política

El expediente clínico se conserva **mínimo 5 años** contados a partir del **último acto médico**.

- El sistema **no tiene borrado físico** de pacientes ni de entradas del historial:
  - Las bajas de pacientes son lógicas (`pacientes.eliminado_en`).
  - El historial clínico solo se inserta; la aplicación no tiene ruta de `DELETE`/`UPDATE` sobre él.
  - El usuario de base de datos `femeci_app` solo tiene `SELECT` e `INSERT` sobre `bitacora`.
- Los respaldos diarios permiten recuperar información; se conservan los 14 más recientes.
- Al cumplirse el plazo, el expediente debe **archivarse** (no borrarse) según la política de la institución.

## Revisión

    C:\xampp\php\php.exe mantenimiento\revisar_retencion.php

Muestra cuántos pacientes están dentro del plazo y cuáles ya lo cumplieron (para archivo).
No elimina nada.

## Pendiente de la institución

- Definir el responsable del archivo histórico y su resguardo físico/digital.
- Documentar el procedimiento de archivo y destrucción (cuando legalmente proceda) y su autorización.
- Verificar que los respaldos se conserven también fuera del servidor.
