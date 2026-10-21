-- 04_DATABASE/02_CAMBIOS/2026-10-20_04_lluvia_reportada_por_zona/reversion.sql
-- Fecha: 2026-10-20
-- Deshace cambio.sql: elimina la tabla de lluvias (se pierden los avisos registrados).

DROP TABLE IF EXISTS lluvias_zona;
