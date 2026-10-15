---
name: sgs-diseno
description: Sistema de diseño oficial de ProviWIN / SGS, versión 2.0 (colores WIN, tipografía única Nunito, menú por secciones, componentes, textos y verificación). Usar SIEMPRE antes de crear, modificar o revisar cualquier pantalla, sección, menú, modal, tabla, indicador, formulario, ficha o estilo en C:\xampp\htdocs\ProviWIN — también al corregir algo "que se ve tosco, grande o desordenado".
---

# Sistema de diseño SGS v2.0 (2026-10-15)

**Objetivo:** que todo el sistema se vea **profesional, limpio y liviano**, igual en cada pantalla. El usuario pidió
expresamente: "todo lo que se vea tosco o grande, rediséñalo" y "el tipo de letra a nivel de todo el sistema".

- **Única hoja de estilos:** `03_FRONTEND/src/assets/css/sgs-theme.css` (más `sgs-login.css` para el ingreso y el cambio
  obligatorio de contraseña). Partes A y B = capas heredadas (no agregar nada ahí). Parte C = sistema de diseño
  (secciones numeradas; las nuevas van al final con el siguiente número y un comentario con fecha y motivo).
- **Guía visible** (administrador): `index.php?page=guia_estilo`. Documento: `01_DOCUMENTACION/03_ARQUITECTURA/GUIA_VISUAL.md`.
- **Los colores del sistema NO se cambian** salvo pedido explícito: el usuario pide forma, letra y orden, no colores.

## 1. Principios (no negociables)
1. **El naranja marca la acción principal y lo activo**; no decora. Una sola acción naranja por zona.
2. **Los colores de estado significan algo**: verde = correcto/a tiempo, amarillo = atención/en plazo, rojo = error/vencido/bloqueado/obligatorio. Nunca para decorar.
3. **Superficies neutras**: fondo `--sgs-bg`, tarjetas blancas con borde fino. **Sin degradados ni bloques oscuros** (solo el menú lateral y el ingreso tienen degradado). Nada de encabezados de tabla oscuros (`table-dark` está prohibido).
4. **Liviano antes que grueso**: ningún texto pasa de **peso 700**; nada de letras gigantes ni círculos/avatars enormes.
5. **Un solo set de íconos**: Bootstrap Icons (`<i class="bi bi-…">`). Prohibidos los emojis.
6. **Español, estilo de oración**: "Nuevo usuario", nunca "Nuevo Usuario". Un solo título por pantalla (en la barra superior; la página no repite `<h1>`).
7. **Ordenado**: cada cosa en su fila (acciones arriba, filtros debajo, datos después); nada de huecos en blanco por textos largos.

## 2. Tokens de color (usar la variable, nunca `#hex` en pantallas)
| Variable | Valor | Uso |
|---|---|---|
| `--sgs-orange` / `-dark` / `-soft` | #FF5A0A / #D8450B / #FFEFE6 | Principal y activo / enlaces y texto naranja / fondo suave de marca |
| `--sgs-ink` / `--sgs-ink-2` | #141414 / #2B2B2B | Títulos y cifras / texto fuerte |
| `--sgs-brown` | #301D19 | Texto de lectura largo |
| `--sgs-muted` | #6B6D70 | Secundario, etiquetas |
| `--sgs-border` | #EDE4DE | Bordes y divisores |
| `--sgs-surface` / `--sgs-surface-alt` | #FFF / #FAF9F7 | Tarjeta / zonas suaves, encabezado de tabla |
| `--sgs-bg` | #F6F4F2 | Fondo de la aplicación |
| `--sgs-success` `--sgs-warning` `--sgs-danger` (+`-dark`, `-soft`) | | Estados (texto con `-dark`, fondo con `-soft`) |

Forma: `--sgs-radius-sm` 10px (controles) · `--sgs-radius` 16px (tarjetas) · `--sgs-radius-lg` 20px (modales).
Bootstrap ya está mapeado: `btn-*`, `badge bg-*`, `alert-*`, `text-*` funcionan sin colores propios.

