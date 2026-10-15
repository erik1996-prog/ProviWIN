# CHANGELOG — SGS

Formato: [versión] — fecha. Tipos: Añadido, Cambiado, Seguridad, Eliminado.

## [0.10.1] — 2026-10-08 — Preparación para hosting (Linux)

### Zona de los técnicos líderes (2026-10-15)
- **Cada técnico líder tiene su zona** (provincia y departamento). Cambio de base `2026-10-15_04_zona_del_tecnico_provincia_y_departamento`: columnas nuevas `tecnicos.provincia` y `tecnicos.departamento`.
- **Carga por Excel con PROVINCIA**: el Excel de técnicos líderes acepta una tercera columna **PROVINCIA**, en cualquier orden si trae encabezados.
  - El sistema busca a qué departamento pertenece con la lista oficial de las 196 provincias del Perú (`config/zonas_peru.php`). También reconoce ciudades como CHIMBOTE, letras sin tildes y "Provincia de …".
  - Si el técnico se movió, la nueva carga cambia su zona. Sin provincia, la zona no se toca.
  - La vista previa muestra las zonas del archivo, quién **cambia de zona** y las provincias que no se reconocen (esas filas no cambian de zona).
  - "Descargar lista (Excel)" ya trae la columna PROVINCIA.
- **Formulario de supervisión**: en el técnico líder (T1) solo aparecen los del **departamento elegido**. Los que aún no tienen zona salen al final, marcados "· sin zona", hasta que se les asigne. En una corrección, el T1 ya elegido se conserva aunque sea de otra zona.
- **Cuadrillas**:
  - filtro **Zona** (incluye "Sin zona"); la zona se muestra bajo el partner;
  - aviso con cuántos técnicos líderes no tienen zona;
  - en el lápiz se escribe la **provincia** (con sugerencias) y el departamento se pone solo;
  - barra de filtros pasada al patrón de la sección 38 (una sola fila).
- Prueba `cuadrillas_carga_test.php` ampliada (34/34).
- **Cuadro de Cuadrillas ordenado**: pasa de 8 a 6 columnas.
  - El vehículo muestra placa, tipo y estado en una sola celda.
  - "Supervisiones" lleva la fecha de la última debajo.
  - El técnico líder tiene más espacio y su nombre ya no se parte en 3 líneas.
- **Tablas anchas que se arrastran (todo el sistema)**: si una tabla no entra a lo ancho, en la PC aparece la **mano** y se mueve hacia los lados arrastrando con el mouse, sin bajar a buscar la barra.
  - En celular se desliza con el dedo.
  - El borde se desvanece del lado donde hay más columnas.
  - Los clics en botones, enlaces y filas siguen funcionando igual (un arrastre no abre nada).
  - `main.js` y sección 48 de `sgs-theme.css`.

### Pantalla de partners, manual del supervisor directo y ajuste en celular (2026-10-15)
- **Nueva pantalla Partners** (Administración → Partners, solo administrador): registrar contratas con nombre (se guarda en mayúsculas) y RUC opcional (11 dígitos, empieza en 10, 15, 17 o 20). El nombre y el RUC no se pueden repetir.
  - Muestra por partner cuántos técnicos líderes, técnicos de apoyo, supervisores y supervisiones tiene.
  - Se puede **editar**, **desactivar** (deja de aparecer para elegir, pero conserva su historial) y **eliminar** (solo si no tiene nada registrado).
  - WIN DIRECTO (personal propio) no se puede renombrar ni desactivar.
  - API `api/partners.php` (auditoría `audit_partner_*`). Prueba nueva `partners_test.php` (13/13). Revisada a 1366 px, 1100 px y 375 px.
- **Manual del supervisor directo** en PDF (`01_DOCUMENTACION/09_MANUALES/Manual_Supervisor_Directo_SGS.pdf`), con capturas en modo celular.
- **Corregido en celular**: el cuadro de validación de cancelaciones se salía de la pantalla. Estilos: sección 46 de `sgs-theme.css`.
- **Dashboard: indicadores destacados más livianos**: partner, distrito y cuadrilla destacados salían a 24 px y cortados con "…" (una regla de la tipografía v2 pisaba el tamaño de los textos). Ahora el nombre va a 15.5 px en hasta 2 líneas y debajo, en gris, el detalle: cantidad de supervisiones, departamento o partner. "Departamento / distrito principal" pasa a "Distrito principal". Estilos: sección 47 de `sgs-theme.css`.

### Cuadrillas editables, carga de técnicos líderes y dirección en cancelaciones (2026-10-15)
- **La cuadrilla se actualiza al cerrar la supervisión**: el T2, el vehículo y su tipo y estado pasan a la ficha del técnico líder solo cuando la supervisión se **finaliza** (con "Guardar el T2 y el vehículo" marcada). Así, un vehículo reparado queda en "Buen estado" y un cambio de vehículo se registra. Mientras está en proceso, la ficha no cambia.
- **Los T2 rotan**: en el formulario se puede buscar y elegir a cualquier técnico de apoyo, también de otra cuadrilla o partner (se indica de dónde viene). Si no existe, se registra ahí mismo como antes. Al cerrar, el T2 sale de su cuadrilla anterior y pasa a la del líder y a su partner. Lo mismo con el vehículo: es de una sola cuadrilla.
- **Pantalla Cuadrillas**: el personal administrativo puede **editar** cada cuadrilla (T2, placa, tipo y estado del vehículo, y estado del técnico líder) para emergencias. Nuevo filtro por estado del técnico líder (habilitados por defecto).
- **Carga de técnicos líderes por Excel** (Cuadrillas → "Cargar técnicos líderes"): dos columnas, técnico líder y partner. Primero muestra una **vista previa** y solo cambia algo al pulsar "Aplicar cambios":
  - el técnico que ya está queda igual;
  - si es la misma persona con **otra nomenclatura** (cambia el inicio, p. ej. "P 4 DOMINION SGI …" → "P 9 OLMA SGA …"), se actualiza su nombre y partner y conserva su historial y cuadrilla;
  - los que no están se agregan;
  - los habilitados que no vienen en el archivo se **inhabilitan** (no se borran) y se reactivan si vuelven en otra carga;
  - la lista negra no se toca;
  - las filas con partner desconocido o repetidas se informan y no se aplican.
  - "Descargar lista (Excel)" baja la lista actual en el mismo formato.
