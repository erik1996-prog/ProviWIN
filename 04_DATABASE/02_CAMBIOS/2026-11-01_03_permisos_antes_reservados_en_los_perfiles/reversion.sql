-- 04_DATABASE/02_CAMBIOS/2026-11-01_03_permisos_antes_reservados_en_los_perfiles/reversion.sql
-- Quita los permisos antes reservados de los perfiles que no son de tipo Administrador (el sistema anterior los daba solo
-- por el tipo Administrador). Los del Administrador se quedan: con el código anterior no se leen, y no estorban.
DELETE pp FROM perfil_permisos pp JOIN perfiles p ON p.id = pp.perfil_id
WHERE p.rol_base <> 'Administrador' AND pp.permiso IN ('supervision.import', 'supervision.delete', 'users.manage', 'system.manage');