## 3. Tipografía v2 — una sola letra en TODO el sistema (sección 43)
- **Solo Nunito** (`var(--sgs-font)`). `--sgs-font-display` apunta a la misma letra. **Montserrat se eliminó**: no volver a cargarla
  ni usarla. Las fuentes "MADETommySoft" y "Panton" nunca existieron (no hay archivos): no declararlas.
- Google Fonts: `family=Nunito:wght@400;500;600;700;800&display=swap` (index.php, login.php, cambiar_password.php).
- **Pesos:** texto 500 · etiquetas, botones, pestañas 600 · títulos, cifras, encabezados de tabla, insignias, botón principal 700.
  **Nunca 800 ni 900** (se ve tosco). `fw-bolder`, `strong`, `b` quedan en 700.

| Elemento | Tamaño / peso |
|---|---|
| Título de pantalla (barra superior) | 20px / 700 · descripción 12.5px / 500 gris |
| Título de tarjeta `<h5>`, título de modal | 15.5px / 700 |
| `h2` / `h3`-`h4` dentro de pantallas | 18px / 16.5px, 700 |
| Texto y celdas de tabla | 14px / 13.5px, 500 |
| Etiqueta de campo `.form-label` | ~12.5px / 600, siempre ARRIBA del campo |
| Botón | 13.5px / 600 (principal `btn-win` 700) |
| Encabezado de tabla, etiqueta de indicador | 11.5px / 700, mayúsculas, gris, `letter-spacing .04em` |
| Cifra de indicador `.sgs-kpi-value` | 24px / 700, `tabular-nums` (texto largo: `.is-text` 15.5px) |
| Menú lateral | 14.5px / 600 (activo 700); subopciones 14px |

Para comprobar en todas las pantallas: medir con JavaScript (`getComputedStyle`) que ningún texto visible pase de 700.

## 4. Estructura de la aplicación
### Menú lateral (`03_FRONTEND/src/layouts/sidebar.php`, secciones 3, 42, 42b, 42c)
- **Ancho 228 px**, naranja con logo WIN blanco **centrado** y subtítulo en una línea.
- **Secciones por tarea, en acordeón** (al abrir una se cierra la otra; la de la pantalla actual empieza abierta):
  Inicio (Dashboard / Mi turno) · Supervisiones (Supervisiones, Cuadrillas) · Seguimiento (Línea de tiempo, Monitor de turnos) ·
  Observaciones (Registro, Control y recurrencia) · Administración (Usuarios y roles).
- `$menuGroups = [[sección, ícono, [[visible, href, páginas, ícono, nombre, nombre suelto opcional], …]], …]`.
  Si una sección tiene **una sola opción visible** para ese usuario, se muestra como enlace directo (con su "nombre suelto",
  p. ej. "Observaciones" en vez de "Registro").
- Encabezado de sección = se ve como una opción más (ícono + nombre + flecha). Las pantallas dentro de una sección van
  **sin ícono**, con sangría y una línea guía fina (el ícono reaparece en el menú contraído).
- Nombres cortos que entren en **una línea** a 228 px. "Mi información" y "Cerrar sesión" van aparte, abajo.
### Página nueva
`03_FRONTEND/src/pages/<nombre>.php` + `index.php` → `$allowedViews` (`file`, `title`, `perm`) y `$pageDescriptions`; si es
solo administrativa, en `$administrativeViews`; agregarla al menú en la sección que corresponda.
```html
<div class="container-fluid p-3 p-md-4">
  <div class="sgs-page-actions"><span class="sgs-counter">0 registros</span>
    <button type="button" class="btn btn-win"><i class="bi bi-plus-lg me-1"></i>Nuevo registro</button></div>
  <!-- tarjetas… -->
</div>
```

