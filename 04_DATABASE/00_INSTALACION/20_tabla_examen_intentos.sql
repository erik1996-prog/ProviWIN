-- 20_tabla_examen_intentos.sql
-- Objetivo: crear la tabla `examen_intentos`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `examen_intentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `foto_inicial` varchar(255) NOT NULL,
  `foto_incidencia` varchar(255) DEFAULT NULL,
  `tipo_incidencia` enum('Rostro ausente','Rostro diferente','Varias personas') DEFAULT NULL,
  `estado` enum('En curso','Finalizado','Cerrado por identidad') NOT NULL DEFAULT 'En curso',
  `puntaje` tinyint(3) unsigned DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_incidencia` datetime DEFAULT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_examen_usuario_fecha` (`usuario_id`,`fecha_inicio`),
  CONSTRAINT `examen_intentos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