- **Cancelaciones con dirección**: la carga por Excel lee la columna **DIRECCIÓN** (opcional). Se ve en el detalle, en los formularios y en el mapa de pendientes. Plantilla nueva `plantilla_cancelaciones_v5.xlsx` (se llamó v4 unas horas; se renombró porque Cloudflare guardaba en caché la v4 dañada) (DIRECCIÓN después de DISTRITO); se retiró la v3.
- **Corregido el mismo día**: la plantilla v4 y "Descargar lista (Excel)" no abrían en Excel (se armaban con PharData porque el PHP local no trae ZipArchive). Ahora se empaquetan con un ZIP estándar propio (`sgsEmpaquetarZip`); verificado abriéndolos con Excel.
- El enlace "Descargar modelo (Excel)" lleva la fecha del archivo (`?v=`): Cloudflare seguía entregando la plantilla dañada que tenía guardada en caché.
- **Corregido**: "Resultado de la validación" salía bloqueado para el supervisor mientras la cancelación seguía pendiente (faltaba en la lista de campos editables); el administrador no lo notaba porque a él no se le bloquea.
- **Barra de Supervisiones ordenada**: fila 1 = estados + acciones (Ver rutas, Columnas como ícono, Cancelaciones, Nueva supervisión); fila 2 = solo filtros, a todo el ancho, con buscador con lupa; todo de 40 px y alineado. Revisada a 1366 px, 1100 px y en celular. Estilos: sección 38 de `sgs-theme.css`; patrón actualizado en la skill sgs-diseno.
- **Corregido**: una cancelación cargada que ya estaba "En proceso" se abría en el formulario de registro nuevo: no mostraba la validación (tipificación y resultado), el distrito del Excel quedaba vacío si no coincidía letra por letra con el catálogo y la sección 1 no dejaba continuar. Ahora las cancelaciones cargadas se abren siempre en su formulario de validación. Además, la ubicación se compara sin tildes y, si no está en el catálogo, se conserva tal cual.
- **"Resultado de la validación" obligatorio y resaltado**: empieza en "Seleccione el resultado de la validación" (ya no viene "Cancelación correcta" por defecto). Mientras está vacío se ve resaltado con la marca "Pendiente". Se puede iniciar y guardar avance, pero no finalizar: con lo demás completo, el botón dice "Seleccione el resultado de la validación" y lleva al campo, que pasa a rojo. El servidor también lo exige. Estilos: sección 39 de `sgs-theme.css`.
- **Vista de una supervisión finalizada ordenada**: ficha de datos en 4 columnas sin cajas; los textos largos (dirección, resumen, observación previa) van a lo ancho al final de su sección, sin huecos en blanco. Muestra también el resultado de la validación, la fecha en dd/mm/aaaa, la ubicación GPS con enlace al mapa y las evidencias en rejilla pareja. Estilos: sección 40 de `sgs-theme.css`.
- **Corregir supervisiones (administrador)**: botón "Corregir supervisión" en la vista de una finalizada. Abre el formulario completo y guarda con "Guardar corrección" sin reabrirla. La opción existía, pero el botón nunca aparecía.
- **Corrección por el administrador en el servidor**: el guardado rechazaba al administrador ("solo un supervisor directo…"). Ahora el rol Administrador puede guardar sobre una supervisión FINALIZADA: sigue finalizada, a nombre del supervisor y con su fecha, y queda en la auditoría (`audit_supervision_corrected`). Registrar supervisiones nuevas sigue siendo solo del supervisor directo. Prueba nueva `correccion_admin_test.php` (7/7).
- **GPS más preciso**: antes se guardaba la primera lectura del celular (suele venir de la red o el wifi, con errores de cientos de metros). Ahora se escucha el GPS hasta 25 s y se queda la lectura más precisa (termina antes si llega a ±15 m), mostrando "Afinando ubicación… ±X m". Si la precisión final pasa de ±50 m, avisa en amarillo que conviene salir a un lugar abierto. Se guardan 7 decimales. Estilo: sección 41 de `sgs-theme.css`.
- **Coordenadas: remota editable**: la remota también toma el GPS al iniciar y tiene "Actualizar ubicación", igual que la presencial; la única diferencia es que en la remota las coordenadas se pueden editar a mano (o pegar las del cliente). En las presenciales quedan solo por GPS (solo lectura); el administrador siempre puede corregirlas. En cualquier modalidad se pueden pegar juntas ("-8.139075, -79.055686") y se separan solas. El servidor valida: números, rangos y las dos juntas; acepta la coma decimal.
- **Fotos comprimidas antes de subir**: en el propio celular se reducen a 2048 px en el lado mayor y JPEG al 85 % (de ~3–6 MB a ~0,3–0,9 MB), sin pérdida visible; se leen las marcas de agua. Aplica a todos los campos de fotos (`main.js`); no toca archivos de menos de 400 KB, formatos que el navegador no lee ni la foto de perfil (tiene su propio recorte; `data-sin-comprimir`). Las fotos no van dentro de la base: se guardan como archivos en `uploads` y la base solo guarda la ruta.
- **Corregido "Data truncated for column unidad_soat"**: al guardar una supervisión presencial sin SOAT (o revisión técnica) registrado, el formulario lo mandaba vacío y el MySQL estricto del hosting lo rechazaba. Ahora los campos de lista fija (SOAT, revisión técnica, tipo y estado del vehículo, brevete) guardan "sin dato" si llegan vacíos o fuera de la lista, o conservan el valor anterior. Caso agregado a `correccion_admin_test.php` (8/8).
- **Menú lateral más limpio, por secciones**: letra más liviana y más aire, mismos colores. Secciones según la tarea: Inicio (Dashboard / Mi turno), Supervisiones (Supervisiones, Cuadrillas), Seguimiento (Línea de tiempo, Monitor de turnos), Observaciones (Registro, Control y recurrencia) y Administración (Usuarios y roles). Se despliegan en acordeón (al abrir una se cierra la otra; la de la pantalla actual empieza abierta) y una sección con una sola opción para ese usuario sale como enlace directo. El menú pasa de 248 a 228 px de ancho (como el ejemplo del usuario); las pantallas dentro de una sección van sin ícono (vuelve en el menú contraído) para que los nombres no se corten. Logo WIN y subtítulo centrados en el menú. Estilos: secciones 42, 42b y 42c de `sgs-theme.css`.
- **Mapa del Dashboard → supervisión**: la ventana de cada punto muestra el ticket, lugar, partner, técnico, cliente y fecha, con el botón **"Ver supervisión"**, que abre Supervisiones y despliega esa supervisión (`?highlight=ID&abrir=1`). El punto trae ahora también el ticket. Estilos: sección 44 de `sgs-theme.css`. Probado: el enlace abre la supervisión. Sin técnico registrado, esa fila no se muestra (antes decía "Técnico: Técnico").
- **Usuarios y roles sin desplazamiento lateral**: la tabla pasa de 7 a 5 columnas ("Rol y organización"; "Estado" con el último ingreso debajo). Entra completa a 1100 y 1366 px; en celular se ve como tarjetas. Sección 45 de `sgs-theme.css`.
- **Tipografía v2 en todo el sistema**: una sola letra (Nunito) en todas las pantallas, el ingreso y el cambio de contraseña; se eliminó Montserrat (títulos en peso 800, se veían toscos) y los nombres de fuentes que nunca existieron. Ningún texto pasa de peso 700: título de pantalla 20/700, títulos de tarjeta 15.5/700, etiquetas y botones 600, cifras de indicadores 24/700. Medido en las 9 pantallas. Sección 43 de `sgs-theme.css`.
- **Rediseños**: Monitor de turnos (encabezado de tabla claro en vez de oscuro, iniciales pequeñas sin recortarse, columna de supervisor más ancha); Mi información (sin la franja con degradado ni el círculo gigante); pestañas de Observaciones como control compacto (antes, barra naranja a todo el ancho). Sección 43b.
- **Guía de diseño (skill sgs-diseno) rehecha desde cero, versión 2.0**: tipografía única, menú por secciones, barra de listados, ficha de consulta, campo pendiente, tablas, prohibiciones y verificación. Página `guia_estilo` y `GUIA_VISUAL.md` actualizadas.
- **Pendientes de la bolsa común**: en "Responsable" aparecen todos los supervisores directos de la zona ("Roger Vasquez / Yelsin Ruiz"); al tomarla, solo quien la inició.
- Código: lector de Excel compartido en `config/excel_lector.php`; carga de líderes en `config/tecnicos_lideres.php`.
- Base: cambio `2026-10-15_03_direccion_del_cliente_en_las_cancelaciones` (`supervisiones.direccion`). Probado aplicar, revertir y volver a aplicar; instalación, diccionario y diagrama regenerados.
- Pruebas: nueva `cuadrillas_carga_test.php` (23/23); `importar_cancelaciones_test.php` cubre la dirección (16/16). Todas las pruebas de integración y de pantallas pasan.

