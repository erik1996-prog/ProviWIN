-- 04_DATABASE/02_CAMBIOS/2026-10-13_03_rescate_de_pendientes_con_seguimiento/reversion.sql
-- Deshace cambio.sql: borra el historial de rescates y las columnas de rescate (se pierden los rescates registrados;
-- las pendientes que estaban "En rescate" vuelven a la bolsa común de su zona).
DROP TABLE IF EXISTS rescate_seguimientos;
DROP INDEX IF EXISTS idx_supervisiones_rescate_estado_supervisor_id ON supervisiones;
ALTER TABLE supervisiones
    DROP COLUMN IF EXISTS rescate_fin,
    DROP COLUMN IF EXISTS rescate_inicio,
    DROP COLUMN IF EXISTS rescate_estado;
