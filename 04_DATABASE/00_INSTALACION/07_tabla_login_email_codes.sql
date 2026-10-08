-- 07_tabla_login_email_codes.sql
-- Objetivo: crear la tabla `login_email_codes`.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `login_email_codes` (
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) DEFAULT NULL,
  `binding` char(64) DEFAULT NULL,
  `expires_at` bigint(20) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_sent` bigint(20) NOT NULL DEFAULT 0,
  `window_start` bigint(20) NOT NULL DEFAULT 0,
  `sends` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