### Cuadrillas, acceso de usuarios y contraseñas (2026-10-15)
- **Cuadrillas que se llenan solas**: en el formulario de supervisión, la casilla **"Guardar el T2 y el vehículo"** (marcada por defecto) deja al técnico de apoyo y al vehículo como cuadrilla del técnico líder. La próxima vez que se elige a ese líder salen su T2, placa, tipo y estado del vehículo; se pueden cambiar. Si se desmarca, la supervisión los registra igual pero la ficha del líder no cambia.
- **Pantalla Cuadrillas** (menú Supervisión; administración y supervisores directos): cada técnico líder con su T2, vehículo, estado del vehículo, supervisiones y última supervisión. Indicadores de completas / por completar y filtros por partner, búsqueda y estado. Solo consulta.
- **Cambio de contraseña obligatorio**: al crear un usuario o ponerle contraseña, la opción **"Pedir que la cambie al ingresar"** (marcada por defecto). La contraseña temporal de "Restablecer contraseña" siempre lo pide. Al ingresar, la persona solo ve la pantalla **"Crea tu contraseña"** hasta cambiarla; las demás APIs responden 403 mientras tanto.
- **Usuarios y roles**: contadores que filtran al hacer clic: **Todos, Con acceso, Por ingresar, Sin acceso, Desactivados, Bloqueados**; columna **Último ingreso** e insignia "Cambiará su contraseña". "Por ingresar" = activo que aún no entra desde que se registra el último ingreso (al inicio estarán todos ahí y se irán moviendo a "Con acceso").
- **Indicador de seguridad de contraseña** (Débil / Aceptable / Buena / Fuerte, con la lista de requisitos) en Usuarios, Mi información y la pantalla de cambio obligatorio (`assets/js/password-meter.js`).
- **Botones del formulario**: los botones de guardar quedaban abajo porque la animación de entrada de la página impedía que quedaran fijos; corregido, ahora flotan siempre a la vista (también en celular).
- Estilos: sección 37 de `sgs-theme.css` (tabla que en celular se vuelve tarjetas).
- Base: cambios `2026-10-15_01_cuadrilla_del_tecnico_lider` (`tecnicos.apoyo_id`, llenado con el último T2 de cada líder) y `2026-10-15_02_ultimo_ingreso_y_cambio_de_contrasena_obligatorio` (`usuarios.ultimo_acceso`, `usuarios.debe_cambiar_password`). Instalación y diagrama regenerados.
- Prueba nueva `acceso_y_cuadrillas_test.php` (23/23). Todas las pruebas de integración, `design_lint`, `smoke_pages` y `check_inline_js` pasan.

### Ajustes a pedido: remota, vehículo, rescate y botones flotantes (2026-10-13)
- **Supervisión remota simplificada**: basta elegir la modalidad "Remota". Se quitaron el medio y las horas de la videollamada. En la sección 3 solo se pide el **partner** (sin técnicos ni vehículo); tampoco GPS. Para finalizar sigue exigiendo la **captura** como foto de evidencia. (El cambio de base `2026-10-13_02` se simplificó antes de subirlo al hosting: solo agrega la opción "Remota".)
- **Vehículo por defecto**: al registrar una supervisión, el tipo, la placa y ahora también el **estado del vehículo** quedan en la ficha del técnico líder y salen solos la próxima vez que se le elige. Si se cambia de vehículo, tipo o estado, se edita y queda guardado lo último. Base: cambio `2026-10-13_05_estado_del_vehiculo_por_defecto` (columna `vehiculos.estado_conservacion`).
- **Rescate en el formulario**: en una cancelación cargada, el campo **"Resultado de la validación"** con las opciones **Cancelación correcta** (por defecto), **En proceso de rescate** y **Rescate**:
  - Cancelación correcta: se valida y se finaliza como hasta ahora.
  - En proceso de rescate: el botón pasa a **"Pasar a rescate"**; la orden queda a cargo del supervisor, en la pestaña "En rescate", con seguimiento hasta cerrarla (no ocupa su supervisión en proceso).
  - Rescate: al finalizar queda como rescatada.
  - Se quitó la tarjeta aparte "¿La va a rescatar?". El cierre de un rescate usa los mismos nombres: **Rescate** o **Cancelación correcta**, y se anota en el estado de la cancelación.
- **Botones siempre a la vista**: "Iniciar supervisión", "Guardar avance" / finalizar (y "Registrar atención" del partner) quedan fijos abajo mientras se baja por el formulario.
- **Monitor de turnos**: se mantiene en el menú.
- Pruebas: `supervision_remota_test.php` (10/10, incluye el vehículo por defecto) y `rescate_pendientes_test.php` (40/40, incluye el resultado de la validación).

### Rescate propuesto por el partner (2026-10-13)
- Al atender una orden asignada, el supervisor del partner responde **"¿Se puede rescatar el servicio?"**. Si marca **Sí**, debe subir al menos una foto de evidencia (hasta 3). El partner **solo propone**: no puede pasarla a rescate ni registrar avances.
- Se avisa al **supervisor directo que la asignó**. En su lista la orden muestra **"Se puede rescatar"** y en el detalle ve la observación y las evidencias del partner con el botón **Pasar a rescate** (solo él; los demás ven quién decide). Desde ahí sigue el mismo rescate: avances, reasignación por el administrador y cierre.
- El rescate tiene su **propio responsable** (`rescate_responsable_id`): en una orden de partner el supervisor asignado no cambia, así **el cumplimiento de 24 horas del partner se mantiene** (sigue contando como atendida a tiempo). En el Dashboard el responsable de un rescate es su supervisor directo.
- **Corregido** (venía de antes): **asignar una pendiente a un partner fallaba con un error de SQL** (faltaba un paréntesis en la consulta que valida al supervisor del partner). La prueba de rescate ahora lo cubre.
- Base: cambio `02_CAMBIOS/2026-10-13_04_rescate_propuesto_por_el_partner` (columnas `partner_rescatable` y `rescate_responsable_id`; tipo "Propuesta del partner" en el historial). Probado aplicar, revertir y volver a aplicar; instalación y diagrama regenerados.
- `rescate_pendientes_test.php` amplía con el flujo del partner (35/35).

### Rescate de pendientes (2026-10-13)
- En el detalle de una pendiente libre de su zona, el supervisor directo puede **Tomar como rescate** (con comentario opcional). La pendiente queda a su nombre en estado **"En rescate"**, sale de la bolsa común (los demás ya no la ven ni la pueden tomar) y **no ocupa su "supervisión en proceso"**: puede seguir trabajando en paralelo.
- **Seguimiento sin plazo**: registra avances (comentario y foto opcional) las veces que necesite. **Cierre**: "Se rescató" o "No se rescató", con comentario obligatorio; la pendiente queda **Finalizada** con su resultado.
- **Reasignación** solo por el administrador (casos puntuales), a otro supervisor directo activo que trabaje esa zona; se le avisa por notificación.
- Todo queda en un **historial** (inicio, avances, reasignación, cierre) que se ve en el detalle. Mientras dura el rescate no se puede iniciar como supervisión normal ni asignar a un partner (también en la API).
- Supervisiones: pestaña **"En rescate"** con su contador; en la lista se ve "En rescate" o "Finalizada · Rescatado / No rescatado".
- Dashboard → Pendientes cargadas: indicadores **En rescate** y **Rescatadas** (con las no rescatadas); en el detalle, situación "En rescate", "Rescatada" o "No rescatada".
- Reemplaza el rescate que había quedado a medias (mensajes en el servidor sin botón en la pantalla).
- Base: cambio `02_CAMBIOS/2026-10-13_03_rescate_de_pendientes_con_seguimiento` (columnas `rescate_estado`, `rescate_inicio`, `rescate_fin` y tabla nueva `rescate_seguimientos`; 22 tablas). Probado aplicar, revertir y volver a aplicar; instalación (se agregó la tabla a `generar_instalacion.php`), diccionario y diagrama regenerados. Estilos: sección 35 de `sgs-theme.css`.
- Prueba nueva `rescate_pendientes_test.php` (21/21).

