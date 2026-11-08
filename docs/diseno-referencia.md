# Diseño de referencia: método y medidas

Cómo se obtuvieron las medidas de diseño de la app de referencia y qué tokens se usaron en el proyecto.
El material extraído vive fuera del proyecto, en la carpeta de análisis estático del usuario.

## Método (análisis estático, sin capturas)

1. Se localizó la instalación de la app de referencia y su perfil web.
2. Se extrajo el paquete de escritorio (`app.asar`): 210 archivos, sin CSS del editor.
3. Se parseó la caché del service worker (formato Simple Cache): cabecera, clave (URL) y cuerpo;
   pares `_0`/`_1` para cuerpo y metadatos HTTP.
4. Resultado: 11,560 archivos reales (143 CSS, 120 HTML, 9,550 JS, fuentes e imágenes).
5. De los CSS se extrajeron las variables de diseño a `tokens-definidos.json` (1,176 variables).

## Tokens medidos (usados como referencia)

| Categoría | Valores reales |
|---|---|
| Radios | 2, 4, 7, 9, 12, 16 px y completo |
| Espaciado | 2, 4, 8, 12, 16, 20, 28, 32, 64 px |
| Texto | 1rem, 0.875rem, 0.75rem, 0.6rem |
| Sombras | `0 1px 2px #00000038, 0 1px 3px #00000017` (y dos niveles más) |
| Azules | `#fbfdff` a `#0090ff`, acción `#0d74ce` |
| Grises | `#fcfcfd` a `#80838d` |
| Tipografía | system-ui, -apple-system, Segoe UI, Roboto… |

## Qué se ajustó en el proyecto

- **Vistas nuevas de base de datos** (`assets/css/modulos/bdp_extra.css`): barras de vista en una línea,
  radios 7/10 px, tarjetas con borde interior, espaciados 6/8/12, textos de 0.75/0.8125/0.875 rem, alturas de 28/30 px.
- **Tablero**: columnas de 280 px, cabecera con contador, tarjetas con hover.
- **Calendario**: celdas de 92 px, números tenues, eventos compactos con hover.
- **Formulario**: cuadrícula de 240 px mínimo, campos de 30 px, etiquetas pequeñas.
- **Etiquetas vacías**: «Sin título» en lugar de «Fila sin nombre»/«Fila».

## Verificación visual

Con Edge (Playwright) se capturan nuestras propias pantallas y se revisan:

    node C:\Users\abelM\extraer-diseno\capturar_local.mjs <url> <salida.png> [clave=valor ...]

Las capturas de revisión quedaron en `C:\Users\abelM\extraer-diseno\local-capturas\`.

## Movimiento y estados

Módulo `assets/css/modulos/vida.css` (se carga solo, como todos los de `modulos/`):

- Entrada suave de las vistas de base de datos (tabla, tablero, calendario, formulario).
- Tarjetas del tablero con elevación al pasar y atenuadas al arrastrar; zona de destino iluminada.
- Filas, pestañas, botones y enlaces con transición corta (100–200 ms).
- Menús emergentes con aparición de 120 ms; foco visible accesible.
- Indicador de brillo para cargas (`--bdp-cargando`).
- Todo respeta `prefers-reduced-motion`.

## Límite

El material extraído se usa solo como referencia de medidas; no se copia al proyecto.
Nuestro código y nuestras clases se mantienen.