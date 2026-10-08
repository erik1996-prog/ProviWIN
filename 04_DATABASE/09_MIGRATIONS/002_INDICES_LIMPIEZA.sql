-- 04_DATABASE/09_MIGRATIONS/002_INDICES_LIMPIEZA.sql
-- Estado: APLICADA el 2026-10-08 (respaldo previo: 10_BACKUPS/win_telecom_db_20261008_013650_manual.sql.gz). Sale de `php 07_SCRIPTS/auditar_bd.php` (2026-10-08).
-- Qué hace: quita 5 índices repetidos (otro índice idéntico ya cubre las mismas columnas) y agrega 2 que faltan.
-- Impacto: no cambia datos ni columnas; el sistema de origen no usa estos nombres de índice (verificado por búsqueda).
--          Se conserva idx_supervision_estado_cancelacion porque el sistema de origen lo recrea si falta.
-- Requiere MariaDB (IF EXISTS / IF NOT EXISTS en índices). Respaldar antes: php 07_SCRIPTS/respaldar_bd.php
-- Aplicar: php 07_SCRIPTS/migrar.php      Reversión: ver rollback/002_INDICES_LIMPIEZA.sql

-- Repetidos (mismas columnas que otro índice)
ALTER TABLE observaciones_supervision DROP INDEX IF EXISTS idx_obs_creador_fecha;
ALTER TABLE observaciones_supervision DROP INDEX IF EXISTS idx_obs_supervision_estado;
ALTER TABLE supervisiones DROP INDEX IF EXISTS idx_sup_estado_cancelacion;
ALTER TABLE asistencia_supervisores DROP INDEX IF EXISTS idx_sup_fecha;
ALTER TABLE ubigeo DROP INDEX IF EXISTS idx_geo;

-- Faltantes: turnos por día (línea de tiempo, monitor, cierre automático 23:59) y rastreo de errores por request_id
CREATE INDEX IF NOT EXISTS IX_ASISTENCIA_FECHA ON asistencia_supervisores (fecha);
CREATE INDEX IF NOT EXISTS IX_SYSTEM_HEALTH_REQUEST ON system_health_events (request_id);
