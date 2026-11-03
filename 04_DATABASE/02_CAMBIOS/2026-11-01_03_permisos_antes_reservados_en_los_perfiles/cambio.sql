-- 04_DATABASE/02_CAMBIOS/2026-11-01_03_permisos_antes_reservados_en_los_perfiles/cambio.sql
-- Fecha: 2026-11-01
-- Qué cambia y por qué (pedido del usuario: "todo lo que hay en las pantallas se debe poner en los permisos"):
--   Ya no hay permisos "reservados" fijos del tipo Administrador. Los 4 que lo eran ahora se dan por perfil (pestaña
--   Permisos): Subir pendientes (supervision.import, con corregir y prioridad alta), Eliminar supervisiones
--   (supervision.delete), Administrar a todos los usuarios (users.manage) y la Guía de diseño (system.manage).
--   Para que nadie pierda nada: los perfiles de tipo Administrador reciben los 4, y el Líder técnico recibe Subir
--   pendientes (pedido del usuario; con "Sus zonas y su área" solo sube las de sus zonas y su área).
-- Tablas afectadas: perfil_permisos (filas nuevas).
-- Cómo deshacerlo: reversion.sql (quita esos permisos de los perfiles que no son de tipo Administrador).
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT p.id, x.permiso FROM perfiles p JOIN (
    SELECT 'supervision.import' AS permiso UNION ALL SELECT 'supervision.delete' UNION ALL SELECT 'users.manage' UNION ALL SELECT 'system.manage'
) x WHERE p.rol_base = 'Administrador';

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT p.id, 'supervision.import' FROM perfiles p WHERE p.codigo = 'LT';
