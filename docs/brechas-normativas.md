# Brechas normativas del expediente clínico electrónico

Análisis del proyecto contra **NOM-004-SSA3-2012** (expediente clínico) y **NOM-024-SSA3-2012**
(sistemas de información de registro electrónico para la salud). No es una declaración de cumplimiento ni
sustituye la evaluación de un organismo certificador: es una revisión técnica de lo que el sistema hace hoy.

Fuentes consultadas:
- DOF, NOM-004-SSA3-2012: https://dof.gob.mx/nota_detalle.php?codigo=5272787&fecha=15/10/2012
- DOF, NOM-024-SSA3-2012: https://dof.gob.mx/nota_detalle_popup.php?codigo=5280847
- DGIS, Certificación NOM-024: http://dgis.salud.gob.mx/contenidos/intercambio/certificacion-nom-024-ssa3-2012.html

## Lo que ya cumple (verificado en código)

| Requisito | Estado | Evidencia |
|---|---|---|
| Notas con fecha, hora, nombre y firma (NOM-004 5.10) | Cumple | `historial_clinico` + `firmas_clinicas` (SHA-256, cédula, sello, revocación, verificación) |
| Identificación del paciente (nombre, sexo, edad, domicilio) | Cumple | Tabla `pacientes`, ficha y documento clínico |
| Notas de consulta, urgencias, interconsulta, referencia, egreso, receta, estudio, hospitalización, consentimiento, incapacidad | Cumple | `TIPOS_REGISTRO` (10 tipos) y validación `validarRegistroClinico()` |
| Consentimiento informado con firmas (NOM-004 10.1) | Cumple | Documento de consentimiento con firmas de paciente, médico y testigos |
| Receta con dosis, vía y periodicidad (NOM-004 6.2.6) | Cumple | Validación y documento de receta |
| Registro de auditoría (NOM-024 3.42) | Cumple a nivel de aplicación | `bitacora`; `clinica_app` solo tiene SELECT/INSERT sobre la tabla |
| Control de acceso y roles (NOM-024) | Cumple | `PERMISOS`, roles, sesiones de 30 min de inactividad, bloqueo por intentos |
| Contraseñas | Cumple | Mínimo 12 caracteres, lista de claves comunes, `password_hash` |
| Respaldo | Parcial | Respaldo diario `mysqldump` con SHA-256; falta probar restauración y cifrar el archivo |
| Catálogos mínimos (CIE-10, medicamentos, entidades, etc.) | Parcial | 36 fuentes; CIE-10 y medicamentos completos; otros catálogos vacíos |
| Interoperabilidad | Parcial | Importación/exportación **HL7 v2 (ADT)** y **FHIR** de pacientes; falta el intercambio SIRES/IHE completo |
| Aviso de privacidad (LFPDPPP) | Parcial | Página pública `/aviso-privacidad.php` por institución; falta evidencia de entrega/aceptación y flujo ARCO |
| Firma electrónica avanzada (NOM-024 3.22) | Falta | El sello actual es huella SHA-256 + cédula; no hay certificado/PKI ni firma con clave privada |
| Retención 5 años del expediente (NOM-004 5.4) | Falta | No hay política de retención, archivo histórico ni bloqueo de borrado por antigüedad |
| Historias clínicas: grupo étnico, interrogatorio por aparatos y sistemas | Parcial | Hay antecedentes y exploración en texto libre; faltan campos estructurados |
| Notas preoperatoria, transoperatoria y postoperatoria (NOM-004 8.5, 8.8) | Cumple | Tipos `preoperatoria`, `transoperatoria`, `postoperatoria` con validación y documento |
| Nota de enfermería (NOM-004 9.1), trabajo social y nutrición | Parcial | Enfermería existe; trabajo social y nutrición no |
| Hoja frontal / resumen clínico independiente / contrato | Parcial | `resumen_clinico` (NOM-004 4.10) existe; faltan hoja frontal y contrato |
| Cifrado de datos en reposo y de respaldos | Falta | Secretos cifrados con AES-256-GCM, pero la base y los respaldos no están cifrados |
| SGSI, análisis de riesgos y evaluación de conformidad | Falta | No hay documentación de gestión de seguridad ni certificación externa |

## Brechas priorizadas

### P0 — Legal y seguridad
1. **Aviso de privacidad** (LFPDPPP): evidencia de entrega/aceptación por paciente y flujo ARCO documentado.
2. **Firma electrónica avanzada**: certificado/PKI, firma con clave privada y verificación de identidad; o validar
   jurídicamente el sello actual antes de usarlo como firma legal.
3. **Retención de 5 años**: archivo histórico, política de no borrado y bitácora de conservación.
4. **Respaldos**: cifrado en reposo y **prueba de restauración** documentada.
5. **Cifrado de datos en reposo** o justificación de control compensatorio.

### P1 — Contenido clínico
6. Notas de **trabajo social y nutrición** (las quirúrgicas y de enfermería ya existen).
7. Campos estructurados de **interrogatorio por aparatos y sistemas** (grupo étnico y lengua materna ya existen).
8. **Hoja frontal** y copia del contrato cuando aplique (el resumen clínico ya existe).

### P2 — Interoperabilidad y catálogos
10. Completar **CIE-9-MC, CLUES, CIE-11** y evaluar licencia de SNOMED/LOINC.
11. Perfiles **IHE/SIRES**, registro de OID y guías de intercambio.
12. Documentación de **SGSI**, análisis de riesgos y preparación para evaluación de conformidad.

## Nota

Los tipos de nota y la firma actual cubren gran parte de NOM-004; las brechas más importantes para una operación
formal son la **firma electrónica avanzada**, el **aviso de privacidad**, la **retención**, el **cifrado/restauración
de respaldos** y los **tipos de nota quirúrgicos y de enfermería**. La certificación NOM-024 requiere evaluación
externa de un organismo acreditado.

## Avances de cumplimiento (ronda de implementación)

Implementado y verificado en esta ronda:

- **Aviso de privacidad** público en `/aviso-privacidad.php`, enlazado desde el inicio de sesión y el registro de
  pacientes (LFPDPPP).
- **Retención de 5 años**: política documentada (`docs/retencion.md`) y revisor
  `mantenimiento/revisar_retencion.php`; el sistema no tiene borrado físico de pacientes/historial.
- **Nuevos tipos de nota**: preoperatoria (NOM-004 8.5), transoperatoria (8.8), postoperatoria (8.8) y
  enfermería (NOM-004 9.1), con validación, formulario y documento. Probada la nota de enfermería de punta a punta.
  Referencias corregidas contra el texto oficial: las notas quirúrgicas trans/post son el numeral 8.8 (no 8.6/8.7)
  y la hoja de enfermería es 9.1 de la NOM-004 (no la NOM-005). Incapacidad: LGS art. 79 · RIS art. 29.
- **Campos de historia clínica**: grupo étnico y lengua materna en el registro del paciente; interrogatorio por
  aparatos y sistemas en la nota de consulta.
- **Detalle genérico** en el visor clínico para los tipos nuevos.

Siguen pendientes (requieren decisión externa, certificados o datos oficiales):

- Firma electrónica avanzada con certificado/PKI.
- Cifrado de respaldos y de datos en reposo, y prueba de restauración documentada.
- Interoperabilidad SIRES/IHE completa y registro de OID.
- Catálogos CIE-9-MC, CLUES, CIE-11 y SNOMED/LOINC.
- SGSI, análisis de riesgos y evaluación de conformidad.
- Hoja frontal y copia del contrato (el resumen clínico independiente ya existe).