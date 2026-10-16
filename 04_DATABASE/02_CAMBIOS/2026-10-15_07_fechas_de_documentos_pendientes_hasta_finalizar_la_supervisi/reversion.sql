-- 04_DATABASE/02_CAMBIOS/2026-10-15_07_fechas_de_documentos_pendientes_hasta_finalizar_la_supervisi/reversion.sql
-- Deshace cambio.sql: las fechas pendientes (de supervisiones en proceso) se borran, porque sin la columna pasarían a contar como vigentes.
DELETE FROM documentos_vencimiento WHERE aplicado = 0;
ALTER TABLE documentos_vencimiento DROP INDEX IF EXISTS idx_documentos_vencimiento_supervision_aplicado;
ALTER TABLE documentos_vencimiento DROP COLUMN IF EXISTS aplicado;
