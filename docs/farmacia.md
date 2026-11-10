# Farmacia

Farmacia es el inventario operativo de la clínica; no sustituye al catálogo de medicamentos.
El catálogo mantiene los medicamentos seleccionables en recetas y formularios. Farmacia registra
existencias físicas por medicamento, lote, caducidad y unidad de inventario.

## Acceso e instalación

- El permiso `farmacia` se configura en Personas y permisos. Por defecto lo tienen Administración y Enfermería.
- Crea las tablas con `database/migracion_farmacia.sql` y aplica los permisos de las dos tablas de farmacia
  incluidos en `database/usuario_aplicacion.sql`. Los despliegues con permisos de auto-reparación también
  pueden crearlas al iniciar.
- No se generan existencias iniciales ni se agregan medicamentos ficticios.

## Operaciones

- **Entrada:** selecciona un medicamento activo del catálogo, registra lote, caducidad, cantidad, unidad y
  referencia de compra. Repetir el mismo medicamento/lote/caducidad/unidad suma una recepción nueva y deja
  otro movimiento.
- **Ajuste o merma:** cualquier cantidad requiere motivo. Las salidas no pueden superar la existencia.
- **Conteo físico:** desde la fila del lote se puede conciliar la cantidad contada con la registrada. La diferencia
  se guarda como ajuste de entrada o salida, con motivo y responsable, dentro de una transacción; si no hay diferencia,
  no se crea un movimiento.
- **Dispensación:** selecciona un tratamiento vigente en una receta del paciente, revisa identidad y fechas,
  elige un lote del mismo medicamento que no esté caducado y confirma la revisión. El sistema descuenta la
  cantidad en la unidad registrada para ese lote y deja responsable, hora, receta, lote, cantidad y
  confirmación en el historial de movimientos.
- El Kardex de administración clínica y la dispensación son hechos distintos. Guardar o administrar una dosis
  no modifica el inventario automáticamente.
- Los movimientos no se editan ni eliminan desde la aplicación; una corrección se registra como otro ajuste.
  Los lotes y movimientos están aislados por institución.
- La exportación CSV contiene existencias, sin datos de pacientes, y neutraliza valores que podrían abrirse
  como fórmulas en una hoja de cálculo.

## Alcance actual

El inventario no interpreta dosis ni convierte automáticamente entre tabletas, cajas, frascos u otras
unidades. El usuario debe registrar y dispensar cantidades en la unidad que aparece en el lote. Todavía no
incluye pedidos a proveedores, códigos de barras, importación masiva ni alertas configurables de stock mínimo.

Las estadísticas agregadas de Morbilidad omiten diagnósticos con menos de cinco pacientes y no incluyen
identificadores ni subgrupos demográficos; su exportación está restringida por rol y deja un evento de
auditoría. La aplicación aún no registra consentimiento individual para compartir esos datos: antes de una
publicación externa se debe verificar la autorización y la política aplicable. Esto no constituye una
certificación de cumplimiento normativo.
