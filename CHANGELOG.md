# CHANGELOG — SGS

Formato: [versión] — fecha. Tipos: Añadido, Cambiado, Seguridad, Eliminado.

## [0.10.0] — 2026-10-08 — Bloqueo de ingreso, cierre de turnos y relación de observaciones

### Seguridad
- **Ingreso: 3 intentos.** Tres contraseñas incorrectas seguidas bloquean el usuario con el mensaje "Comunícate con el administrador del sistema para recuperar tu acceso". El aviso muestra los intentos restantes. El bloqueo se mantiene aunque luego se escriba la contraseña correcta.
- Usuarios y roles muestra la etiqueta "Bloqueado" y el botón **Desbloquear** (queda en auditoría); restablecer la contraseña también desbloquea.
- El límite por equipo (5 intentos en 15 min) ahora aplica solo a usuarios inexistentes, para que el desbloqueo del administrador surta efecto de inmediato.

### Añadido
- **Cierre automático del turno a las 23:59** (`02_BACKEND/src/config/turnos.php`): el turno olvidado se cierra a las 23:59 de su día con "Fuera de turno", horas trabajadas, almuerzo y extras calculados igual que el cierre manual, pausas abiertas cerradas y una nota. Corre cada 5 min mientras se usa el sistema y desde `07_SCRIPTS/cerrar_turnos.php` (programable a las 23:59).
- **Relación de observaciones en Excel y PDF** (`api/observaciones_export.php`): botón **Descargar** en Registro de observaciones con los filtros aplicados (por ejemplo, Estado = Penalizados). El Excel trae 19 columnas, encabezado fijo y autofiltro; el PDF es A4 horizontal con los filtros aplicados y totales por resultado. El PDF de checklist con evidencias sigue en el mismo menú.
- `02_BACKEND/src/utils/XlsxWriter.php`: genera .xlsx sin la extensión zip de PHP.
- **Visor de fotos con zoom** (`window.sgsVerFoto` en `main.js`, sección 26 del tema): la foto se ve completa sin cortes, con acercar y alejar, porcentaje, Ajustar, rueda del mouse, doble clic, pellizco y arrastre. Lo usan Supervisiones y Registro de observaciones; reemplaza el visor oscuro anterior.
- Pruebas: `login_bloqueo_test.php` (7), `cierre_turnos_test.php` (8), `observaciones_export_test.php` (9).

### Corregido
- Al descargar un archivo, la pantalla ya no queda atenuada y bloqueada.

## [0.9.2] — 2026-10-08 — Esquema de la base de datos

### Añadido
- `04_DATABASE/01_SCHEMA/win_telecom_db_esquema.sql`: esquema completo de `win_telecom_db`, solo estructura (36 tablas, 44 relaciones, sin datos ni `DROP`). Verificado cargándolo en una base temporal que luego se eliminó.
- `04_DATABASE/02_TABLES/`: un archivo `.sql` por tabla.

## [0.9.1] — 2026-10-08 — Filtro general del Dashboard

### Cambiado
- **Un solo filtro arriba del Dashboard** que mueve todas las secciones: período **Por mes** (mes y año, se aplica al cambiar) o **Rango de fechas** (Desde y Hasta), más Partner y Supervisor, con "Filtrar" y "Limpiar" (vuelve al mes actual). Muestra el período activo.
- Indicadores operativos, mapa, Cumplimiento de partners, **Meta de productividad** y **Matriz** usan ese período; la Meta y la Matriz ya no tienen selector propio.
- La Matriz acepta rangos que cruzan meses (columnas por fecha, por ejemplo "Lun 28/09") hasta 62 días; con un rango mayor muestra un aviso.
- La Meta calcula los días hábiles del rango elegido.
- El filtro de supervisor también aplica a la Matriz y a la Meta. El de partner aplica a indicadores y cumplimiento (la Matriz y la Meta son del personal directo).
- API: `dashboard.php` acepta `fecha_inicio`/`fecha_fin` (o `mes`/`anio`) y `supervisor_id` en `matriz_mensual` y `productividad_mensual`.
- La Línea de tiempo del día mantiene su propia fecha (es una vista de un solo día).

## [0.9.0] — 2026-10-08 — Meta de productividad

