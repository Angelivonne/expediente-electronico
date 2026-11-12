# Finanzas de la clínica (un solo motor)

## Alcance implementado

- Tablas: `finanzas_movimientos` (ingresos y gastos) y `finanzas_conceptos` (catálogo de conceptos).
- Entidades del motor: `finanzas_movimientos` y `finanzas_conceptos` (mismos componentes: tabla, formulario de alta,
  edición por API, exportación futura).
- Pantalla `/finanzas/`:
  - Resumen del mes: ingresos, gastos, balance y por cobrar (pendiente).
  - Tendencia mensual de cobros y gastos pagados, con la gráfica y la estructura de accesos del Tablero.
  - Tabla de movimientos con los mismos componentes del resto de la app.
  - Acciones para registrar ingresos o gastos en el extremo superior derecho; exportación y filtros permanecen junto
    a la tabla de movimientos.
  - Alta rápida con el formulario estándar del motor.
- Permiso `finanzas` (administrador, médico, recepción).
- Menú lateral: Finanzas → Ingresos y gastos.
- Migración: `database/migracion_finanzas.sql` (idempotente) y permisos de tabla para `clinica_app`.

## Campos de un movimiento

| Campo | Uso |
|---|---|
| tipo | ingreso / gasto |
| fecha | fecha del movimiento |
| concepto | consulta, procedimiento, renta, nómina… |
| descripción | detalle |
| monto | importe |
| método | efectivo, tarjeta, transferencia, cheque, otro |
| estado | pagado, pendiente, cancelado |
| paciente_id | vínculo opcional con el expediente |
| referencia | folio, factura o nota |

## Lo que una clínica necesita y aún falta por fases

1. **Reportes**: ingresos/gastos por periodo, por concepto, por profesional y por sucursal; exportación.
2. **Cuentas por cobrar y por pagar** con vencimientos y recordatorios.
3. **Facturación CFDI 4.0** (datos fiscales del paciente, timbrado, cancelación).
4. **Cierre de caja** por turno y conciliación de métodos de pago.
5. **Presupuestos** y gastos recurrentes (renta, nómina, servicios).
6. **Nómina y honorarios** con retenciones.
7. **Impuestos y reportes fiscales** (ingresos, IVA, ISR, deducciones).
8. **Inventario y costo de insumos** ligado a procedimientos.
9. **Auditoría financiera**: quién registró, modificó o canceló cada movimiento (bitácora ya existe en el motor).

## Decisión de diseño

Todo entra por el motor existente: una tabla por entidad, una pantalla por flujo, sin módulos ni estilos paralelos.
Los cálculos de pantalla (resumen, saldos) no se guardan; los datos viven en sus tablas.

## Reportes (implementado)

- `/finanzas/reportes.php`: totales del periodo, desglose por concepto y por método de pago.
- Filtros por mes (anterior/este/siguiente) y por tipo (todos, ingresos, gastos).
- Exportación CSV con el endpoint del motor (`api/motor/exportar.php?entidad=finanzas_movimientos`), mismos permisos y límites.
- Mismos componentes visuales (`.bd`, `.bd-tabla`, botones existentes); sin estilos nuevos.

## Cierre de caja (implementado)

- Tabla `finanzas_cierres` + entidad `finanzas_cierres` del motor (alta, tabla y edición por API).
- `/finanzas/cierre.php`: totales del día por método de pago (efectivo, tarjeta, transferencia, cheque, otro),
  navegación de días, registro del cierre con el formulario estándar y tabla de cierres registrados.
- Migración: `database/migracion_finanzas_cierre.sql` (idempotente) y permisos de tabla para `clinica_app`.

## Cuentas por cobrar y por pagar (implementado)

- Campo `vence_en` (fecha) en `finanzas_movimientos`.
- `/finanzas/cuentas.php`: pendientes de ingreso (por cobrar) y de gasto (por pagar), con vencimiento y situación
  («Vencida hace N días», «Vence en N días», «Sin fecha»), totales por bloque.
- Sin tablas nuevas: se apoya en el estado `pendiente` y la fecha de vencimiento del mismo movimiento.

## Presupuestos y recurrentes (implementado)

- Tablas `finanzas_presupuestos` y `finanzas_recurrentes` + entidades del motor con sus formularios y tablas.
- `/finanzas/presupuestos.php`: presupuesto contra real del mes, por tipo y por concepto, con diferencia y navegación de meses.
- `/finanzas/recurrentes.php`: renta, nómina, servicios… con día del mes y método; botón «Generar del mes» que crea los
  movimientos una sola vez (referencia `REC-<id>-<año-mes>`, no duplica si se vuelve a generar).
- Migración: `database/migracion_finanzas_presupuesto.sql` y permisos de tabla para `clinica_app`.

## Facturación (implementado)

- Tabla `finanzas_facturas` + entidad del motor (alta, tabla, edición por API y exportación CSV).
- `/finanzas/facturacion.php`: resumen del mes (emitidas, borradores, canceladas con montos) y registro de facturas.
- Campos fiscales: folio, fecha, paciente, RFC, razón social, uso CFDI, método PUE/PPD, forma de pago,
  total, estado, UUID fiscal y notas.
- El **timbrado** lo realiza el PAC externo; aquí se guarda el control y el UUID cuando se timbra.
- Migración: `database/migracion_finanzas_facturacion.sql` y permisos de tabla para `clinica_app`.

## Conciliación del cierre (implementado)

- `/finanzas/conciliacion.php`: compara el cierre registrado del día contra los movimientos reales por método
  (efectivo, tarjeta, transferencia, cheque, otro) y muestra la diferencia por método y en total.
