-- 04_DATABASE/02_CAMBIOS/2026-11-04_01_permisos_vacaciones_y_agregar_t2/cambio.sql
-- Fecha: 2026-11-04
-- Qué cambia y por qué (pedido del usuario: "todo lo que hay en las pantallas se debe poner en los permisos"):
--   1. Vacaciones y licencias pasa a permisos (antes era fijo por rol): Ver, Solicitar (supervisor directo) y Aprobar.
--      Para que nadie pierda nada: los perfiles de tipo Gerente y Coordinador reciben Ver y Aprobar (antes aprobaban por su
--      rol); los de tipo Supervisor reciben Ver y Solicitar. Los perfiles administradores aprueban sin marcarlo.
--   2. Técnicos y cuadrillas: "Agregar T2" (cuadrillas.create_t2). Lo reciben los perfiles que ya editaban cuadrillas
--      (antes "Nuevo técnico" dependía de Editar). El T1 se agrega solo con "Cargar Excel y agregar T1" (cuadrillas.import).
-- Tablas afectadas: perfil_permisos (filas nuevas).
-- Cómo deshacerlo: reversion.sql.
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT p.id, x.permiso FROM perfiles p
JOIN (SELECT 'vacaciones.view' AS permiso UNION ALL SELECT 'vacaciones.approve') x
WHERE p.rol_base IN ('Gerente', 'Coordinador');

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT p.id, x.permiso FROM perfiles p
JOIN (SELECT 'vacaciones.view' AS permiso UNION ALL SELECT 'vacaciones.request') x
WHERE p.rol_base = 'Supervisor';

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT perfil_id, 'cuadrillas.create_t2' FROM perfil_permisos WHERE permiso = 'cuadrillas.edit';