### Supervisión remota por videollamada (2026-10-13)
- Nueva modalidad **"Remota (videollamada)"** para todos los tipos de supervisión que piden modalidad. Funciona como "En caliente" (técnico líder y resultado), pero **no exige GPS ni datos del vehículo**.
- Para finalizarla exige la **captura de la videollamada** (foto de evidencia), el **medio** (WhatsApp, Google Meet, Microsoft Teams, Zoom u otro) y la **hora de inicio y fin** de la llamada (el fin no puede ser antes que el inicio). Se piden al finalizar, no al iniciar. *(Simplificado el mismo día: ver "Ajustes a pedido" arriba; ya no pide medio ni horas.)*
- **Cuenta para la meta de productividad** igual que una presencial. El gráfico de **modalidad** del Dashboard pasa a tres partes (caliente, frío, remota; el tercer color se validó por contraste y daltonismo). En la **línea de tiempo** la supervisión remota se ve con borde punteado y su ventana indica la modalidad. El detalle muestra el medio y la hora de la llamada.
- Base: cambio `02_CAMBIOS/2026-10-13_02_supervision_remota_por_videollamada` (opción "Remota" en `tipo_supervision_inicial`; columnas `remota_medio`, `remota_hora_inicio`, `remota_hora_fin`). Probado aplicar, revertir y volver a aplicar. Estilos: sección 34 de `sgs-theme.css`.
- Prueba nueva `supervision_remota_test.php` (9/9).

### Línea de tiempo por horas con ventana flotante (2026-10-13)
- La **Línea de tiempo** pasa a ser un diagrama por horas: regla de horas arriba (se ajusta sola a la jornada del día, mínimo 8 horas) y una fila por supervisor. Cada actividad se dibuja de la hora en que empezó a la hora en que terminó: franja del turno, supervisiones (finalizadas / en curso), traslados, almuerzo, reunión o capacitación y trabajo remoto. **Flecha verde de inicio de turno** y **flecha oscura de fin de turno**, cada una con su hora (sin flecha de fin mientras el turno sigue abierto). Línea roja de **Ahora** en el día actual. Antes los bloques iban uno tras otro, sin escala de tiempo.
- **Ventana flotante** al tocar: un bloque de supervisión muestra código, pedido/ticket, estado, inicio, fin, duración, técnico y partner, con el botón **Abrir supervisión**; una pausa o traslado, desde/hasta y duración; el nombre o la franja del turno, el resumen de la jornada (inicio y fin de turno, duración, tiempo en supervisiones, traslados, pausas y remoto, 1.ª acción) y, para el administrador, **Reabrir turno** (hoy). Se cierra con la X, Esc o clic afuera. En celular sale como hoja inferior y la columna de nombres se angosta.
- **Menos amontonado**: escala de horas **Día / ×2 / ×4** (se recuerda en el navegador) para ver supervisiones de pocos minutos; el traslado es una línea punteada fina en vez de una franja; las supervisiones tienen un ancho mínimo visible.
- **Lo que fue atendiendo, debajo**: el botón de flecha junto al nombre despliega la lista de la jornada en orden de hora (inicio de turno, supervisiones con código, técnico y partner, traslados, pausas, remoto, fin de turno), con su duración y un botón para abrir cada supervisión.
- Filtros: fecha, búsqueda por nombre y "Solo con actividad". Se actualiza cada minuto (no mientras hay una ventana abierta).
- API `asistencia.php?action=timeline_diaria`: cada bloque envía además `ts_inicio`/`ts_fin` (y el ticket en supervisiones); cada supervisor, `turno_ts`; la respuesta, `ahora`, `es_hoy` y `puede_reabrir`. No se quitó nada de lo anterior (el Dashboard sigue usando los mismos campos).
- Estilos: sección 33 de `sgs-theme.css` (`sgs-gantt-*`, `sgs-tl-pop`). Prueba nueva `timeline_horas_test.php`.

### Dashboard: pendientes cargadas y su atención por supervisor directo (2026-10-13)
- Nueva tarjeta **Pendientes cargadas** en la vista Supervisión (API `dashboard.php?action=pendientes_directos`): cancelaciones subidas por Excel en el período; sin tomar, en proceso, atendidas por directos, transferidas a partner, atendidas por partner, en el partner en plazo y vencidas, % atendidas del total y tiempo promedio desde la carga hasta la atención.
- **Por supervisor directo**: tomadas, las que atendió él, en proceso, transferidas, las que atendió el partner, vencidas en el partner, total atendidas y % de atención. Una pendiente transferida a un partner **sigue siendo del supervisor directo que la asignó**.
- **Detalle de las pendientes** (últimas 200): código, departamento, fecha de carga, situación, supervisor responsable, **atendida por** (si se transfirió: el supervisor del partner y su partner) y fecha de atención.
- Responde a todos los filtros generales (período por fecha de carga, partner, departamento y supervisor).
- Base: cambio `02_CAMBIOS/2026-10-13_01_fecha_de_carga_de_las_pendientes` (columna `supervisiones.fecha_carga`; antes no quedaba la fecha de carga porque `fecha_registro` cambia al tomar la pendiente). Las pendientes ya cargadas reciben la fecha más temprana conocida. La carga por Excel la guarda desde ahora. Probado aplicar, revertir y volver a aplicar; instalación, diccionario y diagrama regenerados.
- Estilo: nueva clase `sgs-tabla-alta` (tabla con alto máximo y encabezado fijo), sección 32 de `sgs-theme.css`.
- Prueba nueva `pendientes_directos_test.php` (12/12); `importar_cancelaciones_test.php` comprueba la fecha de carga (14/14).

### Dashboard, vista Partners: indicadores propios del partner (2026-10-13)
- "Partners y cuadrillas más supervisados" pasa a la vista **Supervisión**: sale de las supervisiones que hacen los supervisores directos, no de la gestión del partner.
- **Cumplimiento de partners**: nueva tabla **Por supervisor del partner** (asignadas, a tiempo, en plazo, bloqueadas, respuesta promedio y cumplimiento de cada uno).
- Nueva tarjeta **Observaciones y descargos** (API `dashboard.php?action=observaciones_partners`): observaciones de campo y de checklist hechas a los partners en el período, respondidas a tiempo, sin respuesta vencidas, pendientes en plazo, descargos por revisar, respuesta promedio, validadas, penalizadas, % de respuesta a tiempo y % de penalidad sobre lo evaluado; tabla por partner, motivos más observados y reparto por gravedad. Cuenta igual que la pantalla Observaciones (cada hallazgo de campo y cada ítem de checklist con comentario o foto). Usa los filtros generales (período, partner, departamento).
- Prueba nueva `dashboard_partners_test.php` (12/12).

### Revisión de seguridad (2026-10-13)
- **Seguridad — eliminar supervisiones solo por POST con token.** La acción `delete` aceptaba GET, donde no se revisa el token CSRF: si el administrador abría un enlace preparado (`api/supervisiones.php?action=delete&id=…`) con su sesión iniciada, se borraba la supervisión. Ahora responde 405 a GET y el id solo se lee del cuerpo POST.
- **Seguridad — "personal directo WIN" por nombre exacto.** Se consideraba directo a cualquier usuario cuyo partner *contuviera* "WIN" en el nombre: un partner futuro como "NETWIN" habría visto todo. Ahora solo "WIN" o "WIN DIRECTO", igual que en las consultas a la base.
- **Seguridad — HSTS**: con HTTPS, el navegador recuerda por 180 días que debe entrar siempre por https://.
- Revisado sin cambios: login (contraseñas cifradas, bloqueo por intentos, sesión nueva al entrar, se cierra si se desactiva la cuenta o cambia el rol), CSRF en todas las acciones, consultas preparadas (sin inyección SQL), escape de textos en pantalla (XSS), subida de archivos (contenido real, solo JPG/PNG/WEBP, nombres aleatorios, sin ejecución en uploads), permisos por registro (orden, partner y zona), exportaciones, enrutado por lista cerrada, sin CORS, sin claves en git; en el hosting, carpetas internas 403/404 y http → https.
- `importar_cancelaciones_test.php`: 3 casos nuevos de eliminación (13/13).

### No duplicar una orden que está en Pendientes (2026-10-13)
- Si un supervisor registra una supervisión nueva con un código de pedido / ticket que ya fue cargado como **pendiente**, no se guarda y se le avisa: "El código … se encuentra en Pendientes (departamento). No se guardó para evitar duplicarla: búsquela en Pendientes y atiéndala desde ahí." El código se compara sin espacios y sin importar mayúsculas.
- Atender la propia pendiente (iniciarla desde Pendientes) funciona igual que antes.
- `pendientes_bolsa_zona_test.php`: caso nuevo (10/10).

