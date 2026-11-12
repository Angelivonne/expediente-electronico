# Seguridad del sistema (mapa completo del código)

Documento de estudio: qué protege el sistema, con qué mecanismo y en qué archivo vive.
Referencias normativas que el propio código cita: NOM-004, NOM-024 y LFPDPPP.

---

## 1. Errores y cabeceras HTTP

Archivo: `app/nucleo/seguridad.php`, `app/nucleo/inicio.php`

- **Nunca se muestran errores al usuario**: `display_errors=0`, `log_errors=1`; todo va a
  `privado/registros/php-errores.log`. Un `set_exception_handler` responde 500 con un mensaje
  genérico ("Ocurrió un error interno") en HTML o JSON.
- El BAT principal de arranque y el instalador ejecutan `mantenimiento/validar_fuentes.php`;
  detienen el proceso si los archivos PHP esenciales faltan, están vacíos, tienen bytes nulos,
  fallan al analizarse o `seguridad.php` ya no define `configurarErrores()`. El arranque también
  permite ejecutarlo sin iniciar servicios con `iniciar_clinica.bat validar`.
- **HTTPS obligatorio** fuera de loopback: si la petición no es HTTPS y no viene de
  `127.0.0.1`/`::1`, responde 403.
- **CSP con nonce** por petición (`script-src 'self' 'nonce-…'`), `object-src 'none'`,
  `frame-ancestors 'none'`, `base-uri`/`form-action` en `'self'`.
- **Anti-clickjacking**: `X-Frame-Options: DENY`.
- **Otros**: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
  `Cross-Origin-Opener-Policy: same-origin`, `Permissions-Policy` (micrófono propio, cámara/geo/USB
  bloqueados), `Strict-Transport-Security` cuando hay HTTPS.
- **Sin caché de datos clínicos**: `Cache-Control: no-store, max-age=0`.
- `header_remove('X-Powered-By')`.

---

## 2. Sesión

Archivo: `app/nucleo/seguridad.php` (`iniciarSesionSegura`)

- Cookie `expediente_sesion` con `HttpOnly`, `SameSite=Lax`, `Secure` automático si hay HTTPS,
  `use_strict_mode`, `use_only_cookies`.
- **Inactividad máxima: 30 min** (`MINUTOS_INACTIVIDAD`).
- **Duración máxima: 12 h** (`HORAS_SESION_MAXIMA`).
- **Regeneración del ID cada 15 min** y al iniciar/cerrar sesión (evita fijación de sesión).
- Sesión vacía y regenerada al vencer; aviso "Tu sesión se cerró por inactividad".

### Actividad del personal

- `api/equipo/tiempo.php` registra segundos activos aproximados de la sesión autenticada mientras la
  pestaña está visible y el usuario tuvo interacción reciente. No recibe pulsaciones ni contenido.
- El dato se limita a la cuenta actual, la clínica y el día; el hash de sesión no permite recuperar
  el identificador de sesión. El perfil lo etiqueta como estimación, no como asistencia.
- Cada usuario puede consultar su propio detalle. El detalle de otras personas requiere el permiso
  `auditoria_equipo`, concedido solo a administración de forma predeterminada.
- La cuadrícula del perfil cuenta acciones de crear, actualizar y eliminar que estén presentes en
  `bitacora`; no afirma que todas las escrituras posibles del sistema estén auditadas.
- Los cursores colaborativos solo se envían a personas que comparten la misma sección o página,
  requieren `ver_expediente`, se validan como coordenadas normalizadas y vencen a los 12 segundos.
  Cada usuario puede ocultarlos localmente; no se guarda un historial de posiciones.
- La retroalimentación del equipo se guarda separada de los comentarios clínicos. Autor y personal
  con `auditoria_equipo` pueden verla; el destinatario solo ve notas compartidas explícitamente por
  un revisor. La interfaz advierte no incluir datos de pacientes y no publica conteos ni calificaciones.

---

## 3. Inicio de sesión (3 pasos)

Archivo: `acceso/index.php`, `app/servicios/cuenta/acceso.php`, `acceso/verificar.php`

- **Paso 1 y 2**: correo + contraseña.
- **Paso 3**: código de **10 dígitos** por correo (`CODIGO_DIGITOS`), vence en 10 min,
  máximo 5 intentos, reenvío cada 60 s. En base solo queda `password_hash` del código.
- **Hash Argon2id** (respaldo bcrypt) para contraseñas; `password_needs_rehash` al entrar.
- **Contraseña robusta** (`claves.php`): 12–128 caracteres, al menos 3 de 4 tipos
  (minúscula/mayúscula/número/símbolo), sin 4 repeticiones seguidas, fuera de lista de comunes,
  sin contener correo ni nombre.
