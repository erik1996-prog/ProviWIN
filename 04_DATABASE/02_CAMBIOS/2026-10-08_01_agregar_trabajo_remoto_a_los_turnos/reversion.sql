-- 04_DATABASE/02_CAMBIOS/2026-10-08_01_agregar_trabajo_remoto_a_los_turnos/reversion.sql
-- Deshace el cambio del 2026-10-08: agregar trabajo remoto a los turnos.
-- Ejecutar con: php 07_SCRIPTS/migrar.php --revertir 2026-10-08_01_agregar_trabajo_remoto_a_los_turnos
-- ATENCIÓN: los registros de trabajo remoto que ya existan pasan a "campo" y los supervisores "En remoto" a "En turno"
-- (la lista ya no tendrá esa opción). El detalle original se recupera del respaldo tomado antes del cambio (10_RESPALDOS/base).

SET NAMES utf8mb4;
UPDATE asistencia_eventos SET tipo_evento = 'campo' WHERE tipo_evento = 'remoto';
UPDATE asistencia_supervisores SET estado_turno = 'En turno' WHERE estado_turno = 'En remoto';
ALTER TABLE asistencia_eventos MODIFY COLUMN tipo_evento ENUM('almuerzo','reunion','capacitacion','campo') NOT NULL;
ALTER TABLE asistencia_supervisores MODIFY COLUMN estado_turno ENUM('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento') DEFAULT 'En turno';