### Mapa de pendientes: órdenes en la misma ubicación (2026-10-13)
- **Corregido**: si dos pendientes tenían la misma ubicación GPS (o a pocos metros), sus marcadores quedaban uno encima del otro y parecía que faltaba una (3 pendientes, 2 puntos). Ahora se agrupan en un solo marcador con sus números ("1 · 2"); al tocarlo se abre una lista para elegir la orden.
- **Cambiado (mismo día)**: en vez de juntarlas en un marcador, cada pendiente tiene **su propio marcador**, abiertos en abanico alrededor del punto real (un punto gris y una línea punteada fina los unen a él). La separación es en pantalla, así que se mantiene al acercar o alejar el mapa; cada marcador abre su orden.

### Área de trabajo, cargo y procesos en correos (2026-10-12)
- Se separan tres datos que antes se confundían: **rol del sistema** (lo que puede hacer en SGS; da permisos), **área de trabajo** (su equipo) y **cargo** (su puesto). Área y cargo no dan permisos.
- **Área de trabajo** pasa a lista fija: Instalaciones, Averías, Instalaciones y averías, Almacén.
- **Cargo** (nuevo): Supervisor de campo, Supervisor de calidad, Coordinador, Jefe de operaciones, Jefe, Gerente, Subgerente, Asistente, Asesor, Almacenero, Analista.
- Un valor guardado antes que no está en la lista (por ejemplo "SUPERVISOR UM") se conserva y se muestra como "(anterior)" hasta que se cambie.
- Nueva sección **"Procesos en los que se le copia en los correos"**: Liquidaciones, Penalidades, Gestión Phoenix, con la casilla "Todos". Se guarda en la columna existente `procesos_copiados`.
- El formulario "Personal" (partners) también usa las listas de área y cargo. La lista de usuarios muestra cargo y área debajo del nombre. En "Roles y permisos" cada rol muestra para qué sirve.
- Base: cambio `02_CAMBIOS/2026-10-12_03_cargo_de_los_usuarios` (columna `cargo`). Probado aplicar, revertir y volver a aplicar. Instalación y diagrama regenerados.
- `usuarios_service_test.php`: 5 casos nuevos (32/32).

### Formulario de usuario en otro orden (2026-10-12)
- Nuevo orden: Datos personales → **Organización y rol** (organización y partner, área de trabajo, cargo, rol del sistema, modalidad, turno) → Procesos en correos → Zonas de trabajo → **Acceso** (usuario y contraseña) → Permisos.

### Rol "Jefe de operaciones" en lugar de "Partner" (2026-10-12)
- El rol **"Partner"** (heredado del sistema anterior) se elimina. El personal de un partner que atiende órdenes es **Supervisor** con organización Partner; por eso los usuarios que tenían rol Partner pasan a Supervisor (así aparecen al asignar órdenes).
- Nuevo rol **"Jefe de operaciones"**, solo para personal de un partner y **solo de consulta**: ve todas las órdenes asignadas a su partner (cualquier zona y estado, con el supervisor asignado), las observaciones de su partner y su personal ("Personal", como directorio). No atiende órdenes ni responde observaciones (tampoco por la API). Su menú dice "Órdenes del partner". Recibe los avisos de observaciones que antes recibía el rol Partner. No lleva zonas.
- El rol no se puede dar a personal directo WIN; el personal de un partner solo puede ser Supervisor o Jefe de operaciones.
- Base: cambio `02_CAMBIOS/2026-10-12_02_rol_jefe_de_operaciones_en_lugar_de_partner` (agrega el rol, pasa Partner → Supervisor, quita Partner). Probado aplicar, revertir y volver a aplicar. Instalación, diccionario y diagrama regenerados.
- **Permiso "Responder observaciones del partner"** (nuevo, delegable): lo trae el Supervisor (el del partner responde como siempre); el Jefe de operaciones no, pero el administrador se lo puede activar en Usuarios → Personalizar. Aplica a observaciones y checklists, en pantalla y en la API.
- Prueba nueva `jefe_operaciones_test.php` (9/9).

### Carga de cancelaciones: fallaba en el hosting (2026-10-12)
- **Corregido**: la carga guardaba `estado_servicio_caliente = 'Cancelado'`, pero la columna solo acepta 'Finalizada' o 'Cancelada'. En el PC no se notaba (MySQL permisivo deja el campo vacío con un aviso); en el hosting, con MySQL en modo estricto, la carga fallaba con "Data truncated". Ahora guarda 'Cancelada'.
- Todas las pruebas se corrieron también con MySQL en modo estricto (como el hosting) y pasan. Dos pruebas tenían datos de prueba que el modo estricto rechazaba ('Cancelado' y columnas obligatorias sin llenar): corregidas.

### Carga de cancelaciones: códigos ya existentes y Excel más robusto (2026-10-12)
- **No se vuelve a ingresar un código que ya está en Supervisiones**: cada "Código de seguimiento / ticket" se compara con el "Código pedido / ticket" de todas las supervisiones, sin importar espacios, mayúsculas ni el ".0" de Excel. Se lista aparte en "Códigos no ingresados: ya están en Supervisiones", con la supervisión, su estado (Finalizada o Pendiente) y la fecha. Ya no cuenta como error.
- Un código repetido dentro del mismo archivo se carga una sola vez y se avisa.
- **Lectura del Excel**: se leen hasta 200 columnas (antes solo de la A a la T: en exportaciones con más columnas no se encontraban los encabezados); se usa la primera hoja del libro aunque internamente no se llame `sheet1`; se aceptan archivos sin numeración de filas o celdas; los tickets numéricos se guardan sin ".0".
- **Errores claros**: los avisos de PHP ya no se mezclan con la respuesta (la pantalla mostraba "No se pudo procesar el archivo"); un error inesperado se registra y se muestra con su código de seguimiento, sin el texto técnico.
- El resultado muestra: pendientes creadas, ya existentes, repetidos del archivo y filas con problemas.
- **Corregido: la plantilla oficial no se podía leer.** La plantilla que descarga el sistema guarda su XML con prefijo (`<x:row>`, `<x:c>`) y el lector solo entendía el formato sin prefijo que usa Excel al guardar: no encontraba encabezados ni filas, y el aviso de PHP que se generaba se mezclaba con la respuesta ("No se pudo procesar el archivo"). Ahora se leen ambos formatos.
- **Corregido: "No se pudo procesar el archivo" aunque la carga sí se hacía.** Al terminar con éxito, la pantalla limpiaba el formulario con `event.currentTarget`, que después de esperar al servidor ya es `null`; el error resultante reemplazaba el mensaje de éxito por el de fallo. Ahora el formulario se guarda antes de esperar.
- Si el servidor responde algo inesperado (error del hosting, archivo muy grande, bloqueo), la ventana muestra el código HTTP y el código de seguimiento en lugar de un mensaje genérico.
- Eliminar una supervisión ahora deja registro (`audit_supervision_deleted`, con una copia completa); antes no quedaba rastro.
- **Errores de la carga traducidos**: en lugar de "error interno", la ventana dice qué pasó, en qué fila del Excel y qué hacer (falta una columna o tabla en la base → aplicar cambios pendientes; valor no válido; dato muy largo; dato que no existe en el catálogo; código duplicado; base saturada; servidor sin la extensión ZIP; archivo demasiado grande). Debajo, el código de seguimiento y el detalle técnico (la carga es solo del administrador).
- Prueba nueva `importar_cancelaciones_test.php` (10/10, incluye la plantilla oficial).

### Cierre técnico: opción "CANCELADA" (2026-10-12)
- "Solución aplicada o cierre técnico" (Avería, Reiterada, Garantía) tiene la opción **CANCELADA**.
- **Corregido**: al editar una supervisión, el campo se armaba primero con otra lista corta y antigua; si la supervisión tenía guardada una opción de la lista real, se perdía al abrirla. Ahora crear y editar usan la misma lista y se conserva lo guardado.

