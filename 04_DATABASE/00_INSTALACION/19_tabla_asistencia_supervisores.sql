-- 19_tabla_asistencia_supervisores.sql
-- Objetivo: crear la tabla `asistencia_supervisores`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
