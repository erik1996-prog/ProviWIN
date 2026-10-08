-- 08_tabla_partners.sql
-- Objetivo: crear la tabla `partners` y cargar sus datos iniciales: contratas (incluye WIN DIRECTO, necesario para el personal propio).
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `partners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_partner` varchar(150) NOT NULL,
  `ruc` varchar(11) DEFAULT NULL,
  `estado` tinyint(1) DEFAULT 1,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (12 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `partners` (`id`, `nombre_partner`, `ruc`, `estado`, `fecha_registro`) VALUES
('18', 'ALFITELL', NULL, '1', '2026-08-09 23:39:37'),
('19', 'DOMINION', NULL, '1', '2026-08-09 23:39:37'),
('20', 'VISUAL', NULL, '1', '2026-08-09 23:39:37'),
('21', 'COBRA', NULL, '1', '2026-08-09 23:39:37'),
('23', 'OLMA', NULL, '1', '2026-08-09 23:39:37'),
('24', 'DIGETEL', NULL, '1', '2026-08-09 23:39:37'),
('30', 'MALLAUSA', NULL, '1', '2026-08-24 01:42:17'),
('31', 'AVANTEL', NULL, '1', '2026-08-24 01:42:17'),
('32', 'COMFICA', NULL, '1', '2026-08-24 01:42:17'),
('33', 'KAJOMI', NULL, '1', '2026-09-15 01:00:54'),
('34', 'DATANTENNA SGI', NULL, '1', '2026-09-16 00:24:07'),
('35', 'WIN DIRECTO', '20600000000', '1', '2026-09-16 00:24:41');
