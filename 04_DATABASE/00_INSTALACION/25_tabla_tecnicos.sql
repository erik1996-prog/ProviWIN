-- 25_tabla_tecnicos.sql
-- Objetivo: crear la tabla `tecnicos`.
-- Depende de: partners, usuarios, vehiculos
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tecnicos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_tecnico` varchar(255) NOT NULL,
  `dni` varchar(20) DEFAULT NULL,
  `tipo_tecnico` tinyint(1) DEFAULT 1,
  `celular` varchar(15) DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `estado_operativo` enum('Habilitado','Inhabilitado','Blacklist') NOT NULL DEFAULT 'Habilitado',
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `partner_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `vehiculo_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre_tecnico` (`nombre_tecnico`),
  UNIQUE KEY `uq_tecnicos_dni` (`dni`),
  UNIQUE KEY `uq_tecnico_usuario` (`usuario_id`),
  KEY `idx_tecnicos_estado_tipo` (`estado_operativo`,`tipo_tecnico`),
  KEY `fk_tecnico_partner` (`partner_id`),
  KEY `fk_tecnico_vehiculo` (`vehiculo_id`),
  CONSTRAINT `fk_tecnico_partner` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`),
  CONSTRAINT `fk_tecnico_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tecnico_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
