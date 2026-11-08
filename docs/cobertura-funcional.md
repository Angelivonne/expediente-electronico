# Cobertura funcional del editor

Comparación entre lo que ofrece la referencia y lo que ya tiene el proyecto. Sirve para saber qué
falta sin adivinar. Estado: **sí** (completo), **parcial** (existe con límites), **no** (pendiente).

## Espacio de trabajo y páginas

| Función | Estado | Notas |
|---|---|---|
| Páginas, subpáginas y carpetas | sí | Árbol lateral con mover, duplicar, papelera |
| Favoritos y recientes | sí | Estrella y lista |
| Compartir y permisos por página | sí | Invitar, acceso general, niveles ver/editar/completo |
| Búsqueda de páginas | sí | En el espacio |
| Papelera con restaurar/eliminar | sí | |
| Página del paciente enlazada | sí | Notas y bases de datos por paciente |
| Menús contextuales con submenús | sí | Página, bloque y selección |
| Comentarios | sí | Expediente, historial, documento y citas |
| Menciones `@nombre` con notificación | sí | Avisa a quienes coinciden |
| Versiones con restauración | sí | De entidades y de notas |
| Botón/plantillas | sí | Plantillas clínicas, de nota, de base de datos y de fila |
| Historial de edición por bloque | no | Solo bitácora general |
| Bloques sincronizados | no | Pendiente |
| Comentarios anclados a un bloque | no | Los comentarios son por registro |

## Editor de notas

| Función | Estado | Notas |
|---|---|---|
| Bloques: párrafo, títulos, listas, tarea, cita, separador | sí | |
| Imágenes y gráficas dentro de la nota | sí | Con pie, tamaño y ancho |
| Arrastrar bloques, insertar arriba/abajo, duplicar | sí | Menú de bloque |
| Atajos de teclado y comandos | sí | Menú «/» y atajos |
| Firma de nota y sello | sí | |
| Impresión y PDF | sí | Con secciones seleccionables |
| Plantillas de nota | sí | Clínicas y propias |
| Tabla dentro de la nota | no | Se usa una base de datos |
| Ecuaciones | no | |

## Bases de datos

| Función | Estado | Notas |
|---|---|---|
| Vista de tabla editable | sí | Columnas tipadas, filas, celdas |
| Propiedades: texto, número, fecha, selección, casilla, paciente, fórmula | sí | |
| Totales al pie y cálculos por columna | sí | |
| Filtros, orden, columnas ocultas, columna fija | sí | Por vista personal del navegador |
| Vista de gráfica (barras, líneas, área, dona) | sí | |
| Vistas múltiples por página | sí | Tabla y gráfica |
| Plantillas de fila | sí | Guardar fila y crear desde plantilla |
| **Vista de tablero (kanban)** | **sí** | Columnas por una propiedad de selección; arrastrar tarjeta cambia el valor |
| **Vista de calendario** | **sí** | Mes por una propiedad de fecha; navegación de meses y clic en el evento |
| **Vista de lista o galería** | **no** | Pendiente |
| **Formulario público/interno** | **no** | Pendiente |
| Bloqueo de filas, relaciones entre bases, rollups | no | |

## Datos, IA y operación

| Función | Estado | Notas |
|---|---|---|
| Asistente con herramientas (`datos`: describir, listar, agrupar, obtener, crear, actualizar, eliminar) | sí | Prueba de regresión sin tokens |
| Asistente con conversación real | parcial | Configurado; falta prueba en vivo autorizada |
| Importar/exportar 10 formatos | sí | Conversión ida y vuelta 10/10 |
| Exportar CSV con la vista actual | sí | |
| Motor único (40 entidades) | sí | Catálogos, pacientes, historial, comentarios, versiones |
| Bitácora inmutable, respaldos y verificación | sí | Automatización diaria |
| Portal del paciente y agenda | sí | |
| DICOM y visor de estudios | sí | |

## Prioridad sugerida para completar la experiencia de bases de datos

1. **Vista de calendario** por una propiedad de fecha.
3. **Vista de formulario** para captura guiada.
4. **Vista de lista/galería** para lectura rápida.
5. Comentarios anclados a bloques y bloques sincronizados.

El diseño visual se mantiene igual que el actual; las vistas nuevas se integran como pestañas de la base
de datos, sin cambiar la apariencia general.

## Verificación NOM-004 de flujos (ronda de cierre)

Probados de punta a punta, con datos de prueba y limpieza posterior:

- **Consentimiento informado** (`/pacientes/nuevo_registro.php?tipo=consentimiento`):
  guarda procedimiento, decisión, quién otorga, testigos, riesgos, beneficios y alternativas;
  el documento (`pacientes/evento.php`) muestra los datos y el bloque de firmas (NOM-004 10.1.1).
- **Receta** (`tipo=receta`): guarda medicamento del catálogo con clave, presentación, dosis, vía,
  frecuencia, duración, cantidad y fechas; el documento muestra el bloque de firma de quien prescribe.
- La validación rechaza recetas sin medicamento y consentimientos sin decisión (comprobado).
- Tipos disponibles: consulta, urgencias, interconsulta, referencia, egreso, receta, estudio,
  hospitalización, consentimiento, incapacidad.

