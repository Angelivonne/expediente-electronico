# Análisis del proyecto — Expediente clínico

> Análisis hecho el 1 de noviembre de 2026 sobre `C:\xampp\htdocs\clinica`
> (4,098 archivos, XAMPP con PHP 8.2.12 y MariaDB 10.4.32, Apache y MySQL en ejecución).
> Punto de entrada revisado a fondo: `pacientes/registro.php`.

## 0. Cambios aplicados

- **Una sola institución.** Se consolidó todo en **Expediente** (id 1): los 9 pacientes, las 7 cuentas, las 124
  páginas y la configuración de las clínicas de prueba pasaron ahí, y las instituciones 2 y 4 se
  eliminaron. Respaldo previo, restaurado y verificado en una base de prueba:
  `privado/respaldos/antes_consolidar_20261102_031829.sql`.
- **Catálogos conectados a sus páginas y a sus tablas** (detalle en §8.3): se restauraron las 34 tablas
  `catalogo_*` con el diseño de `database/migracion_catalogos_admin.sql`, se cargaron sus datos con
  `database/llenar_catalogos_completo.sql` y cada página del menú Catálogos muestra ahora su tabla real.
- **Editor de catálogos arreglado**: las celdas de `/cie10/` y `/medicamentos/` guardan en
  `api/catalogos/actualizar.php` (antes intentaban guardar contra la API de pacientes y fallaban).
- **Permisos alineados** para `cie10`, `medicamentos`, `firmas` y las 34 tablas `catalogo_*`.
- **Correcciones de visualización del expediente** (§11): anclas tapadas por las barras fijas, columna
  lateral pegajosa, rejilla del resumen, etiquetas clínicas cortadas y alergia negada pintada en rojo.

---

## 1. Qué es

Expediente clínico electrónico para consultorios en México, alineado a **NOM-004-SSA3-2012** (expediente),
**NOM-024-SSA3-2012** (sistemas de información) y **LFPDPPP** (datos personales).

| Pieza | Elección |
|---|---|
| Servidor | Apache de XAMPP, `http://localhost/clinica/` |
| Backend | PHP 8.2 "plano": sin framework, sin router, un archivo `.php` por pantalla |
| Base de datos | MySQL/MariaDB, **una sola base**: `clinica` |
| Frontend | HTML renderizado en servidor + JavaScript vanilla (`assets/js`) |
| Dependencias | solo `anthropic-ai/sdk` vía Composer (`vendor/`) |
| Voz a texto | Parakeet TDT 0.6B v3 local (`models/stt/*.gguf`, 485 MB) + binario en `transcribe-cpp/` |

No hay `node_modules`, ni bundler, ni build: el CSS y el JS se cargan con `glob()` y se cachean por
`filemtime` (ver `recurso()` en `app/nucleo/funciones.php:13`).

---

## 2. Cómo se sirve una página (el flujo que comparten todas)

```
navegador
   └─ Apache ──► pacientes/registro.php
                     │
                     ├─ 1. app/nucleo/inicio.php          ← arranque obligatorio
                     │     ├─ config/config.php           constantes (URL_BASE, BD, correo)
                     │     ├─ app/nucleo/seguridad.php    errores, cabeceras CSP, sesión, permisos
                     │     ├─ config/db.php               PDO único (db())
                     │     ├─ app/nucleo/funciones.php    e(), redirigir(), CSRF, bitácora, CURP…
                     │     ├─ app/vistas/plantilla/iconos.php
                     │     ├─ app/datos/catalogos.php     ENTIDADES, SEXOS, GRUPOS_SANGUINEOS
                     │     ├─ app/nucleo/configuracion.php config()/guardarConfig() cifrado AES-GCM
                     │     └─ app/nucleo/institucion.php   institucionId(), exigirInstitucion()
                     │
                     ├─ 2. acceso automático como administrador (si ACCESO_AUTOMATICO)
                     ├─ 3. refresco de rol/foto/institución desde la BD en cada petición
                     ├─ 4. restricción del portal del paciente
                     ├─ 5. sin sesión → /acceso/   (o 401 si RESPUESTA_JSON)
                     ├─ 6. respaldo diario perezoso + exigir institución + idempotencia
                     │
                     ├─ 7. la página: exigirPermiso(), consultas, guardado
                     ├─ 8. app/vistas/plantilla/encabezado.php   <head>, barra lateral, migas
                     └─ 9. app/vistas/plantilla/pie.php          app.js, espacio.js, modulos/*.js
```