## 5. Componentes (copiar estos patrones)
**Barra de un listado (sección 38)** — tarjeta con dos filas: fila 1 = estados + TODAS las acciones (secundarias primero, la
principal al final; botones de solo ícono como `.sgs-btn-icono` con `title` y `aria-label`); fila 2 = SOLO filtros a todo el ancho.
```html
<div class="card sgs-toolbar-card p-3 mb-3">
  <div class="sgs-toolbar">
    <div class="sgs-segmented" role="group" aria-label="Estado">
      <button type="button" class="filter-tab-btn active" data-filter="todos">Todas <span class="sgs-seg-count">0</span></button> …
    </div>
    <div class="sgs-toolbar-actions">
      <button class="btn btn-outline-secondary">Ver rutas</button>
      <div class="dropdown"><button class="btn btn-outline-secondary sgs-btn-icono" title="Columnas visibles" aria-label="Columnas visibles" data-bs-toggle="dropdown"><i class="bi bi-layout-three-columns"></i></button>…</div>
      <button class="btn btn-win">Nueva supervisión</button>
    </div>
  </div>
  <div class="sgs-toolbar-row"><div class="sgs-filtros">
    <div class="sgs-filtro sgs-filtro--ancho"><label class="form-label" for="q">Pedido o ticket</label>
      <div class="sgs-filtro-busqueda"><i class="bi bi-search"></i><input class="form-control" id="q" type="search"></div></div>
    <div class="sgs-filtro"><label class="form-label" for="x">Departamento</label><select class="form-select" id="x">…</select></div>
    <div class="sgs-filtro sgs-filtro--fecha"><label class="form-label" for="d">Desde</label><input class="form-control" id="d" type="date"></div>
    <button class="btn btn-outline-secondary sgs-filtro-limpiar"><i class="bi bi-x-lg me-1"></i>Limpiar</button>
  </div></div>
</div>
```
Todo de 40 px y alineado abajo; todo filtro tiene "Limpiar"; máximo 3–4 botones visibles (el resto en un menú).
Patrón anterior `row g-3 sgs-toolbar-filters` (Dashboard, Cuadrillas, Línea de tiempo): pasarlo a este cuando se toquen.

**Pestañas / segmentos**: `.sgs-segmented` compacto (no barras a todo el ancho). Si el padre es una tarjeta (flex en columna),
agregar `align-self: flex-start` o se estira (los hijos de un flex se "bloquean" y `inline-flex` no basta).

**Indicador (KPI)** — neutro; `sgs-kpi--success|warning|danger|info` solo si el valor tiene ese estado:
```html
<div class="col-6 col-lg-3"><div class="sgs-kpi p-3 h-100">
  <span class="sgs-kpi-icon" aria-hidden="true"><i class="bi bi-people"></i></span>
  <small class="sgs-kpi-label">Usuarios activos</small><h3 class="mb-0 sgs-kpi-value">0</h3></div></div>
```
**Tarjeta / sección**: `<div class="card p-3 p-md-4">` + `<h5 class="mb-0"><i class="bi bi-… me-2 text-primary"></i>Título</h5>` + `<p class="text-muted small">` de ayuda.

**Tabla**: `<div class="table-responsive"><table class="table table-hover mb-0 align-middle sgs-tabla-tarjetas">` y
`data-label="Columna"` en cada `<td>` (en celular se vuelve tarjetas, sección 37). Encabezado claro (nunca `table-dark`).
Acciones a la derecha con botones de solo ícono + `aria-label`. Vacío: `<i class="bi bi-inbox"></i>` + frase corta.
Persona en una tabla (sección 43b): `<div class="sgs-persona"><span class="sgs-mini-avatar">AB</span><div class="sgs-persona-texto"><span class="sgs-persona-nombre">Nombre</span><small>DNI · Rol</small></div></div>`
y la columna con `class="sgs-col-persona"`. Círculos de iniciales: 32px (tablas) o 88px como máximo (perfil), fondo `--sgs-orange-soft`.

**Ficha de consulta (sección 40)** — para ver un registro terminado: `.sgs-ficha-datos > .sgs-dato` (`.sgs-dato-etiqueta` +
`.sgs-dato-valor`), rejilla de 4 columnas **sin cajas**; textos largos con `.sgs-dato--completo` al final de su sección,
medianos `.sgs-dato--ancho`; fotos en `.sgs-evidencias`. Así no quedan huecos en blanco.

