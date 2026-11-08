# Inventario del análisis reverso

Carpeta privada de análisis en el escritorio: **128.2 MB, 241 archivos** (revisión del 01-nov-2026,
21:27). La ruta no se guarda en el proyecto por privacidad.

## Qué contiene

| Carpeta | Contenido real | Utilidad |
|---|---|---|
| `1_informes/` | Informe general actualizado + aviso de uso | Media: arquitectura del escritorio |
| `2_analisis_REA/` | 346 canales IPC, 14 APIs del puente, listados TSV, JSON de 98 MB | Baja para el editor |
| `3_codigo_extraido/` | 210 archivos JS del paquete de escritorio (~30 MB) | Baja: es el shell |
| `4_datos_y_recursos/` | Módulos nativos (`.node`), iconos, ejecutables auxiliares | Baja |
| `5_origen_maquina/` | Hashes y ubicaciones de origen | Solo trazabilidad |
| `6_diseno/` | Paleta de 288 colores, tipografías, radios y sombras | Alta: confirma la paleta |

## Hallazgo principal (confirmado en el informe actualizado)

El paquete analizado es la **cáscara de escritorio** (ventanas, pestañas, IPC, monitor, actualizador,
portapapeles, agente local). **El editor y las bases de datos no están ahí**: la interfaz real se carga
desde la web dentro de cada pestaña. El propio informe lo dice: “la lógica de negocio (bloques, CRDT,
bases de datos web) vive en la app web y no forma parte del paquete”.

Conclusión: este material **no sirve para copiar el editor**. Sirve para el comportamiento del shell y
para confirmar el sistema de diseño. El editor se construye por especificación de comportamiento, que es
el camino que ya seguimos (motor único + componente de tabla + menús contextuales).

## Lo aprovechable

- **Paleta:** azul de acción `#2383e2`, superficies `#191919` / `#101010`, rojo `#eb5757`, grises
  `#acaba9`, `#c4c4c4`, `#808080`. Nuestra app ya usa esa familia.
- **Tipografía:** pila del sistema; sin fuentes propias.
- **Radios y sombras:** `radius-md` / `radius-lg`; sombras calculadas en CSS.
- **Comportamiento del shell:** pestañas, ventanas, historial, atajos de edición y portapapeles
  (copiar texto y copiar imagen por canvas → PNG). Sirve de lista de verificación para nuestra barra.
- **Menús:** el material confirma que la app de referencia usa menús contextuales con submenús; los
  nuestros ya existen (página, bloque y selección) y son la base para las herramientas de base de datos.

## Regla de trabajo

El material es de estudio. **No se copia código, textos ni recursos** al proyecto: se reimplementa el
comportamiento con nuestro código y nuestro diseño. Tampoco se nombra el producto en el proyecto.

## Siguiente paso

Seguir con el motor único: formulario de registro ya migrado (altas y cambios por el motor), después los
34 catálogos como entidades, el historial clínico y las herramientas de base de datos (filtrar, ordenar,
agrupar) con acomodo minimalista.
