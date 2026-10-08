-- 14_tabla_vehiculos.sql
-- Objetivo: crear la tabla `vehiculos`.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