### Añadido
- **Tarjeta "Meta de productividad" en el Dashboard** (personal directo), con selector de mes y año:
  - Indicadores: meta diaria (4.5), promedio del equipo (con punto de estado), supervisores que cumplen y días hábiles transcurridos.
  - Por supervisor: días trabajados (con los domingos marcados), supervisiones finalizadas, **promedio por día trabajado** frente a la meta y **avance del mes**: finalizadas frente a la meta a la fecha, con barra y estado (Cumple, Cerca de la meta, Bajo la meta, Sin turnos).
- **Reglas:**
  - Días trabajados = días con turno iniciado.
  - Meta a la fecha = 4.5 × (lunes a sábado transcurridos + domingos en que el supervisor trabajó).
  - Solo cuentan las supervisiones **finalizadas**, el mismo criterio que la matriz mensual.
  - Estado: Cumple ≥ 4.5; Cerca ≥ 80 % de la meta; Bajo, por debajo de eso.
- La meta se configura en `SGS_META_SUPERVISIONES_DIA` (`.env`); por defecto, 4.5.
- API: `dashboard.php?action=productividad_mensual&mes=&anio=`. Prueba: `05_TEST/integration/productividad_test.php` (9/9, incluido el domingo trabajado).

## [0.8.2] — 2026-10-07 — Importación de cancelaciones

