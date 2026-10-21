-- 04_DATABASE/03_ESTRUCTURA/esquema_completo.sql
-- Estructura de SGS: 28 tablas en orden de dependencias, sin datos ni DROP.
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-20. No editar a mano: los cambios van como migración en 02_CAMBIOS.

SET NAMES utf8mb4;

-- Tabla `categorias_observacion`: Categorías de observación
CREATE TABLE IF NOT EXISTS `categorias_observacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_categoria` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Categorías de observación';

-- Tabla `login_attempts`: Intentos de ingreso (bloqueo a los 3 fallidos)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `fingerprint` char(64) NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 1,
  `first_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `blocked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`fingerprint`),
  KEY `idx_login_attempts_last_attempt_at_blocked_until` (`last_attempt_at`,`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Intentos de ingreso (bloqueo a los 3 fallidos)';

-- Tabla `partners`: Contratas; WIN DIRECTO = personal propio
CREATE TABLE IF NOT EXISTS `partners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_partner` varchar(150) NOT NULL,
  `ruc` varchar(11) DEFAULT NULL,
  `estado` tinyint(1) DEFAULT 1,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contratas; WIN DIRECTO = personal propio';

-- Tabla `sgs_migracion`: Cambios de base de datos ya aplicados (los registra 07_SCRIPTS/migrar.php)
CREATE TABLE IF NOT EXISTS `sgs_migracion` (
  `archivo` varchar(120) NOT NULL COMMENT 'Carpeta del cambio en 04_DATABASE/02_CAMBIOS',
  `fecha_aplicacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`archivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cambios de base de datos ya aplicados (los registra 07_SCRIPTS/migrar.php)';

-- Tabla `tipificaciones_cancelacion`: Motivos de cancelación (los del Excel de pendientes se agregan solos)
CREATE TABLE IF NOT EXISTS `tipificaciones_cancelacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(200) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tipificaciones_cancelacion_descripcion` (`descripcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Motivos de cancelación (los del Excel de pendientes se agregan solos)';

-- Tabla `ubigeo`: Departamentos, provincias y distritos de operación
CREATE TABLE IF NOT EXISTS `ubigeo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `departamento` varchar(100) NOT NULL,
  `provincia` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ubigeo_departamento_provincia_distrito` (`departamento`,`provincia`,`distrito`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Departamentos, provincias y distritos de operación';

-- Tabla `vehiculos`: Vehículos de las cuadrillas
CREATE TABLE IF NOT EXISTS `vehiculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `placa` varchar(10) NOT NULL,
  `tipo` enum('Minivan','Auto','Moto','Otro') DEFAULT NULL,
  `estado_conservacion` enum('Mal estado','Regular','Buen estado') DEFAULT NULL COMMENT 'Último estado registrado del vehículo (sale por defecto en la siguiente supervisión)',
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
  UNIQUE KEY `uq_vehiculos_placa` (`placa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vehículos de las cuadrillas';

-- Tabla `subopciones_observacion`: Tipificaciones de observación con gravedad y penalidad (depende de: categorias_observacion)
CREATE TABLE IF NOT EXISTS `subopciones_observacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `descripcion_subopcion` varchar(200) NOT NULL,
  `gravedad` enum('Media','Grave','Muy Grave') NOT NULL DEFAULT 'Grave',
  `penalidad_monto` decimal(10,2) NOT NULL DEFAULT 0.00,
  `accion_adicional` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subopciones_observacion_categoria_id` (`categoria_id`),
  CONSTRAINT `fk_subopciones_observacion_categoria_id` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_observacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tipificaciones de observación con gravedad y penalidad';

-- Tabla `usuarios`: Usuarios del sistema (personal WIN y de partners) (depende de: partners)
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
  `rol` enum('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Jefe de operaciones') NOT NULL DEFAULT 'Supervisor',
  `area_trabajo` varchar(100) DEFAULT NULL,
  `cargo` varchar(60) DEFAULT NULL COMMENT 'Puesto en la empresa (lista fija en el sistema); no da permisos',
  `zona` varchar(100) DEFAULT 'Provincias',
  `procesos_copiados` text DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_acceso` datetime DEFAULT NULL COMMENT 'Último inicio de sesión correcto en SGS',
  `debe_cambiar_password` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = al ingresar debe cambiar su contraseña (temporal)',
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `avatar` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_dni` (`dni`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  UNIQUE KEY `uq_usuarios_usuario` (`usuario`),
  KEY `idx_usuarios_partner_id` (`partner_id`),
  CONSTRAINT `fk_usuarios_partner_id` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios del sistema (personal WIN y de partners)';

-- Tabla `asistencia_supervisores`: Turnos de los supervisores: inicio, fin y horas (depende de: usuarios)
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
  `estado_turno` enum('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento','En remoto') DEFAULT 'En turno',
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `latitud_inicio` varchar(50) DEFAULT NULL,
  `longitud_inicio` varchar(50) DEFAULT NULL,
  `latitud_fin` varchar(50) DEFAULT NULL,
  `longitud_fin` varchar(50) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asistencia_supervisores_supervisor_id_fecha` (`supervisor_id`,`fecha`),
  KEY `idx_asistencia_supervisores_fecha` (`fecha`),
  CONSTRAINT `fk_asistencia_supervisores_supervisor_id` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Turnos de los supervisores: inicio, fin y horas';

-- Tabla `examen_convocatorias`: Links generales de examen (un link para un grupo de técnicos) (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `examen_convocatorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 del código del link general (el código no se guarda)',
  `titulo` varchar(150) NOT NULL DEFAULT 'Evaluación técnica',
  `examen` varchar(40) NOT NULL DEFAULT 'altura' COMMENT 'Examen que trae el link (banco de preguntas)',
  `filtro` varchar(255) DEFAULT NULL COMMENT 'Filtros de Cuadrillas con los que se armó el grupo',
  `generado_por` int(11) DEFAULT NULL COMMENT 'Usuario que generó el link',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `vence_en` datetime NOT NULL,
  `anulado_en` datetime DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_examen_convocatorias_token_hash` (`token_hash`),
  KEY `idx_examen_convocatorias_creado_en` (`creado_en`),
  KEY `fk_examen_convocatorias_generado_por` (`generado_por`),
  KEY `fk_examen_convocatorias_anulado_por` (`anulado_por`),
  CONSTRAINT `fk_examen_convocatorias_anulado_por` FOREIGN KEY (`anulado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_examen_convocatorias_generado_por` FOREIGN KEY (`generado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links generales de examen (un link para un grupo de técnicos)';

-- Tabla `lluvias_zona`: Lluvia avisada por los supervisores en su zona (línea de tiempo) (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `lluvias_zona` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `departamento` varchar(100) NOT NULL COMMENT 'Zona donde llueve',
  `inicio` datetime NOT NULL,
  `fin` datetime DEFAULT NULL COMMENT 'NULL = sigue lloviendo',
  `reportado_por` int(11) NOT NULL,
  `comentario_inicio` varchar(150) DEFAULT NULL COMMENT 'Qué hacía quien avisó la lluvia',
  `cerrado_por` int(11) DEFAULT NULL,
  `comentario_fin` varchar(150) DEFAULT NULL COMMENT 'Qué hacía quien la cerró',
  PRIMARY KEY (`id`),
  KEY `idx_lluvias_zona_departamento_inicio` (`departamento`,`inicio`),
  KEY `idx_lluvias_zona_fin` (`fin`),
  KEY `idx_lluvias_zona_reportado_por` (`reportado_por`),
  KEY `idx_lluvias_zona_cerrado_por` (`cerrado_por`),
  CONSTRAINT `fk_lluvias_zona_cerrado_por` FOREIGN KEY (`cerrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_lluvias_zona_reportado_por` FOREIGN KEY (`reportado_por`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lluvia avisada por los supervisores en su zona (línea de tiempo)';

-- Tabla `tecnicos`: Técnicos por partner: líderes (T1) y de apoyo (T2) (depende de: partners, usuarios, vehiculos)
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
  `provincia` varchar(100) DEFAULT NULL COMMENT 'Provincia de trabajo (tal como viene en el Excel de técnicos líderes)',
  `departamento` varchar(100) DEFAULT NULL COMMENT 'Zona: departamento de la provincia; filtra los técnicos en el formulario de supervisión',
  `usuario_id` int(11) DEFAULT NULL,
  `vehiculo_id` int(11) DEFAULT NULL,
  `apoyo_id` int(11) DEFAULT NULL COMMENT 'Técnico de apoyo (T2) habitual del líder: sale por defecto en la siguiente supervisión',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tecnicos_nombre_tecnico` (`nombre_tecnico`),
  UNIQUE KEY `uq_tecnicos_dni` (`dni`),
  UNIQUE KEY `uq_tecnicos_usuario_id` (`usuario_id`),
  KEY `idx_tecnicos_estado_operativo_tipo_tecnico` (`estado_operativo`,`tipo_tecnico`),
  KEY `idx_tecnicos_partner_id` (`partner_id`),
  KEY `idx_tecnicos_vehiculo_id` (`vehiculo_id`),
  KEY `fk_tecnicos_apoyo_id` (`apoyo_id`),
  KEY `idx_tecnicos_departamento` (`departamento`),
  CONSTRAINT `fk_tecnicos_apoyo_id` FOREIGN KEY (`apoyo_id`) REFERENCES `tecnicos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tecnicos_partner_id` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`),
  CONSTRAINT `fk_tecnicos_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tecnicos_vehiculo_id` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Técnicos por partner: líderes (T1) y de apoyo (T2)';

-- Tabla `usuario_departamentos_acceso`: Zonas adicionales que puede ver cada usuario (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `usuario_departamentos_acceso` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `departamento` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `otorgado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_departamentos_acceso_usuario_id_departamento` (`usuario_id`,`departamento`),
  KEY `idx_usuario_departamentos_acceso_departamento_activo` (`departamento`,`activo`),
  KEY `idx_usuario_departamentos_acceso_otorgado_por` (`otorgado_por`),
  CONSTRAINT `fk_usuario_departamentos_acceso_otorgado_por` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuario_departamentos_acceso_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Zonas adicionales que puede ver cada usuario';

-- Tabla `usuario_permisos`: Permisos personalizados por usuario (además de los de su rol) (depende de: usuarios)
CREATE TABLE IF NOT EXISTS `usuario_permisos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `permiso` varchar(100) NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT 1,
  `otorgado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_permisos_usuario_id_permiso` (`usuario_id`,`permiso`),
  KEY `idx_usuario_permisos_usuario_id_permitido` (`usuario_id`,`permitido`),
  KEY `idx_usuario_permisos_otorgado_por` (`otorgado_por`),
  CONSTRAINT `fk_usuario_permisos_otorgado_por` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuario_permisos_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permisos personalizados por usuario (además de los de su rol)';

-- Tabla `asistencia_eventos`: Pausas dentro del turno (almuerzo, reunión, capacitación) (depende de: asistencia_supervisores)
CREATE TABLE IF NOT EXISTS `asistencia_eventos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asistencia_id` int(11) NOT NULL,
  `tipo_evento` enum('almuerzo','reunion','capacitacion','campo','remoto') NOT NULL,
  `inicio_evento` datetime NOT NULL,
  `fin_evento` datetime DEFAULT NULL,
  `duracion_minutos` int(11) DEFAULT 0,
  `nota` varchar(255) DEFAULT NULL,
  `latitud` varchar(50) DEFAULT NULL,
  `longitud` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asistencia_eventos_asistencia_id` (`asistencia_id`),
  KEY `idx_asistencia_eventos_asistencia_id_inicio_evento` (`asistencia_id`,`inicio_evento`),
  CONSTRAINT `fk_asistencia_eventos_asistencia_id` FOREIGN KEY (`asistencia_id`) REFERENCES `asistencia_supervisores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pausas dentro del turno (almuerzo, reunión, capacitación)';

-- Tabla `examen_convocatoria_tecnicos`: Técnicos de cada link general de examen (depende de: examen_convocatorias, tecnicos)
CREATE TABLE IF NOT EXISTS `examen_convocatoria_tecnicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `convocatoria_id` int(11) NOT NULL,
  `tecnico_id` int(11) NOT NULL,
  `tipo` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = técnico líder (T1), 2 = técnico de apoyo (T2)',
  `ingreso_en` datetime DEFAULT NULL COMMENT 'Primera vez que entró con su DNI',
  `sesion_hash` char(64) DEFAULT NULL COMMENT 'Huella de la llave del equipo con el que rinde',
  `inicio_en` datetime DEFAULT NULL COMMENT 'Cuándo empezó a responder (corre el tiempo)',
  `preguntas` longtext DEFAULT NULL COMMENT 'Preguntas que le tocaron y orden de alternativas (JSON)',
  `respuestas` longtext DEFAULT NULL COMMENT 'Respuestas guardadas mientras rinde (JSON)',
  `usado_en` datetime DEFAULT NULL COMMENT 'Examen rendido: ya no puede volver a entrar',
  `correctas` tinyint(3) unsigned DEFAULT NULL,
  `total_preguntas` tinyint(3) unsigned DEFAULT NULL,
  `nota` decimal(5,2) DEFAULT NULL COMMENT 'Porcentaje de aciertos (0 a 100)',
  `aprobado` tinyint(1) DEFAULT NULL,
  `segundos` smallint(5) unsigned DEFAULT NULL COMMENT 'Tiempo que usó',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_examen_convocatoria_tecnico` (`convocatoria_id`,`tecnico_id`),
  KEY `idx_examen_convocatoria_tecnicos_tecnico` (`tecnico_id`),
  KEY `idx_examen_convocatoria_tecnicos_usado` (`usado_en`),
  CONSTRAINT `fk_examen_convocatoria_tecnicos_convocatoria` FOREIGN KEY (`convocatoria_id`) REFERENCES `examen_convocatorias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_examen_convocatoria_tecnicos_tecnico` FOREIGN KEY (`tecnico_id`) REFERENCES `tecnicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Técnicos de cada link general de examen';

-- Tabla `examen_enlaces`: Links de examen enviados a los técnicos (depende de: usuarios, tecnicos)
CREATE TABLE IF NOT EXISTS `examen_enlaces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tecnico_id` int(11) NOT NULL COMMENT 'Técnico que rinde el examen (T1 o T2)',
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 del código del link (el código no se guarda)',
  `titulo` varchar(150) NOT NULL DEFAULT 'Evaluación técnica',
  `generado_por` int(11) DEFAULT NULL COMMENT 'Usuario que generó el link',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `vence_en` datetime NOT NULL,
  `abierto_en` datetime DEFAULT NULL COMMENT 'Primera vez que el técnico abrió el link',
  `usado_en` datetime DEFAULT NULL COMMENT 'Examen rendido: el link ya no sirve',
  `anulado_en` datetime DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_examen_enlaces_token_hash` (`token_hash`),
  KEY `idx_examen_enlaces_tecnico` (`tecnico_id`,`creado_en`),
  KEY `fk_examen_enlaces_generado_por` (`generado_por`),
  KEY `fk_examen_enlaces_anulado_por` (`anulado_por`),
  CONSTRAINT `fk_examen_enlaces_anulado_por` FOREIGN KEY (`anulado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_examen_enlaces_generado_por` FOREIGN KEY (`generado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_examen_enlaces_tecnico_id` FOREIGN KEY (`tecnico_id`) REFERENCES `tecnicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links de examen enviados a los técnicos';

-- Tabla `supervisiones`: Supervisiones de campo y órdenes pendientes asignadas (depende de: usuarios, partners, tecnicos, tipificaciones_cancelacion, vehiculos)
CREATE TABLE IF NOT EXISTS `supervisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_supervision` varchar(30) NOT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_carga` datetime DEFAULT NULL COMMENT 'Cuándo se subió la pendiente por Excel (solo órdenes importadas)',
  `supervisor_id` int(11) NOT NULL,
  `asignado_por` int(11) DEFAULT NULL,
  `fecha_asignacion` datetime DEFAULT NULL,
  `estado_atencion_partner` enum('Pendiente','Atendida') NOT NULL DEFAULT 'Pendiente',
  `observacion_atencion_partner` text DEFAULT NULL,
  `partner_rescatable` tinyint(1) DEFAULT NULL COMMENT 'El partner indicó al atender que el servicio se puede rescatar (1) o no (0)',
  `fecha_atencion_partner` datetime DEFAULT NULL,
  `zona_asignacion` varchar(100) DEFAULT NULL,
  `derivacion_orden` enum('Autogestión','Mesa de supervisión','Líder','Coordinador','Gerente') NOT NULL,
  `tipo_servicio` enum('Regular','ZAV') NOT NULL DEFAULT 'Regular',
  `departamento` varchar(100) NOT NULL,
  `provincia` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  `direccion` varchar(255) DEFAULT NULL COMMENT 'Dirección del cliente (carga de cancelaciones por Excel)',
  `cliente_nombre` varchar(200) DEFAULT NULL,
  `cliente_dni` varchar(12) DEFAULT NULL,
  `ticket_codigo` varchar(50) DEFAULT NULL,
  `fecha_cancelacion` date DEFAULT NULL,
  `tipo_supervision_inicial` enum('En caliente','En frío','Remota') NOT NULL,
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
  `rescate_estado` enum('En rescate','Rescatado','No rescatado') DEFAULT NULL COMMENT 'Rescate de la pendiente: en curso o su resultado',
  `rescate_responsable_id` int(11) DEFAULT NULL COMMENT 'Supervisor directo a cargo del rescate',
  `rescate_inicio` datetime DEFAULT NULL COMMENT 'Cuándo se tomó como rescate',
  `rescate_fin` datetime DEFAULT NULL COMMENT 'Cuándo se cerró el rescate',
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
  `datos_pendiente` longtext DEFAULT NULL COMMENT 'Copia (JSON) de la pendiente al iniciarla: si se cancela, vuelve a este estado. Se borra al finalizar.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supervisiones_codigo_supervision` (`codigo_supervision`),
  KEY `idx_supervisiones_partner_id` (`partner_id`),
  KEY `idx_supervisiones_supervisor_id_fecha_registro` (`supervisor_id`,`fecha_registro`),
  KEY `idx_supervisiones_asignado_por` (`asignado_por`),
  KEY `idx_supervisiones_zona_asignacion_partner_id_estado_supervision` (`zona_asignacion`,`partner_id`,`estado_supervision`),
  KEY `idx_supervisiones_ticket_codigo` (`ticket_codigo`),
  KEY `idx_supervisiones_estado_supervision_fecha_registro` (`estado_supervision`,`fecha_registro`),
  KEY `idx_supervisiones_partner_id_estado_supervision_fecha_registro` (`partner_id`,`estado_supervision`,`fecha_registro`),
  KEY `idx_supervisiones_departamento_provincia_distrito` (`departamento`,`provincia`,`distrito`),
  KEY `idx_supervisiones_tecnico1_id` (`tecnico1_id`),
  KEY `idx_supervisiones_tecnico2_id` (`tecnico2_id`),
  KEY `idx_supervisiones_vehiculo_id` (`vehiculo_id`),
  KEY `idx_supervi_es_cancelacion_estado_cancelacion_estado_supervision` (`es_cancelacion`,`estado_cancelacion`,`estado_supervision`),
  KEY `idx_supervisiones_fecha_registro_estado_supervision` (`fecha_registro`,`estado_supervision`),
  KEY `idx_supervisiones_partner_id_fecha_registro` (`partner_id`,`fecha_registro`),
  KEY `idx_super_supervisor_id_estado_atencion_partner_fecha_asignacion` (`supervisor_id`,`estado_atencion_partner`,`fecha_asignacion`),
  KEY `idx_supervisiones_tipificacion_cancelacion` (`tipificacion_cancelacion`),
  KEY `idx_supervisiones_tipificacion_corregida_supervisor` (`tipificacion_corregida_supervisor`),
  KEY `idx_supervisiones_origen_importacion_fecha_carga` (`origen_importacion`,`fecha_carga`),
  KEY `idx_supervisiones_rescate_estado_supervisor_id` (`rescate_estado`,`supervisor_id`),
  KEY `fk_supervisiones_rescate_responsable_id` (`rescate_responsable_id`),
  CONSTRAINT `fk_supervisiones_asignado_por` FOREIGN KEY (`asignado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supervisiones_partner_id` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`),
  CONSTRAINT `fk_supervisiones_rescate_responsable_id` FOREIGN KEY (`rescate_responsable_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supervisiones_supervisor_id` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_supervisiones_tecnico1_id` FOREIGN KEY (`tecnico1_id`) REFERENCES `tecnicos` (`id`),
  CONSTRAINT `fk_supervisiones_tecnico2_id` FOREIGN KEY (`tecnico2_id`) REFERENCES `tecnicos` (`id`),
  CONSTRAINT `fk_supervisiones_tipificacion_cancelacion` FOREIGN KEY (`tipificacion_cancelacion`) REFERENCES `tipificaciones_cancelacion` (`descripcion`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supervisiones_tipificacion_corregida_supervisor` FOREIGN KEY (`tipificacion_corregida_supervisor`) REFERENCES `tipificaciones_cancelacion` (`descripcion`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_supervisiones_vehiculo_id` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Supervisiones de campo y órdenes pendientes asignadas';

-- Tabla `checklists_tecnicos`: Checklist de cuadrilla por supervisión (depende de: supervisiones, usuarios)
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
  KEY `idx_checklists_tecnicos_supervision_id` (`supervision_id`),
  KEY `idx_checklists_tecnicos_supervisor_id` (`supervisor_id`),
  KEY `idx_checklists_tecnicos_fecha_registro` (`fecha_registro`),
  CONSTRAINT `fk_checklists_tecnicos_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_checklists_tecnicos_supervisor_id` FOREIGN KEY (`supervisor_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Checklist de cuadrilla por supervisión';

-- Tabla `documentos_vencimiento`: Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial (depende de: tecnicos, usuarios, supervisiones, vehiculos)
CREATE TABLE IF NOT EXISTS `documentos_vencimiento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('SOAT','Revisión técnica','Extintor','Brevete') NOT NULL,
  `vehiculo_id` int(11) DEFAULT NULL COMMENT 'SOAT, revisión técnica y extintor',
  `tecnico_id` int(11) DEFAULT NULL COMMENT 'Brevete (técnico líder)',
  `fecha_inicio` date NOT NULL COMMENT 'Emisión del documento o última recarga del extintor',
  `fecha_vencimiento` date NOT NULL,
  `foto` varchar(255) NOT NULL COMMENT 'Foto del documento (evidencia)',
  `origen` enum('Supervisión','Cuadrillas') NOT NULL DEFAULT 'Cuadrillas',
  `supervision_id` int(11) DEFAULT NULL COMMENT 'Supervisión en la que se actualizó (si fue desde el checklist)',
  `aplicado` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = registrado en una supervisión en proceso: se asigna a la cuadrilla al finalizarla',
  `lider_id` int(11) DEFAULT NULL COMMENT 'Cuadrilla (técnico líder) en ese momento',
  `departamento` varchar(100) DEFAULT NULL COMMENT 'Zona en ese momento',
  `registrado_por` int(11) DEFAULT NULL,
  `registrado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_documentos_vencimiento_vehiculo` (`vehiculo_id`,`tipo`,`id`),
  KEY `idx_documentos_vencimiento_tecnico` (`tecnico_id`,`tipo`,`id`),
  KEY `idx_documentos_vencimiento_vence` (`fecha_vencimiento`),
  KEY `fk_documentos_vencimiento_supervision` (`supervision_id`),
  KEY `fk_documentos_vencimiento_lider` (`lider_id`),
  KEY `fk_documentos_vencimiento_registrado_por` (`registrado_por`),
  KEY `idx_documentos_vencimiento_supervision_aplicado` (`supervision_id`,`aplicado`),
  CONSTRAINT `fk_documentos_vencimiento_lider_id` FOREIGN KEY (`lider_id`) REFERENCES `tecnicos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documentos_vencimiento_registrado_por` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documentos_vencimiento_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_documentos_vencimiento_tecnico_id` FOREIGN KEY (`tecnico_id`) REFERENCES `tecnicos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documentos_vencimiento_vehiculo_id` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial';

-- Tabla `examen_respuestas`: Resultado de cada pregunta de los exámenes rendidos (depende de: examen_convocatoria_tecnicos)
CREATE TABLE IF NOT EXISTS `examen_respuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `participacion_id` int(11) NOT NULL COMMENT 'examen_convocatoria_tecnicos.id',
  `pregunta` varchar(20) NOT NULL COMMENT 'Código de la pregunta en el banco',
  `tema` varchar(60) NOT NULL,
  `correcta` tinyint(1) NOT NULL DEFAULT 0,
  `respondida` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_examen_respuestas` (`participacion_id`,`pregunta`),
  KEY `idx_examen_respuestas_tema` (`tema`,`correcta`),
  CONSTRAINT `fk_examen_respuestas_participacion` FOREIGN KEY (`participacion_id`) REFERENCES `examen_convocatoria_tecnicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resultado de cada pregunta de los exámenes rendidos';

-- Tabla `observaciones_supervision`: Observaciones en campo, descargos del partner y plazos (depende de: categorias_observacion, usuarios, subopciones_observacion, supervisiones)
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
  KEY `idx_observaciones_supervision_supervision_id` (`supervision_id`),
  KEY `idx_observaciones_supervision_categoria_id` (`categoria_id`),
  KEY `idx_observaciones_supervision_subopcion_id` (`subopcion_id`),
  KEY `idx_observaciones_superv_estado_respuesta_fecha_limite_respuesta` (`estado_respuesta`,`fecha_limite_respuesta`),
  KEY `idx_observaciones_supervision_creado_por_fecha_registro` (`creado_por`,`fecha_registro`),
  KEY `idx_observaciones_supervision_supervision_id_estado_respuesta` (`supervision_id`,`estado_respuesta`),
  CONSTRAINT `fk_observaciones_supervision_categoria_id` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_observacion` (`id`),
  CONSTRAINT `fk_observaciones_supervision_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_observaciones_supervision_subopcion_id` FOREIGN KEY (`subopcion_id`) REFERENCES `subopciones_observacion` (`id`),
  CONSTRAINT `fk_observaciones_supervision_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Observaciones en campo, descargos del partner y plazos';

-- Tabla `rescate_seguimientos`: Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre) (depende de: supervisiones, usuarios)
CREATE TABLE IF NOT EXISTS `rescate_seguimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tipo` enum('Propuesta del partner','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance',
  `comentario` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rescate_seguimientos_supervision_id_fecha` (`supervision_id`,`fecha`),
  KEY `idx_rescate_seguimientos_usuario_id` (`usuario_id`),
  CONSTRAINT `fk_rescate_seguimientos_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rescate_seguimientos_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre)';

-- Tabla `supervision_coordenadas_historial`: Cambios de ubicación GPS de cada supervisión (depende de: usuarios, supervisiones)
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
  KEY `idx_supervision_coordenadas_historial_cambiado_por` (`cambiado_por`),
  KEY `idx_supervision_coordenadas_histor_supervision_id_fecha_registro` (`supervision_id`,`fecha_registro`),
  CONSTRAINT `fk_supervision_coordenadas_historial_cambiado_por` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supervision_coordenadas_historial_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cambios de ubicación GPS de cada supervisión';

-- Tabla `supervision_estado_historial`: Cambios de estado de cada supervisión (horas reales de inicio y fin) (depende de: usuarios, supervisiones)
CREATE TABLE IF NOT EXISTS `supervision_estado_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `estado_anterior` varchar(40) DEFAULT NULL,
  `estado_nuevo` varchar(40) NOT NULL,
  `cambiado_por` int(11) DEFAULT NULL,
  `comentario` varchar(500) DEFAULT NULL,
  `fecha_cambio` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supervision_estado_historial_supervision_id_fecha_cambio` (`supervision_id`,`fecha_cambio`),
  KEY `idx_supervision_estado_historial_cambiado_por_fecha_cambio` (`cambiado_por`,`fecha_cambio`),
  CONSTRAINT `fk_supervision_estado_historial_cambiado_por` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_supervision_estado_historial_supervision_id` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cambios de estado de cada supervisión (horas reales de inicio y fin)';

-- Tabla `checklist_respuestas`: Criterios evaluados en cada checklist (depende de: checklists_tecnicos)
CREATE TABLE IF NOT EXISTS `checklist_respuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `criterio` varchar(255) NOT NULL,
  `estado` enum('Bueno','Regular','Malo','No tiene','Sí','No','Para cambio','No revisado','Mal estado') NOT NULL,
  `observacion` varchar(500) DEFAULT NULL,
  `foto_evidencia` varchar(255) DEFAULT NULL,
  `descargo_foto` varchar(255) DEFAULT NULL,
  `descargo_comentario` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_checklist_respuestas_checklist_id_estado` (`checklist_id`,`estado`),
  CONSTRAINT `fk_checklist_respuestas_checklist_id` FOREIGN KEY (`checklist_id`) REFERENCES `checklists_tecnicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Criterios evaluados en cada checklist';

-- Tabla `notificaciones`: Avisos a los usuarios (depende de: observaciones_supervision, usuarios)
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
  KEY `idx_notificaciones_observacion_id` (`observacion_id`),
  KEY `idx_notificaciones_usuario_id_leida_fecha_registro` (`usuario_id`,`leida`,`fecha_registro`),
  CONSTRAINT `fk_notificaciones_observacion_id` FOREIGN KEY (`observacion_id`) REFERENCES `observaciones_supervision` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Avisos a los usuarios';
