# Diccionario de datos — SGS

Generado por `07_SCRIPTS/generar_instalacion.php` el 2026-10-26 desde la base. **No editar a mano**: la descripción de cada tabla es su comentario en la base.
Diagrama de relaciones: `04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg`.

| # | Tabla | Para qué sirve | En la instalación |
|---|---|---|---|
| 1 | [`categorias_observacion`](#categorias_observacion) | Categorías de observación | 6 registros |
| 2 | [`login_attempts`](#login_attempts) | Intentos de ingreso (bloqueo a los 3 fallidos) | vacía |
| 3 | [`partners`](#partners) | Contratas; WIN DIRECTO = personal propio | 12 registros |
| 4 | [`sgs_migracion`](#sgs_migracion) | Cambios de base de datos ya aplicados (los registra 07_SCRIPTS/migrar.php) | vacía |
| 5 | [`tipificaciones_cancelacion`](#tipificaciones_cancelacion) | Motivos de cancelación (los del Excel de pendientes se agregan solos) | 6 registros |
| 6 | [`ubigeo`](#ubigeo) | Departamentos, provincias y distritos de operación | 41 registros |
| 7 | [`vehiculos`](#vehiculos) | Vehículos de las cuadrillas | 3 registros |
| 8 | [`subopciones_observacion`](#subopciones_observacion) | Tipificaciones de observación con gravedad y penalidad | 84 registros |
| 9 | [`usuarios`](#usuarios) | Usuarios del sistema (personal WIN y de partners) | 10 registros |
| 10 | [`asistencia_supervisores`](#asistencia_supervisores) | Turnos de los supervisores: inicio, fin y horas | vacía |
| 11 | [`ausencias_supervisores`](#ausencias_supervisores) | Vacaciones, descansos médicos y licencias de los supervisores (Mi turno) | vacía |
| 12 | [`examen_convocatorias`](#examen_convocatorias) | Links generales de examen (un link para un grupo de técnicos) | vacía |
| 13 | [`lluvias_cortes_programados`](#lluvias_cortes_programados) | Horarios del corte de lluvias en Teams (Clima) | 1 registros |
| 14 | [`lluvias_zona`](#lluvias_zona) | Lluvia avisada por los supervisores en su zona (línea de tiempo) | vacía |
| 15 | [`tecnicos`](#tecnicos) | Técnicos por partner: líderes (T1) y de apoyo (T2) | 196 registros |
| 16 | [`usuario_departamentos_acceso`](#usuario_departamentos_acceso) | Zonas adicionales que puede ver cada usuario | 0 registros |
| 17 | [`usuario_permisos`](#usuario_permisos) | Permisos personalizados por usuario (además de los de su rol) | 0 registros |
| 18 | [`asistencia_eventos`](#asistencia_eventos) | Pausas dentro del turno (almuerzo, reunión, capacitación) | vacía |
| 19 | [`examen_convocatoria_tecnicos`](#examen_convocatoria_tecnicos) | Técnicos de cada link general de examen | vacía |
| 20 | [`examen_enlaces`](#examen_enlaces) | Links de examen enviados a los técnicos | vacía |
| 21 | [`supervisiones`](#supervisiones) | Supervisiones de campo y órdenes pendientes asignadas | vacía |
| 22 | [`checklists_tecnicos`](#checklists_tecnicos) | Checklist de cuadrilla por supervisión | vacía |
| 23 | [`documentos_vencimiento`](#documentos_vencimiento) | Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial | vacía |
| 24 | [`examen_respuestas`](#examen_respuestas) | Resultado de cada pregunta de los exámenes rendidos | vacía |
| 25 | [`observaciones_supervision`](#observaciones_supervision) | Observaciones en campo, descargos del partner y plazos | vacía |
| 26 | [`rescate_seguimientos`](#rescate_seguimientos) | Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre) | vacía |
| 27 | [`supervision_coordenadas_historial`](#supervision_coordenadas_historial) | Cambios de ubicación GPS de cada supervisión | vacía |
| 28 | [`supervision_estado_historial`](#supervision_estado_historial) | Cambios de estado de cada supervisión (horas reales de inicio y fin) | vacía |
| 29 | [`checklist_respuestas`](#checklist_respuestas) | Criterios evaluados en cada checklist | vacía |
| 30 | [`notificaciones`](#notificaciones) | Avisos a los usuarios | vacía |

### `categorias_observacion`
Categorías de observación

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `nombre_categoria` | varchar(100) | no |  |  |

### `login_attempts`
Intentos de ingreso (bloqueo a los 3 fallidos)

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `fingerprint` | char(64) | no | PK |  |
| `attempts` | smallint(5) unsigned | no |  |  |
| `first_attempt_at` | datetime | no |  |  |
| `last_attempt_at` | datetime | no |  |  |
| `blocked_until` | datetime | sí |  |  |

### `partners`
Contratas; WIN DIRECTO = personal propio

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `nombre_partner` | varchar(150) | no |  |  |
| `ruc` | varchar(11) | sí |  |  |
| `estado` | tinyint(1) | sí |  |  |
| `fecha_registro` | datetime | sí |  |  |

### `sgs_migracion`
Cambios de base de datos ya aplicados (los registra 07_SCRIPTS/migrar.php)

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `archivo` | varchar(120) | no | PK | Carpeta del cambio en 04_DATABASE/02_CAMBIOS |
| `fecha_aplicacion` | datetime | no |  |  |

### `tipificaciones_cancelacion`
Motivos de cancelación (los del Excel de pendientes se agregan solos)

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `descripcion` | varchar(200) | no | Única |  |
| `estado` | tinyint(1) | no |  |  |
| `fecha_registro` | datetime | no |  |  |

### `ubigeo`
Departamentos, provincias y distritos de operación

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `departamento` | varchar(100) | no |  |  |
| `provincia` | varchar(100) | no |  |  |
| `distrito` | varchar(100) | no |  |  |

### `vehiculos`
Vehículos de las cuadrillas

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `placa` | varchar(10) | no | Única |  |
| `tipo` | enum('Minivan','Auto','Moto','Otro') | sí |  |  |
| `estado_conservacion` | enum('Mal estado','Regular','Buen estado') | sí |  | Último estado registrado del vehículo (sale por defecto en la siguiente supervisión) |
| `marca` | varchar(100) | sí |  |  |
| `modelo` | varchar(100) | sí |  |  |
| `anio` | smallint(5) unsigned | sí |  |  |
| `soat` | enum('Vigente','No vigente') | sí |  |  |
| `soat_vencimiento` | date | sí |  |  |
| `revision_tecnica` | enum('Vigente','No vigente') | sí |  |  |
| `revision_tecnica_vencimiento` | date | sí |  |  |
| `extintor_vencimiento` | date | sí |  |  |
| `fecha_registro` | datetime | no |  |  |
| `fecha_actualizacion` | datetime | sí |  |  |

### `subopciones_observacion`
Tipificaciones de observación con gravedad y penalidad · Depende de: `categorias_observacion`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `categoria_id` | int(11) | no | FK → `categorias_observacion` |  |
| `descripcion_subopcion` | varchar(200) | no |  |  |
| `gravedad` | enum('Media','Grave','Muy Grave') | no |  |  |
| `penalidad_monto` | decimal(10,2) | no |  |  |
| `accion_adicional` | varchar(255) | sí |  |  |

### `usuarios`
Usuarios del sistema (personal WIN y de partners) · Depende de: `partners`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `dni` | varchar(8) | no | Única |  |
| `nombres` | varchar(100) | sí |  |  |
| `apellidos` | varchar(100) | sí |  |  |
| `usuario` | varchar(50) | sí | Única |  |
| `nombre_completo` | varchar(200) | no |  |  |
| `email` | varchar(150) | no | Única |  |
| `telefono_personal` | varchar(20) | sí |  |  |
| `telefono_corporativo` | varchar(20) | sí |  |  |
| `turno` | varchar(30) | sí |  |  |
| `modalidad` | varchar(30) | sí |  |  |
| `sede` | varchar(100) | sí |  |  |
| `password_hash` | varchar(255) | no |  |  |
| `password_admin_encrypted` | text | sí |  |  |
| `rol` | enum('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Jefe de operaciones') | no |  |  |
| `area_trabajo` | varchar(100) | sí |  |  |
| `cargo` | varchar(60) | sí |  | Puesto en la empresa (lista fija en el sistema); no da permisos |
| `zona` | varchar(100) | sí |  |  |
| `procesos_copiados` | text | sí |  |  |
| `observacion` | text | sí |  |  |
| `partner_id` | int(11) | sí | FK → `partners` |  |
| `activo` | tinyint(1) | sí |  |  |
| `por_activar` | tinyint(1) | no |  | Espera que la cuenta principal lo active (no puede ingresar) |
| `ultimo_acceso` | datetime | sí |  | Último inicio de sesión correcto en SGS |
| `debe_cambiar_password` | tinyint(1) | no |  | 1 = al ingresar debe cambiar su contraseña (temporal) |
| `fecha_creacion` | datetime | sí |  |  |
| `avatar` | varchar(255) | sí |  |  |

### `asistencia_supervisores`
Turnos de los supervisores: inicio, fin y horas · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `supervisor_id` | int(11) | no | FK → `usuarios` |  |
| `fecha` | date | no |  |  |
| `inicio_turno` | datetime | no |  |  |
| `fin_turno` | datetime | sí |  |  |
| `horas_trabajadas` | decimal(5,2) | sí |  |  |
| `horas_almuerzo` | decimal(4,2) | sí |  |  |
| `horas_extras` | decimal(5,2) | sí |  |  |
| `en_capacitacion` | tinyint(1) | sí |  |  |
| `estado_turno` | enum('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento','En remoto') | sí |  |  |
| `fecha_registro` | datetime | sí |  |  |
| `latitud_inicio` | varchar(50) | sí |  |  |
| `longitud_inicio` | varchar(50) | sí |  |  |
| `latitud_fin` | varchar(50) | sí |  |  |
| `longitud_fin` | varchar(50) | sí |  |  |
| `observaciones` | text | sí |  |  |

### `ausencias_supervisores`
Vacaciones, descansos médicos y licencias de los supervisores (Mi turno) · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `supervisor_id` | int(11) | no | FK → `usuarios` |  |
| `tipo` | enum('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento','Otra licencia') | no |  |  |
| `fecha_inicio` | date | no |  |  |
| `fecha_fin` | date | no |  |  |
| `comentario` | varchar(300) | sí |  |  |
| `sustento` | varchar(120) | sí |  | Archivo en 11_PRIVADO/ausencias (privado) |
| `estado` | enum('Pendiente','Aprobada','Rechazada','Anulada') | no |  |  |
| `revisado_por` | int(11) | sí | FK → `usuarios` |  |
| `revisado_en` | datetime | sí |  |  |
| `motivo_revision` | varchar(300) | sí |  | Motivo del rechazo o de la anulación |
| `creado_en` | datetime | no |  |  |

### `examen_convocatorias`
Links generales de examen (un link para un grupo de técnicos) · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `token_hash` | char(64) | no | Única | SHA-256 del código del link general (el código no se guarda) |
| `titulo` | varchar(150) | no |  |  |
| `examen` | varchar(40) | no |  | Examen que trae el link (banco de preguntas) |
| `filtro` | varchar(255) | sí |  | Filtros de Cuadrillas con los que se armó el grupo |
| `generado_por` | int(11) | sí | FK → `usuarios` | Usuario que generó el link |
| `creado_en` | datetime | no |  |  |
| `vence_en` | datetime | no |  |  |
| `anulado_en` | datetime | sí |  |  |
| `anulado_por` | int(11) | sí | FK → `usuarios` |  |

### `lluvias_cortes_programados`
Horarios del corte de lluvias en Teams (Clima) · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `hora` | time | no | Única | Hora de Lima en que se envía cada día |
| `activo` | tinyint(1) | no |  |  |
| `ultimo_envio` | datetime | sí |  | Último envío hecho (no se repite el mismo día) |
| `creado_por` | int(11) | sí | FK → `usuarios` |  |
| `creado_en` | datetime | no |  |  |

### `lluvias_zona`
Lluvia avisada por los supervisores en su zona (línea de tiempo) · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `departamento` | varchar(100) | no |  | Zona donde llueve |
| `inicio` | datetime | no |  |  |
| `fin` | datetime | sí |  | NULL = sigue lloviendo |
| `reportado_por` | int(11) | no | FK → `usuarios` |  |
| `comentario_inicio` | varchar(150) | sí |  | Qué hacía quien avisó la lluvia |
| `cerrado_por` | int(11) | sí | FK → `usuarios` |  |
| `comentario_fin` | varchar(150) | sí |  | Qué hacía quien la cerró |

### `tecnicos`
Técnicos por partner: líderes (T1) y de apoyo (T2) · Depende de: `partners`, `usuarios`, `vehiculos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `nombre_tecnico` | varchar(255) | no | Única |  |
| `dni` | varchar(20) | sí | Única |  |
| `tipo_tecnico` | tinyint(1) | sí |  |  |
| `celular` | varchar(15) | sí |  |  |
| `observacion` | text | sí |  |  |
| `estado_operativo` | enum('Habilitado','Inhabilitado','Blacklist') | no |  |  |
| `fecha_registro` | datetime | sí |  |  |
| `fecha_actualizacion` | datetime | sí |  |  |
| `partner_id` | int(11) | sí | FK → `partners` |  |
| `provincia` | varchar(100) | sí |  | Provincia de trabajo (tal como viene en el Excel de técnicos líderes) |
| `departamento` | varchar(100) | sí |  | Zona: departamento de la provincia; filtra los técnicos en el formulario de supervisión |
| `usuario_id` | int(11) | sí | FK → `usuarios` |  |
| `vehiculo_id` | int(11) | sí | FK → `vehiculos` |  |
| `apoyo_id` | int(11) | sí | FK → `tecnicos` | Técnico de apoyo (T2) habitual del líder: sale por defecto en la siguiente supervisión |

### `usuario_departamentos_acceso`
Zonas adicionales que puede ver cada usuario · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(10) unsigned | no | PK |  |
| `usuario_id` | int(11) | no | FK → `usuarios` |  |
| `departamento` | varchar(100) | no |  |  |
| `activo` | tinyint(1) | no |  |  |
| `otorgado_por` | int(11) | sí | FK → `usuarios` |  |
| `fecha_registro` | datetime | no |  |  |
| `fecha_actualizacion` | datetime | no |  |  |

### `usuario_permisos`
Permisos personalizados por usuario (además de los de su rol) · Depende de: `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | PK |  |
| `usuario_id` | int(11) | no | FK → `usuarios` |  |
| `permiso` | varchar(100) | no |  |  |
| `permitido` | tinyint(1) | no |  |  |
| `otorgado_por` | int(11) | sí | FK → `usuarios` |  |
| `fecha_registro` | datetime | no |  |  |
| `fecha_actualizacion` | datetime | no |  |  |

### `asistencia_eventos`
Pausas dentro del turno (almuerzo, reunión, capacitación) · Depende de: `asistencia_supervisores`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `asistencia_id` | int(11) | no | FK → `asistencia_supervisores` |  |
| `tipo_evento` | enum('almuerzo','reunion','capacitacion','campo','remoto') | no |  |  |
| `inicio_evento` | datetime | no |  |  |
| `fin_evento` | datetime | sí |  |  |
| `duracion_minutos` | int(11) | sí |  |  |
| `nota` | varchar(255) | sí |  |  |
| `latitud` | varchar(50) | sí |  |  |
| `longitud` | varchar(50) | sí |  |  |
| `created_at` | datetime | sí |  |  |

### `examen_convocatoria_tecnicos`
Técnicos de cada link general de examen · Depende de: `examen_convocatorias`, `tecnicos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `convocatoria_id` | int(11) | no | FK → `examen_convocatorias` |  |
| `tecnico_id` | int(11) | no | FK → `tecnicos` |  |
| `tipo` | tinyint(1) | no |  | 1 = técnico líder (T1), 2 = técnico de apoyo (T2) |
| `ingreso_en` | datetime | sí |  | Primera vez que entró con su DNI |
| `sesion_hash` | char(64) | sí |  | Huella de la llave del equipo con el que rinde |
| `inicio_en` | datetime | sí |  | Cuándo empezó a responder (corre el tiempo) |
| `preguntas` | longtext | sí |  | Preguntas que le tocaron y orden de alternativas (JSON) |
| `respuestas` | longtext | sí |  | Respuestas guardadas mientras rinde (JSON) |
| `usado_en` | datetime | sí |  | Examen rendido: ya no puede volver a entrar |
| `correctas` | tinyint(3) unsigned | sí |  |  |
| `total_preguntas` | tinyint(3) unsigned | sí |  |  |
| `nota` | decimal(5,2) | sí |  | Porcentaje de aciertos (0 a 100) |
| `aprobado` | tinyint(1) | sí |  |  |
| `segundos` | smallint(5) unsigned | sí |  | Tiempo que usó |

### `examen_enlaces`
Links de examen enviados a los técnicos · Depende de: `usuarios`, `tecnicos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `tecnico_id` | int(11) | no | FK → `tecnicos` | Técnico que rinde el examen (T1 o T2) |
| `token_hash` | char(64) | no | Única | SHA-256 del código del link (el código no se guarda) |
| `titulo` | varchar(150) | no |  |  |
| `generado_por` | int(11) | sí | FK → `usuarios` | Usuario que generó el link |
| `creado_en` | datetime | no |  |  |
| `vence_en` | datetime | no |  |  |
| `abierto_en` | datetime | sí |  | Primera vez que el técnico abrió el link |
| `usado_en` | datetime | sí |  | Examen rendido: el link ya no sirve |
| `anulado_en` | datetime | sí |  |  |
| `anulado_por` | int(11) | sí | FK → `usuarios` |  |

### `supervisiones`
Supervisiones de campo y órdenes pendientes asignadas · Depende de: `usuarios`, `partners`, `tecnicos`, `tipificaciones_cancelacion`, `vehiculos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `codigo_supervision` | varchar(30) | no | Única |  |
| `fecha_registro` | datetime | sí |  |  |
| `fecha_carga` | datetime | sí |  | Cuándo se subió la pendiente por Excel (solo órdenes importadas) |
| `supervisor_id` | int(11) | no | FK → `usuarios` |  |
| `asignado_por` | int(11) | sí | FK → `usuarios` |  |
| `fecha_asignacion` | datetime | sí |  |  |
| `estado_atencion_partner` | enum('Pendiente','Atendida') | no |  |  |
| `observacion_atencion_partner` | text | sí |  |  |
| `partner_rescatable` | tinyint(1) | sí |  | El partner indicó al atender que el servicio se puede rescatar (1) o no (0) |
| `fecha_atencion_partner` | datetime | sí |  |  |
| `zona_asignacion` | varchar(100) | sí |  |  |
| `derivacion_orden` | enum('Autogestión','Mesa de supervisión','Líder','Coordinador','Gerente') | no |  |  |
| `tipo_servicio` | enum('Regular','ZAV') | no |  |  |
| `departamento` | varchar(100) | no |  |  |
| `provincia` | varchar(100) | no |  |  |
| `distrito` | varchar(100) | no |  |  |
| `direccion` | varchar(255) | sí |  | Dirección del cliente (carga de cancelaciones por Excel) |
| `cliente_nombre` | varchar(200) | sí |  |  |
| `cliente_dni` | varchar(12) | sí |  |  |
| `ticket_codigo` | varchar(50) | sí |  |  |
| `fecha_cancelacion` | date | sí |  |  |
| `tipo_supervision_inicial` | enum('En caliente','En frío','Remota') | no |  |  |
| `partner_id` | int(11) | sí | FK → `partners` |  |
| `tecnico1_nombre` | varchar(200) | sí |  |  |
| `tecnico1_dni` | varchar(8) | sí |  |  |
| `tecnico2_nombre` | varchar(200) | sí |  |  |
| `tecnico2_dni` | varchar(8) | sí |  |  |
| `brevete_poseedor` | enum('Tecnico 1','Tecnico 2','Ninguno') | sí |  |  |
| `unidad_tipo` | enum('Minivan','Auto','Moto','Otro') | sí |  |  |
| `unidad_placa` | varchar(10) | sí |  |  |
| `unidad_soat` | enum('Vigente','No vigente') | sí |  |  |
| `unidad_revision_tecnica` | enum('Vigente','No vigente') | sí |  |  |
| `unidad_estado_conservacion` | enum('Mal estado','Regular','Buen estado') | sí |  |  |
| `clasificacion_supervision` | varchar(100) | no |  |  |
| `area_servicio` | varchar(100) | no |  |  |
| `sub_tipo_atencion` | varchar(150) | sí |  |  |
| `observacion_servicio` | text | sí |  |  |
| `estado_servicio_caliente` | enum('Finalizada','Cancelada') | sí |  |  |
| `es_cancelacion` | tinyint(1) | sí |  |  |
| `origen_importacion` | tinyint(1) | no |  |  |
| `observacion_previa` | text | sí |  |  |
| `estado_cancelacion` | varchar(60) | sí |  |  |
| `rescate_estado` | enum('En rescate','Rescatado','No rescatado') | sí |  | Rescate de la pendiente: en curso o su resultado |
| `rescate_responsable_id` | int(11) | sí | FK → `usuarios` | Supervisor directo a cargo del rescate |
| `rescate_inicio` | datetime | sí |  | Cuándo se tomó como rescate |
| `rescate_fin` | datetime | sí |  | Cuándo se cerró el rescate |
| `tipificacion_cancelacion` | varchar(200) | sí | FK → `tipificaciones_cancelacion` |  |
| `tipificacion_tecnico_correcta` | tinyint(1) | sí |  |  |
| `tipificacion_corregida_supervisor` | varchar(200) | sí | FK → `tipificaciones_cancelacion` |  |
| `estado_supervision` | enum('Pendiente','Iniciada','En proceso','Finalizada') | no |  |  |
| `latitud` | varchar(50) | sí |  |  |
| `longitud` | varchar(50) | sí |  |  |
| `foto_servicio_1` | varchar(255) | sí |  |  |
| `foto_servicio_comentario_1` | varchar(500) | sí |  |  |
| `foto_servicio_2` | varchar(255) | sí |  |  |
| `foto_servicio_comentario_2` | varchar(500) | sí |  |  |
| `foto_servicio_3` | varchar(255) | sí |  |  |
| `foto_servicio_comentario_3` | varchar(500) | sí |  |  |
| `tecnico1_id` | int(11) | sí | FK → `tecnicos` |  |
| `tecnico2_id` | int(11) | sí | FK → `tecnicos` |  |
| `vehiculo_id` | int(11) | sí | FK → `vehiculos` |  |
| `datos_pendiente` | longtext | sí |  | Copia (JSON) de la pendiente al iniciarla: si se cancela, vuelve a este estado. Se borra al finalizar. |

### `checklists_tecnicos`
Checklist de cuadrilla por supervisión · Depende de: `supervisiones`, `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `supervision_id` | int(11) | sí | FK → `supervisiones` |  |
| `supervisor_id` | int(11) | no | FK → `usuarios` |  |
| `tecnico_nombre` | varchar(200) | no |  |  |
| `tecnico_dni` | varchar(12) | no |  |  |
| `unidad_tipo` | varchar(30) | no |  |  |
| `unidad_placa` | varchar(15) | no |  |  |
| `brevete_poseedor` | varchar(200) | sí |  |  |
| `observacion_general` | text | sí |  |  |
| `firma_supervisor` | varchar(255) | sí |  |  |
| `respuesta_general_partner` | text | sí |  |  |
| `fecha_respuesta_partner` | datetime | sí |  |  |
| `respondido_por_partner` | int(11) | sí |  |  |
| `estado_respuesta` | enum('Pendiente','En revisión','Validado','Penalizado','Reabierto') | no |  |  |
| `sustentacion_evaluacion` | text | sí |  |  |
| `fecha_evaluacion` | datetime | sí |  |  |
| `evaluado_por` | int(11) | sí |  |  |
| `fecha_registro` | datetime | sí |  |  |
| `fecha_limite_respuesta` | datetime | sí |  |  |

### `documentos_vencimiento`
Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial · Depende de: `tecnicos`, `usuarios`, `supervisiones`, `vehiculos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `tipo` | enum('SOAT','Revisión técnica','Extintor','Brevete') | no |  |  |
| `vehiculo_id` | int(11) | sí | FK → `vehiculos` | SOAT, revisión técnica y extintor |
| `tecnico_id` | int(11) | sí | FK → `tecnicos` | Brevete (técnico líder) |
| `fecha_inicio` | date | no |  | Emisión del documento o última recarga del extintor |
| `fecha_vencimiento` | date | no |  |  |
| `foto` | varchar(255) | no |  | Foto del documento (evidencia) |
| `origen` | enum('Supervisión','Cuadrillas') | no |  |  |
| `supervision_id` | int(11) | sí | FK → `supervisiones` | Supervisión en la que se actualizó (si fue desde el checklist) |
| `aplicado` | tinyint(1) | no |  | 0 = registrado en una supervisión en proceso: se asigna a la cuadrilla al finalizarla |
| `lider_id` | int(11) | sí | FK → `tecnicos` | Cuadrilla (técnico líder) en ese momento |
| `departamento` | varchar(100) | sí |  | Zona en ese momento |
| `registrado_por` | int(11) | sí | FK → `usuarios` |  |
| `registrado_en` | datetime | no |  |  |

### `examen_respuestas`
Resultado de cada pregunta de los exámenes rendidos · Depende de: `examen_convocatoria_tecnicos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `participacion_id` | int(11) | no | FK → `examen_convocatoria_tecnicos` | examen_convocatoria_tecnicos.id |
| `pregunta` | varchar(20) | no |  | Código de la pregunta en el banco |
| `tema` | varchar(60) | no |  |  |
| `correcta` | tinyint(1) | no |  |  |
| `respondida` | tinyint(1) | no |  |  |

### `observaciones_supervision`
Observaciones en campo, descargos del partner y plazos · Depende de: `categorias_observacion`, `usuarios`, `subopciones_observacion`, `supervisiones`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `supervision_id` | int(11) | no | FK → `supervisiones` |  |
| `creado_por` | int(11) | sí | FK → `usuarios` |  |
| `gravedad` | enum('Leve','Media','Grave','Muy Grave') | no |  |  |
| `categoria_id` | int(11) | no | FK → `categorias_observacion` |  |
| `subopcion_id` | int(11) | no | FK → `subopciones_observacion` |  |
| `detalle_observacion` | text | no |  |  |
| `foto_1` | varchar(255) | sí |  |  |
| `foto_comentario_1` | varchar(500) | sí |  |  |
| `foto_2` | varchar(255) | sí |  |  |
| `foto_comentario_2` | varchar(500) | sí |  |  |
| `foto_3` | varchar(255) | sí |  |  |
| `foto_comentario_3` | varchar(500) | sí |  |  |
| `fecha_registro` | datetime | sí |  |  |
| `fecha_limite_respuesta` | datetime | no |  |  |
| `plazo_subsanacion_dias` | tinyint(3) unsigned | no |  |  |
| `subsanacion_inmediata` | tinyint(1) | no |  |  |
| `estado_respuesta` | enum('Pendiente','Respondido','En revisión','Penalidad Directa','Apelación Aprobada','Apelación Rechazada','Levantamiento validado','Levantamiento rechazado') | sí |  |  |
| `respuesta_partner` | text | sí |  |  |
| `descargo_foto_1` | varchar(255) | sí |  |  |
| `descargo_comentario_1` | varchar(500) | sí |  |  |
| `descargo_foto_2` | varchar(255) | sí |  |  |
| `descargo_comentario_2` | varchar(500) | sí |  |  |
| `descargo_foto_3` | varchar(255) | sí |  |  |
| `descargo_comentario_3` | varchar(500) | sí |  |  |
| `fecha_respuesta_partner` | datetime | sí |  |  |
| `respondido_por_partner` | int(11) | sí |  |  |
| `evaluacion_supervisor` | varchar(50) | sí |  |  |
| `sustentacion_evaluacion` | text | sí |  |  |
| `fecha_evaluacion` | datetime | sí |  |  |
| `evaluado_por` | int(11) | sí |  |  |

### `rescate_seguimientos`
Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre) · Depende de: `supervisiones`, `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `supervision_id` | int(11) | no | FK → `supervisiones` |  |
| `usuario_id` | int(11) | sí | FK → `usuarios` |  |
| `tipo` | enum('Propuesta del partner','Inicio','Avance','Reasignación','Cierre') | no |  |  |
| `comentario` | text | sí |  |  |
| `foto` | varchar(255) | sí |  |  |
| `fecha` | datetime | no |  |  |

### `supervision_coordenadas_historial`
Cambios de ubicación GPS de cada supervisión · Depende de: `usuarios`, `supervisiones`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | PK |  |
| `supervision_id` | int(11) | no | FK → `supervisiones` |  |
| `latitud_anterior` | varchar(50) | sí |  |  |
| `longitud_anterior` | varchar(50) | sí |  |  |
| `latitud_nueva` | varchar(50) | sí |  |  |
| `longitud_nueva` | varchar(50) | sí |  |  |
| `cambiado_por` | int(11) | sí | FK → `usuarios` |  |
| `fecha_registro` | datetime | no |  |  |

### `supervision_estado_historial`
Cambios de estado de cada supervisión (horas reales de inicio y fin) · Depende de: `usuarios`, `supervisiones`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | no | PK |  |
| `supervision_id` | int(11) | no | FK → `supervisiones` |  |
| `estado_anterior` | varchar(40) | sí |  |  |
| `estado_nuevo` | varchar(40) | no |  |  |
| `cambiado_por` | int(11) | sí | FK → `usuarios` |  |
| `comentario` | varchar(500) | sí |  |  |
| `fecha_cambio` | datetime | no |  |  |

### `checklist_respuestas`
Criterios evaluados en cada checklist · Depende de: `checklists_tecnicos`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `checklist_id` | int(11) | no | FK → `checklists_tecnicos` |  |
| `criterio` | varchar(255) | no |  |  |
| `estado` | enum('Bueno','Regular','Malo','No tiene','Sí','No','Para cambio','No revisado','Mal estado') | no |  |  |
| `observacion` | varchar(500) | sí |  |  |
| `foto_evidencia` | varchar(255) | sí |  |  |
| `descargo_foto` | varchar(255) | sí |  |  |
| `descargo_comentario` | varchar(500) | sí |  |  |

### `notificaciones`
Avisos a los usuarios · Depende de: `observaciones_supervision`, `usuarios`

| Columna | Tipo | Nulo | Clave | Descripción |
|---|---|---|---|---|
| `id` | int(11) | no | PK |  |
| `usuario_id` | int(11) | no | FK → `usuarios` |  |
| `observacion_id` | int(11) | sí | FK → `observaciones_supervision` |  |
| `titulo` | varchar(160) | no |  |  |
| `mensaje` | varchar(500) | no |  |  |
| `url_destino` | varchar(255) | sí |  |  |
| `leida` | tinyint(1) | no |  |  |
| `fecha_registro` | datetime | no |  |  |
| `fecha_lectura` | datetime | sí |  |  |