Pendiente de probar con el mismo método: urgencias, interconsulta, referencia, egreso,
hospitalización, estudio e incapacidad; y la firma electrónica de cada nota.

## Firma electrónica del historial clínico (implementado)

- Tabla `firmas_clinicas` (una firma vigente por entrada; revocación prevista con fecha y motivo).
- Servicio `app/servicios/clinica/firmas_clinicas.php`:
  - Huella **SHA-256** sobre los datos inmutables del registro (id, paciente, tipo, fecha, título, detalle, autor, creado_en).
  - Sella con **nombre y cédula** de quien firma; exige cédula registrada en su cuenta.
  - Verifica que la huella guardada coincida con el contenido actual.
  - Registra el acto en la bitácora.
- `pacientes/evento.php` muestra el sello («Firmado electrónicamente por … · cédula … el …») y la huella,
  y el botón «Firmar electrónicamente» cuando el rol lo permite y la entrada no está firmada.
- Migración: `database/migracion_firmas_clinicas.sql` y permisos para `femeci_app`.

Probado: entrada de consentimiento firmada por el administrador (cédula 1077777), huella `87dd777d7f7fec87…`,
sello visible en el documento; datos de prueba borrados después.

## Revocación de la firma electrónica (implementado)

- `revocarFirmaClinica()` en `app/servicios/clinica/firmas_clinicas.php`: solo quien firmó o un administrador,
  exige motivo, marca `revocada_en` y `motivo_revocacion`, y deja el acto en la bitácora.
- `pacientes/evento.php`: con la entrada firmada muestra el botón «Revocar firma» (con motivo) para quien
  firmó o un administrador; al revocar, el sello desaparece y vuelve a estar disponible «Firmar electrónicamente».
- Probado de punta a punta: firma → revocación con motivo «Prueba de revocación» → firma revocada en base
  (`revocada_en` y motivo) → botón de firmar disponible de nuevo. Datos de prueba borrados.

## Insignia de firma en el historial de pacientes (implementado)

- `pacientes/ver.php` consulta las firmas vigentes (`firmas_clinicas`) de las entradas listadas y muestra
  «Firmado electrónicamente · <nombre>» bajo el registro firmado, con el mismo marcado de la tabla del historial.
- No se duplicó nada del alta de registros: en la ficha hay **un** botón «Nuevo registro», **un** selector de
  tipos y los **10 tipos NOM-004** existentes; la única adición fue la insignia y el sello en el documento.
- Comprobado con una entrada firmada (badge visible) y datos de prueba borrados.

## Incapacidad temporal: validación faltante (corregido)

- El tipo `incapacidad` existía en el formulario (`pacientes/nuevo_registro.php`) y en `TIPOS_REGISTRO`,
  pero **no tenía caso en `validarRegistroClinico()`**: cualquier alta terminaba en el error genérico y no se guardaba.
- Se agregó el caso con las reglas del formulario: fecha de inicio obligatoria y no futura, fecha de fin válida
  y no anterior al inicio, días entre 1 y 365 (se calculan si hay fechas), tipo de incapacidad obligatorio,
  motivo obligatorio, diagnóstico CIE-10 opcional y observaciones.
- Probado de punta a punta: registro de incapacidad creado, documento «Incapacidad temporal» en 200 y datos
  de prueba borrados. Verificado también que en la ficha del paciente sigue habiendo **un solo** botón
  «Nuevo registro» y **un solo** selector de tipos (sin secciones duplicadas).

## Los 10 tipos NOM-004 probados de punta a punta

Creados por HTTP y documentos abiertos en 200, con datos de prueba y limpieza posterior:

- consulta, urgencias, interconsulta, referencia, egreso, receta, estudio, hospitalización,
  consentimiento e incapacidad.
- Urgencias/referencia/egreso usaron diagnóstico CIE-10 real del catálogo (A00) y sus opciones
  (destino, tipo de envío, motivo de egreso); hospitalización y estudio con sus campos obligatorios.
- Se corrigió de paso la validación faltante de incapacidad (documentada arriba).
- Limpieza verificada: historial del paciente de prueba vuelve a 3 registros.

## Verificación de huella en el historial (implementado)

- `pacientes/ver.php` recalcula la huella SHA-256 de cada entrada firmada y muestra en la insignia
  «huella válida» o «huella no coincide».
- Prueba de integridad: entrada firmada → «huella válida»; al alterar el detalle directamente en base
  → «huella no coincide». Datos de prueba borrados después.
- También se detectó y corrigió que la consulta de firmas no traía la columna `huella` (por eso al principio
  siempre decía «no coincide»).

## Agenda probada de punta a punta

- Crear cita por la API (`accion: crear`) con paciente, profesional, inicio, duración y motivo: 200 con cita devuelta.
- **Mover** (`accion: mover`) cambió el inicio de 10:00 a 11:00 y conservó la duración.
- **Cancelar** (`accion: estado`, estado `cancelada`): 200 y estado actualizado en base.
- La agenda y el inicio redirigen/sirven correctamente (200 al seguir la redirección).
- Cita de prueba borrada y bitácora restaurada; no se tocó la cita real existente.