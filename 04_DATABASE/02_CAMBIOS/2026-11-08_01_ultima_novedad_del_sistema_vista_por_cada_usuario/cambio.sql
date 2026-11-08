-- 04_DATABASE/02_CAMBIOS/2026-11-08_01_ultima_novedad_del_sistema_vista_por_cada_usuario/cambio.sql
-- Fecha: 2026-11-08
-- Qué cambia y por qué: aviso "El sistema se actualizó" (pedido del usuario): cada usuario ve una sola vez las novedades que
--   puede usar según sus permisos. Esta columna guarda hasta qué novedad ya vio (config/novedades.php).
-- Tablas afectadas: usuarios (columna nueva, con valor por defecto 0).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-11-08_01_ultima_novedad_del_sistema_vista_por_cada_usuario
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS novedad_vista INT NOT NULL DEFAULT 0 COMMENT 'Última novedad del sistema que el usuario ya vio (config/novedades.php)';
