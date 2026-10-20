-- 04_DATABASE/02_CAMBIOS/2026-10-20_02_examen_con_preguntas_y_resultados/reversion.sql
-- Deshace cambio.sql: borra los resultados por pregunta y las columnas del examen (se pierden notas y respuestas).
-- Revertir: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_02_examen_con_preguntas_y_resultados

DROP TABLE IF EXISTS examen_respuestas;

ALTER TABLE examen_convocatoria_tecnicos
  DROP KEY IF EXISTS idx_examen_convocatoria_tecnicos_usado,
  DROP COLUMN IF EXISTS segundos,
  DROP COLUMN IF EXISTS aprobado,
  DROP COLUMN IF EXISTS nota,
  DROP COLUMN IF EXISTS total_preguntas,
  DROP COLUMN IF EXISTS correctas,
  DROP COLUMN IF EXISTS respuestas,
  DROP COLUMN IF EXISTS preguntas,
  DROP COLUMN IF EXISTS inicio_en,
  DROP COLUMN IF EXISTS sesion_hash;

ALTER TABLE examen_convocatorias DROP COLUMN IF EXISTS examen;
