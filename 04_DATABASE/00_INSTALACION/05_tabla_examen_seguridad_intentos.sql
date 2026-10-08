-- 05_tabla_examen_seguridad_intentos.sql
-- Objetivo: crear la tabla `examen_seguridad_intentos`.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `examen_seguridad_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `version_banco` varchar(30) NOT NULL,
  `escenarios` longtext NOT NULL,
  `respuestas` longtext DEFAULT NULL,
  `resultado` longtext DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_seguridad_usuario` (`usuario_id`,`fecha_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
