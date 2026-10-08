-- 16_tabla_partner_ubigeos.sql
-- Objetivo: crear la tabla `partner_ubigeos` y cargar sus datos iniciales: cobertura de cada partner.
-- Depende de: partners, ubigeo
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `partner_ubigeos` (
  `partner_id` int(11) NOT NULL,
  `ubigeo_id` int(11) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`partner_id`,`ubigeo_id`),
  KEY `idx_partner_ubigeo_activo` (`partner_id`,`activo`),
  KEY `fk_partner_ubigeo_ubigeo` (`ubigeo_id`),
  CONSTRAINT `fk_partner_ubigeo_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_partner_ubigeo_ubigeo` FOREIGN KEY (`ubigeo_id`) REFERENCES `ubigeo` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
