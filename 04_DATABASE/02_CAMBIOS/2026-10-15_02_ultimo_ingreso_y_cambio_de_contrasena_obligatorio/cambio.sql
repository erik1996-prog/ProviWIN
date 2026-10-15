-- 04_DATABASE/02_CAMBIOS/2026-10-15_02_ultimo_ingreso_y_cambio_de_contrasena_obligatorio/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   1) Último ingreso de cada usuario: se muestra en Usuarios y permite ver quién tiene acceso pero nunca entró
--      ("Por ingresar") o quién dejó de usar el sistema.
--   2) Cambio de contraseña obligatorio: al dar acceso o restablecer la contraseña, el administrador puede pedir que
--      la persona la cambie al ingresar; así solo ella conoce su contraseña.
-- Tablas afectadas: usuarios (columnas nuevas ultimo_acceso y debe_cambiar_password; vacías / 0; no toca datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_02_ultimo_ingreso_y_cambio_de_contrasena_obligatorio
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE usuarios
    ADD COLUMN IF NOT EXISTS ultimo_acceso DATETIME NULL DEFAULT NULL COMMENT 'Último inicio de sesión correcto en SGS' AFTER activo,
    ADD COLUMN IF NOT EXISTS debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = al ingresar debe cambiar su contraseña (temporal)' AFTER ultimo_acceso;
