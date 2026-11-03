-- Reversión de 2026-11-03_02: quita el segmento por distrito (el segmento vuelve a elegirse a mano).
ALTER TABLE ubigeo DROP COLUMN segmento_servicio;
