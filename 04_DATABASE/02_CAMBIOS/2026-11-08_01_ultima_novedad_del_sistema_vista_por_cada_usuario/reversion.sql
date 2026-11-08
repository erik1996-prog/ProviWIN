-- Reversión de 2026-11-08_01: quita la columna de la última novedad vista.
ALTER TABLE usuarios DROP COLUMN IF EXISTS novedad_vista;
