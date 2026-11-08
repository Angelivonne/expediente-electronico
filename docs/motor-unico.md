# Motor único de datos

> Objetivo: que pacientes, medicamentos, catálogos, historial clínico, citas y las bases del
> espacio sean **la misma cosa** por dentro: una entidad con campos, vistas y registros.
> Un solo motor, una sola forma de pintar, una sola forma de guardar. Sin cambiar el diseño actual.

---

## 1. El problema, con evidencia

Hoy el mismo concepto (una tabla de datos editable) está resuelto **tres veces** y hay dos motores:

| Qué | Motor | Archivos |
|---|---|---|
| Pacientes | Tabla propia en PHP + celdas editables | `pacientes/index.php`, `app.js`, `api/pacientes/actualizar.php` |
| Medicamentos | Copia del anterior | `medicamentos/index.php`, `api/catalogos/actualizar.php` |
| CIE-10 | Otra copia | `cie10/index.php` |
| Catálogos y bases del espacio | Motor genérico (columnas JSON + `pagina_filas`) | `paginas/ver.php`, `bdp_extra.js`, `api/paginas/base_datos.php` |
| Catálogos con tabla real | Tercer render, solo lectura | `app/vistas/componentes/catalogo_pagina.php` |

Consecuencias:
- Cada arreglo se hace tres veces (y ya se nota: una tabla guarda distinto que la otra).
- Comportamientos distintos: una tiene edición en línea, otra no; una busca, otra no; una ordena, otra no.
- La IA no tiene una superficie estable para operar: tendría que conocer cada endpoint.
- Los formularios clínicos (consulta, receta, nota de urgencias…) están hechos a mano, aparte.

---

## 2. El modelo (una sola pieza)

Todo se describe con cuatro conceptos:

```
Entidad     qué es: paciente, medicamento, diagnóstico, nota clínica, catálogo, cita…
  Campo     una propiedad: nombre, apellidos, fecha de nacimiento, diagnóstico, dosis…
  Vista     cómo se ve: tabla, ficha, formulario, tablero, calendario
  Registro  una fila (un paciente, un medicamento, una nota)
```

Y una relación:

```
Campo de tipo "referencia" -> otra entidad (una nota apunta a un paciente y a un diagnóstico)
```

La diferencia entre "pacientes" y "catálogos" deja de existir en el código: son **configuraciones**
distintas del mismo motor.

### Definición de una entidad (ejemplo real)

```php
'pacientes' => [
    'titulo'      => 'Pacientes',
    'singular'    => 'Paciente',
    'almacen'     => ['tipo' => 'tabla', 'tabla' => 'pacientes'],   // adaptador
    'campo_titulo'=> 'nombre_completo',
    'busqueda'    => ['nombre', 'primer_apellido', 'segundo_apellido', 'curp', 'folio'],
    'vistas'      => ['tabla' => [...], 'ficha' => [...]],
    'campos'      => [
        'nombre'            => ['texto' => 'Nombre(s)', 'tipo' => 'texto', 'req' => true, 'max' => 80],
        'fecha_nacimiento'  => ['texto' => 'Fecha de nacimiento', 'tipo' => 'fecha', 'req' => true],
        'sexo'              => ['texto' => 'Sexo', 'tipo' => 'opcion', 'opciones' => SEXOS, 'req' => true],
        'alergias'          => ['texto' => 'Alergias', 'tipo' => 'texto', 'max' => 255],
        'curp'              => ['texto' => 'CURP', 'tipo' => 'curp'],
    ],
],
```

El mismo bloque sirve para:

```php
'medicamentos'  => ['almacen' => ['tipo' => 'tabla', 'tabla' => 'medicamentos'], ...],
'cie10'         => ['almacen' => ['tipo' => 'tabla', 'tabla' => 'cie10'], ...],
'catalogo_vias' => ['almacen' => ['tipo' => 'tabla', 'tabla' => 'catalogo_vias_admin'], ...],
'notas'         => ['almacen' => ['tipo' => 'pagina'], ...],   // bases del espacio
```

---

## 3. Almacenamiento: adaptadores, no migración forzada

Cada entidad declara **dónde vive** y el motor habla con un adaptador. Tres adaptadores bastan:

1. **`tabla`** — una tabla real (`pacientes`, `medicamentos`, `cie10`, `catalogo_*`).
2. **`pagina`** — una base del espacio (`paginas.contenido` + `pagina_filas`).
3. **`documento`** — una entidad con detalle variable (historial clínico: `tipo` + `detalle` JSON).

Ventaja: **los datos no se mueven**. El motor lee y escribe donde ya están; la UI deja de saber de
dónde vienen. Si más adelante conviene un almacén común, se cambia un adaptador, no las pantallas.

