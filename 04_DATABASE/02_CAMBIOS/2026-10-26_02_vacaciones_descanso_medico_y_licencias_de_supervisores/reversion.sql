-- 04_DATABASE/02_CAMBIOS/2026-10-26_02_vacaciones_descanso_medico_y_licencias_de_supervisores/reversion.sql
-- Fecha: 2026-10-26
-- Deshace cambio.sql: quita la tabla de ausencias (los archivos de 11_PRIVADO/ausencias se borran a mano si se desea).

DROP TABLE IF EXISTS ausencias_supervisores;
