-- 12_tabla_tipificaciones_cancelacion.sql
-- Objetivo: crear la tabla `tipificaciones_cancelacion` y cargar sus datos iniciales: motivos de cancelación.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tipificaciones_cancelacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(200) NOT NULL,
  `estado` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `descripcion` (`descripcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (3 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `tipificaciones_cancelacion` (`id`, `descripcion`, `estado`, `fecha_registro`) VALUES
('1', 'Error comercial coordenadas no coinciden', '1', '2026-08-24 02:13:56'),
('2', 'No hay postes de apoyo', '1', '2026-08-24 02:13:56'),
('3', 'No hay CTO', '1', '2026-08-24 02:13:56');
