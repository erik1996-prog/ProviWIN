-- 04_DATABASE/02_CAMBIOS/2026-10-26_01_quitar_indices_duplicados/reversion.sql
-- Fecha: 2026-10-26
-- Deshace cambio.sql: vuelve a crear los dos índices.

ALTER TABLE supervisiones ADD INDEX IF NOT EXISTS idx_supervisiones_partner_id (partner_id);
ALTER TABLE asistencia_eventos ADD INDEX IF NOT EXISTS idx_asistencia_eventos_asistencia_id (asistencia_id);
