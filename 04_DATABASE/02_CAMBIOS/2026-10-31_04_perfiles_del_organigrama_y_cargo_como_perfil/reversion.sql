-- 04_DATABASE/02_CAMBIOS/2026-10-31_04_perfiles_del_organigrama_y_cargo_como_perfil/reversion.sql
-- Vuelve la columna cargo (vacía), los nombres de antes y los supervisores de partner al perfil Supervisor. Los perfiles
-- nuevos se borran si no tienen usuarios (si tienen, se quedan: pase antes a sus usuarios a otro perfil).

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cargo VARCHAR(60) DEFAULT NULL COMMENT 'Puesto en la empresa (lista fija en el sistema); no da permisos' AFTER area_trabajo;

UPDATE usuarios u JOIN perfiles sp ON sp.codigo = 'SPART' JOIN perfiles s ON s.codigo = 'SUM' SET u.perfil_id = s.id WHERE u.perfil_id = sp.id;

DELETE p FROM perfiles p LEFT JOIN usuarios u ON u.perfil_id = p.id
WHERE p.codigo IN ('SGL', 'SGP', 'CINS', 'LTINS', 'CVT', 'LTVT', 'CPROV', 'LTPROV', 'SSGI', 'SSGA', 'GSGI', 'GSGA', 'SPART') AND u.id IS NULL;

UPDATE perfiles SET nombre = 'Consulta', codigo = 'CONS', descripcion = 'Vista general: indicadores, supervisiones y observaciones. No opera ni valida.' WHERE codigo = 'GG';
UPDATE perfiles SET nombre = 'Supervisor', codigo = 'SUP', descripcion = 'Trabaja en campo: registra supervisiones y su turno (directo) o atiende las órdenes asignadas (partner).' WHERE codigo = 'SUM';
UPDATE perfiles SET nombre = 'Partner' WHERE codigo = 'PART';
UPDATE perfiles SET descripcion = 'Líder técnico: revisa y valida observaciones, monitorea turnos y gestiona a su equipo.' WHERE codigo = 'GEST';

DELETE FROM perfil_asignables;
INSERT INTO perfil_asignables (perfil_id, asignable_id)
SELECT a.id, b.id FROM perfiles a JOIN perfiles b
WHERE a.codigo = 'ADMIN' OR (a.codigo = 'GEST' AND b.codigo IN ('SUP', 'PART'));
