-- 04_DATABASE/02_CAMBIOS/2026-11-03_03_observacion_del_supervisor_en_la_lluvia/cambio.sql
-- Fecha: 2026-11-03
-- Qué cambia y por qué (pedido del usuario): el comentario que escribe el supervisor al avisar la lluvia (Mi turno) solo se
--   publicaba en Teams y no se guardaba. Ahora se guarda para el Excel de lluvias (columna Observaciones). Las lluvias
--   anteriores no lo tienen: salen "Sin observaciones".
-- Tablas afectadas: lluvias_zona (columna nueva observacion).
-- Cómo deshacerlo: reversion.sql.
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

ALTER TABLE lluvias_zona
    ADD COLUMN observacion VARCHAR(200) DEFAULT NULL COMMENT 'Comentario que escribió el supervisor al avisar la lluvia' AFTER comentario_inicio;
