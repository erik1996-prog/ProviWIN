-- 35_tabla_supervision_estado_historial.sql
-- Objetivo: crear la tabla `supervision_estado_historial`.
-- Depende de: supervisiones, usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `supervision_estado_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `estado_anterior` varchar(40) DEFAULT NULL,
  `estado_nuevo` varchar(40) NOT NULL,
  `cambiado_por` int(11) DEFAULT NULL,
  `comentario` varchar(500) DEFAULT NULL,
  `fecha_cambio` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_historial_supervision_fecha` (`supervision_id`,`fecha_cambio`),
  KEY `idx_historial_usuario_fecha` (`cambiado_por`,`fecha_cambio`),
  CONSTRAINT `fk_historial_supervision` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_historial_usuario` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
