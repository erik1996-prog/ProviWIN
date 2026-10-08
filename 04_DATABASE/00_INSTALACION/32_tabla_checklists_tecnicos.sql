-- 32_tabla_checklists_tecnicos.sql
-- Objetivo: crear la tabla `checklists_tecnicos`.
-- Depende de: supervisiones, usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
