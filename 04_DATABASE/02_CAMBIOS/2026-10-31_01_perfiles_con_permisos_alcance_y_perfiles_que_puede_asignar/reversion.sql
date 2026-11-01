-- 04_DATABASE/02_CAMBIOS/2026-10-31_01_perfiles_con_permisos_alcance_y_perfiles_que_puede_asignar/reversion.sql
-- Deshace el cambio: vuelve la tabla de permisos por usuario (vacía: cada usuario queda con los permisos de su rol)
-- y quita los perfiles.

CREATE TABLE IF NOT EXISTS usuario_permisos (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT(11) NOT NULL,
    permiso VARCHAR(100) NOT NULL,
    permitido TINYINT(1) NOT NULL DEFAULT 1,
    otorgado_por INT(11) DEFAULT NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_permisos_usuario_id_permiso (usuario_id, permiso),
    KEY idx_usuario_permisos_usuario_id_permitido (usuario_id, permitido),
    KEY idx_usuario_permisos_otorgado_por (otorgado_por),
    CONSTRAINT fk_usuario_permisos_otorgado_por FOREIGN KEY (otorgado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_usuario_permisos_usuario_id FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permisos personalizados por usuario (además de los de su rol)';

ALTER TABLE usuarios DROP FOREIGN KEY fk_usuarios_perfil;
ALTER TABLE usuarios DROP KEY idx_usuarios_perfil;
ALTER TABLE usuarios DROP COLUMN perfil_id;
DROP TABLE IF EXISTS perfil_asignables;
DROP TABLE IF EXISTS perfil_permisos;
DROP TABLE IF EXISTS perfiles;
