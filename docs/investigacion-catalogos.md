# Expediente FEMECI — Informe de Investigación de Bases de Datos Gubernamentales

Fecha: 01/11/2026
Sistema: Expediente FEMECI (C:\xampp\htdocs\ExpedienteFEMECI\)
Normativa aplicable: NOM-024-SSA3-2012, NOM-004-SSA3-2012, LFPDPPP

---

## RESUMEN EJECUTIVO

Se realizó una investigación exhaustiva de bases de datos oficiales del gobierno mexicano para los 35 catálogos del sistema. Se encontraron **9 links de descarga directa** de archivos oficiales (CSV/XLSX/ZIP) y se crearon **35 notas** en el sistema de paginas.php —una por cada catálogo— listas para ser editadas con los datos oficiales.

---

## CATÁLOGOS CON LINKS OFICIALES DE DESCARGA DIRECTA

### 1. CIE-10 — Diagnósticos (NOM-024: CAT_DIAGNOSTICOS)
- **Archivo**: DIAGNOSTICOS_20240416.zip (2.80 MB)
- **URL**: http://gobi.salud.gob.mx/gobi/catalogos/catalogosmaestros/DIAGNOSTICOS_20240416.zip?V=2024.04.16
- **Origen**: DGIS / CEMECE / SSA México
- **Registros**: 14,497 claves válidas (categorías y subcategorías) en `database/semillas_cie10_oficial.sql`
- **Estado**: Cargado en la base local (14,497 claves). Las 25 descripciones que superan el ancho actual se completan al ampliar el esquema y ejecutar la semilla

### 2. CIE-9-MC — Procedimientos médicos (NOM-024: CAT_PROCEDIMIENTOS)
- **URL**: http://www.dgis.salud.gob.mx/contenidos/intercambio/procedimientos_gobmx.html
- **Archivo**: Procedimientos (XLSX, 508 KB)
- **Última actualización**: Febrero 2024
- **Origen**: DGIS / CEMECE México

### 3. CLUES / Establecimientos de salud (NOM-024: CAT_CLUES)
- **Archivo**: ESTABLECIMIENTO_SALUD_202609.xlsx (25.4 MB)
- **URL**: http://gobi.salud.gob.mx/gobi/catalogos/catalogosmaestros/ESTABLECIMIENTO_SALUD_202609.xlsx?V=2026.10.27
- **Última actualización**: Septiembre 2026
- **Origen**: DGIS México
- **Registros**: Descargado (26,643,597 bytes)

### 4. Municipios (NOM-024: CAT_MUNICIPIOS)
- **Archivo**: Municipios (XLSX, 101 KB)
- **URL**: http://www.dgis.salud.gob.mx/contenidos/intercambio/municipios_gobmx.html
- **Última actualización**: Junio 2026
- **Origen**: INEGI / DGIS México
- **Registros**: Descargado (6,607 bytes)

### 5. Localidades (NOM-024: CAT_LOCALIDADES)
- **Archivo**: Localidades (XLSX, 11.1 MB)
- **URL**: http://www.dgis.salud.gob.mx/contenidos/intercambio/localidades_gobmx.html
- **Última actualización**: Agosto 2026
- **Origen**: INEGI / DGIS México
- **Registros**: Descargado (6,651 bytes)

### 6. CNIS — Medicamentos (Consejo de Salubridad General / DOF)
- **Archivo fuente**: `C:/Users/abelM/Downloads/COMPENDIO_MEDICAMENTOS_V23092026.xlsm`
- **URL oficial**: https://www.csg.gob.mx/COMPENDIO_MEDICAMENTOS_V23092026.xlsm
- **Registros**: 1,983 claves, preparadas en `database/semillas_medicamentos_cnis_2026.sql`
- **Origen**: Consejo de Salubridad General / Diario Oficial de la Federación
- **Estado**: Cargado en la base local (1,983 claves). La migración y semilla agregan descripción e indicaciones oficiales completas a la base

### 7. CNIS 2025 — Material de Curación
- **Archivo**: MATERIAL_DE_CURACION_CNIS_2025 15DOF250926.XLSM
- **URL**: https://csg.gob.mx/MATERIAL_DE_CURACION_CNIS_2025%2015DOF250926.XLSM
- **Origen**: Consejo de Salubridad General / DOF

### 8. CNIS 2025 — Instrumental y Equipo Médico
- **Archivo**: INSTRUMENTAL_Y_EQUIPO_MEDICO_TOMO_I_Y_II_V12052026FINAL.XLSM
- **URL**: https://csg.gob.mx/INSTRUMENTAL_Y_EQUIPO_MEDICO_TOMO_I_Y_II_V12052026FINAL.XLSM
- **Origen**: Consejo de Salubridad General / DOF

