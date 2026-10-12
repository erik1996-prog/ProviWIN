-- 04_DATABASE/02_CAMBIOS/2026-10-08_01_agregar_trabajo_remoto_a_los_turnos/cambio.sql
-- Fecha: 2026-10-08
-- Qué cambia y por qué: el supervisor puede marcar "Trabajo remoto" durante su turno (igual que reunión o
--   capacitación) y así aparece en la línea de tiempo.
-- Tablas afectadas:
--   asistencia_eventos.tipo_evento        + 'remoto'      (tipo de actividad)
--   asistencia_supervisores.estado_turno  + 'En remoto'   (estado del supervisor mientras está en remoto)
-- Datos: NO se modifican. Solo se agrega una opción nueva a cada lista; las opciones existentes quedan igual.
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-08_01_agregar_trabajo_remoto_a_los_turnos
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin y luego anotarlo:
--          INSERT INTO sgs_migracion (archivo) VALUES ('2026-10-08_01_agregar_trabajo_remoto_a_los_turnos');

SET NAMES utf8mb4;
ALTER TABLE asistencia_eventos MODIFY COLUMN tipo_evento ENUM('almuerzo','reunion','capacitacion','campo','remoto') NOT NULL;
ALTER TABLE asistencia_supervisores MODIFY COLUMN estado_turno ENUM('En turno','Fuera de turno','En break','En capacitación','En almuerzo','En reunión','Vacaciones','Descanso Médico','Licencia Paternidad','Permiso Fallecimiento','En remoto') DEFAULT 'En turno';
