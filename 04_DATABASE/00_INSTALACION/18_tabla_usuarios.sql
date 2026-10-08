-- 18_tabla_usuarios.sql
-- Objetivo: crear la tabla `usuarios`.
-- Depende de: partners
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dni` varchar(8) NOT NULL,
  `nombres` varchar(100) DEFAULT NULL,
  `apellidos` varchar(100) DEFAULT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `nombre_completo` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telefono_personal` varchar(20) DEFAULT NULL,
  `telefono_corporativo` varchar(20) DEFAULT NULL,
  `turno` varchar(30) DEFAULT NULL,
  `modalidad` varchar(30) DEFAULT NULL,
  `sede` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password_admin_encrypted` text DEFAULT NULL,
  `rol` enum('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner') NOT NULL DEFAULT 'Supervisor',
  `area_trabajo` varchar(100) DEFAULT NULL,
  `zona` varchar(100) DEFAULT 'Provincias',
  `procesos_copiados` text DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `partner_id` int(11) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `avatar` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dni` (`dni`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_usuario` (`usuario`),
  KEY `partner_id` (`partner_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`partner_id`) REFERENCES `partners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
