-- 04_DATABASE/02_CAMBIOS/2026-10-31_03_cuenta_principal_en_perfil_administrador/cambio.sql
-- Fecha: 2026-10-31
-- Qué cambia y por qué (pedido del usuario: "mi perfil eplasencia es el máximo"):
--   1. La cuenta principal (eplasencia, Auth::USUARIOS_PROTEGIDOS) queda en el perfil Administrador (código ADMIN), aunque
--      antes tuviera permisos personalizados ("Administrador · eplasencia" o "Administrador (permisos anteriores N)").
--   2. Se eliminan los perfiles creados al pasar a perfiles (código U + número) que ya no tienen ningún usuario.
-- Tablas afectadas: usuarios.perfil_id (una fila), perfiles (filas sin usuarios).
-- Cómo deshacerlo: no hace falta (los perfiles borrados no tenían usuarios); reversion.sql no hace nada.
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

UPDATE usuarios u JOIN perfiles p ON p.codigo = 'ADMIN'
SET u.perfil_id = p.id, u.rol = 'Administrador'
WHERE u.usuario = 'eplasencia';

DELETE p FROM perfiles p
LEFT JOIN usuarios u ON u.perfil_id = p.id
WHERE p.codigo REGEXP '^U[0-9]+$' AND u.id IS NULL;
