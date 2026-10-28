---
name: sgs-liviano
description: Reglas de rendimiento de ProviWIN / SGS ("sistema liviano") para no saturar el hosting (cPanel + Cloudflare, 15 a 2000 usuarios). Usar SIEMPRE antes de crear o cambiar código del SGS en C:\xampp\htdocs\ProviWIN que corra en una visita web (páginas, API, JavaScript, consultas a la base, tareas automáticas, avisos a Teams, carga de archivos o fotos), y cuando el usuario pida revisar, medir o aligerar el sistema.
---

# Sistema liviano SGS (regla del usuario desde 2026-10-16)

**Por qué:** el hosting compartido dio **error 504** por procesos pesados dentro de una visita (los respaldos). El usuario pidió
que el sistema aguante 15 o más usuarios a la vez, y luego hasta unos 2000, sin saturarse. **Todo cambio debe mantenerlo
liviano, y antes de un cambio grande se mide, se explica y se espera su visto bueno ("verifícalo primero").**

## 1. Reglas (no negociables)
1. **Nada pesado dentro de una visita web.** Respaldos, envíos masivos, imágenes grandes o recorridos de tablas enteras van:
   - por **tarea programada** (cron de cPanel, scripts en `07_SCRIPTS/`), o
   - **después de mandar la página**: `register_shutdown_function` + `fastcgi_finish_request()` / `litespeed_finish_request()`.
     Modelos: `sgsProgramarRespaldo()` (config/respaldos.php) y `sgsEnviarCortesTrasLaVisita()` (config/lluvias_corte.php).
2. **Tareas periódicas que dependen de visitas:** `win_run_periodic_task('nombre', segundos, fn)` (config/runtime.php), que usa
   un archivo con bloqueo y corre como mucho una vez por intervalo para todo el sistema (p. ej. cierre de turnos cada 300 s y
   envíos del corte cada 300 s). Nunca una consulta pesada en **cada** visita.
3. **Programa y estilos en archivos aparte** (`03_FRONTEND/src/assets/js/*.js`, `css/*.css`): el navegador y Cloudflare los
   guardan. En la página solo va un objeto chico con datos del servidor (modelo: `window.SGS_SUPERVISION`).
   `05_TEST/frontend/check_inline_js.php` lo revisa.
4. **Consultas automáticas desde el navegador (sondeos):** solo si hacen falta, cada **30–60 s como mínimo**, y **nunca con la
   pestaña oculta**: `if (document.hidden) return;` y actualizar al volver (`visibilitychange`). Modelo: dashboard_lluvias.js.
5. **Listados paginados.** Nunca traer todo: `limit` de 20 a 100 en la API (supervisiones: 50) y "Cargar más". En los
   dashboards, los totales se calculan en la base (`GROUP BY`) y al navegador van solo números y 50 filas por página
   (config/dashboard.php).
6. **Consultas a la base:**
   - Filtrar por columnas indexadas. Evitar `SELECT *` en listados.
   - Contar con subconsultas indexadas, no agrupando tablas enteras.
   - No consultar dentro de un bucle por cada fila: traer por lotes con `IN (...)` (modelo: timeline_diaria en api/asistencia.php).
   - Rol, área y zonas de usuarios: `sgsUserZones`, `sgsUserArea` y `sgsUserRol` comparten una caché de la petición. Antes de
     recorrer una lista de usuarios, llamar `sgsPrecargarUsuarios(array_column($lista, 'id'))` (2 consultas en total). En
     UsuarioService, `filasConZonas($filas)`.
   - Con un índice nuevo: cambio de base con su carpeta en `04_DATABASE/02_CAMBIOS` (ver `scripts-sql-por-cambio`).
7. **Librerías pesadas solo donde se usan** (mapas, gráficos, lector de Excel): cargarlas en esa pantalla, no en todo el sistema.
8. **Fotos y archivos:**
   - Comprimir en el navegador antes de subir (main.js: 2048 px / JPEG 85 %; fotos de lluvia: 1600 px / 82 %).
   - Límites de tamaño en el servidor (`validateUploadedFile`).
   - Lo temporal se borra solo: fotos de Teams y cortes a los 90 días, usando la misma rutina de guardado (sin tarea aparte).
9. **Servicios externos (Teams, etc.):** tiempo máximo corto (`CURLOPT_TIMEOUT` 4 s). Si fallan, la operación del usuario
   igual se guarda. Nunca un envío por cada usuario de una lista dentro de la visita.
10. **Cloudflare guarda los estáticos:** los `.js`/`.css` llevan `?v=ASSET_VERSION` (cambia sola con la fecha del archivo). Un
    archivo descargable que cambie (plantillas, imágenes) necesita nombre nuevo o `?v=`. `sw.js` no se guarda en caché.
