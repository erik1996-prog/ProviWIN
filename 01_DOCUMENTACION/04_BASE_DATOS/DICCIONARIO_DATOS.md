# Diccionario de datos — SGS

SGS usa la base **`win_telecom_db`**, compartida con el sistema de origen. Los nombres actuales de las tablas **no se cambian** mientras ambos sistemas convivan. La columna "Nombre SGS" es el nombre que tendrán según la guía de nomenclatura (`SGS_NOMBRE`, en singular) cuando se migre (ver `04_DATABASE/README.md`).

Generado desde `information_schema` el 2026-10-04. Las descripciones vacías quedan pendientes de documentar.

| Tabla actual | Nombre SGS | Uso en SGS |
|---|---|---|
| `supervisiones` | `SGS_SUPERVISION` | Lectura y escritura |
| `checklists_tecnicos` | `SGS_CHECKLIST` | Lectura y escritura |
| `checklist_respuestas` | `SGS_CHECKLIST_RESPUESTA` | Lectura y escritura |
| `observaciones_supervision` | `SGS_OBSERVACION` | Lectura y escritura |
| `supervision_coordenadas_historial` | `SGS_SUPERVISION_COORDENADA` | Escritura |
| `tecnicos` | `SGS_TECNICO` | Lectura y escritura |
| `vehiculos` | `SGS_VEHICULO` | Lectura y escritura |
| `notificaciones` | `SGS_NOTIFICACION` | Lectura y escritura |
| `login_attempts` | `SGS_LOGIN_INTENTO` | Lectura y escritura |
| `usuarios` | `SGS_USUARIO` | Lectura |
| `partners` | `SGS_PARTNER` | Lectura |
| `usuario_permisos` | `SGS_USUARIO_PERMISO` | Lectura |
| `usuario_departamentos_acceso` | `SGS_USUARIO_DEPARTAMENTO` | Lectura |
| `categorias_observacion` | `SGS_CATEGORIA_OBSERVACION` | Lectura |
| `subopciones_observacion` | `SGS_SUBOPCION_OBSERVACION` | Lectura |
| `tipificaciones_cancelacion` | `SGS_TIPIFICACION_CANCELACION` | Lectura |
| `ubigeo` | `SGS_UBIGEO` | Lectura |
| `asistencia_supervisores` | `SGS_ASISTENCIA` | Lectura |
| `asistencia_eventos` | `SGS_ASISTENCIA_EVENTO` | Lectura |

## Detalle por tabla

### `usuarios` → `SGS_USUARIO`

Usuarios del sistema (supervisores, administradores, partners)

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| dni | `varchar(8)` | No | No | Sí |  |
| nombres | `varchar(100)` | No | No | No |  |
| apellidos | `varchar(100)` | No | No | No |  |
| usuario | `varchar(50)` | No | No | No |  |
| nombre_completo | `varchar(200)` | No | No | Sí |  |
| email | `varchar(150)` | No | No | Sí |  |
| telefono_personal | `varchar(20)` | No | No | No |  |
| telefono_corporativo | `varchar(20)` | No | No | No |  |
| turno | `varchar(30)` | No | No | No |  |
| modalidad | `varchar(30)` | No | No | No |  |
| sede | `varchar(100)` | No | No | No |  |
| password_hash | `varchar(255)` | No | No | Sí |  |
| password_admin_encrypted | `text` | No | No | No |  |
| rol | `enum('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner')` | No | No | Sí |  |
| area_trabajo | `varchar(100)` | No | No | No |  |
| zona | `varchar(100)` | No | No | No |  |
| procesos_copiados | `text` | No | No | No |  |
| observacion | `text` | No | No | No |  |
| partner_id | `int(11)` | No | Sí | No | Relación con `partners` (SGS_PARTNER) |
| activo | `tinyint(1)` | No | No | No |  |
| fecha_creacion | `datetime` | No | No | No |  |
| avatar | `varchar(255)` | No | No | No |  |

### `partners` → `SGS_PARTNER`

Empresas contratistas (partners)

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| nombre_partner | `varchar(150)` | No | No | Sí |  |
| ruc | `varchar(11)` | No | No | No |  |
| estado | `tinyint(1)` | No | No | No |  |
| fecha_registro | `datetime` | No | No | No |  |

