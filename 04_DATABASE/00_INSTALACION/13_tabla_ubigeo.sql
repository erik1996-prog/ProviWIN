-- 13_tabla_ubigeo.sql
-- Objetivo: crear la tabla `ubigeo` y cargar sus datos iniciales: departamentos, provincias y distritos de operación.
-- Depende de: 01_crear_base.sql
-- Generado por 07_SCRIPTS/generar_instalacion.php el 2026-10-08 desde win_telecom_db. Solo para una base NUEVA: no borra nada.

USE win_telecom_db;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ubigeo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `departamento` varchar(100) NOT NULL,
  `provincia` varchar(100) NOT NULL,
  `distrito` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ubigeo_departamento_provincia_distrito` (`departamento`,`provincia`,`distrito`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos iniciales (47 registros). INSERT IGNORE: no duplica si ya existen.
INSERT IGNORE INTO `ubigeo` (`id`, `departamento`, `provincia`, `distrito`) VALUES
('1', 'ÁNCASH', 'SANTA', 'CHIMBOTE'),
('2', 'ÁNCASH', 'SANTA', 'NUEVO CHIMBOTE'),
('3', 'AREQUIPA', 'AREQUIPA', 'ALTO SELVA ALEGRE'),
('4', 'AREQUIPA', 'AREQUIPA', 'AREQUIPA'),
('5', 'AREQUIPA', 'AREQUIPA', 'CAYMA'),
('6', 'AREQUIPA', 'AREQUIPA', 'CERRO COLORADO'),
('7', 'AREQUIPA', 'AREQUIPA', 'JACOBO HUNTER'),
('8', 'AREQUIPA', 'AREQUIPA', 'JOSÉ LUIS BUSTAMANTE Y RIVERO'),
('9', 'AREQUIPA', 'AREQUIPA', 'MARIANO MELGAR'),
('10', 'AREQUIPA', 'AREQUIPA', 'MIRAFLORES'),
('11', 'AREQUIPA', 'AREQUIPA', 'PAUCARPATA'),
('12', 'AREQUIPA', 'AREQUIPA', 'SACHACA'),
('13', 'AREQUIPA', 'AREQUIPA', 'SOCABAYA'),
('14', 'AREQUIPA', 'AREQUIPA', 'YANAHUARA'),
('15', 'CUSCO', 'CUSCO', 'CUSCO'),
('16', 'CUSCO', 'CUSCO', 'SAN JERÓNIMO'),
('17', 'CUSCO', 'CUSCO', 'SAN SEBASTIÁN'),
('18', 'CUSCO', 'CUSCO', 'SANTIAGO'),
('19', 'CUSCO', 'CUSCO', 'WANCHAQ'),
('26', 'HUANCAYO', 'HUANCAYO', 'CHILCA'),
('27', 'HUANCAYO', 'HUANCAYO', 'EL TAMBO'),
('28', 'HUANCAYO', 'HUANCAYO', 'HUANCÁN'),
('29', 'HUANCAYO', 'HUANCAYO', 'HUANCAYO'),
('30', 'HUANCAYO', 'HUANCAYO', 'PILCOMAYO'),
('31', 'HUANCAYO', 'HUANCAYO', 'SICAYA'),
('20', 'JUNÍN', 'HUANCAYO', 'CHILCA'),
('21', 'JUNÍN', 'HUANCAYO', 'EL TAMBO'),
('22', 'JUNÍN', 'HUANCAYO', 'HUANCÁN'),
('23', 'JUNÍN', 'HUANCAYO', 'HUANCAYO'),
('24', 'JUNÍN', 'HUANCAYO', 'PILCOMAYO'),
('25', 'JUNÍN', 'HUANCAYO', 'SICAYA'),
('32', 'LA LIBERTAD', 'TRUJILLO', 'EL PORVENIR'),
('33', 'LA LIBERTAD', 'TRUJILLO', 'HUANCHACO'),
('34', 'LA LIBERTAD', 'TRUJILLO', 'LA ESPERANZA'),
('35', 'LA LIBERTAD', 'TRUJILLO', 'TRUJILLO'),
('36', 'LA LIBERTAD', 'TRUJILLO', 'VÍCTOR LARCO HERRERA'),
('37', 'LAMBAYEQUE', 'CHICLAYO', 'CAYALTÍ'),
('38', 'LAMBAYEQUE', 'CHICLAYO', 'CHICLAYO'),
('39', 'LAMBAYEQUE', 'CHICLAYO', 'JOSÉ LEONARDO ORTIZ'),
('40', 'LAMBAYEQUE', 'CHICLAYO', 'LA VICTORIA'),
('41', 'LAMBAYEQUE', 'CHICLAYO', 'PIMENTEL'),
('42', 'LAMBAYEQUE', 'CHICLAYO', 'POMALCA'),
('43', 'LAMBAYEQUE', 'LAMBAYEQUE', 'LAMBAYEQUE'),
('44', 'PIURA', 'PIURA', 'CASTILLA'),
('45', 'PIURA', 'PIURA', 'CATACAOS'),
('46', 'PIURA', 'PIURA', 'PIURA'),
('47', 'PIURA', 'PIURA', 'VEINTISÉIS DE OCTUBRE');
