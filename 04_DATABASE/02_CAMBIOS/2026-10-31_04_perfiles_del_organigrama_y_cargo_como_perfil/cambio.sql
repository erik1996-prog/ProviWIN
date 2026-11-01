-- 04_DATABASE/02_CAMBIOS/2026-10-31_04_perfiles_del_organigrama_y_cargo_como_perfil/cambio.sql
-- Fecha: 2026-10-31
-- Qué cambia y por qué (pedido del usuario: "los perfiles son los del organigrama"):
--   1. Los perfiles son los cargos del organigrama de WIN (personal directo) más los del partner:
--      Gerente general · Subgerente Lima · Subgerente Provincia · Coordinador de instalaciones · Líder técnico de
--      instalaciones · Coordinador de visitas técnicas (DBT) · Líder técnico de visitas técnicas (DBT) · Coordinador de
--      provincia · Líder técnico de provincia · Supervisor SGI · Supervisor SGA · Supervisor UM · Gestor SGI · Gestor SGA ·
--      Jefe de operaciones (partner) · Supervisor de partner · Administrador (la cuenta principal).
--      - "Consulta" pasa a ser "Gerente general" y "Supervisor" pasa a ser "Supervisor UM" (sus usuarios siguen ahí).
--      - Los supervisores de un partner pasan a "Supervisor de partner" (mismos permisos).
--      - "Gestión" queda solo para quien ya lo tenga: el administrador general los pasa al perfil que corresponda.
--      Cada perfil nuevo empieza con los permisos de su tipo base y con los perfiles que puede asignar según el organigrama.
--   2. El cargo es el perfil: se elimina usuarios.cargo (lista fija en el código que repetía lo mismo) y el área
--      "SUPERVISOR UM" que algunos tenían por error (era su cargo, no su área).
-- Tablas afectadas: perfiles, perfil_permisos, perfil_asignables, usuarios (perfil_id, area_trabajo, se quita cargo).
-- Cómo deshacerlo: reversion.sql (vuelve la columna cargo vacía y los nombres de antes; los perfiles nuevos sin
--          usuarios se borran) o restaurar el respaldo tomado antes de aplicar.
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

-- 1. Los perfiles de siempre toman su nombre del organigrama
UPDATE perfiles SET nombre = 'Gerente general', codigo = 'GG',
    descripcion = 'Gerente general: ve todo el país (indicadores, supervisiones, observaciones y personal).' WHERE codigo = 'CONS';
UPDATE perfiles SET nombre = 'Supervisor UM', codigo = 'SUM',
    descripcion = 'Supervisor de última milla: en campo valida el trabajo de los técnicos y registra sus supervisiones y su turno.' WHERE codigo = 'SUP';
UPDATE perfiles SET nombre = 'Jefe de operaciones (partner)',
    descripcion = 'Jefe de operaciones de la contrata: consulta las órdenes, observaciones y personal de su partner.' WHERE codigo = 'PART';
UPDATE perfiles SET descripcion = 'Perfil anterior al organigrama: pase a sus usuarios al perfil que corresponda y elimínelo.' WHERE codigo = 'GEST';

-- 2. Perfiles nuevos del organigrama (tipo base y qué datos ven)
INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance) VALUES
    ('Subgerente Lima', 'SGL', 'Gestiona a toda su gente de Lima, Callao y Norte Chico y aprueba sus vacaciones.', 'Coordinador', 'zonas'),
    ('Subgerente Provincia', 'SGP', 'Gestiona a toda su gente de provincia y aprueba sus vacaciones.', 'Coordinador', 'zonas'),
    ('Coordinador de instalaciones', 'CINS', 'Coordina instalaciones (SGI) en Lima: supervisores SGI y gestores SGI.', 'Coordinador', 'zonas'),
    ('Líder técnico de instalaciones', 'LTINS', 'Lidera a los supervisores de última milla de instalaciones en Lima.', 'Coordinador', 'zonas'),
    ('Coordinador de visitas técnicas (DBT)', 'CVT', 'Coordina visitas técnicas (SGA) en Lima: supervisores SGA y gestores SGA.', 'Coordinador', 'zonas'),
    ('Líder técnico de visitas técnicas (DBT)', 'LTVT', 'Lidera a los supervisores de última milla de visitas técnicas en Lima.', 'Coordinador', 'zonas'),
    ('Coordinador de provincia', 'CPROV', 'Coordina provincia: supervisores SGI y SGA y sus gestores.', 'Coordinador', 'zonas'),
    ('Líder técnico de provincia', 'LTPROV', 'Lidera a los supervisores de última milla de provincia.', 'Coordinador', 'zonas'),
    ('Supervisor SGI', 'SSGI', 'Remoto: hace seguimiento a los gestores de instalaciones.', 'Supervisor', 'propio'),
    ('Supervisor SGA', 'SSGA', 'Remoto: hace seguimiento a los gestores de visitas técnicas.', 'Supervisor', 'propio'),
    ('Gestor SGI', 'GSGI', 'Coordina con los técnicos de los partners las órdenes de instalación: llamadas, asignación y demoras.', 'Supervisor', 'propio'),
    ('Gestor SGA', 'GSGA', 'Coordina con los técnicos de los partners las visitas técnicas: llamadas, asignación y demoras.', 'Supervisor', 'propio'),
    ('Supervisor de partner', 'SPART', 'Supervisor de la contrata: atiende las órdenes que le transfieren y responde observaciones.', 'Supervisor', 'propio');