Regla de oro: **toda pantalla y todo endpoint empiezan con `require inicio.php`** y, si es JSON,
definen `define('RESPUESTA_JSON', true)` **antes** de incluirlo.

---

## 3. Mapa de carpetas: dónde está cada cosa

### 3.1 Carpetas públicas (se abren desde el navegador)

| Carpeta | Contenido | Entra aquí cuando… |
|---|---|---|
| `index.php` | 6 líneas: redirige a Pacientes (o al portal) | abres la raíz |
| `acceso/` | login con correo + contraseña + código de 10 dígitos, registro en 3 pasos, recuperación, salir | autenticación |
| `pacientes/` | **el módulo central**: lista, registro/edición, perfil, notas clínicas, historial, documento, impresión | trabajas con pacientes |
| `agenda/` | calendario de citas (día/semana/mes/lista) | citas |
| `paginas/` | espacio de trabajo: carpetas, notas por bloques, bases de datos, firmas | notas y documentos |
| `datos/` | importar/exportar CSV, Excel, JSON, XML, SQL, SQLite, HL7, FHIR | migraciones de datos |
| `asistente/` | chat de IA a pantalla completa | asistente |
| `configuracion/` | personas y permisos, correo, respaldos | administración |
| `institucion/` | asistente "Registra tu clínica" | cuenta sin institución |
| `cuenta/` | contraseña propia y cierre de sesiones | cuenta |
| `portal/` | portal del paciente (solo lectura de su expediente) | rol `paciente` |
| `catalogos/` | **resuelve** el catálogo a su página del espacio (`editar.php?tabla=…`), la comparte y, si falta, la crea | menú Catálogos |
| `cie10/` | editor real de la tabla `cie10` (`index` con edición en línea, `nuevo`); se abre desde la página del catálogo | corregir diagnósticos |
| `medicamentos/` | editor real de la tabla `medicamentos` (`index`, `nuevo`, `ver`) | corregir medicamentos |
| `api/` | 40 endpoints JSON | llamadas `fetch()` del navegador |
| `mantenimiento/` | respaldo manual, `respaldo_diario.bat`, demostración (**bloqueado al navegador**) | tareas programadas |
| `privado/` | llave AES, `bd.php`, credenciales, fotos, respaldos, `registros/php-errores.log` (**bloqueado**) | nunca desde la web |
| `models/stt/` | modelo de voz `parakeet-tdt-0.6b-v3-Q4_K_M.gguf` | dictado |
| `transcribe-cpp/` | binario nativo + licencias del motor de voz | dictado |

### 3.2 Código interno (`app/`, bloqueado por `app/.htaccess`)

| Ruta | Qué resuelve |
|---|---|
| `app/nucleo/inicio.php` | arranque común de todas las peticiones |
| `app/nucleo/seguridad.php` | CSP, sesión, límites de intentos, **matriz de permisos** (`PERMISOS`, `ROLES`, `PERMISOS_PACIENTE`) |
| `app/nucleo/funciones.php` | `e()`, `recurso()`, `redirigir()`, CSRF, `registrarBitacora()`, `mensajeFlash()`, `curpValida()`, `edad()`, `avatar()` |
| `app/nucleo/configuracion.php` | `config()` / `guardarConfig()` + cifrado AES-256-GCM de secretos |
| `app/nucleo/institucion.php` | `institucionId()`, `exigirInstitucion()`, aislamiento multi-clínica |
| `app/nucleo/idempotencia.php` | POST JSON con `X-Idempotencia` no se aplican dos veces |
| `app/datos/catalogos.php` | 33 entidades RENAPO, 3 sexos, 9 grupos sanguíneos |
| `app/datos/registros_clinicos.php` | **catálogo de los 10 tipos de nota**, signos vitales, vías, modalidades |
| `app/datos/mapa_mexico.php` | trazos SVG del mapa por estado |
| `app/servicios/clinica/` | agenda, `resumen_clinico.php` (diagnósticos/tratamientos derivados), `registros.php` (**validación de notas**, 461 líneas), imágenes, mapa, portal, sugerencias |
| `app/servicios/cuenta/` | acceso, códigos 2FA, invitaciones, claves, cédulas, notificaciones, push, SMTP |
| `app/servicios/espacio/` | páginas, páginas de paciente, firmas digitales, secciones |
| `app/servicios/ia/` | proveedores, herramientas del asistente, transcripción (`*.py`), web |
| `app/servicios/conversion/` | un lector/escritor por formato (csv, xlsx, sqlite, hl7, fhir, json_xml, zip) |
| `app/servicios/sincronizacion/` | cola, triggers, envío al servidor central |
| `app/servicios/sistema/respaldos.php` | respaldo diario comprimido + SHA-256 |
| `app/vistas/plantilla/` | `encabezado.php` (35 KB: head, barra lateral, migas), `pie.php`, `iconos.php`, plantillas de acceso/portal |
| `app/vistas/componentes/` | tarjeta del paciente, detalle de historial, panel del asistente, dictado, notas, sincronización |
| `app/formatos/` | plantillas JSON DGIS/IMSS de expediente |
| `app/tareas/` | tareas de push (`push.php`, vectores RFC) |

