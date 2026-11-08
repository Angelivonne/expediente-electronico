# Sección de Catálogos desde cero (un solo motor)

Documento de trabajo para reconstruir la sección de catálogos con la normativa mexicana, usando **un solo motor**
(entidades + componentes), no módulos sueltos.

## 1. Inventario real hoy

| Concepto | Dato |
|---|---|
| Tablas `catalogo_*` | 34 |
| Catálogos con datos | 23 |
| Catálogos vacíos | 11 (`cie11`, `snomed`, `loinc`, `clues`, `acupuntura`, `auxiliares_diagnostico`, `herbolarios`, `homeopaticos`, `insumos_salud_publica`, `nutriologia`, `osteosintesis`) |
| Filas totales en tablas `catalogo_*` | 309 |
| Fuentes adicionales | `cie10` (14,497) y `medicamentos` (1,983) |
| Páginas de catálogo en el espacio | 0 (se eliminaron; respaldo previo existe) |

El motor ya expone estas fuentes como entidades (puente `catalogos_sistema()` en
`app/servicios/motor/entidades.php`); lo que falta es la sección, metadatos y datos oficiales.

## 2. Marco normativo

La normativa mexicana **no publica un número único de "módulos"**. Lo que exige:

- **NOM-004-SSA3-2012** (expediente clínico): contenido mínimo, notas por tipo, identificación
  del paciente y del personal, diagnóstico/procedimiento estandarizados, medicamentos, firma.
- **NOM-024-SSA3-2012** (registro electrónico para la salud): catálogos nacionales, identificación
  unívoca, interoperabilidad, seguridad, confidencialidad, integridad, auditoría, respaldo.
- NOM por tipo de atención: NOM-005 (enfermería), NOM-006 (ambulatoria), NOM-007 (hospitalización),
  NOM-008 (consentimiento), NOM-012 (trasplantes), NOM-013 (salud mental), entre otras.

Para operar, el conjunto se organiza en **6 secciones** (módulos funcionales) con 36 fuentes.

## 3. Secciones propuestas

### A. Identificación y demografía
Entidades federativas · Municipios · Localidades · Códigos postales · Nacionalidades · Lenguas indígenas
· Religiones · **Faltan**: estado civil, ocupación, escolaridad, tipo de documento de identificación.

### B. Terminologías clínicas
CIE-10 (14,497) · CIE-11 · CIE-9-MC procedimientos · CIF · SNOMED CT · LOINC · Especialidades médicas
· Tipos de consulta · Tipos de atención · **Faltan**: motivos de consulta, escalas clínicas,
diagnósticos de enfermería (NANDA), intervenciones (NIC), resultados (NOC).

### C. Farmacia y terapéutica
Medicamentos (1,983) · Principios activos · Formas farmacéuticas · Concentraciones · Vías de administración
· Unidades de medida · Categorías farmacológicas · Auxiliares de diagnóstico · **Faltan**: biológicos/vacunas,
grupos sanguíneos, alergias.

### D. Establecimientos y personal
CLUES · Establecimientos de salud · Tipos de personal · Formación académica · **Faltan**: servicios/unidades,
turnos, áreas hospitalarias.

### E. Insumos y dispositivos
Insumos para salud pública · Material de curación · Instrumental y equipo médico · Osteosíntesis y endoprótesis.

### F. Medicinas complementarias
Medicamentos homeopáticos · Herbolarios · Acupuntura · Nutriología.

## 4. Lo que falta (además de datos)

1. **Metadatos por catálogo**: fuente oficial, versión, vigencia, norma que lo exige, responsable.
2. **Claves como identidad**: validar clave única, no permitir duplicados, referencias por clave.
3. **Datos oficiales**: cargar CIE-9-MC, CLUES, CIE-11, SNOMED/LOINC (licencia), y completar los 11 vacíos.
4. **UI única**: listado agrupado por secciones, búsqueda por clave/nombre, conteos, importar/exportar,
   edición con permisos y bitácora.
5. **Versionado de catálogos**: cambiar un catálogo sin romper registros históricos.
6. **API de autocompletado** para los formularios clínicos (una sola consulta por clave/nombre).
7. **Pruebas**: integridad de claves, catálogos obligatorios presentes, importación válida.

## 5. Plan por fases

| Fase | Entregable | Verificación |
|---|---|---|
| 1 | Registro de catálogos con secciones y metadatos (`app/datos/catalogos_sistema.php`) | 36 fuentes listadas, 6 secciones |
| 2 | Sección UI sobre el motor: listado por sección, búsqueda, conteo, editor | Capturas y HTTP 200 |
| 3 | Importar/exportar catálogos (CSV oficial) con validación de claves | Importar CIE-9-MC de prueba |
| 4 | Completar vacíos y cargar oficiales (CIE-9-MC, CLUES, CIE-11) | Conteos y claves únicas |
| 5 | Versionado y API de autocompletado | Nota clínica que consume catálogo |

## 6. Para el expediente completo (además de catálogos)

- Notas por tipo NOM-004 (consulta, urgencia, hospitalización, interconsulta, referencia, egreso).
- Consentimiento informado, receta con dosis/intervalo/vía, órdenes de estudio.
- Firma electrónica de nota, auditoría, retención y resguardo.
- Exportación estructurada/interoperabilidad (HL7/FHIR) y respaldo verificado.
- Portal del paciente y reportes de la institución.

## Importar y exportar catálogos (implementado)

- `/catalogos/`: cada catálogo tiene **Exportar** (CSV por el endpoint del motor) e **Importar** (solo administrador).
- `/catalogos/importar.php?tabla=…`: sube CSV con la clave del catálogo (clave/código), nombre, descripción y activo.
  - Valida encabezados, tolera BOM y comas, y **actualiza por clave** (no duplica).
  - Muestra insertados, actualizados y errores por fila.
  - Acepta columnas propias de la tabla (p. ej. capitulo, entidad_id) si vienen en el encabezado.
- Probado con `catalogo_nacionalidades`: 1 insertado, 1 actualizado, 0 errores.

## Metadatos de catálogo (implementado)

- Cada catálogo del registro declara **fuente, versión y norma** (`catalogos_sistema.php`).
- La sección de catálogos muestra una columna **Fuente** con la norma aplicable debajo
  (NOM-004-SSA3-2012 para los clínicos/institucionales, NOM-024-SSA3-2012 para los nacionales).
- La pantalla de importar muestra tabla, clave, fuente y norma del catálogo.
- Se evita repetir la versión cuando es igual a la fuente.

## Metadatos de catálogo persistidos (implementado)

- Tabla `catalogos_meta` (clave, fuente, versión, vigencia, responsable, usuario y fecha) sembrada con los
  valores del registro de catálogos (36 filas). Migración `database/migracion_catalogos_meta.sql`.
- Editor `/catalogos/meta.php?tabla=…` (solo administrador): guarda con UPSERT y deja el cambio en la bitácora.
- La sección de catálogos lee los metadatos de la base y muestra fuente, versión, norma, vigencia y responsable;
  enlace **Metadatos** junto a Exportar/Importar.
- Probado: se guardó vigencia 2026-12-31 y responsable, la sección lo mostró, y luego se restauraron los valores
  sembrados. Datos de prueba de bitácora eliminados.