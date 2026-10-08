---
name: sgs-diseno
description: Sistema de diseño oficial de ProviWIN / SGS (colores WIN, tipografía, componentes, textos y revisión). Usar SIEMPRE antes de crear o modificar cualquier pantalla, sección, modal, tabla, indicador, formulario o estilo en C:\xampp\htdocs\ProviWIN, y al revisar el diseño de una pantalla.
---

# Sistema de diseño SGS v1.0

Objetivo: que cada pantalla nueva se vea **profesional, sencilla y moderna**, idéntica al resto del sistema, sin revisar estilos a mano.
Fuente única de estilos: `03_FRONTEND/src/assets/css/sgs-theme.css` (la ÚNICA hoja del sistema desde v0.7.2). Partes A y B = capas heredadas (no agregar nada ahí); Parte C = sistema de diseño (sección 1 = tokens, 17–20 = componentes v1.0).
Guía visible en vivo (administrador): `index.php?page=guia_estilo`. Documento: `01_DOCUMENTACION/03_ARQUITECTURA/GUIA_VISUAL.md`.

## 1. Principios (no negociables)
1. **El naranja marca la acción principal y lo activo**; no decora. Una sola acción naranja por zona.
2. **Los colores de estado significan algo**: verde = correcto/a tiempo, amarillo = en plazo/atención, rojo = error/vencido/bloqueado. Nunca para diferenciar tarjetas.
3. **Superficies neutras**: fondo `--sgs-bg`, tarjetas blancas con borde fino y sombra suave. Nada de bloques oscuros ni degradados (solo el menú lateral y el login los tienen).
4. **Un solo set de íconos**: Bootstrap Icons (`<i class="bi bi-..."></i>`). **Prohibidos los emojis.**
5. **Textos en español, estilo de oración**: "Nuevo usuario", "Matriz mensual de supervisiones". Nunca "Mayúscula En Cada Palabra" ni "&" en títulos.
6. **Un solo título por pantalla**: vive en la barra superior (se define en `index.php`). La página NO repite un `<h1>`/`<h3>` de título.

## 2. Tokens (usar siempre la variable, nunca el #hex en pantallas)
| Variable | Valor | Uso |
|---|---|---|
| `--sgs-orange` | #FF5A0A | Botón principal, ítem activo, foco |
| `--sgs-orange-dark` | #D8450B | Hover, enlaces, texto naranja |
| `--sgs-orange-soft` | #FFEFE6 | Fondo de ícono/insignia de marca |
| `--sgs-ink` | #141414 | Títulos y cifras |
| `--sgs-brown` | #301D19 | Texto de lectura |
| `--sgs-muted` | #6B6D70 | Texto secundario, etiquetas |
| `--sgs-border` | #EDE4DE | Bordes, divisores |
| `--sgs-surface` / `--sgs-surface-alt` | #FFF / #FAF9F7 | Tarjeta / encabezado de tabla, zonas suaves |
| `--sgs-bg` | #F6F4F2 | Fondo de la aplicación |
| `--sgs-success` (+`-dark`, `-soft`) | #12A150 | Estado correcto |
| `--sgs-warning` (+`-dark`, `-soft`) | #FFC200 | En plazo / atención (texto con `-dark`) |
| `--sgs-danger` (+`-dark`, `-soft`) | #E03131 | Error, vencido, eliminar |
| `--sgs-info` (+`-soft`) | #4A4D52 | Neutro / informativo |

Forma: `--sgs-radius-sm` 10px (controles) · `--sgs-radius` 16px (tarjetas) · `--sgs-radius-lg` 20px (modales). Sombras: `--sgs-shadow-sm` (tarjetas), `--sgs-shadow` (modales). Foco: `--sgs-ring`.
Bootstrap ya está mapeado a la paleta: `btn-*`, `badge bg-*`, `alert-*`, `text-*` funcionan sin colores propios.

