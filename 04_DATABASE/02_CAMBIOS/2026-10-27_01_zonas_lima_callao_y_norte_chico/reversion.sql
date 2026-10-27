-- 04_DATABASE/02_CAMBIOS/2026-10-27_01_zonas_lima_callao_y_norte_chico/reversion.sql
-- Fecha: 2026-10-27
-- Deshace cambio.sql: quita del catálogo los distritos de Lima (provincia Lima), Callao y Norte Chico.

DELETE FROM ubigeo WHERE (departamento = 'LIMA' AND provincia IN ('LIMA', 'HUAURA', 'HUARAL', 'BARRANCA')) OR departamento = 'CALLAO';