### 3.3 JavaScript y CSS

- `assets/js/app.js` (140 KB): comportamiento general, menús, **edición en línea de la tabla**, dictado, propiedades.
- `assets/js/espacio.js` (191 KB): el espacio de notas y las bases de datos del espacio.
- `assets/js/graficas.js`, `markdown.js`, `razonamiento.js`, `portal.js`, `push-sw.js`, `visor_dicom.js`.
- `assets/js/modulos/*.js` (29 archivos): **se cargan todos, en todas las páginas**, por `glob()` en
  `pie.php:47`. Ahí viven `registros_clinicos.js` (catálogo CIE-10/medicamentos), `borradores.js`,
  `perfil_paciente.js`, `agenda.js`, `sin_conexion.js`, etc.
- `assets/css/estilos.css` + `assets/css/modulos/*.css` (22 archivos), también por `glob()` en
  `encabezado.php:78`.
- `sw.js` en la raíz: service worker (modo sin conexión) + `assets/js/modulos/sin_conexion.js`.

### 3.4 Datos, esquema y documentación

| Archivo | Para qué |
|---|---|
| `database/esquema.sql` | 1,071 líneas: todas las tablas, migraciones idempotentes, **triggers de sincronización**, semillas |
| `database/usuario_aplicacion.sql` | usuario MySQL `clinica_app` con privilegios mínimos y `clinica_respaldo` |
| `database/migracion_catalogos_admin.sql` | 34 tablas `catalogo_*` (nunca se importaron en esta instalación) |
| `database/migracion_medicamentos_cie10_incapacidad.sql` | crea `medicamentos` y `cie10`, agrega el tipo `incapacidad` |
| `database/semillas_cie10_oficial.sql` (2 MB) / `semillas_medicamentos_cnis_2026.sql` (1 MB) | carga de los 14,497 diagnósticos y 1,983 medicamentos |
| `database/sincronizacion_triggers.php` | regenera triggers desde `app/servicios/sincronizacion/tablas.php` |
| `docs/requisitos.md`, `investigacion-normativa.md`, `respaldos.md` | alcance, norma, respaldos |
| `README.md` | guía general (fiel en lo grande; ver hallazgos) |

---

## 4. `pacientes/registro.php` desmenuzado (479 líneas)

Es el mismo archivo para **alta** y **edición** (`?id=`).

| Líneas | Qué hace |
|---|---|
| 2–3 | arranque + `exigirPermiso('editar_paciente')` → admin, médico, enfermería y recepción |
| 5–10 | 21 campos "de columna" (`nombre` … `motivo_consulta`) |
| 11–15 | 11 campos de perfil que **no** son columnas: viven en `extras` JSON |
| 21–22 | propiedades que el administrador agregó a la base (`config('pacientes_propiedades')`) |
| 25–41 | modo edición: carga el paciente por `id` + `institucion_id`, precarga columnas y `extras['_perfil']` |
| 43–53 | POST: recorta, limita longitudes (4,000 en antecedentes; 160 en el resto), CURP a mayúsculas |
| 55–104 | validación: CSRF, envío duplicado, obligatorios, fecha real y no futura, sexo del catálogo, CURP (formato + dígito verificador + **duplicado por institución**), entidad, correo, grupo sanguíneo, casilla de confirmación |
| 106–135 | validación de las propiedades del administrador (texto, número, fecha, selección, casilla) |
| 137–170 | **edición**: transacción, un `UPDATE` por campo modificado, una fila de bitácora por cambio, `extras` si cambió; luego vuelve al perfil |
| 172–200 | **alta**: transacción, `INSERT` con folio temporal `TMP-…`, `lastInsertId()`, folio definitivo `EXP-%06d`, bitácora, umbral de push, mensaje y redirección a la lista |
| 203–229 | migas, títulos, notificación y ayudas de pintado (`$v`, `$error`, `$aviso`, `$sel`) |
| 232–479 | HTML: 5 secciones + pie legal |

