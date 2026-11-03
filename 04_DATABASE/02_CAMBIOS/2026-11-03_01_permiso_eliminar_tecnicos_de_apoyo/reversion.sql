-- Reversión de 2026-11-03_01: quita el permiso "Eliminar técnicos de apoyo (T2)" de todos los perfiles.
DELETE FROM perfil_permisos WHERE permiso = 'cuadrillas.delete';