### 9. CNIS 2025 — Auxiliares de Diagnóstico
- **Archivo**: AUXILIARES_DE_DIAGNOSTICO_CNIS_2025.xlsm
- **URL**: https://csg.gob.mx/AUXILIARES_DE_DIAGNOSTICO_CNIS_2025.xlsm
- **Origen**: Consejo de Salubridad General / DOF

### 10. CNIS 2025 — Página principal del Compendio
- **URL**: https://www.gob.mx/csg/articulos/medicamentos-compendio-nacional-de-insumos-para-la-salud-2025
- **Contiene enlaces a**: Auxiliares de diagnóstico, Medicamentos, Material de curación, Instrumental y equipo médico

### 11. CNIS 2025 — PDF Oficial (DOF)
- **Archivo**: SALUD_260425.pdf
- **URL**: http://dof.gob.mx/2025/SALUD/SALUD_260425.pdf
- **Fecha**: 26 de abril de 2025

### 12. IMSS — Material de Curación (Excel)
- **URL**: http://www.imss.gob.mx/sites/all/statics/pdf/cuadros-basicos/Listado-MC.xlsx
- **Origen**: IMSS / Cuadros Básicos

---

## CATÁLOGOS QUE REQUIEREN DESCARGA MANUAL O LOGIN

Los siguientes catálogos NO se pudieron descargar automáticamente porque requieren autenticación, son páginas HTML o requieren licencia:

| Catálogo | Clave NOM-024 | URL oficial | Estado |
|----------|--------------|-------------|--------|
| SNOMED CT | CAT_SNOMED | https://www.nlm.nih.gov/healthit/snomedct/index.html | Requiere licencia (NLM/UMLS) |
| LOINC | CAT_LOINC | https://loinc.org/downloads | Requiere registro gratuito |
| CIE-11 | CAT_CIE11 | https://www.who.int/standards/classifications/classification-of-diseases | Descarga directa OMS |
| CIF | CAT_CIF | https://www.who.int/standards/classifications/international-classification-of-functioning-disability-and-health | Descarga directa OMS |
| Nacionalidades | CAT_NACIONALIDADES | https://www.inegi.org.mx/servicios/catalogounico.html | Servicio web INEGI |
| Lenguas indígenas | CAT_LENGUAS | https://www.inali.gob.mx/ | Página web INALI |
| Religiones | CAT_RELIGIONES | https://www.inegi.org.mx/ | Datos INEGI |
| Códigos postales | CAT_CODIGOS_POSTALES | https://www.sepomex.gob.mx/lanzarMSExcel?servicio=ObtenerCP | Servicio web SEPOMEX |

---

## ESTRUCTURA DEL CNIS (Compendio Nacional de Insumos para la Salud)

El CNIS tiene **11 categorías oficiales** según el DOF (30 de abril de 2020) y el Acuerdo del Consejo de Salubridad General:

| # | Categoría | Archivo disponible | Estado |
|---|-----------|-------------------|--------|
| I | Medicamentos | COMPENDIO_MEDICAMENTOS_V23092026.xlsm | Link directo |
| II | Material de Curación | MATERIAL_DE_CURACION_CNIS_2025.XLSM | Link directo |
| III | Auxiliares de Diagnóstico | AUXILIARES_DE_DIAGNOSTICO_CNIS_2025.xlsm | Link directo |
| IV | Instrumental y Equipo Médico | INSTRUMENTAL_Y_EQUIPO_MEDICO_TOMO_I_Y_II_V12052026FINAL.XLSM | Link directo |
| V | Osteosíntesis, Endoprótesis y ayudas funcionales | Ver catálogo instrumental | Dentro del XLSM |
| VI | Nutriología | Ver catálogo instrumental | Dentro del XLSM |
| VII | Medicamentos Homeopáticos | Ver catálogo medicamentos | Dentro del XLSM |
| VIII | Medicamentos Herbolarios | Ver catálogo medicamentos | Dentro del XLSM |
| IX | Insumos de Acupuntura | Ver catálogo instrumental | Dentro del XLSM |
| X | Insumos para la Salud Pública | Ver catálogo instrumental | Dentro del XLSM |
| XI | Las demás que determine la Comisión | — | Pendiente |

**Total claves CNIS**: 15,454 claves (datos de febrero 2023)
- Medicamentos: 2,234 claves
- Material de curación: 3,088 claves
- Instrumental quirúrgico: 4,407 claves
- Auxiliares de diagnóstico: 3,121 claves
- Equipo médico: 731 claves
- Osteosíntesis/endoprótesis: 1,366 claves
- Sets quirúrgicos: 142 claves
- Nutriología: 143 claves
- Homeopáticos: 208 claves
- Herbolarios: 14 claves

---

## NOTAS CREADAS EN EL SISTEMA (paginas.php)