- Si no hay cierre registrado, lo indica y muestra solo los movimientos reales.
- Lista los cierres de ese día y enlaza al cierre para corregir.
- Campo `factura_id` agregado a `finanzas_movimientos` para vincular un movimiento con su factura.
- Migración: `database/migracion_finanzas_conciliacion.sql`.

## Navegación de finanzas

- La barra lateral tiene una sola entrada «Finanzas»; dentro, una barra de pestañas (`finanzas/_nav.php`)
  lleva a Ingresos, Reportes, Cierre de caja, Conciliación, Cuentas, Presupuestos, Recurrentes y Facturación.
- Se eliminó la lista larga del menú que se cortaba; mismos botones y componentes del sistema.

## Vínculo factura ↔ movimiento (implementado)

- El campo `factura_id` del movimiento es un **selector** con las facturas registradas (folio · total).
- Al registrar un movimiento (ingresos y gastos) se puede elegir la factura que lo respalda.
- `/finanzas/facturacion.php` incluye la tabla **Movimientos ligados a factura**: folio, fecha, concepto, monto y estado.
- Sin tablas nuevas: usa `finanzas_movimientos.factura_id` y el catálogo de facturas existente.

## Resumen fiscal (implementado)

- `/finanzas/fiscales.php`: por mes, facturas emitidas, total facturado (IVA incluido), base gravable e
  IVA trasladado estimado (16%), ingresos cobrados, gastos del mes (posible deducción) y utilidad estimada.
- Navegación de meses y pestaña «Fiscal» en la barra interna de finanzas.
- Cálculo de pantalla sobre las mismas tablas; aclara que el IVA acreditable depende de la factura del proveedor
  y el timbrado del PAC, y que no sustituye la contabilidad.

## Cancelación de facturas (implementado)

- Columnas `cancelada_en` y `motivo_cancelacion` en `finanzas_facturas` (migración `migracion_finanzas_cancelacion.sql`).
- `/finanzas/facturacion.php`: sección «Cancelar factura» (solo administrador) con selección de factura no cancelada
  y motivo obligatorio; al cancelar se marca `estado = 'cancelada'`, fecha y motivo, y se registra en la bitácora.
- Probado: factura F-3000 pasó a cancelada con motivo «Prueba de cancelación»; fila de prueba borrada.

## Reportes por profesional (implementado)

- Campo `profesional_id` en `finanzas_movimientos` (migración `migracion_finanzas_profesional.sql`) y selector
  de profesional en el formulario de movimientos (usuarios activos).
- `/finanzas/reportes.php` incluye la tabla **«Por profesional»**: profesional, registros y total del periodo,
  con los mismos filtros por mes y tipo.
- Probado con movimientos de dos profesionales: reporte con Angel Zamora (2 registros, $2,200) y Joseluis
  (1 registro, $400); datos de prueba borrados.
- El reporte por sucursal queda pendiente: la institución es única y los establecimientos viven en un catálogo.

## Reportes por establecimiento (implementado)

- Campo `establecimiento_id` en `finanzas_movimientos` (migración `migracion_finanzas_establecimiento.sql`) y
  selector de establecimiento en el formulario de movimientos (catálogo de establecimientos activos).
- `/finanzas/reportes.php` incluye la tabla **«Por establecimiento»**: establecimiento, registros y total del periodo,
  con los mismos filtros de mes y tipo.
- Probado con movimientos de dos establecimientos: totales correctos ($2,500 ingresos, $300 gastos, $2,200 balance);
  datos de prueba borrados.
- Con esto quedan los reportes por concepto, método, profesional y establecimiento.

## Conciliación automática al cerrar caja (implementado)

- Columna `diferencia_total` en `finanzas_cierres` (migración `migracion_finanzas_cierre_diferencia.sql`) y
  columna «Diferencia» en la tabla de cierres registrados.
- Al registrar un cierre, `finanzas/cierre.php` calcula el **total real de movimientos pagados del día** y guarda
  la diferencia contra el total capturado.
- El aviso al guardar indica si el cierre coincide o la diferencia detectada.
- Robustez: si el formulario no envía algún método, se normaliza a 0; el motor no guarda campos de solo lectura,
  por eso la diferencia se escribe después de crear el cierre.
- Probado: movimientos del día $1,000 (600 efectivo + 400 tarjeta) y cierre capturado $900 → diferencia persistida
  **$100.00**. Datos de prueba borrados.

## Catálogo de servicios clínicos

- `/finanzas/servicios.php` permite al administrador de cada clínica crear, ajustar y desactivar sus servicios y precios.
- El catálogo inicia vacío; no instala conceptos ni precios de demostración.
- El alta de movimientos permite elegir un servicio activo y copia su nombre al concepto y su precio al importe inicial. El importe del movimiento queda como historial aunque después cambie el catálogo.
- `database/migracion_servicios_clinicos.sql` crea el catálogo por institución, agrega la referencia opcional al movimiento y da a la cuenta de aplicación permisos limitados de lectura y escritura en el catálogo.
- Desactivar un servicio conserva los movimientos existentes y deja rastro en la bitácora.

## Catálogo de servicios clínicos

- `/finanzas/servicios.php` permite al administrador de cada clínica crear, ajustar y desactivar sus servicios y precios.
- El catálogo inicia vacío; no instala conceptos ni precios de demostración.
- El alta de movimientos permite elegir un servicio activo, copia su nombre al concepto y propone el precio vigente. El importe del movimiento queda guardado como historial aunque luego cambie el precio del catálogo.
- La migración `database/migracion_servicios_clinicos.sql` crea el catálogo por institución, agrega la referencia opcional al movimiento y concede a la cuenta de aplicación solo lectura/escritura sobre el catálogo.
- El catálogo no modifica ni elimina movimientos históricos al desactivar un servicio.
