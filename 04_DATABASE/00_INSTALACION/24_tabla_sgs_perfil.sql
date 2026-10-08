-- 24_tabla_sgs_perfil.sql
-- Objetivo: crear la tabla `sgs_perfil`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sgs_perfil` (
  `PERFIL_ID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `NOMBRE` varchar(80) NOT NULL,
  `DESCRIPCION` varchar(255) DEFAULT NULL,
  `ROL_BASE` varchar(30) NOT NULL,
  `PERMISOS` text NOT NULL COMMENT 'JSON: lista de claves de permiso habilitadas',
  `ACTIVO` tinyint(1) NOT NULL DEFAULT 1,
  `CREADO_POR` int(11) DEFAULT NULL,
  `FECHA_CREACION` datetime NOT NULL DEFAULT current_timestamp(),
  `FECHA_ACTUALIZACION` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PERFIL_ID`),
  UNIQUE KEY `UQ_SGS_PERFIL_NOMBRE` (`NOMBRE`),
  KEY `FK_SGS_PERFIL_CREADO_POR` (`CREADO_POR`),
  CONSTRAINT `FK_SGS_PERFIL_CREADO_POR` FOREIGN KEY (`CREADO_POR`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
