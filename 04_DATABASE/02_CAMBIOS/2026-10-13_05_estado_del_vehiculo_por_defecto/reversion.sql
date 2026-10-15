-- 04_DATABASE/02_CAMBIOS/2026-10-13_05_estado_del_vehiculo_por_defecto/reversion.sql
-- Deshace cambio.sql: quita el estado guardado del vehículo (las supervisiones conservan el suyo).
ALTER TABLE vehiculos DROP COLUMN IF EXISTS estado_conservacion;
