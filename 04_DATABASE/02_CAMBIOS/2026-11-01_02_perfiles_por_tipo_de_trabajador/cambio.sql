-- 04_DATABASE/02_CAMBIOS/2026-11-01_02_perfiles_por_tipo_de_trabajador/cambio.sql
-- Fecha: 2026-11-01
-- Qué cambia y por qué (pedido del usuario: "la zona y el área ya se eligen al crear el usuario"):
--   Perfiles por tipo de trabajador, sin la zona ni el área en el nombre:
--   Subgerente Lima + Subgerente Provincia → Subgerente · Coordinador de instalaciones / de visitas técnicas / de
--   provincia → Coordinador · Líder técnico de instalaciones / de visitas técnicas / de provincia → Líder técnico ·
--   Gestor SGI + Gestor SGA → Gestor. Supervisor de SGI y Supervisor de SGA siguen separados (equipos remotos que hacen
--   seguimiento a los gestores: SGI en instalaciones, SGA en visitas técnicas).
--   Sus usuarios pasan solos al perfil que los junta (con los permisos del primero de cada grupo, hoy iguales).
--   El área de trabajo NO cambia (Instalaciones, VT (visitas técnicas), las dos o Almacén). Un área que no es de la lista
--   (p. ej. "LIDER TÉCNICO PROVINCIAS", que era un cargo) queda vacía: el cargo lo da el perfil.
-- Tablas afectadas: usuarios (perfil_id, area_trabajo), perfiles, perfil_asignables (y en cascada perfil_permisos).
-- Cómo deshacerlo: restaurar el respaldo tomado antes de aplicar (los perfiles juntados no se vuelven a separar solos).
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

-- Áreas que no son de la lista (eran cargos)
UPDATE usuarios SET area_trabajo = NULL
WHERE area_trabajo IS NOT NULL AND area_trabajo NOT IN ('Instalaciones', 'VT (visitas técnicas)', 'Instalaciones y VT', 'Almacén');

-- Queda el primero de cada grupo, con nombre nuevo, y recibe a los usuarios de los demás
UPDATE perfiles SET nombre = 'Subgerente', codigo = 'SUBG', descripcion = 'Gestiona a toda su gente (de sus zonas) y aprueba sus vacaciones.' WHERE codigo = 'SGL';
UPDATE perfiles SET nombre = 'Coordinador', codigo = 'COORD', descripcion = 'Coordina a los supervisores de SGI y SGA y a los gestores de sus zonas y su área.' WHERE codigo = 'CINS';
UPDATE perfiles SET nombre = 'Líder técnico', codigo = 'LT', descripcion = 'Lidera a los supervisores de última milla (seguimiento a los técnicos) de sus zonas y su área.' WHERE codigo = 'LTINS';
UPDATE perfiles SET nombre = 'Supervisor de SGI', descripcion = 'Remoto: hace seguimiento a los gestores de instalaciones (SGI).' WHERE codigo = 'SSGI';
UPDATE perfiles SET nombre = 'Supervisor de SGA', descripcion = 'Remoto: hace seguimiento a los gestores de visitas técnicas (SGA).' WHERE codigo = 'SSGA';
UPDATE perfiles SET nombre = 'Gestor', codigo = 'GES', descripcion = 'Coordina con los técnicos de los partners las órdenes de su área: llamadas, asignación y demoras.' WHERE codigo = 'GSGI';

UPDATE usuarios u JOIN perfiles o ON o.id = u.perfil_id
JOIN perfiles d ON d.codigo = CASE
        WHEN o.codigo = 'SGP' THEN 'SUBG'
        WHEN o.codigo IN ('CVT', 'CPROV') THEN 'COORD'
        WHEN o.codigo IN ('LTVT', 'LTPROV') THEN 'LT'
        WHEN o.codigo = 'GSGA' THEN 'GES' END
SET u.perfil_id = d.id
WHERE o.codigo IN ('SGP', 'CVT', 'CPROV', 'LTVT', 'LTPROV', 'GSGA');

DELETE FROM perfiles WHERE codigo IN ('SGP', 'CVT', 'CPROV', 'LTVT', 'LTPROV', 'GSGA');

-- Perfiles que puede asignar cada uno
DELETE FROM perfil_asignables;
INSERT INTO perfil_asignables (perfil_id, asignable_id)
SELECT a.id, b.id FROM perfiles a JOIN perfiles b ON (
       (a.codigo = 'ADMIN')
    OR (a.codigo = 'GG' AND b.codigo NOT IN ('ADMIN', 'GG'))
    OR (a.codigo = 'SUBG' AND b.codigo IN ('COORD', 'LT', 'SUM', 'SSGI', 'SSGA', 'GES', 'PART', 'SPART'))
    OR (a.codigo = 'COORD' AND b.codigo IN ('SSGI', 'SSGA', 'GES', 'PART', 'SPART'))
    OR (a.codigo = 'LT' AND b.codigo = 'SUM')
);