**Campo obligatorio pendiente (sección 39)**: contenedor con `.sgs-campo-pendiente` mientras esté vacío (amarillo con marca
"Pendiente"); `.is-alerta` si intentaron continuar sin llenarlo (rojo, "Obligatorio"). El botón principal indica qué falta.

**Botones flotantes de formularios largos (sección 36)**: las acciones de guardar quedan fijas abajo a la derecha (en celular,
barra completa). La animación de entrada de página usa `backwards` y termina en `transform: none` (si no, `position: fixed` falla).

**Botones** (jerarquía): principal `btn btn-win` (1 por zona) · secundario `btn btn-outline-secondary` · peligro
`btn btn-outline-danger`. Texto = verbo + objeto ("Guardar corrección"), nunca "Aceptar/OK".
**Insignias**: `badge bg-success|warning|danger|secondary|primary|dark` (se ven en tono suave). Estado de turno: `badge-status badge-en-turno…`.
**Modal**: `modal-dialog-centered`, título con ícono, pie con secundario y principal a la derecha; abrir con `bootstrap.Modal.getOrCreateInstance(el)`.
**Avisos**: `window.showToast(mensaje, 'success'|'error')`; en formularios `alert alert-danger|success`.
**Estado del GPS (sección 41)**: `<span class="sgs-gps-estado" data-estado="buscando|ok|baja|error">`.
**Subir archivos**: `<input type="file" class="form-control">` (sección 20). Las fotos se comprimen solas antes de subir
(`main.js`, 2048 px / JPEG 85 %); excluir un campo con `data-sin-comprimir`.

## 6. Textos
- Español neutro, frases cortas, estilo de oración. Sin dato: **—**. Fechas dd/mm/aaaa; horas 24 h.
- Los datos guardados (departamentos, estados como "En Turno") no se reescriben. No cambiar el texto de un `<option>` sin `value`.

## 7. Prohibido
Colores `#hex` y `style="…"` nuevos en pantallas · `!important` en páginas · degradados (salvo menú e ingreso) · `table-dark` y
bloques oscuros · pesos 800/900 · Montserrat u otras fuentes · emojis · títulos duplicados · tarjetas de color sólido ·
barras de pestañas a todo el ancho · círculos o letras gigantes · huecos en blanco por textos largos.
Si falta un estilo: agregarlo como clase en `sgs-theme.css` (nueva sección al final, con tokens) y mostrarlo en `guia_estilo.php`.

## 8. Al tocar código heredado
Antes de agregar una regla, buscar si otra la pisa (`grep` en `sgs-theme.css` y en el `<style>` de la página). Si una regla no
se aplica: listar con JavaScript qué reglas coinciden con el elemento (`el.matches(regla.selectorText)`) y revisar el padre
(flex/grid estira a los hijos). Preferir una clase propia antes que sumar `!important`.

## 9. Verificación obligatoria al terminar
```bash
/c/xampp/php/php.exe 05_TEST/frontend/design_lint.php      # 0 errores; la deuda heredada no puede subir
/c/xampp/php/php.exe 05_TEST/frontend/smoke_pages.php      # y con Supervisor:directo y Supervisor:partner
/c/xampp/php/php.exe 05_TEST/frontend/check_inline_js.php
```
Además, en el navegador: la pantalla a **1366 px, 1100 px y 375 px** (sin desplazamiento lateral), con datos y vacía, como
administrador y como supervisor (a los supervisores les tapa la pantalla el aviso de GPS: leer el DOM en vez de capturar).
Los estáticos se recargan solos (la versión incluye la fecha del CSS/JS). En el hosting hay **Cloudflare**: un archivo
descargable que cambie (plantillas .xlsx, imágenes) necesita nombre nuevo o `?v=` en el enlace.
Usuario de prueba: `07_SCRIPTS/crear_usuario_prueba.php [Rol]` (credenciales en `09_CONFIG/usuario_prueba.local.txt`, nunca
escribirlas en respuestas) y `--eliminar` al terminar.
