-- 33_tabla_observaciones_supervision.sql
-- Objetivo: crear la tabla `observaciones_supervision`.
-- Depende de: usuarios, supervisiones, categorias_observacion, subopciones_observacion
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
