# Recopilación de lo solicitado

Estado de cada petición hecha durante el desarrollo. ✅ hecho · ⏳ pendiente · 👤 requiere una acción tuya.

## Reglas generales
- ✅ Una sola base de datos MySQL (`expediente_expediente`).
- ✅ Estilo minimalista oscuro de las referencias; sin tarjetas con sombra, degradados, insignias ni emojis en la interfaz.
- ✅ Sin datos falsos: estados vacíos reales; se quitó el proveedor de IA simulado.
- ✅ Notificaciones y componentes con el estilo de las referencias; animaciones sutiles.
- ✅ Tipografía de la app; pestañas y ruta a 13 px como la barra lateral; textos informativos pequeños agrandados.
- ✅ Nombre "Expedientes Clínicos"; sin el logo de la "E".

## Acceso, registro y permisos
- ✅ Inicio de sesión en tres pasos: correo → contraseña → código de 10 dígitos (5–5) que llega por correo.
- ✅ Invitaciones por correo con rol (enlace de un solo uso, 7 días).
- ✅ Registro en tres pasos con el diseño de las referencias (01 / 03): datos y foto de perfil, rol (cédula obligatoria para médico), contraseña y permisos del rol.
- ✅ Personas y permisos: aprobar, rechazar, cambiar rol, desactivar, reenviar/cancelar invitaciones, matriz de permisos.
- 👤 Correo real: configurar SMTP (`MAIL_MODO = 'mail'`) y `URL_ORIGEN` con el dominio. Hoy los correos se guardan en `C:/xampp/mailoutput`.
- ✅ Aviso de privacidad (LFPDPPP) público por institución en `/aviso-privacidad.php`.
- 👤 Aviso de privacidad: completar datos del responsable y registrar evidencia de entrega/aceptación.
- ⏳ "Continuar con Google": aparece solo cuando haya credenciales OAuth (`GOOGLE_CLIENT_ID`).

## Barra lateral
- ✅ Estilo Arc plano con el contenido como tarjeta redondeada; secciones plegables (Favoritos, Expediente, Privado, Compartido, Pestañas abiertas) sin huecos al plegar.
- ✅ Foto de la clínica arriba, antes del buscador y de Inicio (la cambia el administrador).
- ✅ Buscador "Buscar o preguntar Ctrl+K", Inicio y acceso al asistente.
- ✅ Árbol carpeta → carpeta → nota o base de datos, arrastrar y soltar, menú "…" y "+".
- ✅ Historial, cerradas recientemente y papelera.
- ✅ Registrar paciente ya no está en la barra lateral: se hace desde la base de datos con "Nuevo".

## Pacientes
- ✅ Base de datos editable con todas las columnas del formulario de registro, agrupadas por sección.
- ✅ Botón azul "Nuevo" para registrar; lupa con paleta de búsqueda.
- ✅ Mapa de México con estados reales detrás del botón "i" (se despliega al pulsarlo).
- ✅ Perfil: propiedades, mini mapa, mapa de calor de actividad, gráficas pequeñas (barras y línea/área), historial clínico desplegable y barra de acciones que se expande.
- ✅ Dictado por voz local (Whisper) y modelo PyTorch que llena el formulario.

## Espacio de trabajo (notas)
- ✅ Notas con editor por bloques, comandos "/", atajos de Markdown, plantillas NOM-004, anotador con IA, importar texto.
- ✅ Bases de datos con propiedades tipadas y opciones de colores.
- ✅ Compartir (invitar, niveles de acceso, acceso general, copiar enlace), actividad al pasar el ratón y panel de Notificaciones/Estadísticas.
- ✅ Burbujas de perfil con "··· +N".
- ✅ Portadas: agregar, cambiar (galería o subir imagen) y quitar en notas y en toda sección con portada.
- 👤 Imágenes de banners: guardarlas en `assets/img/banners/` (aparecen en el registro y en la galería de portadas).
- ⏳ "Formulario" (creador de formularios) y "Reposicionar" la portada: no se construyeron.