Secciones del formulario y a dónde va cada cosa:

| Sección | Campos | Destino |
|---|---|---|
| Datos de identificación | nombre(s), apellidos, fecha nac., sexo, CURP, entidad, nacionalidad, estado civil, ocupación, escolaridad, afiliación | columnas + `extras._perfil` |
| Contacto y domicilio | teléfono, correo, domicilio, municipio/estado, contacto y teléfono de emergencia, tutor | columnas + `extras._perfil` |
| Información clínica inicial | alergias, 6 antecedentes, grupo sanguíneo, padecimientos, medicamentos actuales (con autocompletado) | columnas + `extras._perfil` + tabla `medicamentos` |
| Evaluación | propiedades definidas por el administrador (máx. 30) | `extras.<clave pXXXXXX>` |
| Motivo de consulta | texto libre | `pacientes.motivo_consulta` |

Automatismos que no se ven en el archivo:

- **Folio**: `TMP-…` → `EXP-000123` en la misma transacción (`registro.php:186–190`).
- **Bitácora**: cada alta y cada campo modificado (`registrarBitacora('pacientes', …)`).
- **Sincronización**: los triggers de MariaDB insertan la fila en `sincronizacion_cola` (activos: la cola
  tiene 320 registros y el último es de las 17:26:08 del 1 de noviembre).
- **Push**: `evaluarUmbralesPush('pacientes')` encola el aviso "N pacientes nuevos".
- **Doble envío**: `campoEnvio()` + `envioYaProcesado()`/`recordarEnvio()` evitan el alta duplicada por
  doble clic o recarga.
- **Borrador**: `data-borrador="paciente-nuevo"` lo guarda `assets/js/modulos/borradores.js`.
- **Dictado**: botón "Dictar" (`data-dictado-mostrar`, `app.js:1313`) → `api/pacientes/dictado.php` →
  Parakeet local.

---

## 5. La base de datos real (no la del esquema: la que está cargada)

`clinica` · usuario `clinica_app` · MariaDB 10.4.32 · **66 tablas**: 32 de la aplicación y 34
`catalogo_*` con el diseño de `migracion_catalogos_admin.sql` (clave, nombre, descripción, activo y sus
llaves a entidades, municipios, localidades y unidades). 23 de esas 34 tienen datos cargados.

| Tabla | Filas | Nota |
|---|---|---|
| `cie10` | **14,497** | la que consulta el autocompletado de diagnósticos |
| `medicamentos` | **1,983** | la que consulta el autocompletado de medicamentos |
| `pacientes` | 9 | todos en la institución única; ninguno dado de baja |
| `historial_clinico` | 3 | 1 consulta, 1 receta, 1 estudio |
| `usuarios` | 7 | 2 administradores, 3 médicos (1 dado de baja), enfermería y recepción — todos en Expediente |
| `instituciones` | 1 | **Expediente** (consultorio) |
| `paginas` | 124 | 87 notas, 34 bases de datos (32 son catálogos), 2 carpetas |
| `pagina_filas` | 2 | el espacio de trabajo está prácticamente vacío |
| `bitacora` | 459 | incluye los accesos anteriores a la consolidación |
| `sincronizacion_cola` | 320 | **0 enviadas**: no hay servidor central configurado |
| `citas` | 1 | programada |
| `firmas` | 1 | tabla sin DDL en ningún `.sql` (ver hallazgos) |
| `configuracion` | 17 | por institución; clave primaria `(institucion_id, clave)` |

Tablas notables que no están en `esquema.sql`: `cie10`, `medicamentos`, `firmas`, `imagenes_medicas`,
`asistente_memoria` (las tres primeras se crean en migraciones o a mano).

---

## 6. Un paciente tiene **tres capas** de campos

Entenderesto evita la mitad de las confusiones al tocar el registro:

1. **Columnas de `pacientes`** (21 campos del formulario + folio, curp, foto, fechas).
2. **`extras` → `_perfil`**: los 11 campos de "formato largo" (estado civil, ocupación, escolaridad,
   afiliación, tutor y 6 antecedentes). No hay DDL; se guardan en el JSON.
3. **`extras` → propiedades del administrador**: claves aleatorias `p` + 6 hex, definidas en
   `config('pacientes_propiedades')` y editables desde la tabla (botón Propiedades).

Además `config('pacientes_columnas')` guarda **renombres y columnas ocultas** de la tabla de pacientes,
y `extras['_diagnosticos_estado']` guarda el estado de cada diagnóstico del historial.

Consecuencia práctica: agregar un campo "de perfil" obliga a tocar **tres** archivos
(`pacientes/registro.php:11`, `api/pacientes/actualizar.php:36`, `pacientes/ver.php:20`), mientras que
agregar una propiedad desde la interfaz no requiere código.

---

## 7. Convenciones que hay que respetar al modificar

1. `require inicio.php` primero; `define('RESPUESTA_JSON', true)` antes si es endpoint.
2. Escapar siempre con `e()`; nada de `echo $variable` crudo (hay CSP estricta).
3. SQL con PDO preparado y **siempre** `institucion_id = ?` (multi-clínica).
4. Permisos con `puede()` / `exigirPermiso()`; la matriz está en `app/nucleo/seguridad.php:142`.
5. Configuración con `config()` / `guardarConfig()`; secretos cifrados con `cifrar()`.
6. Toda escritura clínica deja rastro con `registrarBitacora()`.
7. CSS y JS nuevos: basta dejarlos en `assets/css/modulos/` o `assets/js/modulos/` (se cargan solos).
8. Los errores nunca se muestran: van a `privado/registros/php-errores.log` (`display_errors=0`).
9. Scripts inline necesitan `nonce="<?= nonceCsp() ?>"`.
10. Textos en español de México, sin frameworks ni dependencias nuevas si se puede.

---

## 8. Hallazgos (por prioridad)

### 8.1 Alta: el acceso automático convierte cualquier visita en administrador
`config/config.php:39` tiene `ACCESO_AUTOMATICO = true`; `app/nucleo/inicio.php:27-39` entra como el
primer administrador activo sin contraseña ni código. En `localhost` es cómodo; **si esto se publica, es
un expediente clínico abierto**. Para producción: `false`.

### 8.2 Resuelto: la lista de pacientes se veía vacía
La sesión automática entraba como **usuario #1**, que había quedado en la **institución #4 "Clinica Test"**
(0 pacientes). Se consolidó todo en **Expediente** (institución 1) y el usuario #1 volvió a ella: la lista
muestra los 9 pacientes. Sigue en pie **8.1**: apagar el acceso automático en producción.

### 8.3 Resuelto: los catálogos ya están conectados a sus páginas

Antes había tres piezas sueltas: el menú buscaba una **nota** con el título exacto (las páginas de catálogo
son bases de datos, nunca las encontraba), esas páginas eran **privadas** y estaban **vacías**, y los datos
reales vivían en las tablas `cie10` y `medicamentos`, que solo usaba el autocompletado del formulario.

Quedó con una sola fuente de verdad por catálogo:

1. **`app/datos/catalogos_sistema.php`** — registro único: título de la página, **tabla real**, sus
   columnas tal como están en el DDL, sus uniones para mostrar nombres legibles, búsqueda y orden. El
   menú, el visor y la creación de páginas leen de aquí; no se inventa ningún diseño.
2. **`catalogos/editar.php`** — resuelve la clave del menú a la **página** del catálogo (por título
   normalizado: sin acentos, sin paréntesis, sin mayúsculas), la comparte si estaba privada y, si no
   existe, la crea marcada con su origen.
3. **`paginas/ver.php`** + **`app/vistas/componentes/catalogo_pagina.php`** — si la página está marcada con
   `{"origen":"catalogo_procedimientos"}` (o cualquier otra clave), muestra **su tabla** en solo lectura,
   con búsqueda y paginación de 100 en 100, y un botón **Editar catálogo** cuando hay un editor dedicado
   (`/cie10/`, `/medicamentos/`).
4. Las 34 tablas `catalogo_*` se restauraron con el diseño del proyecto y se poblaron con
   `database/llenar_catalogos_completo.sql`; los catálogos nacionales (entidades, municipios, CP, CLUES)
   no se filtran por institución, los clínicos sí.