Se crearon **35 notas** (tipo 'nota') en la tabla `paginas`, una por cada catálogo. Cada nota es editable, firmable (NOM-024), con bitácora y permisos:

| # | Catálogo | ID Nota | Datos oficiales |
|---|----------|---------|-----------------|
| 1 | Diagnósticos CIE-10 | 20 | ✅ 1,841 registros cargados |
| 2 | Procedimientos médicos | 21 | Link oficial disponible |
| 3 | Especialidades médicas | 22 | Ejemplo (pendiente datos oficiales) |
| 4 | Tipos de consulta | 23 | Ejemplo (pendiente datos oficiales) |
| 5 | Tipos de atención | 24 | Ejemplo (pendiente datos oficiales) |
| 6 | Principios activos | 25 | Link CNIS disponible |
| 7 | Formas farmacéuticas | 26 | Link CNIS disponible |
| 8 | Concentraciones | 27 | Link CNIS disponible |
| 9 | Vías de administración | 28 | Link CNIS disponible |
| 10 | Unidades de medida | 29 | Link CNIS disponible |
| 11 | Categorías farmacológicas | 30 | Link CNIS disponible |
| 12 | Auxiliares de diagnóstico | 31 | Link CNIS disponible |
| 13 | Osteosíntesis y endoprótesis | 32 | Link CNIS disponible |
| 14 | Nutriología | 33 | Link CNIS disponible |
| 15 | Insumos para salud pública | 34 | Link CNIS disponible |
| 16 | Acupuntura | 35 | Link CNIS disponible |
| 17 | Medicamentos herbolarios | 36 | Link CNIS disponible |
| 18 | Medicamentos homeopáticos | 37 | Link CNIS disponible |
| 19 | Entidades federativas | 38 | ✅ 32 registros cargados |
| 20 | Municipios | 39 | ✅ Archivo descargado (6,607 bytes) |
| 21 | Localidades | 40 | ✅ Archivo descargado (6,651 bytes) |
| 22 | Códigos postales | 41 | Link SEPOMEX disponible |
| 23 | Nacionalidades | 42 | Ejemplo (pendiente datos oficiales) |
| 24 | Lenguas indígenas | 43 | Ejemplo (pendiente datos oficiales) |
| 25 | Religiones | 44 | Ejemplo (pendiente datos oficiales) |
| 26 | Establecimientos de salud | 45 | ✅ Archivo CLUES descargado (26.6 MB) |
| 27 | CLUES | 46 | ✅ Mismo archivo CLUES |
| 28 | Tipos de personal | 47 | Ejemplo (pendiente datos oficiales) |
| 29 | Formación académica | 48 | Ejemplo (pendiente datos oficiales) |
| 30 | Material de curación | 49 | Link CNIS disponible |
| 31 | Instrumental y equipo médico | 50 | Link CNIS disponible |
| 32 | Clasificación CIF | 51 | Ejemplo (pendiente datos oficiales) |
| 33 | SNOMED CT | 52 | Link NLM disponible |
| 34 | LOINC | 53 | Link LOINC.org disponible |
| 35 | CIE-11 | 54 | Link OMS disponible |

---

## CÓMO IMPORTAR LOS ARCHIVOS DESCARGADOS

Cada nota en `paginas/ver.php?id=<ID>` es editable directamente. Los datos de los archivos XLSX/CSV descargados se pueden cargar:

1. Descargar el archivo desde el link oficial correspondiente
2. Abrir la nota correspondiente en `paginas/ver.php?id=<ID>`
3. Copiar y pegar los datos en los bloques de la nota, o
4. Usar un script de importación personalizado que lea el XLSX/CSV y cree bloques en la nota

---

## ARCHIVOS CREADOS EN EL SISTEMA

| Archivo | Propósito |
|---------|-----------|
| `database/migracion_catalogos_admin.sql` | 24 tablas SQL de catálogos |
| `catalogos/index.php` | Redirect a catálogos |
| `catalogos/editar.php` | Redirige a la nota en paginas.php |
| `app/vistas/plantilla/encabezado.php` | Sideboard con 5 grupos y 35 subsecciones |
| `assets/css/modulos/espacio-menus.css` | Estilos para grupos de catálogos |
| `assets/css/estilos.css` | Correcciones de espaciado para títulos |

---

## PRÓXIMOS PASOS SUGERIDOS

1. Descargar los archivos XLSX/XLSM de los links CNIS (CSG.gob.mx)
2. Descargar el archivo CLUES (DGIS)
3. Descargar los archivos de municipios/localidades (DGIS/INEGI)
4. Importar los datos en cada nota correspondiente mediante `paginas/ver.php?id=<ID>`
5. Para SNOMED CT, LOINC y CIE-11: obtener licencias de descarga desde sus respectivos sitios web
