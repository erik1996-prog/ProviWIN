-- 28_tabla_usuario_permisos.sql
-- Objetivo: crear la tabla `usuario_permisos`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `usuario_permisos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `permiso` varchar(100) NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT 1,
  `otorgado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_permiso` (`usuario_id`,`permiso`),
  KEY `idx_usuario_permisos_consulta` (`usuario_id`,`permitido`),
  KEY `fk_usuario_permisos_otorgante` (`otorgado_por`),
  CONSTRAINT `fk_usuario_permisos_otorgante` FOREIGN KEY (`otorgado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_usuario_permisos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
