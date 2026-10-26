-- 04_DATABASE/02_CAMBIOS/2026-10-26_03_usuarios_por_activar_por_la_cuenta_principal/reversion.sql
-- Fecha: 2026-10-26
-- Deshace cambio.sql: quita la marca "Por activar" (esos usuarios siguen inactivos).

ALTER TABLE usuarios DROP COLUMN IF EXISTS por_activar;
