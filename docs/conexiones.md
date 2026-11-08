# Conexiones con clínicas

La sección vincula clínicas que ya están registradas en la misma base de datos de ExpedienteExpediente. No enlaza todavía instalaciones independientes ni envía datos a un servidor remoto.

## Flujo de acceso

1. Una clínica solicita el vínculo y carga el convenio firmado por ambas instituciones.
2. La clínica invitada revisa el convenio y confirma la conexión.
3. La clínica que conserva el expediente registra una autorización firmada para un paciente, una institución receptora, un alcance y una finalidad, con fecha de vencimiento.
4. Solo el personal con el permiso **Conexiones** (administración y medicina por defecto) puede buscar expedientes compartidos.
5. Revocar el vínculo invalida las autorizaciones que dependían de esa conexión. La revocación individual también está disponible.

Los PDFs se guardan en `privado/conexiones/` con nombre aleatorio, permisos de archivo limitados y acceso web denegado. La aplicación conserva una huella SHA-256 y registra solicitudes, aprobaciones, búsquedas, aperturas y revocaciones en la bitácora.

## Requisitos para activar

- Aplicar `database/migracion_conexiones_clinicas.sql` con una cuenta administrativa de MySQL. El usuario de aplicación `expediente_app` no puede crear tablas ni otorgar permisos; el script crea las tablas y asigna privilegios mínimos al usuario de aplicación.
- La instancia permite cargar documentos y consultar datos si el sitio usa HTTPS, o si el acceso HTTP llega únicamente desde `localhost` por loopback (`127.0.0.1`/`::1`) en la misma computadora. Una conexión HTTP desde otra computadora o dirección de red queda bloqueada.
- Para operar entre computadoras y clínicas, configura HTTPS con un certificado confiable. El certificado SSL instalado actualmente en localhost no es confiable para el navegador, así que esa ruta aún no está habilitada en esta PC.
- Usar únicamente convenios y autorizaciones ya firmados por las personas e instituciones correspondientes. La sección almacena la evidencia; no redacta ni sustituye documentos legales.

## Alcance actual

Las clínicas que aparecen en el directorio pertenecen a la misma instancia y base de datos. Para enlazar clínicas con servidores separados hace falta desarrollar y configurar un servicio remoto autenticado con certificados, TLS, políticas de interoperabilidad y una revisión de los convenios y formatos de autorización aplicables. No se debe abrir la búsqueda clínica remota por HTTP ni compartir credenciales de base de datos entre instituciones.
