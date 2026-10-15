-- 04_DATABASE/02_CAMBIOS/2026-10-15_05_enlaces_de_examen_para_los_tecnicos/reversion.sql
-- Deshace cambio.sql: se pierde el historial de links de examen.
DROP TABLE IF EXISTS examen_enlaces;
