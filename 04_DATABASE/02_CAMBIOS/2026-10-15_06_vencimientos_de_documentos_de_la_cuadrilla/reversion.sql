-- 04_DATABASE/02_CAMBIOS/2026-10-15_06_vencimientos_de_documentos_de_la_cuadrilla/reversion.sql
-- Deshace cambio.sql: se pierde el historial de vencimientos. La falta nueva se quita solo si ninguna observación la usa.
DROP TABLE IF EXISTS documentos_vencimiento;
DELETE s FROM subopciones_observacion s
WHERE s.descripcion_subopcion = 'VEHÍCULO CON REVISIÓN TÉCNICA VENCIDA'
  AND NOT EXISTS (SELECT 1 FROM observaciones_supervision o WHERE o.subopcion_id = s.id);
