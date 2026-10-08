-- 07_SCRIPTS/limpiar_ingresos.sql
-- Objetivo: dejar la base SIN ingresos de prueba (supervisiones, observaciones, checklists, historiales,
--           notificaciones e inicios de turno), conservando usuarios, permisos, zonas, partners, técnicos,
--           vehículos, catálogos y penalidades.
-- ATENCIÓN: borra registros. Ejecutar solo después de un respaldo:
--   C:\xampp\php\php.exe 07_SCRIPTS\respaldar.php antes-de-limpiar-ingresos
--   (respaldo ya tomado el 2026-10-08: 10_RESPALDOS/base/win_telecom_db_20261008_171811_antes-de-limpiar-ingresos.sql.gz)
-- Ejecutar: phpMyAdmin (seleccionar la base → Importar) o
--   C:\xampp\mysql\bin\mysql.exe -u root -p win_telecom_db < 07_SCRIPTS\limpiar_ingresos.sql
-- Las fotos ya subidas no se borran: quedan en la carpeta uploads/ sin registro asociado.
-- Orden: primero las tablas que dependen de otras. Todo en una transacción: o se borra todo o nada.

START TRANSACTION;
DELETE FROM notificaciones;
DELETE FROM checklist_respuestas;
DELETE FROM checklists_tecnicos;
DELETE FROM observaciones_supervision;
DELETE FROM supervision_estado_historial;
DELETE FROM supervision_coordenadas_historial;
DELETE FROM supervisiones;
DELETE FROM asistencia_eventos;
DELETE FROM asistencia_supervisores;
DELETE FROM login_attempts;
COMMIT;

-- Verificación: todo en 0 menos lo que se conserva
SELECT 'supervisiones' AS tabla, COUNT(*) AS registros FROM supervisiones
UNION ALL SELECT 'inicios de turno', COUNT(*) FROM asistencia_supervisores
UNION ALL SELECT 'observaciones', COUNT(*) FROM observaciones_supervision
UNION ALL SELECT 'usuarios (se conservan)', COUNT(*) FROM usuarios
UNION ALL SELECT 'penalidades (se conservan)', COUNT(*) FROM subopciones_observacion
UNION ALL SELECT 'técnicos (se conservan)', COUNT(*) FROM tecnicos;
