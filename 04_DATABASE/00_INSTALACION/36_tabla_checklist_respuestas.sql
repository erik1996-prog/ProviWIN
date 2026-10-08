-- 36_tabla_checklist_respuestas.sql
-- Objetivo: crear la tabla `checklist_respuestas`.
-- Depende de: checklists_tecnicos
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
