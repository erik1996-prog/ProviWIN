-- 04_DATABASE/02_CAMBIOS/2026-10-20_05_comentario_de_inicio_y_fin_de_la_lluvia/reversion.sql
-- Fecha: 2026-10-20
-- Deshace cambio.sql: quita los comentarios de inicio y fin de la lluvia.

ALTER TABLE lluvias_zona
  DROP COLUMN IF EXISTS comentario_inicio,
  DROP COLUMN IF EXISTS comentario_fin;