- **Anti-enumeración de usuarios**: si el correo no existe se verifica contra `HASH_SIMULADO`
  (hash Argon2id de una cadena aleatoria) para igualar el tiempo de respuesta. El mensaje es
  siempre "Correo o contraseña incorrectos".
- **Límites de intentos** (tabla `limites`, clave hasheada SHA-256):
  - 20 intentos por IP / 15 min,
  - 5 fallos por correo+IP / 15 min,
  - 30 fallos por correo / 15 min (frena ataques repartidos).
- **Modo desarrollo** (`MAIL_MODO='archivo'`) guarda los correos como `.eml` en
  `C:/xampp/mailoutput` y permite saltar el paso del código.
- **Acceso automático** opcional (`ACCESO_AUTOMATICO`), desactivado por defecto.

---

## 4. Código / aplicación web

Archivo: `app/nucleo/funciones.php`, endpoints en `api/`

- **CSRF**: token de 32 bytes en sesión (`tokenCsrf`), campo oculto `campoCsrf()`,
  comparación con `hash_equals` (`csrfValido`). Los endpoints JSON exigen cabecera
  `X-CSRF-Token` con el mismo token (p. ej. `api/codigos/crear.php`).
- **Escapado de salida**: `e()` = `htmlspecialchars(ENT_QUOTES, UTF-8)` en todo el HTML.
- **Un solo envío de formulario**: `clave_envio` + `envioYaProcesado` / `recordarEnvio`
  (evita duplicados por doble clic, reenvío o reintento tras un corte).
- **Idempotencia de POST JSON**: cabecera `X-Idempotencia` + tabla `peticiones_idempotentes`
  (`app/nucleo/idempotencia.php`) para la cola sin conexión del navegador.
- **Validación de entrada**: `filter_var`, `ctype_digit`, `preg_match`, topes con `mb_substr`,
  `json_decode` y verificación de tipos; se rechaza lo que no cumple.
- **Saneado de texto** (mojibake): `sanoTexto` / `saneaProfundo`.
- **Redirecciones** con `URL_BASE` (no con `Host`).
- El CSV agregado de Morbilidad para compartir omite diagnósticos con menos de cinco personas y
  no exporta edades, sexo ni identificadores. Las gráficas se generan en un directorio temporal
  por solicitud y se incrustan en la respuesta; no se guardan como imágenes públicas compartidas.

---

## 5. Base de datos

Archivos: `config/db.php`, `database/usuario_aplicacion.sql`, `database/esquema.sql`

- **PDO con consultas preparadas** (`ATTR_EMULATE_PREPARES = false`), modo excepción,
  `utf8mb4`.
- **Usuario MySQL de privilegios mínimos** `clinica_app` (nunca `root`); credenciales en
  `privado/bd.php` (fuera de la web). Permisos por tabla:
  - `SELECT, INSERT, UPDATE` en datos operativos,
  - **`historial_clinico` y `bitacora`: solo `SELECT, INSERT`** (no se pueden modificar ni borrar),
  - `limites`/espacio de trabajo: también `DELETE`.
- **Usuario aparte para respaldos** `clinica_respaldo` (`privado/respaldo_bd.php`); la app normal
  no puede borrar tablas.
- **Multitenencia**: casi todas las consultas filtran por `institucion_id`; `puedeVerExpediente()`
  comprueba que el paciente sea de la institución de la sesión.
- `database/migracion_equipo.sql` concede únicamente SELECT/INSERT/UPDATE en las tablas nuevas
  de actividad y retroalimentación; no concede borrado desde la aplicación.
- **Respaldos**: `.sql.gz` + suma **SHA-256** al lado, rotación, verificación de integridad,
  candado para evitar respaldos simultáneos (`app/servicios/sistema/respaldos.php`).
- **Secretos cifrados**: `configuracion` guarda valores sensibles con **AES-256-GCM**
  (`cifrar`/`descifrar`); la llave de 32 bytes vive en `privado/llave.bin` (creada de forma atómica).

---

## 6. Permisos por rol

Archivo: `app/nucleo/seguridad.php`

- Roles: `administrador`, `medico`, `enfermeria`, `recepcion` y `paciente`.
- Permisos: `ver_expediente`, `editar_paciente`, `historial`, `agenda`, `conexiones`,
  `asistente`, `configurar`, `importar_exportar`, `finanzas`.
