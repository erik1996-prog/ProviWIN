-- 04_DATABASE/02_CAMBIOS/2026-10-20_05_comentario_de_inicio_y_fin_de_la_lluvia/cambio.sql
-- Fecha: 2026-10-20
-- Qué cambia y por qué:
--   La lluvia ya no cambia la actividad del supervisor (puede llover hasta en plena supervisión). Al avisarla y al cerrarla
--   el sistema anota solo qué estaba haciendo quien la avisó y quien la cerró (por ejemplo "Supervisando TCK-123",
--   "En almuerzo", "En turno"). Es la bitácora del indicador de lluvias por zona.
-- Tablas afectadas: lluvias_zona.
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_05_comentario_de_inicio_y_fin_de_la_lluvia
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE lluvias_zona
  ADD COLUMN IF NOT EXISTS comentario_inicio varchar(150) DEFAULT NULL COMMENT 'Qué hacía quien avisó la lluvia' AFTER reportado_por,
  ADD COLUMN IF NOT EXISTS comentario_fin varchar(150) DEFAULT NULL COMMENT 'Qué hacía quien la cerró' AFTER cerrado_por;
