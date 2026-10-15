-- 04_DATABASE/02_CAMBIOS/2026-10-15_01_cuadrilla_del_tecnico_lider/reversion.sql
-- Deshace cambio.sql: se pierde el técnico de apoyo habitual de cada líder (el vehículo se conserva).
ALTER TABLE tecnicos DROP FOREIGN KEY IF EXISTS fk_tecnicos_apoyo_id;
ALTER TABLE tecnicos DROP COLUMN IF EXISTS apoyo_id;
