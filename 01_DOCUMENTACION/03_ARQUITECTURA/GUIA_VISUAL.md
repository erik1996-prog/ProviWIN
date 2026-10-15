# Guía visual de SGS — sistema de diseño v1.0 (2026-10-07)

Identidad: **profesional, sencilla y moderna**. Fondo neutro cálido, tarjetas blancas, acento naranja WIN solo para acciones y lo activo, menú lateral naranja con logo blanco.

- Estilos: `03_FRONTEND/src/assets/css/sgs-theme.css`, la única hoja del sistema desde v0.7.2. Partes A y B: capas heredadas, no se agregan reglas ahí. Parte C: sistema de diseño (sección 1 = tokens, secciones 17 a 20 = componentes v1.0).
- Regresión visual: `05_TEST/frontend/visual_snapshot.js` (comparar el estilo final de cada elemento antes y después de reorganizar CSS).
- Guía en vivo: `index.php?page=guia_estilo` (administrador). Muestra colores, tipografía y componentes con las clases reales.
- Reglas para crear pantallas: skill del proyecto `.claude/skills/sgs-diseno/SKILL.md`.
- Revisor automático: `php 05_TEST/frontend/design_lint.php`.

## Paleta (tokens)

| Token | Valor | Uso |
|---|---|---|
| `--sgs-orange` | `#FF5A0A` | Botón principal, ítem activo, foco |
| `--sgs-orange-dark` | `#D8450B` | Hover, enlaces, texto naranja |
| `--sgs-orange-soft` | `#FFEFE6` | Fondo de ícono o insignia de marca |
| `--sgs-ink` | `#141414` | Títulos y cifras |
| `--sgs-brown` | `#301D19` | Texto de lectura |
| `--sgs-muted` | `#6B6D70` | Texto secundario, etiquetas |
| `--sgs-surface` / `--sgs-surface-alt` | `#FFFFFF` / `#FAF9F7` | Tarjetas / encabezados de tabla y zonas suaves |
| `--sgs-border` | `#EDE4DE` | Bordes |
| `--sgs-bg` | `#F6F4F2` | Fondo de la aplicación |
| `--sgs-success` | `#12A150` | Correcto, activo, a tiempo |
| `--sgs-warning` | `#FFC200` | En plazo, atención (texto con `--sgs-warning-dark`) |
| `--sgs-danger` | `#E03131` | Vencido, bloqueado, error, eliminar |
| `--sgs-info` | `#4A4D52` | Neutro / informativo |

Bootstrap está alineado a esta paleta: `btn-*`, `badge bg-*`, `alert-*` y `text-*` ya usan estos colores.

## Tipografía
- **Texto:** Nunito (`--sgs-font`), 15 px. Alternativa libre a MADE Tommy Soft.
- **Títulos y cifras:** desde 2026-10-15 la misma letra, Nunito (peso 700; nunca 800/900). Montserrat se eliminó porque se veía tosca. Detalle en la skill `sgs-diseno` v2.0, sección Tipografía.
- **Estilo de oración** en títulos, botones y etiquetas ("Matriz mensual de supervisiones"). Solo las etiquetas de indicadores y los encabezados de tabla van en mayúsculas pequeñas.

## Componentes

| Componente | Cómo se escribe | Aspecto |
|---|---|---|
| Título de pantalla | `title` en `index.php` (barra superior) | Uno solo por pantalla, estilo de oración |
| Acciones de pantalla | `.sgs-page-actions` | Fila alineada a la derecha |
| Contador | `.sgs-counter` | Píldora blanca neutra (informativa, no es botón) |
| Indicador (KPI) | `.sgs-kpi` + `.sgs-kpi-label` + `.sgs-kpi-value` (+ `.sgs-kpi-icon` opcional) | Tarjeta neutra; `sgs-kpi--success/warning/danger/info` agrega un punto de estado solo si el dato lo tiene |
| Botón principal | `.btn-win` | Naranja (uno por zona) |
| Botón secundario | `.btn-outline-secondary` | Blanco con borde |
| Botón de peligro | `.btn-outline-danger` | Borde rojo |
| Insignia de estado | `badge bg-success / bg-warning / bg-danger / bg-secondary` | Fondo suave y texto del mismo tono |
| Insignia de rol | `badge bg-primary` (Supervisor) · `bg-dark` (Administrador) | Suave |
| Leyenda de gráfico | contenedor `.sgs-legend` | Insignias sólidas, del color del elemento |
| Tabla | `.table.table-hover` | Encabezado neutro en mayúsculas pequeñas; hover gris muy claro |
| Modal | `.modal` | Encabezado blanco con línea naranja de 2 px; el visor de fotos sigue oscuro |
| Menú lateral | `layouts/sidebar.php` → `$menuGroups` | Naranja, grupos Panel · Supervisión · Observaciones · Administración; cuenta fija abajo |

## Reglas
1. Nunca escribir `#hex` ni `style="..."` nuevos en una pantalla: usar `var(--sgs-*)` y clases.
2. El color comunica el estado, no decora: verde = bien, amarillo = en plazo, rojo = vencido o error.
3. Solo Bootstrap Icons. Prohibidos los emojis.
4. Sin dato: "—". Opciones vacías: "Todos los…" o "Seleccione…", nunca "-- … --".
5. No reescribir textos que son datos (estados guardados, `<option>` sin `value`).
6. Antes de publicar: `design_lint.php`, `smoke_pages.php`, `check_inline_js.php` y revisión a 1366 px y 375 px.
