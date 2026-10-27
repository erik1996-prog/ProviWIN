-- 04_DATABASE/02_CAMBIOS/2026-10-26_05_huancayo_no_es_departamento/cambio.sql
-- Fecha: 2026-10-26
-- Qué cambia y por qué:
--   HUANCAYO figuraba como departamento en el catálogo de ubicaciones (ubigeo), repitiendo los 6 distritos que ya están en
--   JUNÍN › HUANCAYO. Huancayo es una provincia de Junín: se quitan las filas repetidas y los datos guardados con el
--   departamento "HUANCAYO" pasan a JUNÍN (supervisiones, técnicos, lluvias, vencimientos y accesos por zona).
--   La sede "Huancayo" de los usuarios es una ciudad y se conserva.
-- Tablas afectadas: ubigeo (filas), supervisiones, tecnicos, lluvias_zona, documentos_vencimiento, usuario_departamentos_acceso (datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-26_05_huancayo_no_es_departamento
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

INSERT IGNORE INTO ubigeo (departamento, provincia, distrito) SELECT 'JUNÍN', provincia, distrito FROM ubigeo WHERE departamento = 'HUANCAYO';
DELETE FROM ubigeo WHERE departamento = 'HUANCAYO';
UPDATE supervisiones SET departamento = 'JUNÍN' WHERE departamento = 'HUANCAYO';
UPDATE tecnicos SET departamento = 'JUNÍN' WHERE departamento = 'HUANCAYO';
UPDATE lluvias_zona SET departamento = 'JUNÍN' WHERE departamento = 'HUANCAYO';
UPDATE documentos_vencimiento SET departamento = 'JUNÍN' WHERE departamento = 'HUANCAYO';
UPDATE IGNORE usuario_departamentos_acceso SET departamento = 'JUNÍN' WHERE departamento = 'HUANCAYO';
DELETE FROM usuario_departamentos_acceso WHERE departamento = 'HUANCAYO';