### Corregido
- **"No se pudieron identificar las columnas requeridas" al subir la plantilla de cancelaciones.** La función que quitaba tildes (`iconv` ASCII//TRANSLIT) en Windows convierte "Ó" en "'O", así que no reconocía "CÓDIGO DE SEGUIMIENTO", "GESTIÓN O ACTIVIDAD SUPERVISADA" ni las zonas ÁNCASH o JUNÍN. Ahora se quitan las tildes sin `iconv`.
- **Columnas mal asignadas:** la búsqueda por parecido podía tomar "FECHA CANCELACIÓN" como motivo o "DISTRITO" como provincia. Ahora primero se buscan los encabezados exactos y la ubicación se resuelve según el formato (plantilla: DEPARTAMENTO/PROVINCIA/DISTRITO; exportación antigua: PROVINCIA/LOCALIDAD/ZONA). La búsqueda parcial queda solo como respaldo, sin repetir columnas. Verificado con el archivo del usuario: las 11 columnas, correctas.
- **"OBSERVACIÓN PREVIA"** de la plantilla se guarda (antes se descartaba).
- **Asignación automática:** también considera las zonas de trabajo adicionales de cada supervisor, no solo su zona base.

## [0.8.1] — 2026-10-07 — Línea de tiempo más clara

### Cambiado
- Colores definidos por el usuario: **iniciada (en curso) = verde**, **finalizada = gris oscuro**. La leyenda dice "Supervisión iniciada" y "Supervisión finalizada".
- Supervisión SUP-20261007-8010 (Angelo Palomino), la única finalizada antes de que existiera el historial: se registró su hora de fin, **01:07:28**, reconstruida del registro del servidor web (guardado de cierre y subida de las 3 fotos de evidencia en ese segundo). El dato queda anotado con su origen en `supervision_estado_historial.comentario`.
- El texto del bloque usa etiquetas explícitas: "Inicio 00:18 · Fin 00:57 · 39 min" y "Inicio 01:16 · En curso · 12 min". La leyenda se actualizó.

## [0.8.0] — 2026-10-07 — Horas reales, guardado de avances, GPS y turno obligatorios

### Corregido
- **"Guardar avance" perdía datos.** Probado con una supervisión real de prueba: se perdían el técnico de apoyo (nombre y DNI), el tipo de vehículo, el SOAT y la revisión técnica. Había dos causas: (1) al reabrir la supervisión, el autocompletado de la cuadrilla desde el catálogo pisaba lo registrado por el supervisor; (2) el formulario de trabajo no envía SOAT ni revisión técnica y el servidor los borraba. Ahora se respeta lo guardado y, en la misma prueba, se conservan todos los campos.

### Añadido
- **Historial de estados:** cada cambio de estado de una supervisión (inicio, avance, finalización) queda registrado con su hora en `supervision_estado_historial`.
- **Línea de tiempo con horas reales:** cada supervisión muestra la hora de inicio y la de fin (antes se asumían 35 minutos fijos). **En curso:** borde naranja con el tiempo transcurrido. **Finalizada:** verde. Las finalizadas antes de este cambio muestran "sin hora de fin". Lo mismo en el Dashboard.
- **Turno obligatorio antes de supervisar:** al pulsar "Nueva supervisión" o retomar una en proceso, si el supervisor directo no inició turno aparece el aviso "Inicia tu turno" con el botón para iniciarlo; si su turno ya terminó, se le informa. Antes recién se enteraba al guardar.
- **GPS obligatorio para el personal de campo** (supervisores directos y de partner): sin ubicación, una pantalla bloquea el sistema y explica cómo activarla según el caso (permiso bloqueado, GPS apagado o sin señal). Se vuelve a comprobar cada 3 minutos y cuando cambia el permiso (`layouts/gps_gate.php`). Requiere conexión segura (https) en producción.

## [0.7.4] — 2026-10-07 — Barras de desplazamiento

### Cambiado
- **Una sola barra de desplazamiento en todo el sistema** (`sgs-theme.css`, sección 22): delgada, gris cálido translúcido, sin flechas ni fondo; en el menú lateral, blanca translúcida. Reemplaza la barra ancha de Windows y las heredadas (naranja gruesa en tablas, matriz y línea de tiempo; gris azulado en modales).

## [0.7.3] — 2026-10-07 — Menú por secciones

### Cambiado
- **Menú por secciones desplegables** (Panel, Supervisión, Observaciones, Administración): un clic en el nombre de la sección la abre o la cierra, con una flecha que lo indica y una animación suave. La sección de la pantalla actual siempre aparece abierta. Las demás se recuerdan como las dejó cada usuario en su navegador. Con el menú contraído (solo íconos) se ven todas las opciones.
- **La barra del título queda fija al bajar.** Antes desaparecía y la campana quedaba flotando sobre el contenido; la causa era `#content{overflow-x:hidden}` (heredado), reemplazado por `overflow-x:clip`.
- Dashboard, "Cumplimiento de partners": las 6 tarjetas pasan a 3 por fila en laptops (antes eran de 113 px y cortaban las palabras).

## [0.7.2] — 2026-10-07 — Limpieza de código sobrescrito y muerto

### Cambiado
- **Una sola hoja de estilos.** `win-theme.css` + `win-executive.css` + `sgs-theme.css` se unieron en `sgs-theme.css`, en el mismo orden de carga, así que la cascada es idéntica. Se quitaron 276 selectores de elementos que no existen y 500 declaraciones que otra posterior siempre anulaba. `!important` pasa de 934 a 623; el tamaño, de 134 KB en 3 archivos a 95 KB en 1, y el navegador hace 2 descargas menos.
- **Verificación:** se comparó el estilo final de cada elemento (unos 8.000) en 24 vistas: 12 pantallas, administrador y supervisor, escritorio y celular. Resultado: **0 diferencias**. La herramienta queda en `05_TEST/frontend/visual_snapshot.js`.

### Eliminado
- **Restos de la Vista provisional** en 10 archivos: index, menú, Mi información, Mi turno, Observaciones, Supervisiones, APIs de turnos y de supervisiones, auth y config. Eran ramas que nunca se ejecutaban porque la sesión siempre estaba en modo normal. Se conservó la lógica real; por ejemplo, el administrador sigue viendo todo.
- **Captcha:** `middlewares/captcha.php`, `routes/api/captcha.php` y `api/captcha.php` (ahora 404). También variables sin uso en `index.php`.

## [0.7.1] — 2026-10-07 — Orden de paneles y formularios

### Cambiado
- **Supervisiones, panel de control rehecho.** Fila 1: estados (Todas, En proceso, Finalizadas, Pendientes) en un control segmentado con contadores, y las acciones a la derecha. Fila 2: filtros con etiqueta arriba y un botón nuevo, "Limpiar". "Descargar modelo" y "Subir cancelaciones" van en un solo menú, "Cancelaciones". En celular, "Nueva supervisión" queda primero. Se conservan todos los id que usa el JS.
- **Mi turno:** el cronómetro pasa de bloque negro con círculos decorativos a bloque claro. "Fuera de turno" sale en gris (antes en rojo, como un error). El título ya no repite el de la página. "Fin de Turno" pasa a "Finalizar turno" y las pausas usan botones neutros.
- **Estados de turno con una sola definición** (`sgs-theme.css`, sección 19). Antes había una regla con degradado e `!important` en `win-theme.css` que anulaba la de la página.
- **Formularios heredados** (`.field-bitacora`): borde gris estándar y la misma flecha en los selectores. El token `--win-border-strong` apuntaba al naranja.
- **Selectores en todo el sistema:** el texto ya no pasa por debajo de la flecha ni se parte en dos renglones.
- **Subir archivos:** botón del sistema y zona punteada en lugar del control nativo del navegador.
- Skill `sgs-diseno`: nuevos patrones (barra de herramientas, estados de turno, archivos) y regla para trabajar sobre código heredado.

## [0.7.0] — 2026-10-07 — Sistema de diseño v1.0

### Añadido
- **Skill del proyecto `sgs-diseno`** (`.claude/skills/sgs-diseno/SKILL.md`): reglas de colores, tipografía, componentes, textos y verificación para que toda sección nueva salga igual al resto.
- **Guía de diseño en vivo** (`index.php?page=guia_estilo`, solo administración): colores, tipografía, indicadores, botones, insignias, formularios y tabla con las clases reales.
- **Revisor automático** `05_TEST/frontend/design_lint.php`. Marca como error los emojis, las opciones "-- … --", las fuentes ajenas y las clases fuera de la paleta. La deuda heredada (#hex y `style=` en pantallas) queda medida y no puede crecer.

### Cambiado (auditoría de diseño)
- Fondo neutro y plano. Títulos de pantalla en estilo de oración, sin mayúsculas forzadas.
- Indicadores unificados en todas las pantallas: tarjeta neutra con punto de estado solo cuando el dato lo tiene. Se quitaron colores decorativos (por ejemplo, "Administradores" en rojo). La Línea de tiempo y el Monitor de turnos usan el mismo componente, con ícono.
- Tablas con encabezado neutro. Insignias en tono suave. Roles: Supervisor en naranja suave y Administrador en gris.
- Contadores como píldora neutra (antes parecían botones). Las pestañas de Observaciones ya no usan colores distintos.
- 38 emojis reemplazados por Bootstrap Icons. 96 etiquetas pasadas a estilo de oración, sin tocar valores de datos. 45 opciones "-- Seleccione --" limpiadas. "Pausa" pasa de morado a marrón de marca; "-" pasa a "—".
- Corregida la mancha borrosa sobre el título de cada pantalla (era la sombra del enlace oculto "Ir al contenido").
- La versión de estáticos incluye la fecha de `sgs-theme.css`: el navegador toma los estilos nuevos sin Ctrl+F5.

## [0.6.3] — 2026-10-07

### Añadido
- **Editor de foto de perfil ("Ajustar foto")**: al elegir una imagen se abre una ventana para encuadrarla: arrastrar para mover, zoom (botones, barra, rueda del mouse o dos dedos), girar 90°, vista previa circular y "Otra imagen". Guarda un cuadrado de 512 px. Funciona en celular. Corregido: la vista previa se veía borrosa y estirada (el lienzo quedaba en 300×150); ahora se dibuja a la resolución real de la pantalla, y el zoom mantiene enfocada la zona encuadrada.

### Cambiado
- **Menú lateral ordenado por grupos:** Panel (Dashboard) → Supervisión (Mi turno, Supervisiones, Línea de tiempo, Monitor de turnos) → Observaciones (Registro, Control y recurrencia) → Administración (Usuarios y roles). "Mi información" y "Cerrar sesión" quedan fijos abajo. Cada grupo aparece solo si el rol tiene opciones en él.

### Eliminado
- **Captcha del login**, a pedido del usuario. Sigue el bloqueo de 15 minutos tras 5 intentos fallidos. `api/captcha.php` responde 410.

## [0.6.2] — 2026-10-07

### Añadido
- **Foto de perfil propia** en Mi información: botón de cámara sobre la foto y opción "Quitar foto". La imagen se recorta en cuadrado de 512 px (JPEG) en el navegador, lo que también quita datos ocultos como el GPS. El servidor solo acepta JPG, PNG o WEBP reales de hasta 3 MB. Se guarda en `uploads/avatars` y en `usuarios.avatar`, igual que el sistema original, así que los dos muestran la misma foto. Al reemplazarla se borra la anterior. La tarjeta y el menú lateral se actualizan sin recargar.
- Prueba `05_TEST/integration/foto_perfil_test.php` (subir, servir, reemplazar, rechazar no-imagen, CSRF, quitar): 6/6.

### Eliminado
- Usuarios inactivos `supervisor.comfica.prueba` (id 52) y `codex_audit` (id 53), con respaldo JSON en `04_DATABASE/10_BACKUPS/`.

## [0.6.1] — 2026-10-06

### Cambiado
- **Usuarios y roles: un solo campo "Zonas de trabajo".** Se quitaron "Sede" y "Zonas adicionales": se marcan los departamentos que ve el usuario o "Todas las zonas" (obligatorio para Supervisor y Partner). La tabla muestra la columna *Zonas*; Mi información ya no muestra Departamento ni Sede, solo las zonas. Los usuarios existentes conservan su zona (la sede anterior se convierte en su zona base). Sin cambios en la base de datos.
- **Zonas = departamentos de Supervisiones.** La lista sale de la tabla `ubigeo` (la misma del formulario de supervisión): ÁNCASH, AREQUIPA, CUSCO, JUNÍN, LA LIBERTAD, LAMBAYEQUE, PIURA. Se quitaron CAJAMARCA, ICA y TACNA, que no se usan. El formulario de supervisión ya no muestra "HUANCAYO" como departamento (era un duplicado de JUNÍN → HUANCAYO).

### Eliminado
- Usuario partner de prueba `supervisor.mallausa.prueba` (id 51), respaldo en `04_DATABASE/10_BACKUPS/`.

### Pruebas
- `usuarios_service_test.php` cubre zonas y "Todas las zonas" (20/20). `permission_matrix.php` omite con aviso un tipo de usuario que no exista (hoy no hay partners).

## [0.6.0] — 2026-10-06

### Eliminado
- **Vista provisional** (el administrador cambiaba a "Supervisor directo" o "Supervisor partner"): se retiró el panel y el servidor la anula en cada petición, también si se intenta por la dirección web. Cada usuario trabaja con su propia cuenta y rol.

### Rendimiento y capacidad (100 usuarios)
- Las consultas de solo lectura de la API liberan la sesión de inmediato; antes las consultas simultáneas de un mismo usuario se ponían en fila. La lista de supervisiones pasó de 510 a 613 pet/s con 100 usuarios.
- Prueba de carga `05_TEST/integration/carga_100_usuarios.php`: 100 usuarios con sesiones distintas, 0 errores, p95 < 0,24 s.
- Prueba de volumen `05_TEST/integration/volumen_bd_prueba.php`: un año de datos en una BD de prueba aparte (se borra al terminar); ninguna consulta pasa de 0,41 s.
- Configuración de producción en `09_CONFIG/`: `php-produccion.ini` (OPcache), `mysql-produccion.cnf` (memoria de datos y consultas lentas) y `apache-sgs.conf.example` (HTTPS, PHP-FPM, mpm_event).

## [0.5.8] — 2026-10-06

### Añadido
- Guía `01_DOCUMENTACION/08_DESPLIEGUE/PUBLICAR_Y_ACTUALIZAR.md`: publicar en internet y actualizar el código (Git) y la base de datos (migraciones) sin perder datos.
- `06_DEPLOY/production/actualizar.sh`: actualización en el servidor (respaldo → git pull → migraciones → comprobación).
- `07_SCRIPTS/respaldar_bd.php`: respaldo comprimido de la BD (Windows y Linux) con retención de 30 días.

### Cambiado
- `08_LOGS/errores` registra solo fallos vividos por usuarios; lo ejecutado por consola queda en `backend/` con `origin=cli`.

### Revisión
- Uso real del 05 y 06/10: sin errores registrados. Todas las pruebas pasan.

## [0.5.7] — 2026-10-04

### Corregido (control del turno)
- Un administrador en *Vista provisional* como un supervisor podía pulsar "Iniciar turno", y el turno se guardaba **a nombre del administrador**, no del supervisor. Por eso no aparecía en la Línea de tiempo (caso Enzo Ayllón). Ahora el inicio, las pausas y el fin de turno solo los registra el propio supervisor directo desde su cuenta (403 en cualquier otro caso).
- En Vista provisional, *Mi turno* muestra el estado real del supervisor representado, con un aviso de "solo consulta" y los botones desactivados.
- En Vista provisional tampoco se pueden registrar supervisiones a nombre del supervisor representado (inflaban su productividad).
- Se eliminó el turno registrado por error a nombre del administrador (id 116).

### Pruebas
- `05_TEST/api/turno_control_test.php`: verifica quién puede registrar el turno, sin crear datos.

## [0.5.6] — 2026-10-04

### Añadido
- Ícono de WIN en la pestaña del navegador (favicon) en todas las pantallas y en el login: cuadrado naranja con "win" en blanco, en vector (`03_FRONTEND/src/assets/img/favicon.svg`). Se redibujó porque la "w" del vector del sistema de origen está deformada.
- `theme-color` naranja: en Android, la barra del navegador toma el color de WIN.

## [0.5.5] — 2026-10-04

### Corregido (celular)
- En ventanas de menos de 320 px la página no se encogía y se cortaba a la derecha (heredado: `html{min-width:320px}` en win-executive.css). Ahora todo el sistema se ve completo desde 240 px.
- El panel *Vista provisional* usaba en celular la posición guardada en escritorio y quedaba en medio de la pantalla; ahora en celular siempre va abajo.
- Observaciones: las pestañas (Todas, Checklist y En campo) iban apiladas; ahora son una fila deslizable. El botón del PDF parte su texto en vez de salirse.
- El título de la barra superior se recorta con "…" en vez de quedar debajo de la campana.
- Los indicadores pasan a una columna por debajo de 360 px (antes se cortaban las etiquetas).
- El espaciado de algunas filas desbordaba 2 px en pantallas pequeñas.

## [0.5.2] — 2026-10-04

### Corregido
- Al pasar a modo celular sin recargar, el panel *Vista provisional* del administrador seguía desplegado y tapaba el contenido. Antes solo se evaluaba al cargar la página; ahora se pliega y se reubica al cambiar el tamaño de pantalla.
- *Usuarios y roles* en celular: la tabla obligaba a deslizar de lado; ahora cada usuario se muestra como una tarjeta.

## [0.5.0] — 2026-10-04

### Añadido
- **Usuarios y roles** (solo administrador, menú *Administración*). Es el primer módulo construido con la estructura por capas de la guía: `routes/api/usuarios.php` → `UsuarioController` → `UsuarioService` → `UsuarioRepository` / `PerfilRepository`, con validación en `UsuarioValidator`.
  - Usuarios: buscar y filtrar; crear y editar (datos, acceso, rol, organización WIN o partner, sede, zonas adicionales); activar o desactivar; restablecer contraseña con una temporal que se muestra una sola vez.
  - Permisos: los del rol por defecto, o personalizados por usuario.
  - **Perfiles de acceso** (roles personalizados): nombre, rol base y permisos. Al asignarlo, el usuario recibe el rol base y los permisos; si se edita el perfil, sus usuarios se actualizan al instante.
  - Protecciones: el sistema nunca queda sin administrador activo, nadie puede desactivarse a sí mismo y el personal de partner no puede ser Administrador, Gerente ni Coordinador.
  - Auditoría en `08_LOGS` (`audit_*`) de cada alta, edición, cambio de estado y restablecimiento de contraseña.
- **Cambiar contraseña** en *Mi información*: pide la contraseña actual.
- Migraciones: `04_DATABASE/09_MIGRATIONS/001_SGS_PERFIL.sql` (tablas nuevas `SGS_PERFIL` y `SGS_USUARIO_PERFIL`, sin modificar las existentes) y el ejecutor `07_SCRIPTS/migrar.php`.
- `07_SCRIPTS/restablecer_contrasena.php`: recuperación de acceso desde el servidor.
- `05_TEST/integration/usuarios_service_test.php`: 19 pruebas del módulo, que limpian sus datos al terminar.

### Datos
- Se eliminaron todas las supervisiones (66) y sus notificaciones (66) a pedido del usuario. Hay respaldo completo en `04_DATABASE/10_BACKUPS/win_telecom_db_completo_*.sql`.

### Corregido
- Supervisiones: en pantallas medianas los filtros se montaban sobre las fechas.
- El panel de vista provisional tapaba los botones de los modales.

## [0.4.3] — 2026-10-04

### Cambiado
- **Observaciones para el partner**: ve las observaciones y checklists de **su partner y de su zona de trabajo** (departamento base según su sede más los departamentos con acceso adicional). Antes veía solo las de las órdenes asignadas a su propio usuario.
- Sus respuestas (descargos) se validan con la misma regla: si la observación no es de su partner o de su zona, se rechaza con 403.
- Los filtros de la pantalla (cuadrillas y departamentos) usan la misma regla.
- Nuevas funciones `sgsUserZones()`, `sgsZoneSql()` y `sgsUserInZone()` en `02_BACKEND/src/config/territorial.php`: una sola definición de “zona de trabajo”.

## [0.4.2] — 2026-10-04

Verificación integral del sistema.

### Seguridad
- La línea de tiempo y sus filtros (`asistencia?action=timeline_diaria|list_filters`) podían ser leídos por cualquier usuario con sesión, incluido un supervisor de partner. Ahora exigen permiso de línea de tiempo o dashboard (403).
- Una cuenta desactivada, eliminada o con rol cambiado conservaba el acceso mientras su sesión siguiera abierta. Ahora la sesión se revalida contra la BD cada minuto y se cierra (`session_revoked` en el log).
- El PDF de observaciones mostraba al usuario la salida técnica del script si fallaba. Ahora muestra solo el código de seguimiento y el detalle va a `08_LOGS`.
- `admin_summary` respondía 200 al rechazar el acceso; ahora responde 403.

### Cambiado
- Retención automática de `08_LOGS` (`SGS_LOG_RETENTION_DAYS`, 90 días por defecto).
- Límite de 30 reportes de error por minuto por sesión desde el navegador.
- Intérprete de Python del PDF configurable (`PYTHON_BIN`); antes estaba fijo en una carpeta de usuario.

### Pruebas
- `05_TEST/api/permission_matrix.php`: 19 acciones de la API × 3 roles, comparadas con las reglas del negocio.
- `05_TEST/frontend/check_inline_js.php`: valida con Node.js el JavaScript incrustado en cada pantalla.

## [0.4.1] — 2026-10-04

### Cambiado
- **Mi información** rediseñada: tarjeta de perfil con franja naranja WIN (rol, organización, estado, correo y teléfono como enlaces), datos agrupados en personales, contacto y laborales, y zonas de trabajo.
- Bloque de actividad según el rol. Supervisor directo: turno de hoy y supervisiones del día, del mes y finalizadas. Supervisor de partner: órdenes en plazo, atendidas a tiempo, bloqueadas y % de cumplimiento del mes.
- El texto ya no ofrece "actualizar tu foto" (esa función no está en SGS).

## [0.4.0] — 2026-10-04

Proceso y roles alineados al negocio, y revisión profesional del diseño.

### Añadido
- **Mi turno** (inicio, pausas y fin de jornada) para el supervisor directo WIN: es su pantalla de inicio. **Monitor de turnos** para Gerente y Coordinador.
- **Cumplimiento de partners** en el Dashboard: asignadas, atendidas a tiempo, bloqueadas (plazo de 24 h vencido), en plazo, respuesta promedio, % de cumplimiento por partner y lista de órdenes bloqueadas (`api/dashboard.php?action=cumplimiento_partners`).
- Menú y títulos por rol: el partner ve *Mi agenda*. El supervisor directo ve *Mi turno*.
- `sgsDirectStaffSql()`: una sola definición de “personal directo WIN”.
- `05_TEST/api/call_api.php` y roles `Supervisor:directo` / `Supervisor:partner` en `smoke_pages.php`.

### Corregido
- **El supervisor directo no podía registrar supervisiones en SGS**: la API exige turno iniciado y la pantalla de turno no existía.
- La Línea de tiempo, el monitor de turnos y la matriz mensual contaban a los supervisores de partner, que no deben tener seguimiento.
- Celular: franja vacía de 54px sobre la barra superior y botón de menú invisible.
- Variante de transición de página omitida (`InvalidStateError`) registrada como error.

### Cambiado (diseño)
- Menú lateral naranja WIN con el logo blanco. Logo del login sin el lema.
- Un solo título por pantalla, en la barra superior y con descripción. Se quitaron los títulos duplicados (hasta tres seguidos en el Dashboard).
- Panel de Supervisiones en cuadrícula ordenada: acciones, filtros, fechas y estados; controles a la misma altura y un solo color de íconos.
- Acciones por fila con botones de ícono (se quitó el emoji 🗑️); filas de 104 a 59 px.
- Escala de alturas: 36 px para controles pequeños y 40 px para normales, de modo que todas las filas de filtros quedan alineadas.
- Indicadores con la misma altura y escala de cifras. Pestañas de Observaciones del mismo ancho.

## [0.2.3] — 2026-10-04

Unificación de colores y formatos en todo el sistema, a partir del login.

### Cambiado
- `sgs-theme.css` es ahora la fuente única de colores: tokens `--sgs-*`, Bootstrap alineado a la paleta (text-*, bg-*, btn-*, alert-*, badge) y variables heredadas apuntando a los tokens.
- Fondo claro del login en todas las pantallas. Menú lateral claro con el logo oficial de WIN.
- Encabezados, indicadores (KPI), cabeceras de tabla, modales y cabeceras de checklist pasan de oscuro a claro con acentos naranja.
- Componente común `sgs-kpi` para los indicadores del Dashboard, la Línea de tiempo y Control de observaciones.
- Unos 200 colores fijos de las pantallas reemplazados por tokens.
- Botones "Filtrar" en naranja en todas las pantallas; pestañas de Observaciones con un solo estilo.
- Cabecera del perfil (Mi información) en naranja WIN.
- Documentación: `01_DOCUMENTACION/03_ARQUITECTURA/GUIA_VISUAL.md`.

### Corregido
- Observaciones no abría (`SGS-OBS`): la página buscaba `observation_records.php` en una ruta antigua.
- El aviso flotante vacío de Supervisiones se veía siempre como una pastilla negra (heredado: la animación de entrada forzaba opacidad 1).
- El panel "Vista provisional" guardaba una posición al redimensionar la ventana y quedaba encima de los filtros.
- El ícono de la campana de notificaciones era invisible.
- Las columnas de la tabla de Observaciones no ocupaban todo el ancho.
- Las transiciones de página omitidas se registraban como errores en `08_LOGS/frontend`.

### Pruebas
- `smoke_pages.php` ahora incluye Observaciones.

## [0.1.2] — 2026-10-04

### Cambiado
- Login más compacto y ajustable (tamaños fluidos según el ancho y alto de la pantalla) con sombras en la tarjeta, los campos y el botón.

## [0.1.1] — 2026-10-04

### Cambiado
- Login con fondo claro, Bootstrap 5 y usuario, contraseña y captcha en una sola pantalla.
- Logo original de WIN (copiado del sistema de origen) en el login y en el menú lateral.
- Sin sesión, la API responde 401 en JSON en lugar de redirigir a una ruta inexistente.

### Añadido
- `07_SCRIPTS/crear_usuario_prueba.php`: crea, renueva o elimina un usuario de prueba. Las credenciales quedan en `09_CONFIG/usuario_prueba.local.txt`.

## [0.1.0] — 2026-10-04

Primera versión. Fase 1: el módulo de supervisión se trae del sistema de origen sin cambiar sus reglas de negocio.

### Añadido
- Estructura de carpetas según la *Guía de estructura y nomenclatura* (01_DOCUMENTACION … 09_CONFIG).
- Login en dos pasos (usuario → contraseña + captcha) con el diseño WIN.
- Captcha generado en el servidor (SVG dibujado con trazos, un solo uso, caduca a los 5 minutos).
- Registro de errores por canal en `08_LOGS/` (backend, frontend, database, errores), con Request ID, usuario, módulo y endpoint.
- Código de seguimiento visible para el usuario: `SGS-<MÓDULO>-<ID>`.
- Errores del navegador guardados en `08_LOGS/frontend` (`api/error_tickets.php`).
- Capa visual `sgs-theme.css`: tipografías Nunito/Montserrat (equivalentes libres de MADE Tommy Soft y Panton), naranja #FF5A0A y radios de 10, 20 y 30 px.
- Menú lateral y barra superior nuevos, solo con las secciones de supervisión.
- Carga de variables desde `.env`.
- Documentación: mapa del sistema, trazabilidad, diccionario de datos, endpoints e instalación.
- Prueba de humo `05_TEST/frontend/smoke_pages.php`.

### Cambiado
- Sesión propia `SGS_SESSID`, separada de la del sistema de origen.
- Archivos compartidos (`uploads/`) leídos desde el sistema de origen.
- `api/asistencia.php` solo responde `timeline_diaria` y `list_filters`.

### Seguridad
- Se eliminó la creación automática de la BD (`setup.sql`) y las migraciones por petición.
- El login ya no indica si falló el usuario o la contraseña.
- `.htaccess` bloquea todas las carpetas internas, los archivos ocultos y la documentación.
