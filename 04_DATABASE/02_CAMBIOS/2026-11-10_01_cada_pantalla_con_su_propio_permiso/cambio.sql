-- 04_DATABASE/02_CAMBIOS/2026-11-10_01_cada_pantalla_con_su_propio_permiso/cambio.sql
-- Fecha: 2026-11-10
-- Qué cambia y por qué (pedido del usuario: "todo tiene que ser dividido para yo dar permisos"): cada pantalla depende solo de
--   su permiso en Permisos, ya no del tipo de usuario. Para que nadie gane ni pierda nada al aplicarlo:
--   1. Monitor de turnos (turnos.monitor, nuevo): antes lo veían por tipo Administrador, Gerente y Coordinador. Lo reciben los
--      perfiles de tipo Gerente y Coordinador (los perfiles administradores lo tienen sin marcarlo).
--   2. Órdenes cercanas (ordenes.cercanas, nuevo): antes por tipo administrativo o supervisor directo. Lo reciben los perfiles de
--      tipo Gerente, Coordinador y Supervisor (el personal de partners nunca: lo impide Auth::puede).
--   3. Dashboard de supervisiones, Línea de tiempo y Control y recurrencia pedían además ser Administrador, Gerente o Coordinador:
--      a los perfiles de otro tipo se les quita ese permiso (lo tenían marcado pero no lo veían).
-- Tablas afectadas: perfil_permisos (filas nuevas y filas quitadas).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-11-10_01_cada_pantalla_con_su_propio_permiso
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) SELECT id, 'turnos.monitor' FROM perfiles WHERE rol_base IN ('Gerente', 'Coordinador');
INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) SELECT id, 'ordenes.cercanas' FROM perfiles WHERE rol_base IN ('Gerente', 'Coordinador', 'Supervisor');
DELETE pp FROM perfil_permisos pp JOIN perfiles p ON p.id = pp.perfil_id WHERE p.rol_base NOT IN ('Administrador', 'Gerente', 'Coordinador') AND pp.permiso IN ('dashboard.view', 'timeline.view', 'observation.review');
