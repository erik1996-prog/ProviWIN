-- 04_DATABASE/02_CAMBIOS/2026-10-31_02_unir_perfiles_personalizados_iguales/cambio.sql
-- Fecha: 2026-10-31
-- Qué cambia y por qué: el cambio 2026-10-31_01 dio un perfil propio ("Supervisor · usuario", código U + id) a cada usuario
-- que tenía permisos personalizados. El editor anterior guardaba TODOS los permisos de esa persona aunque fueran iguales a
-- los de su rol, así que en el hosting salieron muchos perfiles propios idénticos al perfil base. Aquí:
--   1. El perfil propio con los mismos permisos que el perfil base de su tipo: sus usuarios pasan al base y se elimina.
--   2. Los que quedan (permisos de verdad distintos) se juntan: un perfil por cada combinación de permisos, llamado
--      "<base> (permisos anteriores N)", para que el administrador general los revise.
-- Tablas afectadas: perfiles, perfil_permisos y perfil_asignables (filas), usuarios.perfil_id.
-- Cómo deshacerlo: no se puede volver a separar a cada persona en su perfil propio. reversion.sql no hace nada; si hace
--          falta, restaurar el respaldo tomado antes de aplicar.
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

SET SESSION group_concat_max_len = 20000;

-- Permisos de cada perfil en una sola línea ("firma") para comparar
CREATE TEMPORARY TABLE tmp_firma_base AS
SELECT p.id, p.rol_base, COALESCE((SELECT GROUP_CONCAT(x.permiso ORDER BY x.permiso) FROM perfil_permisos x WHERE x.perfil_id = p.id), '') AS firma
FROM perfiles p WHERE p.codigo IN ('ADMIN', 'CONS', 'GEST', 'PART', 'SUP');

CREATE TEMPORARY TABLE tmp_firma_propio AS
SELECT p.id, p.rol_base, COALESCE((SELECT GROUP_CONCAT(x.permiso ORDER BY x.permiso) FROM perfil_permisos x WHERE x.perfil_id = p.id), '') AS firma
FROM perfiles p WHERE p.codigo REGEXP '^U[0-9]+$';

-- 1. Iguales al perfil base: sus usuarios pasan al base
UPDATE usuarios u
JOIN tmp_firma_propio f ON f.id = u.perfil_id
JOIN tmp_firma_base b ON b.rol_base = f.rol_base AND b.firma = f.firma
SET u.perfil_id = b.id;

DELETE p FROM perfiles p
JOIN tmp_firma_propio f ON f.id = p.id
JOIN tmp_firma_base b ON b.rol_base = f.rol_base AND b.firma = f.firma;

-- 2. Los distintos: uno por cada combinación (tipo base + permisos); los demás pasan a ese y se eliminan
CREATE TEMPORARY TABLE tmp_grupo AS
SELECT MIN(f.id) AS queda, f.rol_base, f.firma
FROM tmp_firma_propio f JOIN perfiles p ON p.id = f.id
GROUP BY f.rol_base, f.firma;

UPDATE usuarios u
JOIN tmp_firma_propio f ON f.id = u.perfil_id
JOIN tmp_grupo g ON g.rol_base = f.rol_base AND g.firma = f.firma
SET u.perfil_id = g.queda;

DELETE p FROM perfiles p
JOIN tmp_firma_propio f ON f.id = p.id
LEFT JOIN tmp_grupo g ON g.queda = p.id
WHERE g.queda IS NULL;

UPDATE perfiles p
JOIN tmp_grupo g ON g.queda = p.id
JOIN perfiles b ON b.rol_base = p.rol_base AND b.codigo IN ('ADMIN', 'CONS', 'GEST', 'PART', 'SUP')
SET p.nombre = LEFT(CONCAT(b.nombre, ' (permisos anteriores ', p.id, ')'), 60),
    p.descripcion = 'Permisos que tenían personalizados antes de los perfiles. Revíselos en Permisos o pase a sus usuarios a otro perfil.';

DROP TEMPORARY TABLE tmp_grupo;
DROP TEMPORARY TABLE tmp_firma_propio;
DROP TEMPORARY TABLE tmp_firma_base;