11. **Código simple:** sin código sin uso. No unir código "repetido" si las copias no son idénticas y la ganancia es mínima:
    explicarlo en vez de forzarlo.

## 2. Antes de hacer un cambio
- ¿Corre en **cada** visita? Si lee mucho, se calcula, se guarda en caché o va en tarea periódica.
- ¿Hace algo lento (más de 1 s, archivos grandes, servicio externo)? Va después de mandar la página o por cron.
- ¿Agrega un sondeo? Justificarlo, ≥ 30 s y con `document.hidden`.
- ¿Trae listas? Paginadas y con índice.
- Si el cambio es grande (estructura, librería nueva, proceso automático), **medir y explicar al usuario antes de hacerlo**.

## 3. Cómo medir (herramientas que ya existen)
```bash
/c/xampp/php/php.exe 05_TEST/integration/carga_100_usuarios.php 100 3     # 100 sesiones a la vez: 0 errores
/c/xampp/php/php.exe 05_TEST/integration/volumen_bd_prueba.php 100 365     # BD de prueba aparte con 1 año de datos (la borra)
/c/xampp/php/php.exe 05_TEST/frontend/check_inline_js.php                  # programa en archivos, no en la página
/c/xampp/php/php.exe 05_TEST/frontend/smoke_pages.php                      # todas las pantallas responden
```
- **Peso de una pantalla:** con sesión de prueba (`07_SCRIPTS/crear_usuario_prueba.php`), `curl -s -o /dev/null -w '%{size_download} %{time_total}'`.
  Referencia: Supervisiones bajó de 406 KB a 136 KB por visita.
- **Consulta lenta:** `EXPLAIN` en MariaDB; si recorre la tabla entera (`type: ALL`) con muchas filas, falta un índice o
  sobra un `GROUP BY`.
- En el navegador: pestaña Red (número y peso de peticiones) y que con la pestaña oculta no haya peticiones repetidas.

## 4. Señales de alarma (corregir antes de terminar)
- Una consulta o `curl` dentro de un `foreach` sobre todos los usuarios o supervisiones, en una visita.
- `setInterval` sin `document.hidden` o con menos de 30 s (salvo relojes que no consultan al servidor).
- `<script>` o `<style>` largos dentro de una página.
- `SELECT *` sin `LIMIT` en un listado, o `COUNT(*)` agrupando la tabla entera en cada visita.
- Un archivo o foto que se guarda sin límite de tamaño ni fecha de borrado.
- Un proceso que puede tardar (respaldo, cientos de envíos) disparado por un botón sin pasar a segundo plano.

## 5. Código sin uso, parches y simplificación (regla del usuario)
- **Nada de código sin uso.** Si un cambio deja de usar una función, archivo, clase CSS, constante o columna, se borra en el
  mismo cambio, sin comentarlo ni dejarlo "por si acaso". Antes de borrar, buscar todos los usos (`grep` en PHP, JS y
  páginas; ojo con los nombres armados al vuelo, como `is-${estado}` o `venc-chip--${estado}`).
- **Nada de parches.** Corregir la causa en el lugar común (una función central), no agregar un caso especial en cada
  pantalla. Ejemplo: la zona se calcula solo en `sgsZonaDeUbicacion` / `sgsZonaSql`. Ejemplo de lo que no se hace: el antiguo
  alias "HUANCAYO = JUNÍN" repartido en varios archivos; se corrigió el dato y se quitaron los alias.
- **Simplificar sin cambiar lo que hace:** menos líneas y menos consultas, con el mismo resultado. Si la simplificación cambia
  el comportamiento o toca muchas pantallas, se explica y se espera el visto bueno.
- **Al reemplazar algo** (por ejemplo, el visor de fotos que el usuario rechazó), se quitan también sus archivos, estilos,
  textos de ayuda y pruebas.
- **Revisión periódica:** buscar funciones PHP y JS que nadie llama, archivos `.js`/`.css` que ninguna página carga, páginas
  sin ruta, endpoints que nadie llama, constantes sin uso y clases CSS sin uso. Presentar la lista al usuario antes de borrar.
- Las partes A y B de `sgs-theme.css` son capas heredadas: no se agrega nada ahí; lo que no se use se puede quitar con pruebas.

## 6. Al terminar
- Correr `check_inline_js.php`, `smoke_pages.php` y, si el cambio toca listas o consultas frecuentes, `carga_100_usuarios.php`.
- Decir al usuario en una línea qué se cuidó del rendimiento si el cambio corre en las visitas.
- Pendiente sugerido (aún no hecho): bajar la compresión de fotos si el espacio del hosting se llena.
