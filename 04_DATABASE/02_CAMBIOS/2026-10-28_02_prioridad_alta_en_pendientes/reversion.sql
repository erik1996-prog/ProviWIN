-- 04_DATABASE/02_CAMBIOS/2026-10-28_02_prioridad_alta_en_pendientes/reversion.sql
-- Deshace el cambio: quita la columna prioridad_alta (se pierden las marcas de prioridad).

ALTER TABLE supervisiones DROP COLUMN IF EXISTS prioridad_alta;
