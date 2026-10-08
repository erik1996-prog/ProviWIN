-- 04_DATABASE/03_ESTRUCTURA/esquema_completo.sql
-- Estructura de SGS: 23 tablas en orden de dependencias, sin datos ni DROP.
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08. No editar a mano: los cambios van como migración en 02_CAMBIOS.

SET NAMES utf8mb4;

-- Tabla `categorias_observacion`: categorías de observación
CREATE TABLE IF NOT EXISTS `categorias_observacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_categoria` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `login_attempts`: intentos de ingreso (bloqueo a los 3 intentos)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `fingerprint` char(64) NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 1,
  `first_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`fingerprint`),
  KEY `idx_login_attempt_expiry` (`last_attempt_at`,`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `partners`: contratas (incluye WIN DIRECTO, necesario para el personal propio)
CREATE TABLE IF NOT EXISTS `partners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_partner` varchar(150) NOT NULL,
  `ruc` varchar(11) DEFAULT NULL,
  `estado` tinyint(1) DEFAULT 1,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `sgs_migracion`: migraciones aplicadas (empieza vacía: la instalación ya trae la estructura al día)
CREATE TABLE IF NOT EXISTS `sgs_migracion` (
  `ARCHIVO` varchar(120) NOT NULL,
  `FECHA_APLICACION` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ARCHIVO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `tipificaciones_cancelacion`: motivos de cancelación
CREATE TABLE IF NOT EXISTS `tipificaciones_cancelacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(200) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `descripcion` (`descripcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `ubigeo`: departamentos, provincias y distritos de operación
CREATE TABLE IF NOT EXISTS `ubigeo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `departamento` varchar(100) NOT NULL,
  `provincia` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ubigeo_departamento_provincia_distrito` (`departamento`,`provincia`,`distrito`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `vehiculos`: vehículos de las cuadrillas
CREATE TABLE IF NOT EXISTS `vehiculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `placa` varchar(10) NOT NULL,
  `tipo` enum('Minivan','Auto','Moto','Otro') DEFAULT NULL,
  `marca` varchar(100) DEFAULT NULL,
  `modelo` varchar(100) DEFAULT NULL,
  `anio` smallint(5) unsigned DEFAULT NULL,
  `soat` enum('Vigente','No vigente') DEFAULT NULL,
  `soat_vencimiento` date DEFAULT NULL,
  `revision_tecnica` enum('Vigente','No vigente') DEFAULT NULL,
  `revision_tecnica_vencimiento` date DEFAULT NULL,
  `extintor_vencimiento` date DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `placa` (`placa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `subopciones_observacion`: tipificaciones con gravedad y penalidad (depende de: categorias_observacion)
CREATE TABLE IF NOT EXISTS `subopciones_observacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `descripcion_subopcion` varchar(200) NOT NULL,
  `gravedad` enum('Media','Grave','Muy Grave') NOT NULL DEFAULT 'Grave',
  `penalidad_monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `accion_adicional` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categoria_id` (`categoria_id`),
  CONSTRAINT `subopciones_observacion_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_observacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `usuarios`: usuarios del sistema (depende de: partners)
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dni` varchar(8) NOT NULL,
  `nombres` varchar(100) DEFAULT NULL,
  `apellidos` varchar(100) DEFAULT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `nombre_completo` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telefono_personal` varchar(20) DEFAULT NULL,
  `telefono_corporativo` varchar(20) DEFAULT NULL,
  `turno` varchar(30) DEFAULT NULL,
  `modalidad` varchar(30) DEFAULT NULL,
  `sede` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password_admin_encrypted` text DEFAULT NULL,
  `rol` enum('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner') NOT NULL DEFAULT 'Supervisor',
  `area_trabajo` varchar(100) DEFAULT NULL,
  `zona` varchar(100) DEFAULT 'Provincias',
  `procesos_copiados` text DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `avatar` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dni` (`dni`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_usuario` (`usuario`),
  KEY `partner_id` (`partner_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `asistencia_supervisores`: inicios y cierres de turno (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `asistencia_supervisores` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervisor_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `inicio_turno` datetime NOT NULL,
  `fin_turno` datetime DEFAULT NULL,
  `horas_trabajadas` decimal(5,2) DEFAULT 0.00,
  `horas_almuerzo` decimal(4,2) DEFAULT 1.00,
  `horas_extras` decimal(5,2) DEFAULT 0.00,
  `en_capacitacion` tinyint(1) DEFAULT 0,
  `estado_turno` enum('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento') DEFAULT 'En turno',
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `latitud_inicio` varchar(50) DEFAULT NULL,
  `longitud_inicio` varchar(50) DEFAULT NULL,
  `latitud_fin` varchar(50) DEFAULT NULL,
  `longitud_fin` varchar(50) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asistencia_supervisor_fecha` (`supervisor_id`,`fecha`),
  KEY `IX_ASISTENCIA_FECHA` (`fecha`),
  CONSTRAINT `asistencia_supervisores_ibfk_1` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `sgs_perfil`: perfiles de acceso (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `sgs_perfil` (
  `PERFIL_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `NOMBRE` varchar(80) NOT NULL,
  `DESCRIPCION` varchar(255) DEFAULT NULL,
  `ROL_BASE` varchar(30) NOT NULL,
  `PERMISOS` text NOT NULL COMMENT 'JSON: lista de claves de permiso habilitadas',
  `ACTIVO` tinyint(1) NOT NULL DEFAULT 1,
  `CREADO_POR` int(11) DEFAULT NULL,
  `FECHA_CREACION` datetime NOT NULL DEFAULT current_timestamp(),
  `FECHA_ACTUALIZACION` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PERFIL_ID`),
  UNIQUE KEY `UQ_SGS_PERFIL_NOMBRE` (`NOMBRE`),
  KEY `FK_SGS_PERFIL_CREADO_POR` (`CREADO_POR`),
  CONSTRAINT `FK_SGS_PERFIL_CREADO_POR` FOREIGN KEY (`CREADO_POR`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `tecnicos`: técnicos líderes (T1) y de apoyo (T2) por partner (depende de: partners, usuarios, vehiculos)
CREATE TABLE IF NOT EXISTS `tecnicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_tecnico` varchar(255) NOT NULL,
  `dni` varchar(20) DEFAULT NULL,
  `tipo_tecnico` tinyint(1) DEFAULT 1,
  `celular` varchar(15) DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `estado_operativo` enum('Habilitado','Inhabilitado','Blacklist') NOT NULL DEFAULT 'Habilitado',
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `partner_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `vehiculo_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre_tecnico` (`nombre_tecnico`),
  UNIQUE KEY `uq_tecnicos_dni` (`dni`),
  UNIQUE KEY `uq_tecnico_usuario` (`usuario_id`),
  KEY `idx_tecnicos_estado_tipo` (`estado_operativo`,`tipo_tecnico`),
  KEY `fk_tecnico_partner` (`partner_id`),
  KEY `fk_tecnico_vehiculo` (`vehiculo_id`),
  CONSTRAINT `fk_tecnico_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`),
  CONSTRAINT `fk_tecnico_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tecnico_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `usuario_departamentos_acceso`: zonas adicionales de cada usuario (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `usuario_departamentos_acceso` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `departamento` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `otorgado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_departamento_acceso` (`usuario_id`,`departamento`),
  KEY `idx_acceso_departamento_activo` (`departamento`,`activo`),
  KEY `fk_acceso_departamento_otorgante` (`otorgado_por`),
  CONSTRAINT `fk_acceso_departamento_otorgante` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_acceso_departamento_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `usuario_permisos`: permisos personalizados por usuario (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `usuario_permisos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `permiso` varchar(100) NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT 1,
  `otorgado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_permiso` (`usuario_id`,`permiso`),
  KEY `idx_usuario_permisos_consulta` (`usuario_id`,`permitido`),
  KEY `fk_usuario_permisos_otorgante` (`otorgado_por`),
  CONSTRAINT `fk_usuario_permisos_otorgante` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuario_permisos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `asistencia_eventos`: pausas del turno (almuerzo, reunión) (depende de: asistencia_supervisores)
CREATE TABLE IF NOT EXISTS `asistencia_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asistencia_id` int(11) NOT NULL,
  `tipo_evento` enum('almuerzo','reunion','capacitacion','campo') NOT NULL,
  `inicio_evento` datetime NOT NULL,
  `fin_evento` datetime DEFAULT NULL,
  `duracion_minutos` int(11) DEFAULT 0,
  `nota` varchar(255) DEFAULT NULL,
  `latitud` varchar(50) DEFAULT NULL,
  `longitud` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asistencia_id` (`asistencia_id`),
  KEY `idx_evento_asistencia_inicio` (`asistencia_id`,`inicio_evento`),
  CONSTRAINT `asistencia_eventos_ibfk_1` FOREIGN KEY (`asistencia_id`) REFERENCES `asistencia_supervisores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `sgs_usuario_perfil`: perfil asignado a cada usuario (depende de: usuarios, sgs_perfil)
CREATE TABLE IF NOT EXISTS `sgs_usuario_perfil` (
  `USUARIO_ID` int(11) NOT NULL,
  `PERFIL_ID` int(10) unsigned NOT NULL,
  `ASIGNADO_POR` int(11) DEFAULT NULL,
  `FECHA_ASIGNACION` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`USUARIO_ID`),
  KEY `FK_SGS_USUARIO_PERFIL_ASIGNADO` (`ASIGNADO_POR`),
  KEY `IX_SGS_USUARIO_PERFIL_PERFIL_001` (`PERFIL_ID`),
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_ASIGNADO` FOREIGN KEY (`ASIGNADO_POR`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_PERFIL` FOREIGN KEY (`PERFIL_ID`) REFERENCES `sgs_perfil` (`PERFIL_ID`) ON DELETE CASCADE,
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_USUARIO` FOREIGN KEY (`USUARIO_ID`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `supervisiones`: supervisiones (depende de: tipificaciones_cancelacion, usuarios, tecnicos, vehiculos, partners)
CREATE TABLE IF NOT EXISTS `supervisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_supervision` varchar(30) NOT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `supervisor_id` int(11) NOT NULL,
  `asignado_por` int(11) DEFAULT NULL,
  `fecha_asignacion` datetime DEFAULT NULL,
  `estado_atencion_partner` enum('Pendiente','Atendida') NOT NULL DEFAULT 'Pendiente',
  `observacion_atencion_partner` text DEFAULT NULL,
  `fecha_atencion_partner` datetime DEFAULT NULL,
  `zona_asignacion` varchar(100) DEFAULT NULL,
  `derivacion_orden` enum('Autogestión','Mesa de supervisión','Líder','Coordinador','Gerente') NOT NULL,
  `tipo_servicio` enum('Regular','ZAV') NOT NULL DEFAULT 'Regular',
  `departamento` varchar(100) NOT NULL,
  `provincia` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  `cliente_nombre` varchar(200) DEFAULT NULL,
  `cliente_dni` varchar(12) DEFAULT NULL,
  `ticket_codigo` varchar(50) DEFAULT NULL,
  `fecha_cancelacion` date DEFAULT NULL,
  `tipo_supervision_inicial` enum('En caliente','En frío') NOT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `tecnico1_nombre` varchar(200) DEFAULT NULL,
  `tecnico1_dni` varchar(8) DEFAULT NULL,
  `tecnico2_nombre` varchar(200) DEFAULT NULL,
  `tecnico2_dni` varchar(8) DEFAULT NULL,
  `brevete_poseedor` enum('Tecnico 1','Tecnico 2','Ninguno') DEFAULT 'Ninguno',
  `unidad_tipo` enum('Minivan','Auto','Moto','Otro') DEFAULT NULL,
  `unidad_placa` varchar(10) DEFAULT NULL,
  `unidad_soat` enum('Vigente','No vigente') DEFAULT NULL,
  `unidad_revision_tecnica` enum('Vigente','No vigente') DEFAULT NULL,
  `unidad_estado_conservacion` enum('Mal estado','Regular','Buen estado') DEFAULT 'Buen estado',
  `clasificacion_supervision` varchar(100) NOT NULL,
  `area_servicio` varchar(100) NOT NULL,
  `sub_tipo_atencion` varchar(150) DEFAULT NULL,
  `observacion_servicio` text DEFAULT NULL,
  `estado_servicio_caliente` enum('Finalizada','Cancelada') DEFAULT NULL,
  `es_cancelacion` tinyint(1) DEFAULT 0,
  `origen_importacion` tinyint(1) NOT NULL DEFAULT 0,
  `observacion_previa` text DEFAULT NULL,
  `estado_cancelacion` varchar(60) DEFAULT NULL,
  `tipificacion_cancelacion` varchar(200) DEFAULT NULL,
  `tipificacion_tecnico_correcta` tinyint(1) DEFAULT NULL,
  `tipificacion_corregida_supervisor` varchar(200) DEFAULT NULL,
  `estado_supervision` enum('Pendiente','Iniciada','En proceso','Finalizada') NOT NULL DEFAULT 'Iniciada',
  `latitud` varchar(50) DEFAULT NULL,
  `longitud` varchar(50) DEFAULT NULL,
  `foto_servicio_1` varchar(255) DEFAULT NULL,
  `foto_servicio_comentario_1` varchar(500) DEFAULT NULL,
  `foto_servicio_2` varchar(255) DEFAULT NULL,
  `foto_servicio_comentario_2` varchar(500) DEFAULT NULL,
  `foto_servicio_3` varchar(255) DEFAULT NULL,
  `foto_servicio_comentario_3` varchar(500) DEFAULT NULL,
  `tecnico1_id` int(11) DEFAULT NULL,
  `tecnico2_id` int(11) DEFAULT NULL,
  `vehiculo_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_supervision` (`codigo_supervision`),
  KEY `partner_id` (`partner_id`),
  KEY `idx_filtro_sup_fecha` (`supervisor_id`,`fecha_registro`),
  KEY `idx_supervision_asignador` (`asignado_por`),
  KEY `idx_supervision_zona_pendiente` (`zona_asignacion`,`partner_id`,`estado_supervision`),
  KEY `idx_supervisiones_ticket` (`ticket_codigo`),
  KEY `idx_supervisiones_estado_fecha` (`estado_supervision`,`fecha_registro`),
  KEY `idx_supervisiones_partner_estado_fecha` (`partner_id`,`estado_supervision`,`fecha_registro`),
  KEY `idx_supervisiones_ubicacion` (`departamento`,`provincia`,`distrito`),
  KEY `fk_supervision_tecnico1` (`tecnico1_id`),
  KEY `fk_supervision_tecnico2` (`tecnico2_id`),
  KEY `fk_supervision_vehiculo` (`vehiculo_id`),
  KEY `idx_supervision_estado_cancelacion` (`es_cancelacion`,`estado_cancelacion`,`estado_supervision`),
  KEY `idx_sup_fecha_estado` (`fecha_registro`,`estado_supervision`),
  KEY `idx_sup_partner_fecha` (`partner_id`,`fecha_registro`),
  KEY `idx_atencion_partner_plazo` (`supervisor_id`,`estado_atencion_partner`,`fecha_asignacion`),
  KEY `FK_SUPERVISION_TIPIFICACION` (`tipificacion_cancelacion`),
  KEY `FK_SUPERVISION_TIPIFICACION_CORREGIDA` (`tipificacion_corregida_supervisor`),
  CONSTRAINT `FK_SUPERVISION_TIPIFICACION` FOREIGN KEY (`tipificacion_cancelacion`) REFERENCES `tipificaciones_cancelacion` (`descripcion`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `FK_SUPERVISION_TIPIFICACION_CORREGIDA` FOREIGN KEY (`tipificacion_corregida_supervisor`) REFERENCES `tipificaciones_cancelacion` (`descripcion`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supervision_asignador` FOREIGN KEY (`asignado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supervision_tecnico1` FOREIGN KEY (`tecnico1_id`) REFERENCES `tecnicos` (`id`),
  CONSTRAINT `fk_supervision_tecnico2` FOREIGN KEY (`tecnico2_id`) REFERENCES `tecnicos` (`id`),
  CONSTRAINT `fk_supervision_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`),
  CONSTRAINT `supervisiones_ibfk_1` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `supervisiones_ibfk_2` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `checklists_tecnicos`: checklists de cuadrilla (depende de: supervisiones, usuarios)
CREATE TABLE IF NOT EXISTS `checklists_tecnicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) NOT NULL,
  `tecnico_nombre` varchar(200) NOT NULL,
  `tecnico_dni` varchar(12) NOT NULL,
  `unidad_tipo` varchar(30) NOT NULL,
  `unidad_placa` varchar(15) NOT NULL,
  `brevete_poseedor` varchar(200) DEFAULT NULL,
  `observacion_general` text DEFAULT NULL,
  `firma_supervisor` varchar(255) DEFAULT NULL,
  `respuesta_general_partner` text DEFAULT NULL,
  `fecha_respuesta_partner` datetime DEFAULT NULL,
  `respondido_por_partner` int(11) DEFAULT NULL,
  `estado_respuesta` enum('Pendiente','En revisión','Validado','Penalizado','Reabierto') NOT NULL DEFAULT 'Pendiente',
  `sustentacion_evaluacion` text DEFAULT NULL,
  `fecha_evaluacion` datetime DEFAULT NULL,
  `evaluado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_limite_respuesta` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supervision_id` (`supervision_id`),
  KEY `supervisor_id` (`supervisor_id`),
  KEY `idx_checklist_fecha` (`fecha_registro`),
  CONSTRAINT `checklists_tecnicos_ibfk_1` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `checklists_tecnicos_ibfk_2` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `observaciones_supervision`: observaciones en campo (depende de: usuarios, supervisiones, categorias_observacion, subopciones_observacion)
CREATE TABLE IF NOT EXISTS `observaciones_supervision` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `creado_por` int(11) DEFAULT NULL,
  `gravedad` enum('Leve','Media','Grave','Muy Grave') NOT NULL DEFAULT 'Grave',
  `categoria_id` int(11) NOT NULL,
  `subopcion_id` int(11) NOT NULL,
  `detalle_observacion` text NOT NULL,
  `foto_1` varchar(255) DEFAULT NULL,
  `foto_comentario_1` varchar(500) DEFAULT NULL,
  `foto_2` varchar(255) DEFAULT NULL,
  `foto_comentario_2` varchar(500) DEFAULT NULL,
  `foto_3` varchar(255) DEFAULT NULL,
  `foto_comentario_3` varchar(500) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_limite_respuesta` datetime NOT NULL,
  `plazo_subsanacion_dias` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `subsanacion_inmediata` tinyint(1) NOT NULL DEFAULT 0,
  `estado_respuesta` enum('Pendiente','Respondido','En revisión','Penalidad Directa','Apelación Aprobada','Apelación Rechazada','Levantamiento validado','Levantamiento rechazado') DEFAULT 'Pendiente',
  `respuesta_partner` text DEFAULT NULL,
  `descargo_foto_1` varchar(255) DEFAULT NULL,
  `descargo_comentario_1` varchar(500) DEFAULT NULL,
  `descargo_foto_2` varchar(255) DEFAULT NULL,
  `descargo_comentario_2` varchar(500) DEFAULT NULL,
  `descargo_foto_3` varchar(255) DEFAULT NULL,
  `descargo_comentario_3` varchar(500) DEFAULT NULL,
  `fecha_respuesta_partner` datetime DEFAULT NULL,
  `respondido_por_partner` int(11) DEFAULT NULL,
  `evaluacion_supervisor` varchar(50) DEFAULT NULL,
  `sustentacion_evaluacion` text DEFAULT NULL,
  `fecha_evaluacion` datetime DEFAULT NULL,
  `evaluado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supervision_id` (`supervision_id`),
  KEY `categoria_id` (`categoria_id`),
  KEY `subopcion_id` (`subopcion_id`),
  KEY `idx_obs_sla` (`estado_respuesta`,`fecha_limite_respuesta`),
  KEY `idx_observaciones_creador_fecha` (`creado_por`,`fecha_registro`),
  KEY `idx_observaciones_supervision_estado` (`supervision_id`,`estado_respuesta`),
  CONSTRAINT `fk_observacion_creador` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `observaciones_supervision_ibfk_1` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `observaciones_supervision_ibfk_2` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_observacion` (`id`),
  CONSTRAINT `observaciones_supervision_ibfk_3` FOREIGN KEY (`subopcion_id`) REFERENCES `subopciones_observacion` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `supervision_coordenadas_historial`: ubicaciones GPS registradas (depende de: supervisiones, usuarios)
CREATE TABLE IF NOT EXISTS `supervision_coordenadas_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `latitud_anterior` varchar(50) DEFAULT NULL,
  `longitud_anterior` varchar(50) DEFAULT NULL,
  `latitud_nueva` varchar(50) DEFAULT NULL,
  `longitud_nueva` varchar(50) DEFAULT NULL,
  `cambiado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_coord_hist_usuario` (`cambiado_por`),
  KEY `idx_coord_hist_supervision_fecha` (`supervision_id`,`fecha_registro`),
  CONSTRAINT `fk_coord_hist_supervision` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coord_hist_usuario` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `supervision_estado_historial`: cambios de estado de cada supervisión (depende de: supervisiones, usuarios)
CREATE TABLE IF NOT EXISTS `supervision_estado_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `estado_anterior` varchar(40) DEFAULT NULL,
  `estado_nuevo` varchar(40) NOT NULL,
  `cambiado_por` int(11) DEFAULT NULL,
  `comentario` varchar(500) DEFAULT NULL,
  `fecha_cambio` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_historial_supervision_fecha` (`supervision_id`,`fecha_cambio`),
  KEY `idx_historial_usuario_fecha` (`cambiado_por`,`fecha_cambio`),
  CONSTRAINT `fk_historial_supervision` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `checklist_respuestas`: criterios de cada checklist (depende de: checklists_tecnicos)
CREATE TABLE IF NOT EXISTS `checklist_respuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `criterio` varchar(255) NOT NULL,
  `estado` enum('Bueno','Regular','Malo','No tiene','S├¡','No','Para cambio','No revisado','Mal estado') NOT NULL,
  `observacion` varchar(500) DEFAULT NULL,
  `foto_evidencia` varchar(255) DEFAULT NULL,
  `descargo_foto` varchar(255) DEFAULT NULL,
  `descargo_comentario` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checklist_respuesta_estado` (`checklist_id`,`estado`),
  CONSTRAINT `checklist_respuestas_ibfk_1` FOREIGN KEY (`checklist_id`) REFERENCES `checklists_tecnicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla `notificaciones`: notificaciones a usuarios (depende de: usuarios, observaciones_supervision)
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `observacion_id` int(11) DEFAULT NULL,
  `titulo` varchar(160) NOT NULL,
  `mensaje` varchar(500) NOT NULL,
  `url_destino` varchar(255) DEFAULT NULL,
  `leida` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_lectura` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `observacion_id` (`observacion_id`),
  KEY `idx_notificacion_usuario` (`usuario_id`,`leida`,`fecha_registro`),
  CONSTRAINT `notificaciones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notificaciones_ibfk_2` FOREIGN KEY (`observacion_id`) REFERENCES `observaciones_supervision` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
