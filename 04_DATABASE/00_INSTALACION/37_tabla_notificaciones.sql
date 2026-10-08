-- 37_tabla_notificaciones.sql
-- Objetivo: crear la tabla `notificaciones`.
-- Depende de: usuarios, observaciones_supervision
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

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
