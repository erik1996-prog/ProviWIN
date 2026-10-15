-- 04_DATABASE/02_CAMBIOS/2026-10-12_02_rol_jefe_de_operaciones_en_lugar_de_partner/cambio.sql
-- Fecha: 2026-10-12
-- Qué cambia y por qué:
--   El rol "Partner" (heredado del sistema anterior) se reemplaza por "Jefe de operaciones": rol solo para personal de
--   un partner, de solo consulta (ve las órdenes asignadas a su partner, sus observaciones y su personal).
--   El personal de un partner que atiende órdenes es rol "Supervisor" con organización Partner; por eso los usuarios
--   que tenían rol "Partner" pasan a "Supervisor" (así aparecen al asignar órdenes). Si alguno es en realidad jefe,
--   se le cambia el rol desde Usuarios.
-- Tablas afectadas: usuarios (columna rol: lista de valores y datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-12_02_rol_jefe_de_operaciones_en_lugar_de_partner
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).
-- Requiere subir antes el sistema actualizado.

-- 1) Se agrega el rol nuevo (sin quitar todavía el anterior)
ALTER TABLE usuarios MODIFY rol ENUM('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Partner','Jefe de operaciones') NOT NULL DEFAULT 'Supervisor';

-- 2) Quien tenía rol Partner pasa a Supervisor (personal del partner que atiende órdenes)
UPDATE usuarios SET rol = 'Supervisor' WHERE rol = 'Partner';

-- 3) Se quita el rol Partner de la lista
ALTER TABLE usuarios MODIFY rol ENUM('Administrador','Gerente','Coordinador','Supervisor','SGA','SGI','Jefe de operaciones') NOT NULL DEFAULT 'Supervisor';
