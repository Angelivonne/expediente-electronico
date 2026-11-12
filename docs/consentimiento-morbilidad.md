# Consentimiento para estadísticas agregadas de morbilidad

Este flujo es distinto de los consentimientos clínicos para procedimientos. El permiso se registra por paciente, institución y destinatario; requiere una copia firmada PDF/JPG/PNG. La aplicación conserva el texto exacto mostrado, su versión y huella SHA-256, quién lo registró, fechas y un historial de otorgamiento/revocación de solo inserción.

## Alcance del informe

La versión `1.0` autoriza calcular y entregar estadísticas agregadas de morbilidad al destinatario específico escrito en el consentimiento. Cada exportación:

- solo toma en cuenta autorizaciones vigentes de la versión actual y para el destinatario seleccionado;
- antes de exportar, vuelve a comprobar las huellas SHA-256 del texto aceptado y del archivo firmado;
- omite nombre, CURP, folio, edad y sexo;
- suprime diagnósticos con menos de cinco pacientes autorizados;
- registra la exportación y el destinatario en la bitácora.

El umbral de cinco reduce el riesgo de identificación, pero no garantiza anonimización ante cruces con otras fuentes o comparaciones entre informes. Revisa cada archivo antes de entregarlo. La revocación impide incluir a esa persona en informes generados posteriormente; no puede retirar archivos que ya fueron descargados o entregados.

## Instalación y operación

Las tablas se crean en instalaciones nuevas desde `database/esquema.sql`. En instalaciones existentes, aplicar `database/migracion_consentimiento_morbilidad.sql` con una cuenta que pueda crear tablas; el reparador también intenta crearlas cuando faltan y hay privilegios disponibles. Aplicar después los permisos de `database/usuario_aplicacion.sql`; el usuario de aplicación no necesita privilegio `CREATE`.

Desde el expediente del paciente, personal con permiso `consentimiento_morbilidad` puede registrar o revocar una autorización. Antes de registrar, debe revisar que el paciente o representante recibió y firmó el texto completo para el destinatario indicado, adjuntar la copia y confirmar que coinciden. El archivo queda en `privado/subidas/consentimientos_morbilidad/`, fuera del acceso web directo. Solo el mismo permiso puede descargarlo y cada consulta se audita.

## Límites importantes

La aplicación registra la declaración del personal y conserva los archivos, pero no verifica identidad, capacidad legal, autenticidad de firmas ni que el archivo corresponda al documento original. La huella SHA-256 ayuda a detectar cambios del archivo guardado; no es una firma electrónica ni una certificación. El texto es un borrador operativo y debe revisarlo el responsable clínico/jurídico antes de usarlo con pacientes. No constituye una afirmación de cumplimiento legal o de la NOM.

Al cambiar el texto, incrementar `VERSION_CONSENTIMIENTO_MORBILIDAD`; las versiones anteriores dejarán de habilitar exportaciones y deberá recabarse autorización para la versión vigente.
