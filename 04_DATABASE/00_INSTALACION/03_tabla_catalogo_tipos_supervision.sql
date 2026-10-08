-- 03_tabla_catalogo_tipos_supervision.sql
-- Objetivo: crear la tabla `catalogo_tipos_supervision` y cargar sus datos iniciales: tipos de supervisión.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `catalogo_tipos_supervision` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `requiere_ticket` tinyint(1) NOT NULL DEFAULT 1,
  `requiere_partner` tinyint(1) NOT NULL DEFAULT 1,
  `requiere_cuadrilla` tinyint(1) NOT NULL DEFAULT 1,
  `permite_checklist` tinyint(1) NOT NULL DEFAULT 1,
  `permite_observaciones` tinyint(1) NOT NULL DEFAULT 1,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalogo_tipo_codigo` (`codigo`),
  UNIQUE KEY `uq_catalogo_tipo_nombre` (`nombre`),
  KEY `idx_catalogo_tipo_activo_orden` (`activo`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (10 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `catalogo_tipos_supervision` (`id`, `codigo`, `nombre`, `requiere_ticket`, `requiere_partner`, `requiere_cuadrilla`, `permite_checklist`, `permite_observaciones`, `activo`, `orden`) VALUES
('1', 'INSTALACION', 'Instalaci├│n', '1', '1', '1', '1', '1', '1', '10'),
('2', 'POSTVENTA', 'Postventa', '1', '1', '1', '1', '1', '1', '20'),
('3', 'AVERIA', 'Aver├¡a', '1', '1', '1', '1', '1', '1', '30'),
('4', 'REITERADA', 'Reiterada', '1', '1', '1', '1', '1', '1', '40'),
('5', 'GARANTIA', 'Garant├¡a', '1', '1', '1', '1', '1', '1', '50'),
('6', 'INCIDENCIA_ATIPICA', 'Incidencia / Casos at├¡picos', '0', '0', '0', '0', '0', '1', '60'),
('7', 'ORDENAMIENTO', 'Proyecto de ordenamiento', '0', '1', '1', '1', '1', '1', '70'),
('8', 'CAPACITACION_PARTNER', 'Visita partner por capacitaci├│n', '0', '1', '0', '0', '0', '1', '80'),
('9', 'CONJUNTA_PEXT', 'Conjunta PEXT', '0', '1', '1', '0', '0', '1', '90'),
('10', 'CANCELACION', 'Cancelaci├│n', '1', '1', '1', '0', '0', '1', '100');
