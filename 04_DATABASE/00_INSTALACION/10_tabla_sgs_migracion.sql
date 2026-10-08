-- 10_tabla_sgs_migracion.sql
-- Objetivo: crear la tabla `sgs_migracion` y cargar sus datos iniciales: migraciones ya incluidas en esta estructura.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sgs_migracion` (
  `ARCHIVO` varchar(120) NOT NULL,
  `FECHA_APLICACION` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ARCHIVO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (2 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `sgs_migracion` (`ARCHIVO`, `FECHA_APLICACION`) VALUES
('001_SGS_PERFIL.sql', '2026-10-04 22:46:41'),
('002_INDICES_LIMPIEZA.sql', '2026-10-08 01:36:53');
