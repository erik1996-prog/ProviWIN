-- 04_DATABASE/02_CAMBIOS/2026-10-28_03_matriz_de_asistencia/cambio.sql
-- Fecha: 2026-10-28
-- Qué cambia y por qué (Matriz de asistencia mensual de los supervisores directos):
--   1. Vacaciones y licencias: se agregan "Licencia sin goce de haber" (LSG), "Licencia con goce de haber" (LG) y "Subsidio"
--      (SUB). "Otra licencia" deja de existir: las que ya había pasan a "Licencia con goce de haber".
--   2. usuarios.fecha_cese: día en que se desactivó al usuario (desde ese día la matriz lo marca C = cesado).
--   3. asistencia_ajustes: correcciones del administrador en la matriz (una por supervisor y día), con quién y cuándo.
-- Tablas afectadas: ausencias_supervisores (valores de tipo), usuarios (columna nueva), asistencia_ajustes (tabla nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-28_03_matriz_de_asistencia
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

ALTER TABLE ausencias_supervisores MODIFY COLUMN tipo ENUM('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento',
    'Otra licencia','Licencia sin goce de haber','Licencia con goce de haber','Subsidio') NOT NULL;
UPDATE ausencias_supervisores SET tipo = 'Licencia con goce de haber' WHERE tipo = 'Otra licencia';
ALTER TABLE ausencias_supervisores MODIFY COLUMN tipo ENUM('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento',
    'Licencia sin goce de haber','Licencia con goce de haber','Subsidio') NOT NULL;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fecha_cese DATE NULL DEFAULT NULL
    COMMENT 'Día en que se desactivó al usuario (Matriz de asistencia: C = cesado desde ese día)' AFTER por_activar;

CREATE TABLE IF NOT EXISTS asistencia_ajustes (
    id INT(11) NOT NULL AUTO_INCREMENT,
    supervisor_id INT(11) NOT NULL,
    fecha DATE NOT NULL,
    codigo ENUM('A','V','LSG','LG','DM','SUB','LPF','LP','D','FT','C') NOT NULL,
    comentario VARCHAR(200) DEFAULT NULL,
    usuario_id INT(11) DEFAULT NULL COMMENT 'Quién hizo la corrección',
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asistencia_ajustes_supervisor_id_fecha (supervisor_id, fecha),
    KEY idx_asistencia_ajustes_fecha (fecha),
    KEY idx_asistencia_ajustes_usuario_id (usuario_id),
    CONSTRAINT fk_asistencia_ajustes_supervisor_id FOREIGN KEY (supervisor_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_asistencia_ajustes_usuario_id FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Correcciones del administrador en la matriz de asistencia';
