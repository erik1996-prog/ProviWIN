-- 11_tabla_system_health_events.sql
-- Objetivo: crear la tabla `system_health_events`.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `system_health_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `component` varchar(80) NOT NULL,
  `status` varchar(30) NOT NULL,
  `message` varchar(1000) DEFAULT NULL,
  `request_id` varchar(40) DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_health_component_time` (`component`,`occurred_at`),
  KEY `idx_health_open` (`status`,`resolved_at`),
  KEY `IX_SYSTEM_HEALTH_REQUEST` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