### `usuario_permisos` → `SGS_USUARIO_PERMISO`

Permisos individuales por usuario

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `bigint(20) unsigned` | Sí | No | Sí | Identificador |
| usuario_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| permiso | `varchar(100)` | No | No | Sí |  |
| permitido | `tinyint(1)` | No | No | Sí |  |
| otorgado_por | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| fecha_registro | `datetime` | No | No | Sí |  |
| fecha_actualizacion | `datetime` | No | No | Sí |  |

### `usuario_departamentos_acceso` → `SGS_USUARIO_DEPARTAMENTO`

Departamentos que puede ver cada usuario

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(10) unsigned` | Sí | No | Sí | Identificador |
| usuario_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| departamento | `varchar(100)` | No | No | Sí |  |
| activo | `tinyint(1)` | No | No | Sí |  |
| otorgado_por | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| fecha_registro | `datetime` | No | No | Sí |  |
| fecha_actualizacion | `datetime` | No | No | Sí |  |

### `login_attempts` → `SGS_LOGIN_INTENTO`

Control de intentos fallidos de inicio de sesión

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| fingerprint | `char(64)` | Sí | No | Sí | Identificador |
| attempts | `smallint(5) unsigned` | No | No | Sí |  |
| first_attempt_at | `datetime` | No | No | Sí |  |
| last_attempt_at | `datetime` | No | No | Sí |  |
| blocked_until | `datetime` | No | No | No |  |

### `supervisiones` → `SGS_SUPERVISION`

Supervisión de campo (tabla principal)

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| codigo_supervision | `varchar(30)` | No | No | Sí |  |
| fecha_registro | `datetime` | No | No | No |  |
| supervisor_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| asignado_por | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| fecha_asignacion | `datetime` | No | No | No |  |
| estado_atencion_partner | `enum('Pendiente','Atendida')` | No | No | Sí |  |
| observacion_atencion_partner | `text` | No | No | No |  |
| fecha_atencion_partner | `datetime` | No | No | No |  |
| zona_asignacion | `varchar(100)` | No | No | No |  |
| derivacion_orden | `enum('Autogestión','Mesa de supervisión','Líder','Coordinador','Gerente')` | No | No | Sí |  |
| tipo_servicio | `enum('Regular','ZAV')` | No | No | Sí |  |
| departamento | `varchar(100)` | No | No | Sí |  |
| provincia | `varchar(100)` | No | No | Sí |  |
| distrito | `varchar(100)` | No | No | Sí |  |
| cliente_nombre | `varchar(200)` | No | No | No |  |
| cliente_dni | `varchar(12)` | No | No | No |  |
| ticket_codigo | `varchar(50)` | No | No | No |  |
| fecha_cancelacion | `date` | No | No | No |  |
| tipo_supervision_inicial | `enum('En caliente','En frío')` | No | No | Sí |  |
| partner_id | `int(11)` | No | Sí | No | Relación con `partners` (SGS_PARTNER) |
| tecnico1_nombre | `varchar(200)` | No | No | No |  |
| tecnico1_dni | `varchar(8)` | No | No | No |  |
| tecnico2_nombre | `varchar(200)` | No | No | No |  |
| tecnico2_dni | `varchar(8)` | No | No | No |  |
| brevete_poseedor | `enum('Tecnico 1','Tecnico 2','Ninguno')` | No | No | No |  |
| unidad_tipo | `enum('Minivan','Auto','Moto','Otro')` | No | No | No |  |
| unidad_placa | `varchar(10)` | No | No | No |  |
| unidad_soat | `enum('Vigente','No vigente')` | No | No | No |  |
| unidad_revision_tecnica | `enum('Vigente','No vigente')` | No | No | No |  |
| unidad_estado_conservacion | `enum('Mal estado','Regular','Buen estado')` | No | No | No |  |
| clasificacion_supervision | `varchar(100)` | No | No | Sí |  |
| area_servicio | `varchar(100)` | No | No | Sí |  |
| sub_tipo_atencion | `varchar(150)` | No | No | No |  |
| observacion_servicio | `text` | No | No | No |  |
| estado_servicio_caliente | `enum('Finalizada','Cancelada')` | No | No | No |  |
| es_cancelacion | `tinyint(1)` | No | No | No |  |
| origen_importacion | `tinyint(1)` | No | No | Sí |  |
| observacion_previa | `text` | No | No | No |  |
| estado_cancelacion | `varchar(60)` | No | No | No |  |
| tipificacion_cancelacion | `varchar(200)` | No | No | No |  |
| tipificacion_tecnico_correcta | `tinyint(1)` | No | No | No |  |
| tipificacion_corregida_supervisor | `varchar(200)` | No | No | No |  |
| estado_supervision | `enum('Pendiente','Iniciada','En proceso','Finalizada')` | No | No | Sí |  |
| latitud | `varchar(50)` | No | No | No |  |
| longitud | `varchar(50)` | No | No | No |  |
| foto_servicio_1 | `varchar(255)` | No | No | No |  |
| foto_servicio_comentario_1 | `varchar(500)` | No | No | No |  |
| foto_servicio_2 | `varchar(255)` | No | No | No |  |
| foto_servicio_comentario_2 | `varchar(500)` | No | No | No |  |
| foto_servicio_3 | `varchar(255)` | No | No | No |  |
| foto_servicio_comentario_3 | `varchar(500)` | No | No | No |  |
| tecnico1_id | `int(11)` | No | Sí | No | Relación con `tecnicos` (SGS_TECNICO) |
| tecnico2_id | `int(11)` | No | Sí | No | Relación con `tecnicos` (SGS_TECNICO) |
| vehiculo_id | `int(11)` | No | Sí | No | Relación con `vehiculos` (SGS_VEHICULO) |

### `supervision_coordenadas_historial` → `SGS_SUPERVISION_COORDENADA`

Historial de coordenadas GPS de una supervisión

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `bigint(20) unsigned` | Sí | No | Sí | Identificador |
| supervision_id | `int(11)` | No | Sí | Sí | Relación con `supervisiones` (SGS_SUPERVISION) |
| latitud_anterior | `varchar(50)` | No | No | No |  |
| longitud_anterior | `varchar(50)` | No | No | No |  |
| latitud_nueva | `varchar(50)` | No | No | No |  |
| longitud_nueva | `varchar(50)` | No | No | No |  |
| cambiado_por | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| fecha_registro | `datetime` | No | No | Sí |  |

### `checklists_tecnicos` → `SGS_CHECKLIST`

Checklist aplicado a un técnico en una supervisión

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| supervision_id | `int(11)` | No | Sí | No | Relación con `supervisiones` (SGS_SUPERVISION) |
| supervisor_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| tecnico_nombre | `varchar(200)` | No | No | Sí |  |
| tecnico_dni | `varchar(12)` | No | No | Sí |  |
| unidad_tipo | `varchar(30)` | No | No | Sí |  |
| unidad_placa | `varchar(15)` | No | No | Sí |  |
| brevete_poseedor | `varchar(200)` | No | No | No |  |
| observacion_general | `text` | No | No | No |  |
| firma_supervisor | `varchar(255)` | No | No | No |  |
| respuesta_general_partner | `text` | No | No | No |  |
| fecha_respuesta_partner | `datetime` | No | No | No |  |
| respondido_por_partner | `int(11)` | No | No | No |  |
| estado_respuesta | `enum('Pendiente','En revisión','Validado','Penalizado','Reabierto')` | No | No | Sí |  |
| sustentacion_evaluacion | `text` | No | No | No |  |
| fecha_evaluacion | `datetime` | No | No | No |  |
| evaluado_por | `int(11)` | No | No | No |  |
| fecha_registro | `datetime` | No | No | No |  |
| fecha_limite_respuesta | `datetime` | No | No | No |  |

### `checklist_respuestas` → `SGS_CHECKLIST_RESPUESTA`

Respuestas de cada ítem del checklist

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| checklist_id | `int(11)` | No | Sí | Sí | Relación con `checklists_tecnicos` (SGS_CHECKLIST) |
| criterio | `varchar(255)` | No | No | Sí |  |
| estado | `enum('Bueno','Regular','Malo','No tiene','S├¡','No','Para cambio','No revisado','Mal estado')` | No | No | Sí |  |
| observacion | `varchar(500)` | No | No | No |  |
| foto_evidencia | `varchar(255)` | No | No | No |  |
| descargo_foto | `varchar(255)` | No | No | No |  |
| descargo_comentario | `varchar(500)` | No | No | No |  |

### `observaciones_supervision` → `SGS_OBSERVACION`

Observaciones (hallazgos) y descargos del partner

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| supervision_id | `int(11)` | No | Sí | Sí | Relación con `supervisiones` (SGS_SUPERVISION) |
| creado_por | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| gravedad | `enum('Leve','Media','Grave','Muy Grave')` | No | No | Sí |  |
| categoria_id | `int(11)` | No | Sí | Sí | Relación con `categorias_observacion` (SGS_CATEGORIA_OBSERVACION) |
| subopcion_id | `int(11)` | No | Sí | Sí | Relación con `subopciones_observacion` (SGS_SUBOPCION_OBSERVACION) |
| detalle_observacion | `text` | No | No | Sí |  |
| foto_1 | `varchar(255)` | No | No | No |  |
| foto_comentario_1 | `varchar(500)` | No | No | No |  |
| foto_2 | `varchar(255)` | No | No | No |  |
| foto_comentario_2 | `varchar(500)` | No | No | No |  |
| foto_3 | `varchar(255)` | No | No | No |  |
| foto_comentario_3 | `varchar(500)` | No | No | No |  |
| fecha_registro | `datetime` | No | No | No |  |
| fecha_limite_respuesta | `datetime` | No | No | Sí |  |
| plazo_subsanacion_dias | `tinyint(3) unsigned` | No | No | Sí |  |
| subsanacion_inmediata | `tinyint(1)` | No | No | Sí |  |
| estado_respuesta | `enum('Pendiente','Respondido','En revisión','Penalidad Directa','Apelación Aprobada','Apelación Rechazada','Levantamiento validado','Levantamiento rechazado')` | No | No | No |  |
| respuesta_partner | `text` | No | No | No |  |
| descargo_foto_1 | `varchar(255)` | No | No | No |  |
| descargo_comentario_1 | `varchar(500)` | No | No | No |  |
| descargo_foto_2 | `varchar(255)` | No | No | No |  |
| descargo_comentario_2 | `varchar(500)` | No | No | No |  |
| descargo_foto_3 | `varchar(255)` | No | No | No |  |
| descargo_comentario_3 | `varchar(500)` | No | No | No |  |
| fecha_respuesta_partner | `datetime` | No | No | No |  |
| respondido_por_partner | `int(11)` | No | No | No |  |
| evaluacion_supervisor | `varchar(50)` | No | No | No |  |
| sustentacion_evaluacion | `text` | No | No | No |  |
| fecha_evaluacion | `datetime` | No | No | No |  |
| evaluado_por | `int(11)` | No | No | No |  |

### `categorias_observacion` → `SGS_CATEGORIA_OBSERVACION`

Catálogo de categorías de observación

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| nombre_categoria | `varchar(100)` | No | No | Sí |  |

### `subopciones_observacion` → `SGS_SUBOPCION_OBSERVACION`

Catálogo de subopciones por categoría

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| categoria_id | `int(11)` | No | Sí | Sí | Relación con `categorias_observacion` (SGS_CATEGORIA_OBSERVACION) |
| descripcion_subopcion | `varchar(200)` | No | No | Sí |  |
| gravedad | `enum('Media','Grave','Muy Grave')` | No | No | Sí |  |
| penalidad_monto | `decimal(10,2)` | No | No | Sí |  |
| accion_adicional | `varchar(255)` | No | No | No |  |

### `tipificaciones_cancelacion` → `SGS_TIPIFICACION_CANCELACION`

Catálogo de motivos de cancelación

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| descripcion | `varchar(200)` | No | No | Sí |  |
| estado | `tinyint(1)` | No | No | Sí |  |
| fecha_registro | `datetime` | No | No | Sí |  |

### `tecnicos` → `SGS_TECNICO`

Técnicos de las cuadrillas

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| nombre_tecnico | `varchar(255)` | No | No | Sí |  |
| dni | `varchar(20)` | No | No | No |  |
| tipo_tecnico | `tinyint(1)` | No | No | No |  |
| celular | `varchar(15)` | No | No | No |  |
| observacion | `text` | No | No | No |  |
| estado_operativo | `enum('Habilitado','Inhabilitado','Blacklist')` | No | No | Sí |  |
| fecha_registro | `datetime` | No | No | No |  |
| fecha_actualizacion | `datetime` | No | No | No |  |
| partner_id | `int(11)` | No | Sí | No | Relación con `partners` (SGS_PARTNER) |
| usuario_id | `int(11)` | No | Sí | No | Relación con `usuarios` (SGS_USUARIO) |
| vehiculo_id | `int(11)` | No | Sí | No | Relación con `vehiculos` (SGS_VEHICULO) |

### `vehiculos` → `SGS_VEHICULO`

Unidades vehiculares de las cuadrillas

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| placa | `varchar(10)` | No | No | Sí |  |
| tipo | `enum('Minivan','Auto','Moto','Otro')` | No | No | No |  |
| marca | `varchar(100)` | No | No | No |  |
| modelo | `varchar(100)` | No | No | No |  |
| anio | `smallint(5) unsigned` | No | No | No |  |
| soat | `enum('Vigente','No vigente')` | No | No | No |  |
| soat_vencimiento | `date` | No | No | No |  |
| revision_tecnica | `enum('Vigente','No vigente')` | No | No | No |  |
| revision_tecnica_vencimiento | `date` | No | No | No |  |
| extintor_vencimiento | `date` | No | No | No |  |
| fecha_registro | `datetime` | No | No | Sí |  |
| fecha_actualizacion | `datetime` | No | No | No |  |

### `ubigeo` → `SGS_UBIGEO`

Departamento / provincia / distrito

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| departamento | `varchar(100)` | No | No | Sí |  |
| provincia | `varchar(100)` | No | No | Sí |  |
| distrito | `varchar(100)` | No | No | Sí |  |

### `asistencia_supervisores` → `SGS_ASISTENCIA`

Turno diario del supervisor (lo usa la línea de tiempo)

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| supervisor_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| fecha | `date` | No | No | Sí |  |
| inicio_turno | `datetime` | No | No | Sí |  |
| fin_turno | `datetime` | No | No | No |  |
| horas_trabajadas | `decimal(5,2)` | No | No | No |  |
| horas_almuerzo | `decimal(4,2)` | No | No | No |  |
| horas_extras | `decimal(5,2)` | No | No | No |  |
| en_capacitacion | `tinyint(1)` | No | No | No |  |
| estado_turno | `enum('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento')` | No | No | No |  |
| fecha_registro | `datetime` | No | No | No |  |
| latitud_inicio | `varchar(50)` | No | No | No |  |
| longitud_inicio | `varchar(50)` | No | No | No |  |
| latitud_fin | `varchar(50)` | No | No | No |  |
| longitud_fin | `varchar(50)` | No | No | No |  |
| observaciones | `text` | No | No | No |  |

### `asistencia_eventos` → `SGS_ASISTENCIA_EVENTO`

Eventos del turno (pausas, reuniones...)

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| asistencia_id | `int(11)` | No | Sí | Sí | Relación con `asistencia_supervisores` (SGS_ASISTENCIA) |
| tipo_evento | `enum('almuerzo','reunion','capacitacion','campo')` | No | No | Sí |  |
| inicio_evento | `datetime` | No | No | Sí |  |
| fin_evento | `datetime` | No | No | No |  |
| duracion_minutos | `int(11)` | No | No | No |  |
| nota | `varchar(255)` | No | No | No |  |
| latitud | `varchar(50)` | No | No | No |  |
| longitud | `varchar(50)` | No | No | No |  |
| created_at | `datetime` | No | No | No |  |

### `notificaciones` → `SGS_NOTIFICACION`

Notificaciones a usuarios

| Campo | Tipo | PK | FK | Oblig. | Descripción |
|---|---|---|---|---|---|
| id | `int(11)` | Sí | No | Sí | Identificador |
| usuario_id | `int(11)` | No | Sí | Sí | Relación con `usuarios` (SGS_USUARIO) |
| observacion_id | `int(11)` | No | Sí | No | Relación con `observaciones_supervision` (SGS_OBSERVACION) |
| titulo | `varchar(160)` | No | No | Sí |  |
| mensaje | `varchar(500)` | No | No | Sí |  |
| url_destino | `varchar(255)` | No | No | No |  |
| leida | `tinyint(1)` | No | No | Sí |  |
| fecha_registro | `datetime` | No | No | Sí |  |
| fecha_lectura | `datetime` | No | No | No |  |