Reglas del adaptador:
- Filtra siempre por institución (salvo catálogos nacionales).
- Registra bitácora de cada cambio (como hoy).
- Devuelve tipos normalizados (texto, número, fecha, opción, casilla, referencia).

---

## 4. La interfaz: un componente, el diseño de siempre

No se rehace el diseño. Se reemplaza el **motor de pintado** por uno solo:

- `app/vistas/componentes/vista_tabla.php` — tabla (encabezado, filas, celdas editables).
- `assets/js/modulos/motor_tabla.js` — comportamiento: edición en línea, orden, búsqueda, selección.
- `app/vistas/componentes/vista_ficha.php` — ficha (el perfil del paciente es una ficha más).
- `app/vistas/componentes/vista_formulario.php` — captura (los formularios clínicos son formularios de entidad).

**Herramientas fuera del flujo** (distinto a la costumbre de ponerlas arriba):
- Arriba solo título y conteo.
- Riel vertical fino al borde derecho de la tabla, solo iconos: buscar, filtrar, ordenar, propiedades, vistas.
- Acción principal en pastilla flotante abajo a la izquierda.
- El resto, en clic derecho sobre fila, celda o columna.

El perfil del paciente conserva su diseño: pasa a ser la **vista ficha** de la entidad `pacientes`,
con la misma plantilla de secciones que ya tiene.

---

## 5. Formularios y registros clínicos

Un formulario es una vista de la entidad en modo captura. Los formularios clínicos (consulta,
urgencias, receta, hospitalización, consentimiento…) ya tienen su catálogo de tipos en
`app/datos/registros_clinicos.php` y su validación en `app/servicios/clinica/registros.php`.

Con el motor:
- `historial_clinico` es una entidad con `tipo` y campos por tipo (plantillas).
- Cada tipo declara sus campos obligatorios (NOM-004) y se captura con el mismo componente.
- El `detalle` JSON actual se conserva tal cual: no se migran notas.

---

## 6. Tool calling (la IA sobre el mismo contrato)

La IA no debe conocer endpoints por pantalla. Se le expone **un solo contrato**:

| Operación | Qué hace |
|---|---|
| `describe(entidad)` | campos, tipos, obligatorios, vistas disponibles |
| `list(entidad, filtro, orden, página)` | filas con sus campos |
| `get(entidad, id)` | una fila |
| `create(entidad, datos)` | alta con validación y bitácora |
| `update(entidad, id, datos)` | cambio con bitácora |
| `delete(entidad, id)` | baja lógica |
| `aggregate(entidad, medida, grupo)` | conteos, sumas, promedios |

Con eso puede hacer **todo** lo que hace la interfaz: registrar un paciente, corregir un teléfono,
buscar diagnósticos, contar consultas del mes, crear una nota. Los permisos y la auditoría se
resuelven **en el motor**, no en cada herramienta, así ninguna operación se salta el control.

---

## 7. Plan por fases (cada una funcionando y probada)

1. **Contrato + adaptador `tabla`** con `pacientes` como piloto. Pruebas de lectura, edición y bitácora.
2. **Componente único de tabla** y migración de `pacientes` (mismo aspecto, mismo comportamiento).
3. **Medicamentos y CIE-10** sobre el mismo componente (se borran las copias).
4. **Catálogos (34)** como entidades `tabla`, con su visor y su edición.
5. **Historial clínico** como entidad `documento` + formularios por tipo.
6. **Ficha del paciente** sobre el motor, conservando el diseño actual.
7. **Tool calling** sobre el contrato.
8. **Vistas** (tablero, calendario) y menús contextuales completos.

---

## 8. Reglas para no romper nada

- Las URLs y los permisos actuales no cambian.
- Los datos no se mueven en las primeras fases (solo cambia quien los lee).
- Toda escritura pasa por el adaptador y deja bitácora.
- Una entidad tiene una sola fuente de verdad.
- Nada de motores paralelos: si una pantalla necesita algo que el motor no tiene, se agrega al motor.

## 9. Estado

- **Motor y base:** 40 entidades, CRUD, agrupaciones, solo lectura, tabla editable, formularios, catálogos,
  historial, herramientas de tabla, CSV y tool calling probado.
- **Bases de datos:** tabla, gráfica, **tablero (kanban)** y **calendario** (mes por una fecha, con
  navegación); vistas múltiples, filtros, orden y plantillas de fila.
- **Colaboración:** comentarios con menciones, versiones con restauración y plantillas.
- **Pendiente:** vista de formulario y de lista/galería, prueba del asistente en vivo.