## Asistente de IA
- ✅ Panel lateral y página completa con el diseño de las referencias y las mismas sugerencias en ambos.
- ✅ Razonamiento visible, respuestas en Markdown seguro con espacio entre párrafos.
- ✅ Herramientas (tool calling): crear notas, carpetas y bases de datos; escribir y editar notas; agregar columnas y filas; renombrar; con acceso al expediente también buscar y leer páginas y buscar pacientes. Todo con los permisos del usuario y en la bitácora.
- 👤 Pegar la API real (p. ej. OpenRouter) en ⚙ Configuración; sin ella el asistente no responde (no hay respuestas simuladas).

## Seguridad
- ✅ Usuario de MySQL con permisos mínimos; bitácora e historial inmutables.
- ✅ CSRF, CSP con nonce, sesión endurecida, límites de intentos, sin enumeración de cuentas, subidas validadas, carpetas internas bloqueadas.
- ✅ Auditoría de seguridad y correcciones: mover/eliminar páginas, ciclos, roles por endpoint, bloqueo de cuentas, carreras en el registro.
- ✅ IA en notas: consentimiento explícito para compartir el texto con el proveedor y casilla de revisión médica antes de aplicar; la IA nunca firma la nota. Modo `borrador_nota` sin herramientas del lado servidor.
- ✅ La IA no puede editar notas firmadas (`notaFirmada`) y el guardado usa candado optimista por versión.
- 👤 Producción: contraseña de root de MySQL/phpMyAdmin, HTTPS y `ServerTokens Prod` en Apache.

## Dictado por voz (ronda reciente)
- ✅ Panel de dictado movible y redimensionable, minimizable a bolita; transcripción conversacional sin cortes.
- ✅ Extracción de nombre y campos mejorada; autollenado de receta con catálogo de medicamentos.
- ✅ Dictado disponible en cualquier editor de notas (`/dictar` y botón del panel), no solo en el formulario.
- ⏳ Motor STT local definitivo (Parakeet TDT 0.6B v3, puerto 8766) pendiente de decidir; hoy Web Speech del navegador.

## Tarjetas y menús (ronda reciente)
- ✅ Tarjetas compactas con el lenguaje del menú de la nota: iconos pequeños que abren tarjetas, sin fondos de botón ni tipografías distintas.
- ✅ Menú lateral derecho unificado y ordenado; ficha lateral en la nota en lugar de tres puntos.
- ✅ Portada corta antes del nombre en las tarjetas; carga de portada corregida; "Cambiar portada" fuera del menú de la tarjeta.
- ✅ Foto y portada por paciente: subida y refresco unificados.

## Documentos clínicos (ronda reciente)
- ✅ Formato de impresión oficial mexicano con logo institucional y datos NOM-004 en notas y recetas.
- ✅ Firma visible y acomodada en la nota; la firma electrónica es acción explícita, nunca automática.
- ✅ Referencias normativas corregidas: transoperatoria/postoperatoria NOM-004 8.8; enfermería NOM-004 9.1; incapacidad LGS 79 · RIS 29.

## Motor de notas en Registrar paciente (ronda reciente)
- ✅ Los campos clínicos del registro y de la ficha abren el motor real de notas (Editor por bloques): mismo contenedor redondeado, título `hoja__titulo`, comandos "/", clic derecho, menús de bloque, colores, IA y dictado.
- ✅ Los bloques de cada campo se guardan saneados en `extras['_bloques']` (formulario y edición en línea) y se recargan al editar; el texto plano viaja como siempre.
- ✅ Aislamiento por campo e institución; sin XSS (textContent + saneador de lista blanca); pruebas de servidor y de navegador en sesión real.
- 👤 Verificación visual final con el panel de navegador abierto (las pruebas estructurales pasaron; la pestaña en segundo plano no anima).