-- Permisos de partida: los del perfil base de su tipo (Gestión para coordinadores, subgerentes y líderes; Supervisor UM
-- para supervisores y supervisor de partner). Los gestores, solo consulta de supervisiones, pendientes y cuadrillas.
INSERT INTO perfil_permisos (perfil_id, permiso)
SELECT n.id, b.permiso FROM perfiles n JOIN perfiles base ON base.codigo = 'GEST' JOIN perfil_permisos b ON b.perfil_id = base.id
WHERE n.codigo IN ('SGL', 'SGP', 'CINS', 'LTINS', 'CVT', 'LTVT', 'CPROV', 'LTPROV');
INSERT INTO perfil_permisos (perfil_id, permiso)
SELECT n.id, b.permiso FROM perfiles n JOIN perfiles base ON base.codigo = 'SUM' JOIN perfil_permisos b ON b.perfil_id = base.id
WHERE n.codigo IN ('SSGI', 'SSGA', 'SPART');
INSERT INTO perfil_permisos (perfil_id, permiso)
SELECT n.id, x.permiso FROM perfiles n JOIN (
    SELECT 'supervision.view' AS permiso UNION ALL SELECT 'supervision.view_all' UNION ALL SELECT 'supervision.filters'
    UNION ALL SELECT 'supervision.filter.date' UNION ALL SELECT 'supervision.filter.status' UNION ALL SELECT 'supervision.filter.columns'
    UNION ALL SELECT 'pendientes.view' UNION ALL SELECT 'cuadrillas.view' UNION ALL SELECT 'attendance.self'
) x WHERE n.codigo IN ('GSGI', 'GSGA');

-- 3. Los supervisores de un partner pasan a "Supervisor de partner"
UPDATE usuarios u
JOIN perfiles s ON s.codigo = 'SUM'
JOIN perfiles sp ON sp.codigo = 'SPART'
JOIN partners p ON p.id = u.partner_id
SET u.perfil_id = sp.id
WHERE u.perfil_id = s.id AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN', 'WIN DIRECTO');

-- 4. Perfiles que puede asignar cada uno (según el organigrama; los coordinadores también al personal del partner)
DELETE FROM perfil_asignables;
INSERT INTO perfil_asignables (perfil_id, asignable_id)
SELECT a.id, b.id FROM perfiles a JOIN perfiles b ON (
       (a.codigo = 'ADMIN')
    OR (a.codigo = 'GG' AND b.codigo NOT IN ('ADMIN', 'GG'))
    OR (a.codigo = 'SGL' AND b.codigo IN ('CINS', 'LTINS', 'CVT', 'LTVT', 'SSGI', 'SSGA', 'SUM', 'GSGI', 'GSGA', 'PART', 'SPART'))
    OR (a.codigo = 'SGP' AND b.codigo IN ('CPROV', 'LTPROV', 'SSGI', 'SSGA', 'SUM', 'GSGI', 'GSGA', 'PART', 'SPART'))
    OR (a.codigo = 'CINS' AND b.codigo IN ('SSGI', 'GSGI', 'PART', 'SPART'))
    OR (a.codigo = 'CVT' AND b.codigo IN ('SSGA', 'GSGA', 'PART', 'SPART'))
    OR (a.codigo = 'CPROV' AND b.codigo IN ('SSGI', 'SSGA', 'GSGI', 'GSGA', 'PART', 'SPART'))
    OR (a.codigo IN ('LTINS', 'LTVT', 'LTPROV') AND b.codigo = 'SUM')
    OR (a.codigo = 'GEST' AND b.codigo IN ('SUM', 'PART', 'SPART'))
);

-- 5. El cargo es el perfil: fuera la columna cargo y el área "SUPERVISOR UM" (era su cargo)
UPDATE usuarios SET area_trabajo = NULL WHERE UPPER(TRIM(area_trabajo)) = 'SUPERVISOR UM';
ALTER TABLE usuarios DROP COLUMN cargo;
