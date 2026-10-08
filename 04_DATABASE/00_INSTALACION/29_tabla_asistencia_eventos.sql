-- 29_tabla_asistencia_eventos.sql
-- Objetivo: crear la tabla `asistencia_eventos`.
-- Depende de: asistencia_supervisores
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
