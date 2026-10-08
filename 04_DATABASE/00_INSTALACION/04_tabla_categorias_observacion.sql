-- 04_tabla_categorias_observacion.sql
-- Objetivo: crear la tabla `categorias_observacion` y cargar sus datos iniciales: categorías de observación.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `categorias_observacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_categoria` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (6 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `categorias_observacion` (`id`, `nombre_categoria`) VALUES
('1', 'INCUMPLIMIENTO OPERATIVO'),
('2', 'MALA PRAXIS'),
('3', 'FALSA INFORMACIÓN'),
('4', 'INCUMPLIMIENTO SEGURIDAD'),
('5', 'DAÑO A LA PLANTA'),
('6', 'DAÑO AL CLIENTE');
