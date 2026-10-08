-- 30_tabla_sgs_usuario_perfil.sql
-- Objetivo: crear la tabla `sgs_usuario_perfil`.
-- Depende de: usuarios, sgs_perfil
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sgs_usuario_perfil` (
  `USUARIO_ID` int(11) NOT NULL,
  `PERFIL_ID` int(10) unsigned NOT NULL,
  `ASIGNADO_POR` int(11) DEFAULT NULL,
  `FECHA_ASIGNACION` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`USUARIO_ID`),
  KEY `FK_SGS_USUARIO_PERFIL_ASIGNADO` (`ASIGNADO_POR`),
  KEY `IX_SGS_USUARIO_PERFIL_PERFIL_001` (`PERFIL_ID`),
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_ASIGNADO` FOREIGN KEY (`ASIGNADO_POR`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_PERFIL` FOREIGN KEY (`PERFIL_ID`) REFERENCES `sgs_perfil` (`PERFIL_ID`) ON DELETE CASCADE,
  CONSTRAINT `FK_SGS_USUARIO_PERFIL_USUARIO` FOREIGN KEY (`USUARIO_ID`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