### Dashboard: vistas Supervisión / Partners y modalidad (2026-10-12)
- **Dos vistas** debajo de los filtros (que aplican a ambas): "Supervisión" (indicadores, mapa, distritos, modalidad, meta de productividad y matriz mensual) y "Partners" (partners y cuadrillas más supervisados y cumplimiento de partners en 24 h). La vista elegida se recuerda en el navegador.
- **Gráfico de modalidad**: anillo con el porcentaje y la cantidad en caliente / en frío; cambia con cualquier filtro (período, partner, departamento, supervisor). No cuenta checklist ni visitas de capacitación (no tienen modalidad). Colores validados para daltonismo (naranja = caliente, azul = frío), en `sgs-theme.css` sección 30.
- **Supervisión en frío**: la ficha de una supervisión ya registrada ya no muestra técnicos, brevete ni vehículo (el formulario ya no los pedía).

### Ubicación automática al hacer una supervisión (2026-10-12)
- Al abrir "Nueva supervisión" la ubicación GPS se toma sola. También al abrir una orden pendiente que no tiene coordenadas; si ya las trae (por ejemplo, una cancelación importada con la dirección del cliente), se respetan.
- El botón pasa a "Actualizar ubicación" para volver a tomarla si el supervisor cambia de lugar. El aviso muestra la hora (24 h) y la precisión; si el celular no da permiso, explica qué hacer.

### Turno finalizado por error (2026-10-12)
- **Caso real**: un supervisor inició capacitación y, con un toque doble en el celular, terminó en "Finalizar turno" (07:04). Como solo se permite un turno al día, ya no podía seguir trabajando.
- **Reabrir turno**: el supervisor puede reabrirlo durante 15 minutos ("¿Lo finalizaste por error? Reabrir turno"); el administrador puede hacerlo el mismo día desde el Monitor de turnos (botón "Reabrir"). La pausa que el cierre cortó (capacitación, reunión, almuerzo o remoto) sigue abierta y el turno vuelve a ese estado. Queda anotado en el turno y en el registro (`audit_shift_reopened`). No aplica al cierre automático de las 23:59.
- **Finalizar turno** ahora usa una ventana propia con el botón "Sí, finalizar mi turno" (antes era el aviso del navegador) y se ignora si llega en los 2,5 segundos siguientes a otra acción del turno.
- **Monitor de turnos para el administrador**: antes solo lo veían Gerente y Coordinador. El administrador (dueño del sistema) ve todas las pantallas. Administración, gerencia y coordinación abren directo el monitor (sin la pestaña "Mi turno", que es solo del supervisor directo).
- Prueba nueva `reabrir_turno_test.php` (7/7).

### Permisos de usuarios y personal (2026-10-12)
- **Permisos nuevos** (se habilitan por usuario con "Personalizar"):
  - "Ver usuarios": solo consulta. El personal directo WIN ve a todos; el personal de un partner ve solo a su partner y dentro de sus zonas.
  - "Agregar y editar personal": solo datos personales (DNI, nombres, correo, teléfonos). No crea usuario ni contraseña, no asigna rol ni zonas, no activa, desactiva ni elimina, y no toca a administradores.
- **Trabajador "Sin acceso"**: el registrado así queda sin usuario e inactivo; a los administradores les llega el aviso "Nuevo personal por completar". El administrador lo abre con "Completar acceso" (usuario, contraseña, rol, zonas) y al guardar con contraseña queda activo. El filtro "Activos y sin acceso" los muestra.
- Quien no es administrador ve la pantalla como "Personal", un directorio: sin pestaña de roles, sin indicadores, sin filtros de rol u organización y sin estados (activo, inactivo, sin acceso).
- Al agregar personal solo se llenan DNI, nombres, apellidos, correo, teléfonos, área de trabajo y zona. El partner y la organización salen de quien registra; turno Día y modalidad Presencial quedan por defecto.
- **Limpieza de permisos**: se quitaron los que no hacían nada ("Ver recursos y archivos", "Ver examen técnico" y los 4 filtros de usuarios). "Ver usuarios" deja de venir por defecto para Supervisor y Partner. El rol Administrador sigue siendo solo para personal directo WIN.
- **Correo libre otra vez**: obligatorio y válido, pero sin forzar `@win.pe` ni `@partner.pe` (se deshace la regla de esta misma fecha).
- Observaciones: se quitó el aviso "Vista restringida al partner".
- Pruebas: `usuarios_service_test.php` 27/27 (personal sin acceso, alcance del partner, completar acceso); las demás pruebas usan correos completos.

### Observaciones: el partner ve las fotos como WIN y un solo comentario general (2026-10-12)
- **Corregido**: el partner no podía abrir las fotos de una observación (su ventana mostraba imágenes fijas). Ahora usa el mismo "Ver detalle" que WIN: datos de la orden, hallazgo, fotos que se abren en el visor con zoom y seguimiento. Si le toca responder, tiene "Responder" en la fila y dentro del detalle.
- **Un solo comentario general**: al registrar una observación ya no se pide comentario por cada foto; queda "Comentario de la observación" + hasta 3 fotos. Al responder, el partner sube sus 3 fotos y un "Comentario del levantamiento". El servidor ya no exige comentarios por foto (las columnas se conservan para los registros anteriores, que se siguen mostrando).
- Las fotos del levantamiento dentro del detalle ahora también se abren en el visor. Ya no aparece "Sin comentario" bajo cada foto.

### Filtros que se aplican solos y vista del partner (2026-10-12)
- **Filtros sin botón "Filtrar"**: en Observaciones, Dashboard y Control y recurrencia cada filtro se aplica apenas cambia (lo escrito espera a que se deje de teclear, sin perder el cursor). Queda solo "Limpiar".
- **Observaciones**: el filtro de estado (Pendientes, En revisión, Aceptados, Penalizados, Reabiertos) ahora lo tienen todos, también el partner.
- **Mi agenda (partner)**: pestañas Pendientes (asignadas y dentro de las 24 h), En proceso (ya registró su atención; falta que WIN la cierre) y No revisados (pasaron las 24 h sin atención), con sus contadores. Sin cambios en la base.
- Menú del partner: "De mi partner" pasa a "Registro", igual que para el resto.
- Prueba nueva `agenda_partner_test.php` (5/5).

### Usuarios: sin perfiles de acceso, correo con dominio y turno día o noche (2026-10-12)
- **Eliminado**: los "perfiles de acceso" (nunca se usaron; sus 2 tablas estaban vacías). Quedan el rol con sus permisos por defecto y, si hace falta, permisos personalizados por usuario ("Personalizar"). La pestaña pasa a llamarse "Roles y permisos". Se borró `PerfilRepository.php`.
- **Correo opcional y con dominio fijo**: si no se escribe, se arma con el usuario. Siempre termina en `@win.pe` (personal directo) o `@partner.pe` (personal de partner); en el formulario solo se escribe lo de antes de la @ y el dominio cambia solo según la organización. Si pegan un correo completo, se conserva solo lo de antes de la @.
- **Turno**: solo Día o Noche. Los turnos anteriores (Mañana, Tarde, Rotativo) se guardan como Día.
- Base: cambio `02_CAMBIOS/2026-10-12_01_quitar_perfiles_de_acceso_y_dejar_turnos_dia_y_noche` (borra `sgs_perfil` y `sgs_usuario_perfil`; pasa los turnos a Día). Quedan 21 tablas. Probado aplicar, revertir y volver a aplicar. Instalación, diccionario y diagrama regenerados.
- Pruebas: `usuarios_service_test.php` cambia los casos de perfiles por los de correo y turno (23/23); las demás pruebas usan correos sin dominio.

