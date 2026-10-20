-- 04_DATABASE/02_CAMBIOS/2026-10-20_01_links_generales_de_examen_por_grupo_de_tecnicos/reversion.sql
-- Deshace cambio.sql: borra los links generales de examen y sus técnicos (los links ya enviados dejan de funcionar).
-- Revertir: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_01_links_generales_de_examen_por_grupo_de_tecnicos

DROP TABLE IF EXISTS examen_convocatoria_tecnicos;
DROP TABLE IF EXISTS examen_convocatorias;
