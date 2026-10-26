-- 04_DATABASE/02_CAMBIOS/2026-10-26_01_quitar_indices_duplicados/cambio.sql
-- Fecha: 2026-10-26
-- Qué cambia y por qué:
--   Revisión de rendimiento para ~2000 usuarios. Dos índices repetían la primera columna de otro índice más completo,
--   así que no aceleraban ninguna consulta y sí hacían más lento cada guardado (supervisiones se guarda sola mientras
--   se llena el formulario). Las consultas y las claves foráneas siguen usando los índices compuestos:
--     supervisiones.idx_supervisiones_partner_id      → cubierto por idx_supervisiones_partner_id_fecha_registro (y _estado_…)
--     asistencia_eventos.idx_asistencia_eventos_asistencia_id → cubierto por idx_asistencia_eventos_asistencia_id_inicio_evento
-- Tablas afectadas: supervisiones, asistencia_eventos (solo índices; no cambia ningún dato).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-26_01_quitar_indices_duplicados
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones DROP INDEX IF EXISTS idx_supervisiones_partner_id;
ALTER TABLE asistencia_eventos DROP INDEX IF EXISTS idx_asistencia_eventos_asistencia_id;