### Foto que congelaba la pantalla y búsqueda por pedido o ticket (2026-10-12)
- **Corregido**: al ver una foto desde el detalle de una observación y cerrarla, la pantalla quedaba gris y no respondía. Al cerrar una ventana que estaba encima de otra se perdía el estado "ventana abierta" y la animación de la página dejaba la ventana de abajo detrás del fondo. Se corrigió en el manejo general de ventanas (`main.js`), así que vale para todas las pantallas.
- **Corregido**: el navegador no descargaba los cambios de JavaScript (la versión de los archivos solo miraba el CSS). Ahora también mira los `.js`, así que basta con subir el archivo.
- **Buscador "Pedido o ticket"** en Observaciones y en Supervisiones, para todos los roles (cada uno sigue viendo solo lo suyo). También encuentra por código de supervisión. En Observaciones se mantiene al exportar a Excel o PDF.
- El detalle y la tabla del partner dicen "Pedido o ticket" (es el mismo dato).

### Observaciones, Dashboard y pendientes (2026-10-11)
- **Detalle de la observación rediseñado** (vista del partner y de todos): fecha de ingreso, fecha plazo (en rojo si venció), código de pedido, cuadrilla, partner, departamento, hallazgo con gravedad y fotos, y seguimiento por pasos.
- **Administrador**: puede ampliar el plazo (también en penalidad directa; si la nueva fecha es futura vuelve a Pendiente) y **eliminar** la observación con motivo obligatorio; se guarda una copia completa en el registro (`audit_observation_deleted`).
- **Dashboard**: el filtro de partners no cargaba (la lista no se enviaba). Nuevo filtro por **departamento**; la lista de supervisores se ajusta al departamento elegido (zona base o adicional) y la matriz, la productividad y el cumplimiento se filtran por él.
- **Pendientes como bolsa común de la zona**: las ve cada supervisor directo de esa zona (base o adicional); quien la inicia se queda con ella (también si la finaliza directamente) y desaparece de la lista de los demás. Si otro intenta iniciarla después, se le avisa que "ya fue tomada". Al importar se avisa a todos los supervisores de la zona con un resumen, en lugar de a uno solo.
- Prueba nueva `pendientes_bolsa_zona_test.php` (9/9).

### Corregido (2026-10-11)
- Menú "Columnas" de Supervisiones cortado: la tarjeta de la barra recortaba lo que sobresalía (overflow heredado). Ahora el menú se ve completo y por encima de la tabla.
- Al abrir otra supervisión quedaban restos de la anterior en el formulario: sección de observación abierta con sus fotos, tipificación marcada como elegida y buscadores de T1/T2 bloqueados. El formulario ahora queda en blanco.

### Trabajo remoto y primera acción del turno
- **Trabajo remoto**: botón nuevo en Mi turno (con nota opcional), estado "En remoto", bloque propio en la línea de tiempo (borde punteado), tarjeta y filtro en el monitor. Cuenta como tiempo trabajado y puede repetirse en el turno.
- **Primera acción del turno**: la línea de tiempo y el monitor muestran la primera supervisión, reunión, capacitación o trabajo remoto y cuántos minutos después del inicio ocurrió ("Sin acciones aún" si todavía no hay).
- Base: cambio `02_CAMBIOS/2026-10-08_01_agregar_trabajo_remoto_a_los_turnos` (agrega la opción a las listas; no toca datos).
- Corregido: las tarjetas de la línea de tiempo contaban mal (almuerzo, reunión y capacitación salían como "Fuera de turno"); los bloques de pausa salían invisibles (color inexistente) y sin tilde ("Reunion").
- Prueba nueva `trabajo_remoto_test.php` (8/8).

### Base de datos ordenada (punto de partida para el hosting)
- Las 3 tablas propias (`sgs_perfil`, `sgs_usuario_perfil`, `sgs_migracion`, vacías) se recrearon con nombres en minúsculas y `id` como clave, igual que el resto.
- Un solo estilo de nombres para índices y relaciones: `idx_tabla_columna`, `uq_tabla_columna`, `fk_tabla_columna` (57 índices y 32 relaciones renombrados; ningún dato ni columna tocados).
- Cada tabla tiene su descripción en la base (visible en phpMyAdmin); el generador la exige.
- `00_instalacion_completa.sql` con índice de contenido al inicio.
- `DICCIONARIO_DATOS.md` se genera solo desde la base (el anterior estaba desactualizado).

### Instalación en un archivo y cambios por carpeta con fecha
- `01_INSTALACION/00_instalacion_completa.sql`: toda la base (23 tablas + datos actuales) en un solo archivo para subirla de una vez. Probado en una base vacía.
- Desde ahora cada cambio de base va en `02_CAMBIOS/AAAA-MM-DD_NN_descripcion/` con `cambio.sql` y `reversion.sql`; se crea con `07_SCRIPTS/nuevo_cambio.php` y se aplica con `migrar.php` (`--estado`, `--revertir`). Probado: crear, aplicar, no repetir y revertir.

### Respaldos automáticos
- **Base de datos cada 6 horas y sistema completo (código, .env, fotos) cada 24 horas**, solos mientras el sistema se usa, sin depender de mysqldump (`02_BACKEND/src/config/respaldos.php`). **Apenas la base vuelve después de una caída**, se respaldan ambos al instante (motivo `tras-caida`).
- Nueva carpeta `10_RESPALDOS/` (`base/` .sql.gz, `sistema/` .tar.gz), bloqueada por web y fuera de git; se conservan 30 días de la base (mínimo 5) y los 5 últimos del sistema. Guía de restauración en `10_RESPALDOS/LEEME.md`.
- `07_SCRIPTS/respaldar.php [todo|base|sistema]` reemplaza a `respaldar_bd.php`. Probado: el respaldo de la base se restauró en una base temporal con las 23 tablas y los mismos registros; el simulacro de caída generó ambos respaldos.
- Los scripts de cambios de la base no se crean hasta que el sistema esté subido: se quitaron `001` y `002` (la instalación ya los incluye) y se vació `sgs_migracion`.

### Base de datos limpia
- **Tipificaciones enlazadas**: migración `002_vincular_tipificaciones.sql`. Las pendientes importadas y la corrección del supervisor quedan vinculadas al catálogo `tipificaciones_cancelacion` (antes era texto suelto y la tabla no tenía relaciones). La importación registra sola las tipificaciones nuevas del Excel; renombrar en el catálogo actualiza las órdenes; la base rechaza tipificaciones que no estén en la lista. Nueva prueba `importar_tipificacion_test.php` (5/5).
- Prueba `registrar_apoyo_test.php` (5/5): el T2 registrado desde la supervisión queda como técnico de apoyo del partner elegido en esa supervisión.
- **Código sin uso eliminado**: páginas `supervision_registro.php` y `observaciones_partner.php` (ninguna ruta las abría) con `supervision.js` y `observaciones.js`, 4 logos sin uso, 8 carpetas vacías y `06_DEPLOY` (su script pasó a `07_SCRIPTS/actualizar_servidor.sh`).
- **Base local limpia**: se vaciaron supervisiones, observaciones, checklists, historiales, notificaciones e inicios de turno de prueba; se conservan usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades (respaldo previo).
- **Solo tablas en uso**: migración `001_eliminar_tablas_sin_uso.sql` quita 13 tablas del sistema original (exámenes, chat, flyers, recursos, tickets de error, catálogos de tipos…). Quedan 23, todas usadas por SGS. Reversión en `rollback/`.
- **Carpeta `04_DATABASE` reorganizada**: `01_INSTALACION` (un script por tabla), `02_CAMBIOS` (cambios + rollback), `03_ESTRUCTURA` (esquema y diagrama), `04_RESPALDOS`. Se quitaron 6 carpetas vacías y el diagrama viejo de 36 tablas; `generar_diagrama.php` lo regenera. README reescrito.
- Los `.sql` de instalación llevan datos reales: excluidos de git. Eliminado el código heredado que creaba tablas al conectar (~350 líneas).
- **MySQL se caía** con consultas que unen tablas de `information_schema` (MariaDB 10.4): los scripts de mantenimiento ya no las usan. Se sacó de la carpeta de datos de MySQL una copia rota de septiembre (`repair-backup-20260907-1853`, movida a `C:
mpp\mysqlespaldo-fuera-de-data`).