## 3. Tipografía
- Texto: **Nunito** (`var(--sgs-font)`) 15px. Títulos y cifras: **Montserrat** (`var(--sgs-font-display)`). No cargar ni usar otras fuentes.
- Título de pantalla: barra superior, ~22px, 800, estilo de oración (automático).
- Título de tarjeta: `<h5>` (16px, 800) con ícono naranja opcional: `<h5 class="mb-0"><i class="bi bi-... me-2 text-primary"></i>Título</h5>`.
- Etiquetas de indicador y encabezados de tabla: 12px, mayúsculas, gris (automático con las clases).

## 4. Componentes (copiar estos patrones)

**Página nueva** (`03_FRONTEND/src/pages/<nombre>.php`) + registro en `index.php` → `$allowedViews` (`file`, `title`, `perm`) y su descripción en `$pageDescriptions`. Agregar al menú en `03_FRONTEND/src/layouts/sidebar.php` → `$menuGroups` en el grupo correcto (Panel, Supervisión, Observaciones, Administración). Las secciones son desplegables solas (sección 21) y la de la pantalla actual abre automáticamente; no crear grupos nuevos sin necesidad.
```html
<div class="container-fluid p-3 p-md-4">
  <div class="sgs-page-actions">            <!-- acciones de la pantalla, alineadas a la derecha -->
    <span class="sgs-counter">0 registros</span>
    <button type="button" class="btn btn-win"><i class="bi bi-plus-lg me-1"></i>Nuevo registro</button>
  </div>
  <!-- tarjetas… -->
</div>
```
**Indicador (KPI)** — neutro; `sgs-kpi--success|warning|danger|info` SOLO si el valor tiene ese estado; ícono opcional:
```html
<div class="col-6 col-lg-3"><div class="sgs-kpi sgs-kpi--danger p-3 h-100">
  <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-lock"></i></span>
  <small class="sgs-kpi-label">Bloqueadas</small>
  <h3 class="mb-0 sgs-kpi-value" id="kBloqueadas">0</h3>   <!-- texto largo: h6.sgs-kpi-value.is-text -->
</div></div>
```
**Tarjeta / sección**: `<div class="card p-3 p-md-4">` + `<h5>` + `<p class="text-muted small">` de ayuda.
**Filtros**: dentro de una tarjeta; `<label class="form-label">` visible arriba de cada campo; `form-select`/`form-control`; botón `btn btn-win` "Filtrar" + `btn btn-outline-secondary` "Limpiar". Opción vacía: `<option value="">Todos los departamentos</option>` o "Seleccione …" (nunca `-- … --`).
**Tabla**: `<div class="table-responsive"><table class="table table-hover mb-0 align-middle">`; acciones a la derecha con `btn btn-sm btn-outline-secondary` de solo ícono + `aria-label`. En celular, poner `data-label="Columna"` en cada `<td>` (ver `usuarios.php`). Estado vacío: `<i class="bi bi-inbox"></i>` + frase corta.
**Botones** (jerarquía): principal `btn btn-win` (1 por zona) · secundario `btn btn-outline-secondary` · peligro `btn btn-outline-danger` · cierre definitivo `btn btn-dark`. Texto = verbo + objeto ("Guardar foto"), nunca "Aceptar/OK".
**Insignias de estado**: `badge bg-success` (activo/finalizado/a tiempo) · `bg-warning` (en plazo/en proceso) · `bg-danger` (vencida/bloqueada) · `bg-secondary` (inactivo) · `bg-primary` (rol Supervisor/marca) · `bg-dark` (rol Administrador). Se ven en tono suave automáticamente. Leyendas de gráficos: envolver en `.sgs-legend` para colores sólidos.
**Pestañas/segmentos**: `btn-check` + `btn btn-outline-secondary` (el activo se pinta naranja solo).
**Modal**: Bootstrap estándar (`modal-dialog-centered`); título con ícono; pie: secundario a la izquierda/centro, principal a la derecha. Abrir con `bootstrap.Modal.getOrCreateInstance(el)` dentro de eventos o `DOMContentLoaded`.
**Avisos**: `window.showToast(mensaje, 'success'|'error')`; mensajes en formularios con `alert alert-danger|success`.
**Contador informativo**: `<span class="sgs-counter">` (no usar botones/insignias naranjas para datos).
**Barra de herramientas de un listado** (ver Supervisiones): una tarjeta, fila 1 = estados + acciones, fila 2 = filtros con etiqueta.
```html
<div class="card sgs-toolbar-card p-3 mb-3">
  <div class="sgs-toolbar">
    <div class="sgs-segmented" role="group" aria-label="Estado">
      <button type="button" class="filter-tab-btn active" data-filter="todos">Todas <span class="sgs-seg-count">0</span></button> …
    </div>
    <div class="sgs-toolbar-actions">            <!-- secundarias primero, la principal (btn-win) al final -->
      <button class="btn btn-outline-secondary">…</button>
      <div class="dropdown">…acciones relacionadas agrupadas en un menú…</div>
      <button class="btn btn-win">…</button>
    </div>
  </div>
  <div class="row g-3 sgs-toolbar-filters">     <!-- NO usar align-items-end aquí: activa una rejilla heredada -->
    <div class="col-sm-6 col-lg-3"><label class="form-label" for="x">Departamento</label><select class="form-select" id="x">…</select></div>
    … <div class="col-12 col-lg-auto"><button class="btn btn-outline-secondary w-100">Limpiar</button></div>
  </div>
</div>
```
Reglas de la barra: etiquetas siempre ARRIBA del campo (nunca al costado con ícono de color); acciones del mismo proceso en un solo menú desplegable; máximo 3–4 botones visibles; todo filtro tiene "Limpiar".
**Estado de turno**: `badge badge-status badge-en-turno | badge-en-almuerzo | badge-en-reunion | badge-en-capacitacion | badge-fuera-turno` (fuera de turno = gris, no es error).
**Barras de desplazamiento**: ya están definidas para todo el sistema (sección 22); no darles color ni tamaño por pantalla.
**Subir archivos**: `<input type="file" class="form-control">` ya sale con botón del sistema y zona punteada (sección 20); no usar el control nativo sin estilo.

