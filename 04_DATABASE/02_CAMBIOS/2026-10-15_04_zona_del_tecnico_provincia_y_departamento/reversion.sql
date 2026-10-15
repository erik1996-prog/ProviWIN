-- 04_DATABASE/02_CAMBIOS/2026-10-15_04_zona_del_tecnico_provincia_y_departamento/reversion.sql
-- Deshace cambio.sql: se pierde la zona (provincia y departamento) de los técnicos.
ALTER TABLE tecnicos DROP INDEX IF EXISTS idx_tecnicos_departamento;
ALTER TABLE tecnicos DROP COLUMN IF EXISTS departamento, DROP COLUMN IF EXISTS provincia;
