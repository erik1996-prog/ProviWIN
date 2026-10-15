-- 04_DATABASE/02_CAMBIOS/2026-10-12_01_quitar_perfiles_de_acceso_y_dejar_turnos_dia_y_noche/cambio.sql
-- Fecha: 2026-10-12
-- Qué cambia y por qué:
--   1) Se quitan los "perfiles de acceso": nunca se usaron (ambas tablas vacías). Los permisos siguen siendo
--      los del rol, o personalizados por usuario en usuario_permisos (eso no cambia).
--   2) El turno de los usuarios queda solo en "Día" o "Noche": Mañana, Tarde y Rotativo pasan a "Día".
-- Tablas afectadas: sgs_usuario_perfil (se elimina), sgs_perfil (se elimina), usuarios (columna turno, solo datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-12_01_quitar_perfiles_de_acceso_y_dejar_turnos_dia_y_noche
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).
-- Requiere subir antes el sistema actualizado (el código ya no usa estas tablas).

-- 1) Perfiles de acceso (primero la tabla que depende de la otra)
DROP TABLE IF EXISTS sgs_usuario_perfil;
DROP TABLE IF EXISTS sgs_perfil;

-- 2) Turnos: solo Día y Noche
UPDATE usuarios SET turno = 'Día' WHERE turno IN ('Mañana', 'Tarde', 'Rotativo');