**Al tocar código heredado**: antes de agregar una regla, buscar si ya existe otra que la pisa (`grep` en las Partes A/B de `sgs-theme.css` y en el `<style>` de la página). Preferir NO coincidir con el selector heredado (otra clase) en vez de sumar `!important`. Si el problema viene de un token heredado (`--win-*`), corregir el token en la sección 1 de `sgs-theme.css`.

## 5. Textos
- Español neutro, frases cortas, estilo de oración. Sin dato: **—** (raya), no "-" ni "N/A". Fechas dd/mm/aaaa; horas 24 h.
- Nombres propios de datos (departamentos, estados guardados en BD como "En Turno", "Penalidad Directa") **no se reescriben**: son datos.
- Nunca cambiar el texto de un `<option>` sin `value` ni valores de BD: rompe registros.

## 6. Prohibido en pantallas
Colores `#hex`, `style="..."` nuevos, `!important` en páginas, `bg-gradient`, `bg-purple`/`indigo`, `btn-info`, otras fuentes (Inter, Arial, Roboto…), emojis, títulos duplicados, tarjetas de color sólido, sombras/animaciones propias. Si falta un estilo: agregarlo como clase en `sgs-theme.css` (sección 17) con tokens, y mostrarlo en `guia_estilo.php`.

## 7. Verificación obligatoria al terminar
```bash
php 05_TEST/frontend/design_lint.php          # 0 errores; deuda heredada no puede subir
php 05_TEST/frontend/smoke_pages.php          # y con Supervisor:directo
php 05_TEST/frontend/check_inline_js.php
```
Además: revisar la pantalla a 1366 px y a 375 px (sin scroll horizontal), con datos y vacía. Si se cambió `sgs-theme.css`, el navegador la recarga solo (la versión de estáticos incluye su fecha).
Si se reorganiza o borra CSS existente: usar `05_TEST/frontend/visual_snapshot.js` (foto antes / comparación después, en escritorio y celular) y exigir 0 diferencias.
Ejecutable PHP en XAMPP: `/c/xampp/php/php.exe`.
