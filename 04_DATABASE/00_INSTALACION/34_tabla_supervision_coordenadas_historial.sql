-- 34_tabla_supervision_coordenadas_historial.sql
-- Objetivo: crear la tabla `supervision_coordenadas_historial`.
-- Depende de: supervisiones, usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `supervision_coordenadas_historial` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supervision_id` int(11) NOT NULL,
  `latitud_anterior` varchar(50) DEFAULT NULL,
  `longitud_anterior` varchar(50) DEFAULT NULL,
  `latitud_nueva` varchar(50) DEFAULT NULL,
  `longitud_nueva` varchar(50) DEFAULT NULL,
  `cambiado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_coord_hist_usuario` (`cambiado_por`),
  KEY `idx_coord_hist_supervision_fecha` (`supervision_id`,`fecha_registro`),
  CONSTRAINT `fk_coord_hist_supervision` FOREIGN KEY (`supervision_id`) REFERENCES `supervisiones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coord_hist_usuario` FOREIGN KEY (`cambiado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
