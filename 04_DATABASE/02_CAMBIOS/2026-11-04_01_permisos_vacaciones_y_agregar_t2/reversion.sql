-- Reversión de 2026-11-04_01: quita los permisos de Vacaciones y licencias y "Agregar T2" de todos los perfiles.
DELETE FROM perfil_permisos WHERE permiso IN ('vacaciones.view', 'vacaciones.request', 'vacaciones.approve', 'cuadrillas.create_t2');