- `exigirPermiso()` corta con 403 (JSON o página breve); `puede()` consulta el rol de la sesión.
- El administrador siempre conserva `configurar` y `conexiones`.
- **Portal del paciente**: solo abre rutas de `RUTAS_PACIENTE`, y cada una verifica que el dato
  sea de su propio expediente (protección IDOR, incluida su foto).

---

## 7. Validación de la cédula profesional

Archivo: `app/servicios/cuenta/cedula.php`

- **Formato**: 7 u 8 dígitos (`cedulaFormatoValido`).
- **No duplicada**: `cedulaRegistrada` (profesional o de especialidad).
- **Verificación en el RNP (SEP)** solo si se configura `CEDULA_SERVICIO_URL`:
  - petición por **HTTPS**, `CURLOPT_SSL_VERIFYPEER=true`, sin seguir redirecciones,
    timeouts de 4 s (conexión) y 8 s (total);
  - compara **número** y **nombre** normalizado (mayúsculas, sin acentos, sin partículas
    "DE/DEL/LA…");
  - estados: `pendiente`, `verificada`, `no_coincide`, `no_encontrada`.
- **Nunca se marca verificada sin respuesta real** del RNP; si no hay servicio/internet queda
  "pendiente" y no bloquea el trabajo.
- La cédula es obligatoria para **firmar** notas (NOM-004 5.10).

---

## 8. Firma electrónica del historial clínico

Archivo: `app/servicios/clinica/firmas_clinicas.php`

- Huella **SHA-256** sobre una cadena canónica del registro (id, paciente, tipo, fecha, título,
  detalle, autor, creado_en).
- Se guarda quién firmó (nombre y cédula), fecha y huella.
- Verificación posterior con `hash_equals` (`firmaRegistroClinicoValida`).
- **Revocación** solo por quien firmó o un administrador, y **con motivo obligatorio**; queda en
  bitácora.

---

## 9. Códigos de acceso (invitar personal/pacientes)

Archivo: `app/servicios/cuenta/codigos.php`

- Formato **Crockford base32**: `XXXX-XXXX-XXXX` = 12 caracteres = **60 bits** de entropía.
- En base solo se guarda **HMAC-SHA-256** (llave del servidor) y los **últimos 4** caracteres;
  el código completo se muestra una sola vez.
- Vigencia 1–30 días (7 por defecto), usos 1–50; el de paciente es de **un solo uso** y ligado
  a un expediente de la institución.
- **Límites**: 10 intentos/IP / 15 min y 20 intentos por código / hora; 5 fallos de identidad
  (fecha de nacimiento) por día para códigos de paciente.
- **Mensaje uniforme** si el código no existe, venció, se usó o se revocó (no revela cuál).
- Revocación con auditoría.

---

## 10. Recuperación de contraseña e invitaciones

Archivos: `app/servicios/cuenta/recuperacion.php`, `app/servicios/cuenta/invitaciones.php`

- Token de **256 bits**; en base solo **SHA-256**; **un solo uso**; vence en 30 min
  (invitación: 7 días).
- Pedir uno nuevo **invalida los anteriores**.
- La respuesta es la misma exista o no el correo (anti-enumeración).
- El enlace se arma con **`URL_ORIGEN`** (config), nunca con la cabecera `Host`.
- Al cambiar contraseña: desbloquea la cuenta y **cierra las demás sesiones** (`cerrarOtrasSesiones`).
- Invitación: rol validado contra `ROLES`, token único, sin revelar cuentas de otras instituciones.

---

## 11. Subida de archivos

Archivo: `app/servicios/clinica/imagenes.php`

- Tamaño máximo **40 MB** por archivo.
- Tipo real con **`finfo` (MIME)**, no por extensión:
  - DICOM, `image/jpeg|png|webp` validado con `getimagesize`,
  - PDF validado por cabecera `%PDF-`.
- Nombre aleatorio de 64 hex + extensión controlada; se guarda **fuera de la web**
  (`privado/imagenes_medicas`), con `.htaccess` que niega el acceso directo.
- Hash **SHA-256** del archivo (deduplicación) y bitácora de subida/consulta/retiro.

---

## 12. Conexiones y comunicación entre clínicas

Archivos: `conexiones/index.php`, `app/servicios/clinica/conexiones.php`,
`database/migracion_conexiones_clinicas.sql`

- **Canal protegido**: solo HTTPS, o HTTP únicamente en loopback (localhost) para pruebas
  (`conexionesCanalProtegido`).
- **Convenio bilateral**: se adjunta el **PDF firmado**, se guarda su **SHA-256**; el vínculo
  pasa por estados `pendiente → conectada → rechazada/revocada`, con confirmación de **ambas**
  clínicas.
