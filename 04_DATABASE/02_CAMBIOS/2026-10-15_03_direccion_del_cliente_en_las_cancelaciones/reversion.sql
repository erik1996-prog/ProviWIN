-- 04_DATABASE/02_CAMBIOS/2026-10-15_03_direccion_del_cliente_en_las_cancelaciones/reversion.sql
-- Deshace cambio.sql: se pierde la dirección cargada de las cancelaciones.
ALTER TABLE supervisiones DROP COLUMN IF EXISTS direccion;
