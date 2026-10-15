-- 04_DATABASE/02_CAMBIOS/2026-10-15_02_ultimo_ingreso_y_cambio_de_contrasena_obligatorio/reversion.sql
-- Deshace cambio.sql: se pierde el registro del último ingreso y la marca de cambio de contraseña pendiente.
ALTER TABLE usuarios
    DROP COLUMN IF EXISTS debe_cambiar_password,
    DROP COLUMN IF EXISTS ultimo_acceso;
