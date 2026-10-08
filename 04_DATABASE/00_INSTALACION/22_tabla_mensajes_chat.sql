-- 22_tabla_mensajes_chat.sql
-- Objetivo: crear la tabla `mensajes_chat`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `mensajes_chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emisor_id` int(11) DEFAULT NULL,
  `receptor_id` int(11) DEFAULT NULL,
  `mensaje` text NOT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `emisor_id` (`emisor_id`),
  KEY `receptor_id` (`receptor_id`),
  KEY `fecha_registro` (`fecha_registro`),
  KEY `idx_chat_conversacion_fecha` (`emisor_id`,`receptor_id`,`fecha_registro`),
  KEY `idx_chat_receptor_fecha` (`receptor_id`,`fecha_registro`),
  CONSTRAINT `fk_chat_emisor` FOREIGN KEY (`emisor_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chat_receptor` FOREIGN KEY (`receptor_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
