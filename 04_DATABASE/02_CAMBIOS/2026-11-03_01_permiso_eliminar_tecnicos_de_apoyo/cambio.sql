-- 04_DATABASE/02_CAMBIOS/2026-11-03_01_permiso_eliminar_tecnicos_de_apoyo/cambio.sql
-- Fecha: 2026-11-03
-- Qué cambia y por qué (el usuario no podía editar ni eliminar un T2 en Técnicos y cuadrillas):
--   Permiso nuevo "Eliminar técnicos de apoyo (T2)" (cuadrillas.delete) en la pantalla Técnicos y cuadrillas. Si el T2
--   ya sale en supervisiones no se borra: queda Inhabilitado y fuera de su cuadrilla. Lo reciben los perfiles que ya
--   cargan técnicos con el Excel (cuadrillas.import). Los perfiles administradores lo tienen sin marcarlo.
--   Editar un T2 usa el permiso que ya existía (Editar cuadrillas): no cambia la base.
-- Tablas afectadas: perfil_permisos (filas nuevas).
-- Cómo deshacerlo: reversion.sql.
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT perfil_id, 'cuadrillas.delete' FROM perfil_permisos WHERE permiso = 'cuadrillas.import';
