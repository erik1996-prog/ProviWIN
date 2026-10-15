-- 04_DATABASE/02_CAMBIOS/2026-10-13_01_fecha_de_carga_de_las_pendientes/reversion.sql
-- Deshace cambio.sql: quita el índice y la columna fecha_carga (se pierden las fechas de carga registradas).
DROP INDEX IF EXISTS idx_supervisiones_origen_importacion_fecha_carga ON supervisiones;
ALTER TABLE supervisiones DROP COLUMN IF EXISTS fecha_carga;