### Corregido
- **Nombres de tabla en minúsculas** (`sgs_perfil`, `sgs_usuario_perfil`, `sgs_migracion`): el código usaba `SGS_PERFIL`; en Linux MySQL distingue mayúsculas y Usuarios y roles habría fallado. Nueva prueba `05_TEST/api/nombres_tablas_test.php` que lo detecta. El estándar ahora exige minúsculas en nombres de tabla.
- `.env`: un valor vacío seguido de un comentario (`CLAVE=   # nota`) se leía como "# nota".

### Cambiado
- **Fotos sin el sistema de origen**: si `../SISTEMA-WIN-PROVINCIA` no existe, se guardan y sirven desde `ProviWIN/uploads/` (se crean las carpetas solas). En `uploads/` nunca se ejecuta código (403).
- Python para PDF centralizado (`SGS_PYTHON_BIN`): `python3` en Linux si no se define `PYTHON_BIN`.
- HTTPS detectado también detrás de proxy (`X-Forwarded-Proto`); `display_errors` apagado en producción.
- Scripts de instalación sin `USE`: se importan en la base seleccionada, con cualquier nombre.
- Técnicos de apoyo (T2), campos fijos al elegir y tipificación con búsqueda (ver 0.10.0).

### Añadido
- `01_DOCUMENTACION/08_DESPLIEGUE/SUBIR_A_HOSTING.md`: lista de control para hosting compartido (cPanel/phpMyAdmin), cron de cierre de turnos y respaldo, limitaciones (PDF).

## [0.10.0] — 2026-10-08 — Bloqueo de ingreso, cierre de turnos y relación de observaciones

### Seguridad
- **Ingreso: 3 intentos.** Tres contraseñas incorrectas seguidas bloquean el usuario con el mensaje "Comunícate con el administrador del sistema para recuperar tu acceso". El aviso muestra los intentos restantes. El bloqueo se mantiene aunque luego se escriba la contraseña correcta.
- Usuarios y roles muestra la etiqueta "Bloqueado" y el botón **Desbloquear** (queda en auditoría); restablecer la contraseña también desbloquea.
- El límite por equipo (5 intentos en 15 min) ahora aplica solo a usuarios inexistentes, para que el desbloqueo del administrador surta efecto de inmediato.

### Añadido
- **Cierre automático del turno a las 23:59** (`02_BACKEND/src/config/turnos.php`): el turno olvidado se cierra a las 23:59 de su día con "Fuera de turno", horas trabajadas, almuerzo y extras calculados igual que el cierre manual, pausas abiertas cerradas y una nota. Corre cada 5 min mientras se usa el sistema y desde `07_SCRIPTS/cerrar_turnos.php` (programable a las 23:59).
- **Relación de observaciones en Excel y PDF** (`api/observaciones_export.php`): botón **Descargar** en Registro de observaciones con los filtros aplicados (por ejemplo, Estado = Penalizados). El Excel trae 19 columnas, encabezado fijo y autofiltro; el PDF es A4 horizontal con los filtros aplicados y totales por resultado. El PDF de checklist con evidencias sigue en el mismo menú.
- `02_BACKEND/src/utils/XlsxWriter.php`: genera .xlsx sin la extensión zip de PHP.
- **Tipificación de observación en un solo campo con búsqueda**: se escribe (sin importar tildes), se elige de la lista y la categoría y la gravedad se completan solas desde el catálogo; si es Muy Grave avisa que pasa directo a penalidad. Reemplaza los 3 combos en cascada (registro y edición de supervisión). El servidor ya tomaba la gravedad del catálogo; no cambia la BD.
- **Opción elegida = campo fijo**: en Tipificación, T1 y T2, una vez elegida la opción no se puede escribir encima; un botón de quitar la libera para buscar otra.
- **Técnicos de apoyo (T2)**: son un grupo distinto de los T1 (sin nomenclatura "P n …"), pertenecen a un partner y rotan entre cuadrillas. La lista del T2 muestra solo técnicos de apoyo del partner y nunca al mismo T1. Si el técnico no aparece, desde el mismo campo se registra con nombre y DNI (`api/tecnicos.php?action=crear_apoyo`, valida DNI y nombre únicos; queda en auditoría). El servidor rechaza un líder como T2 o un apoyo como T1 con un mensaje claro. "Sin técnico de apoyo (T2)" reemplaza a "La cuadrilla no cuenta con T2".
- **Visor de fotos con zoom** (`window.sgsVerFoto` en `main.js`, sección 26 del tema): la foto se ve completa sin cortes, con acercar y alejar, porcentaje, Ajustar, rueda del mouse, doble clic, pellizco y arrastre. Lo usan Supervisiones y Registro de observaciones; reemplaza el visor oscuro anterior.
- Pruebas: `login_bloqueo_test.php` (7), `cierre_turnos_test.php` (8), `observaciones_export_test.php` (9).

### Corregido
- Al descargar un archivo, la pantalla ya no queda atenuada y bloqueada.

### Base de datos y organización
- `04_DATABASE/01_INSTALACION`: `01_crear_base.sql` y un script por tabla en orden de dependencias, con los catálogos. Lo genera `07_SCRIPTS/generar_instalacion.php`, que también reescribe `03_ESTRUCTURA`.
- Migración `002_INDICES_LIMPIEZA.sql` aplicada (respaldo previo); `07_SCRIPTS/auditar_bd.php` (solo lectura) y `crear_administrador.php`.
- Eliminadas las copias repetidas `02_TABLES/` y `08_SEEDS/01_catalogos.sql`.
- El proyecto queda en git (rama `main`).

## [0.9.2] — 2026-10-08 — Esquema de la base de datos

### Añadido
- `04_DATABASE/03_ESTRUCTURA/esquema_completo.sql`: esquema completo de `win_telecom_db`, solo estructura (36 tablas, 44 relaciones, sin datos ni `DROP`). Verificado cargándolo en una base temporal que luego se eliminó.
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
- Usuarios inactivos `supervisor.comfica.prueba` (id 52) y `codex_audit` (id 53), con respaldo JSON en `10_RESPALDOS/base/`.

## [0.6.1] — 2026-10-06

### Cambiado
- **Usuarios y roles: un solo campo "Zonas de trabajo".** Se quitaron "Sede" y "Zonas adicionales": se marcan los departamentos que ve el usuario o "Todas las zonas" (obligatorio para Supervisor y Partner). La tabla muestra la columna *Zonas*; Mi información ya no muestra Departamento ni Sede, solo las zonas. Los usuarios existentes conservan su zona (la sede anterior se convierte en su zona base). Sin cambios en la base de datos.
- **Zonas = departamentos de Supervisiones.** La lista sale de la tabla `ubigeo` (la misma del formulario de supervisión): ÁNCASH, AREQUIPA, CUSCO, JUNÍN, LA LIBERTAD, LAMBAYEQUE, PIURA. Se quitaron CAJAMARCA, ICA y TACNA, que no se usan. El formulario de supervisión ya no muestra "HUANCAYO" como departamento (era un duplicado de JUNÍN → HUANCAYO).

### Eliminado
- Usuario partner de prueba `supervisor.mallausa.prueba` (id 51), respaldo en `10_RESPALDOS/base/`.

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
- `07_SCRIPTS/respaldar.php`: respaldo comprimido de la BD (Windows y Linux) con retención de 30 días.

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
- Migraciones: `04_DATABASE/02_CAMBIOS/001_SGS_PERFIL.sql` (tablas nuevas `SGS_PERFIL` y `SGS_USUARIO_PERFIL`, sin modificar las existentes) y el ejecutor `07_SCRIPTS/migrar.php`.
- `07_SCRIPTS/restablecer_contrasena.php`: recuperación de acceso desde el servidor.
- `05_TEST/integration/usuarios_service_test.php`: 19 pruebas del módulo, que limpian sus datos al terminar.

### Datos
- Se eliminaron todas las supervisiones (66) y sus notificaciones (66) a pedido del usuario. Hay respaldo completo en `10_RESPALDOS/base/win_telecom_db_completo_*.sql`.

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
