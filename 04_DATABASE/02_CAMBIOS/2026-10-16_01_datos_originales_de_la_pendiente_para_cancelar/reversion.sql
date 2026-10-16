-- 04_DATABASE/02_CAMBIOS/2026-10-16_01_datos_originales_de_la_pendiente_para_cancelar/reversion.sql
-- Deshace cambio.sql: las pendientes iniciadas ya no podrán volver a su estado original al cancelarse.
ALTER TABLE supervisiones DROP COLUMN IF EXISTS datos_pendiente;
