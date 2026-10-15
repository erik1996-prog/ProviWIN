-- 04_DATABASE/02_CAMBIOS/2026-10-12_02_rol_jefe_de_operaciones_en_lugar_de_partner/reversion.sql
-- Deshace cambio.sql: vuelve el rol "Partner" y los "Jefe de operaciones" pasan a "Partner".
-- Nota: no se puede saber qué usuarios tenían rol Partner antes del cambio (pasaron a Supervisor); esos quedan como Supervisor.
-- Para usar el rol Partner otra vez también hay que volver al código anterior.

ALTER TABLE usuarios MODIFY rol ENUM('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner','Jefe de operaciones') NOT NULL DEFAULT 'Supervisor';
UPDATE usuarios SET rol = 'Partner' WHERE rol = 'Jefe de operaciones';
ALTER TABLE usuarios MODIFY rol ENUM('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner') NOT NULL DEFAULT 'Supervisor';
