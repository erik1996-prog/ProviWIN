-- Reversión manual de 002_INDICES_LIMPIEZA.sql (no la ejecuta migrar.php). Luego: DELETE FROM SGS_MIGRACION WHERE ARCHIVO='002_INDICES_LIMPIEZA.sql';
CREATE INDEX IF NOT EXISTS idx_obs_creador_fecha ON observaciones_supervision (creado_por, fecha_registro);
CREATE INDEX IF NOT EXISTS idx_obs_supervision_estado ON observaciones_supervision (supervision_id, estado_respuesta);
CREATE INDEX IF NOT EXISTS idx_sup_estado_cancelacion ON supervisiones (es_cancelacion, estado_cancelacion, estado_supervision);
CREATE INDEX IF NOT EXISTS idx_sup_fecha ON asistencia_supervisores (supervisor_id, fecha);
CREATE INDEX IF NOT EXISTS idx_geo ON ubigeo (departamento, provincia, distrito);
ALTER TABLE asistencia_supervisores DROP INDEX IF EXISTS IX_ASISTENCIA_FECHA;
ALTER TABLE system_health_events DROP INDEX IF EXISTS IX_SYSTEM_HEALTH_REQUEST;
