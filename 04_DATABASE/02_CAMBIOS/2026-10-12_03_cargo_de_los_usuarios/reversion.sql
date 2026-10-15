-- 04_DATABASE/02_CAMBIOS/2026-10-12_03_cargo_de_los_usuarios/reversion.sql
-- Deshace cambio.sql: quita la columna cargo (se pierden los cargos registrados).
ALTER TABLE usuarios DROP COLUMN IF EXISTS cargo;
