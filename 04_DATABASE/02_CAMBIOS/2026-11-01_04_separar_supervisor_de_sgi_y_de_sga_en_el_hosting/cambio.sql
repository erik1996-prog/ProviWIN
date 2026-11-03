-- 04_DATABASE/02_CAMBIOS/2026-11-01_04_separar_supervisor_de_sgi_y_de_sga_en_el_hosting/cambio.sql
-- Fecha: 2026-11-01
-- Qué cambia y por qué (el usuario seguía viendo "Supervisor SGI/SGA" en el hosting):
--   En el hosting quedó aplicada la primera versión de 2026-11-01_02, que juntó los dos perfiles en uno solo
--   ("Supervisor SGI/SGA", código SSG). La versión corregida de _02 ya no lo hace, pero no deshace lo que la primera hizo.
--   Este cambio los separa otra vez, como pidió el usuario:
--   - SSG vuelve a ser "Supervisor de SGI" (SSGI), con sus usuarios, permisos y perfiles que puede asignar;
--   - se crea "Supervisor de SGA" (SSGA) con los mismos permisos y perfiles que puede asignar;
--   - quien podía asignar SSGI ahora también puede asignar SSGA;
--   - los usuarios de SSGI con área "VT (visitas técnicas)" pasan a SSGA (SGA = visitas técnicas).
--   Donde ya estaban separados (local) no cambia nada: todo se hace solo si falta.
-- Tablas afectadas: perfiles, perfil_permisos, perfil_asignables, usuarios (perfil_id).
-- Cómo deshacerlo: reversion.sql (los vuelve a juntar en SSG).
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

UPDATE perfiles SET nombre = 'Supervisor de SGI', codigo = 'SSGI', descripcion = 'Remoto: hace seguimiento a los gestores de instalaciones (SGI).'
WHERE codigo = 'SSG';

INSERT IGNORE INTO perfiles (nombre, codigo, descripcion, rol_base, alcance)
SELECT 'Supervisor de SGA', 'SSGA', 'Remoto: hace seguimiento a los gestores de visitas técnicas (SGA).', rol_base, alcance
FROM perfiles WHERE codigo = 'SSGI';

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT a.id, pp.permiso FROM perfiles a JOIN perfiles i ON i.codigo = 'SSGI' JOIN perfil_permisos pp ON pp.perfil_id = i.id
WHERE a.codigo = 'SSGA' AND NOT EXISTS (SELECT 1 FROM perfil_permisos x WHERE x.perfil_id = a.id);

INSERT IGNORE INTO perfil_asignables (perfil_id, asignable_id)
SELECT a.id, pa.asignable_id FROM perfiles a JOIN perfiles i ON i.codigo = 'SSGI' JOIN perfil_asignables pa ON pa.perfil_id = i.id
WHERE a.codigo = 'SSGA';

INSERT IGNORE INTO perfil_asignables (perfil_id, asignable_id)
SELECT pa.perfil_id, a.id FROM perfil_asignables pa JOIN perfiles i ON i.id = pa.asignable_id AND i.codigo = 'SSGI' JOIN perfiles a ON a.codigo = 'SSGA';

UPDATE usuarios u JOIN perfiles i ON i.id = u.perfil_id AND i.codigo = 'SSGI' JOIN perfiles a ON a.codigo = 'SSGA'
SET u.perfil_id = a.id WHERE u.area_trabajo = 'VT (visitas técnicas)';
