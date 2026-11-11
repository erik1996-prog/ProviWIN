-- Reversión de 2026-11-10_01: quita los permisos nuevos y devuelve "Validar" (Control y recurrencia) a los perfiles de tipo
-- Supervisor, como venía por defecto (el código anterior igual no se lo mostraba).
DELETE FROM perfil_permisos WHERE permiso IN ('turnos.monitor', 'ordenes.cercanas');
INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) SELECT p.id, 'observation.review' FROM perfiles p WHERE p.rol_base = 'Supervisor' AND EXISTS (SELECT 1 FROM perfil_permisos x WHERE x.perfil_id = p.id AND x.permiso = 'observation.view');
