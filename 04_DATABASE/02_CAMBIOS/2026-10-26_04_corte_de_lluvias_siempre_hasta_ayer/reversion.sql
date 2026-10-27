-- 04_DATABASE/02_CAMBIOS/2026-10-26_04_corte_de_lluvias_siempre_hasta_ayer/reversion.sql
-- Fecha: 2026-10-26
-- Deshace cambio.sql: vuelve la columna "Incluye hoy" (todos los horarios quedan "Hasta ayer").

ALTER TABLE lluvias_cortes_programados
  ADD COLUMN IF NOT EXISTS incluye_hoy tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = matriz hasta ayer; 1 = incluye hoy hasta la hora del envío' AFTER hora;
