-- 04_DATABASE/02_CAMBIOS/2026-11-01_01_solo_perfiles_del_organigrama/cambio.sql
-- Fecha: 2026-11-01
-- Qué cambia y por qué (pedido del usuario: "trabaja encima de los perfiles reales y borra los antiguos"):
-- quedan solo los perfiles del organigrama. Cada persona con un perfil antiguo pasa a su perfil real:
--   - "Supervisor (permisos anteriores N)" → Supervisor UM (o Supervisor de partner si es de un partner).
--   - "Gestión" y "Gestión (permisos anteriores N)" (el líder técnico de antes) → el Líder técnico de su área y zona:
--     área VT → Líder técnico de visitas técnicas (DBT); área Instalaciones o zona Lima/Callao/Norte Chico → Líder técnico
--     de instalaciones; si no → Líder técnico de provincia.
--   - Administrador, Gerente o Jefe de operaciones con "permisos anteriores" → Administrador, Gerente general o Jefe de
--     operaciones (partner).
-- Después se borran Gestión y todos los "(permisos anteriores N)" (código U + número).
-- Tablas afectadas: usuarios.perfil_id, perfiles (y en cascada perfil_permisos y perfil_asignables).
-- Cómo deshacerlo: no se puede volver a separar a cada persona; restaurar el respaldo tomado antes de aplicar.
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

-- Supervisores: directos → Supervisor UM; de un partner → Supervisor de partner
UPDATE usuarios u
JOIN perfiles o ON o.id = u.perfil_id
JOIN perfiles d ON d.codigo = 'SUM'
LEFT JOIN partners p ON p.id = u.partner_id
SET u.perfil_id = d.id
WHERE o.codigo REGEXP '^U[0-9]+$' AND o.rol_base = 'Supervisor'
  AND (p.id IS NULL OR UPPER(TRIM(p.nombre_partner)) IN ('WIN', 'WIN DIRECTO'));

UPDATE usuarios u
JOIN perfiles o ON o.id = u.perfil_id
JOIN perfiles d ON d.codigo = 'SPART'
JOIN partners p ON p.id = u.partner_id
SET u.perfil_id = d.id
WHERE o.codigo REGEXP '^U[0-9]+$' AND o.rol_base = 'Supervisor' AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN', 'WIN DIRECTO');

-- Administrador, Gerente y Jefe de operaciones con permisos anteriores → su perfil real
UPDATE usuarios u
JOIN perfiles o ON o.id = u.perfil_id
JOIN perfiles d ON d.codigo = CASE o.rol_base WHEN 'Administrador' THEN 'ADMIN' WHEN 'Gerente' THEN 'GG' WHEN 'Jefe de operaciones' THEN 'PART' END
SET u.perfil_id = d.id
WHERE o.codigo REGEXP '^U[0-9]+$' AND o.rol_base IN ('Administrador', 'Gerente', 'Jefe de operaciones');

-- Gestión (el líder técnico de antes) → el Líder técnico de su área y zona
UPDATE usuarios u
JOIN perfiles o ON o.id = u.perfil_id
JOIN perfiles d ON d.codigo = CASE
        WHEN u.area_trabajo = 'VT (visitas técnicas)' THEN 'LTVT'
        WHEN u.area_trabajo = 'Instalaciones' THEN 'LTINS'
        WHEN UPPER(TRIM(COALESCE(u.sede, ''))) IN ('LIMA', 'CALLAO', 'HUACHO')
          OR EXISTS (SELECT 1 FROM usuario_departamentos_acceso z WHERE z.usuario_id = u.id AND z.activo = 1
                     AND UPPER(z.departamento) IN ('LIMA', 'CALLAO', 'NORTE CHICO')) THEN 'LTINS'
        ELSE 'LTPROV' END
SET u.perfil_id = d.id
WHERE o.rol_base = 'Coordinador' AND (o.codigo = 'GEST' OR o.codigo REGEXP '^U[0-9]+$');

-- Fuera los perfiles antiguos
DELETE FROM perfiles WHERE codigo = 'GEST' OR codigo REGEXP '^U[0-9]+$';
