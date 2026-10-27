-- 04_DATABASE/02_CAMBIOS/2026-10-26_05_huancayo_no_es_departamento/reversion.sql
-- Fecha: 2026-10-26
-- Deshace cambio.sql: vuelve el departamento repetido "HUANCAYO" al catálogo de ubicaciones. Los datos que pasaron a
-- JUNÍN se quedan así (no se puede saber cuáles eran "HUANCAYO"; son la misma zona).

INSERT IGNORE INTO ubigeo (departamento, provincia, distrito) SELECT 'HUANCAYO', provincia, distrito FROM ubigeo WHERE departamento = 'JUNÍN' AND provincia = 'HUANCAYO';