5. **`api/catalogos/actualizar.php`** — guarda la edición en línea de CIE-10 y medicamentos con sus reglas
   y la deja en la bitácora.

Comprobado: las 34 entradas del menú abren su página; el visor de CIE-10 muestra 14,497 registros
(145 páginas) y el de medicamentos 1,983 (20 páginas).

### 8.4 Media: `usuario_aplicacion.sql` sigue incompleto
Ya se alinearon `cie10` y `medicamentos` (SELECT/INSERT/UPDATE/DELETE) y `firmas` (SELECT/INSERT/UPDATE),
que es lo que necesitan el editor de catálogos y la firma de notas. Faltan en el archivo permisos que la
base viva sí tiene: `imagenes_medicas`, `asistente_memoria` y `recuperaciones_clave`.

### 8.5 Media: el esquema no es reproducible desde cero
`firmas` no tiene `CREATE TABLE` en ningún `.sql` (solo existe en la base viva). Las 34 tablas
`catalogo_*` sí siguen su migración, pero `usuario_aplicacion.sql` debe incluir sus 34 `GRANT` (ya están,
al final del archivo) o la app no podría leerlas en una instalación nueva.

### 8.6 Baja: en edición hay que volver a marcar la casilla de confirmación
`registro.php:464` no viene marcada y el servidor la exige también al editar (`registro.php:102`), así que
guardar un cambio de teléfono obliga a re-confirmar el origen de los datos.

### 8.7 Baja: las gráficas del perfil solo conocen 4 tipos de nota
`pacientes/ver.php:74-75` define colores y nombres solo para consulta, receta, hospitalización y estudio;
`TIPOS_REGISTRO` ya tiene 10 (urgencias, interconsulta, referencia, egreso, consentimiento, incapacidad).
Esas notas no aparecen en la gráfica.

### 8.8 Baja: 320 filas en la cola de sincronización, 0 enviadas
Normal sin servidor central (`configuracion → Sincronización`), pero la cola solo se purga de lo enviado
hace más de 30 días: crecerá indefinidamente mientras no haya central.

### 8.9 Higiene
- `privado/credenciales.txt` guarda credenciales en claro (protegido por `.htaccess`, pero mejor fuera de
  `htdocs` o cifrado).
- **No hay repositorio git**: 4,098 archivos sin historial. Un `git init` con `.gitignore` para
  `privado/`, `vendor/`, `models/stt/` y `transcribe-cpp/` daría red de seguridad.
- Comentarios con doble codificación (mojibake) en `.htaccess`, `app/nucleo/inicio.php` y
  `catalogos/editar.php` ("Clínicos"). Es cosmético, pero indica guardados en UTF-8 sobre texto ya UTF-8.
- El log tiene advertencias de un `test_dictado2.php` de pruebas ya eliminado y de una `$control`
  indefinida en `nuevo_registro.php` (esa línea ya está corregida en el archivo actual).

---

## 9. Recetario: "quiero cambiar X, ¿dónde lo toco?"

| Quiero… | Tocar |
|---|---|
| agregar un campo al registro (columna real) | DDL en `database/` + `pacientes/registro.php` (arreglo `$campos` y HTML) + `api/pacientes/actualizar.php` (`$editables`) |
| agregar un campo de perfil (JSON) | `registro.php:11-15`, `api/pacientes/actualizar.php:36-41`, `pacientes/ver.php:20`, y pintarlo en el HTML |
| agregar una propiedad sin código | tabla de pacientes → botón **Propiedades** (`api/pacientes/propiedades.php`) |
| cambiar una validación del registro | `pacientes/registro.php:55-135` (y replicar en `api/pacientes/actualizar.php`) |
| agregar un tipo de nota clínica | `app/datos/registros_clinicos.php` (constante `TIPOS_REGISTRO`) + `ENUM` de `historial_clinico` + `app/servicios/clinica/registros.php` (validación) + formulario en `nuevo_registro.php` |
| cambiar quién puede qué | `app/nucleo/seguridad.php` (`PERMISOS`, `ROLES`) o Configuración → Personas y permisos |
| cambiar colores/estilos | `assets/css/estilos.css` y `assets/css/modulos/*.css` |
| cambiar el título/subtítulo de una sección | Configuración → Textos, o `textoEditable()`/`atributosEditable()` |
| agregar un endpoint | `api/<área>/<nombre>.php` con `RESPUESTA_JSON` + CSRF por cabecera `X-CSRF-Token` |
| conectar un catálogo nuevo | una entrada en `app/datos/catalogos_sistema.php` (título y columnas; si tiene tabla real, `tabla` y `editor`): el menú y la página se resuelven solos |
| que una página muestre una tabla real | marcar su contenido con `{"origen":"cie10"}` (o `medicamentos`) |
| reconstruir la base | `database/esquema.sql` → `usuario_aplicacion.sql` → `privado/bd.php` → migraciones de catálogos y semillas |

