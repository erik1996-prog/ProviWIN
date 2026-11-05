-- Reversión de 2026-11-03_03: quita la observación del supervisor de las lluvias.
ALTER TABLE lluvias_zona DROP COLUMN observacion;