- **Autorización individual por paciente** (no basta el vínculo entre clínicas):
  - alcance `resumen` o `expediente`,
  - finalidad obligatoria,
  - **consentimiento firmado en PDF + SHA-256**,
  - fecha de firma y **vencimiento**,
  - revocación individual; al revocar la conexión se revocan todas sus autorizaciones.
- Solo el administrador solicita/acepta/rechaza/revoca; todo queda en bitácora.

### Sincronización entre instalaciones

Archivos: `app/servicios/sincronizacion/*`, `api/sincronizacion/*`

- **Sin sesión**: se autentica cada lote con **HMAC-SHA256** usando un token compartido.
- **Marca de tiempo ±5 min**, **nonce de un solo uso**, **respuesta también firmada**.
- Límite de **16 MB** por cuerpo, 1000 cambios por lote, control de frecuencia.
- **Idempotencia por UUID**; el **historial clínico solo se agrega** (no se sobrescribe).
- El token está atado a una institución: un registro de otra institución se **rechaza**.

---

## 13. Auditoría y trazabilidad (NOM-024)

- **Almacenamientos separados lógicamente** (tablas distintas dentro del mismo esquema MySQL):
  `asistente_memoria` guarda recuerdos del asistente por usuario; `agentes_memoria` y
  `agentes_conversaciones` guardan memoria e historial por agente; `notificaciones` conserva la
  bandeja y su `actor_id` cuando se conoce quién originó el aviso; `paginas`/`pagina_filas` contienen
  el espacio de trabajo. No se mezclan esos datos en una misma tabla ni son bases MySQL físicas
  independientes.
- Tabla **`bitacora`**: tabla, registro, acción, campo, valor anterior/nuevo, usuario, institución,
  IP y fecha.
- Registro inmutable a nivel MySQL (solo `INSERT`).
- Las filas de bases de datos del espacio de trabajo guardan eventos por fila/celda, con usuario,
  hora del servidor, página, fila y campo. No se copian valores de celdas a la bitácora; la persona
  con acceso a la página puede revisar el historial y exportarlo a CSV. Si no se puede escribir la
  evidencia de una mutación de fila, la operación se revierte. Este seguimiento no reconstruye
  cambios anteriores a su instalación.
- Este historial es evidencia técnica de actividad, no una firma digital ni una certificación legal.
- Se registra: accesos, altas/cambios/bajas, firmas y revocaciones, creación/uso/revocación de
  códigos, autorizaciones entre clínicas, subida/consulta de imágenes, respaldos.
- Las notas dictadas conservan, con revisión expresa, el texto reconocido, el autor, la hora del
  servidor y huellas SHA-256 del texto y de los campos finales. La sesión vincula la transcripción
  pendiente a un formulario, paciente y tipo de nota por un máximo de 24 horas; no se almacena el
  audio. Las huellas ayudan a detectar cambios, pero no son una firma electrónica ni una garantía
  legal. La IA no recibe el texto fuente al leer el expediente; la vista de trazabilidad queda
  disponible al personal autorizado.
- La herramienta de trazabilidad de medicamentos de la IA es de solo lectura y limita sus consultas
  a los registros de la institución. Informa hechos documentados de recetas y Kardex; no decide si
  una dosis es segura, no diagnostica y no atribuye causalidad o responsabilidad.

---

## 14. Resumen rápido (tabla)

| Área | Mecanismo principal |
|------|---------------------|
| Contraseñas | Argon2id + política robusta |
| 2.º factor | Código 10 dígitos por correo |
| Sesión | Cookie HttpOnly/SameSite/Secure, 30 min / 12 h, regeneración |
| CSRF | Token de sesión + `hash_equals` / `X-CSRF-Token` |
| XSS | `e()` (htmlspecialchars) + CSP con nonce |
| SQL injection | PDO preparado, sin emulación |
| Permisos | Roles + `puede()`/`exigirPermiso()` + institución |
| Cédula | Formato, unicidad, RNP HTTPS opcional |
| Firma clínica | SHA-256 + `hash_equals` + revocación con motivo |
| Códigos de acceso | Crockford 60 bits, HMAC, un uso, límites |
| Recuperación | Token 256 bits, SHA-256, 30 min, un uso |
| Archivos | MIME real, 40 MB, fuera de la web |
| Entre clínicas | Convenio PDF + SHA-256, autorización por paciente |
| Sincronización | HMAC-SHA256, nonce, ±5 min, idempotencia |
| Secretos | AES-256-GCM, llave en `privado/llave.bin` |
| Auditoría | Bitácora inmutable |
| Respaldos | `.sql.gz` + SHA-256, usuario aparte |