---

## 10. Siguiente paso recomendado (en este orden)

1. Poner `ACCESO_AUTOMATICO = false` antes de cualquier demostración o publicación.
2. `git init` + primer commit (sin `privado/`, `vendor/`, modelos), ahora que el árbol está ordenado.
3. Revisar en Configuración el nombre y los datos de la institución Expediente (el nombre quedó provisional).
4. Decidir si se borran las 34 tablas `catalogo_*` vacías junto con su migración y sus `GRANT`.
5. Completar en `usuario_aplicacion.sql` los permisos de `imagenes_medicas`, `asistente_memoria` y
   `recuperaciones_clave`, y llevar el DDL de `firmas` a `esquema.sql`.
6. Si los catálogos vacíos se van a usar, importar los datos oficiales (CNIS / INEGI / OMS).

---

## 11. Importar y exportar (verificado)

Es la pieza que debe aguantar cualquier archivo. Se probó con 17 archivos reales (CSV con BOM y punto y
coma, CSV con comas dentro de comillas, TSV, XLSX con fechas y teléfonos numéricos, JSON, NDJSON, XML,
volcado SQL de MySQL, SQLite, HL7 v2, FHIR, Windows-1252, encabezados repetidos, filas descuadradas,
columnas obligatorias ausentes, archivo vacío y solo encabezados).

**Resultado:** los 12 formatos se analizan y validan sin error; el único rechazo es el archivo vacío, con
mensaje claro. Los casos límite se comportan bien: detecta Windows-1252 y conserva acentos, avisa de la
columna obligatoria que falta ("Falta Sexo"), marca fila por fila las descuadradas, no confunde encabezados
repetidos y valida el dígito verificador de la CURP.

**Normativa:** la importación **no** marca confirmacion_datos (la confirmación del paciente solo se
obtiene en el formulario), exige nombre, primer apellido, fecha de nacimiento y sexo, detecta duplicados
por CURP y por nombre+fecha, y cada conversión queda en conversiones con SHA-256 y en la bitácora.

**Exportación:** probada en CSV, XLSX, JSON, HL7 v2, FHIR y SQL (MySQL/PostgreSQL/SQLite); escribe el
archivo, lo registra y deja bitácora por cada paciente exportado.

**Prueba repetible:** mantenimiento/probar_conversion.php escribe una tabla en los 10 formatos, la vuelve
a leer y compara filas y columnas. Hoy: **10 de 10**.

**Pendiente para la reestructura:** la importación solo escribe en Pacientes o en una base nueva del
espacio; falta un destino "catálogo del sistema" (mapear clave/nombre/descripcion/activo a
catalogo_*, cie10 o medicamentos) y plantillas descargables por destino. Es el siguiente paso
natural para cargar CNIS/INEGI/OMS desde archivo.

---

## 12. Correcciones de visualización aplicadas

- Anclas #historial y #notas-seccion con scroll-margin-top: ya no quedan debajo de las barras fijas.
- Columna lateral pegajosa con 	op calculado y altura máxima con scroll: no se mete bajo las barras ni
  se corta.
- Resumen del expediente: 7 columnas (4 en pantallas medianas) para sus 7 datos.
- Etiquetas clínicas largas ya no se recortan con "…".
- Una alergia **negada** ("ninguna", "niega", "sin alergias") ya no se pinta en rojo en el documento ni en
  el formulario; sigue en rojo una alergia real (p. ej. "Penicilina").
- Títulos con su fuente y cadenas largas con overflow-wrap (nombres, rutas DICOM).
- Barra de desplazamiento visible en la tabla del historial; firma que no se separa al imprimir.

Pendientes de la auditoría (no aplicados): encabezado h2 antes del h1 en el expediente, pie de página en
la impresión del navegador (@page margin boxes no soportadas) y el resumen duplicado al expandir una fila.
