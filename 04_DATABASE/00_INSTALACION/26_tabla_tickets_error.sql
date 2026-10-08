-- 26_tabla_tickets_error.sql
-- Objetivo: crear la tabla `tickets_error`.
-- Depende de: usuarios
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tickets_error` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `tipo` varchar(80) NOT NULL,
  `mensaje` varchar(1000) NOT NULL,
  `detalle` text DEFAULT NULL,
  `pagina` varchar(500) DEFAULT NULL,
  `codigo_http` varchar(10) DEFAULT NULL,
  `navegador` varchar(500) DEFAULT NULL,
  `huella` char(64) NOT NULL,
  `repeticiones` int(10) unsigned NOT NULL DEFAULT 1,
  `estado` enum('Nuevo','En revisión','Resuelto','Descartado') NOT NULL DEFAULT 'Nuevo',
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `ultima_ocurrencia` datetime NOT NULL DEFAULT current_timestamp(),
  `resuelto_por` int(11) DEFAULT NULL,
  `fecha_resolucion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_estado_fecha` (`estado`,`ultima_ocurrencia`),
  KEY `idx_ticket_huella` (`huella`),
  KEY `fk_ticket_usuario` (`usuario_id`),
  KEY `fk_ticket_admin` (`resuelto_por`),
  KEY `idx_ticket_ultima_ocurrencia` (`ultima_ocurrencia`),
  CONSTRAINT `fk_ticket_admin` FOREIGN KEY (`resuelto_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
