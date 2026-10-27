-- 04_DATABASE/02_CAMBIOS/2026-10-27_02_area_vt_visitas_tecnicas/reversion.sql
-- Fecha: 2026-10-27
-- Deshace cambio.sql: el área de trabajo vuelve a "Averías" e "Instalaciones y averías".

UPDATE usuarios SET area_trabajo = 'Averías' WHERE area_trabajo = 'VT (visitas técnicas)';
UPDATE usuarios SET area_trabajo = 'Instalaciones y averías' WHERE area_trabajo = 'Instalaciones y VT';