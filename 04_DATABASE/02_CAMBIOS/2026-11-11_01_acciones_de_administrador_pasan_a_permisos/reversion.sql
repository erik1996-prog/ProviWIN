-- Reversión de 2026-11-11_01: quita de los perfiles los permisos nuevos.
DELETE FROM perfil_permisos WHERE permiso IN ('supervision.filter_people', 'clima.teams', 'turnos.reopen', 'supervision.correct', 'rescates.manage', 'observation.manage');
