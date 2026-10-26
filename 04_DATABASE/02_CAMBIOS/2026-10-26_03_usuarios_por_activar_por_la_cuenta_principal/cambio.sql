-- 04_DATABASE/02_CAMBIOS/2026-10-26_03_usuarios_por_activar_por_la_cuenta_principal/cambio.sql
-- Fecha: 2026-10-26
-- Qué cambia y por qué:
--   Seguridad: un usuario creado (o al que se le da acceso) por un administrador que no es la cuenta principal
--   (Auth::USUARIOS_PROTEGIDOS) queda "Por activar": no puede ingresar hasta que la cuenta principal lo active.
--   La marca distingue a estos usuarios de los desactivados a propósito. Los usuarios actuales no cambian (0).
-- Tablas afectadas: usuarios (columna nueva por_activar).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-26_03_usuarios_por_activar_por_la_cuenta_principal
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS por_activar tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Espera que la cuenta principal lo active (no puede ingresar)' AFTER activo;
