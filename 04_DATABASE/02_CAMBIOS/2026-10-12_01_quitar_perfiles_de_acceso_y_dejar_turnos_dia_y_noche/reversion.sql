-- 04_DATABASE/02_CAMBIOS/2026-10-12_01_quitar_perfiles_de_acceso_y_dejar_turnos_dia_y_noche/reversion.sql
-- Deshace cambio.sql: vuelve a crear las tablas de perfiles (vacías, como estaban) y pasa "Día" a "Mañana".
-- Nota: no se puede saber qué usuario tenía Tarde o Rotativo; todos los "Día" vuelven a "Mañana" (antes del cambio todos eran Mañana).
-- Para usar los perfiles otra vez también hay que volver al código anterior (PerfilRepository.php y la pantalla de usuarios).

CREATE TABLE IF NOT EXISTS `sgs_perfil` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `rol_base` varchar(30) NOT NULL COMMENT 'Rol del sistema sobre el que se arma el perfil',
  `permisos` text NOT NULL COMMENT 'JSON: lista de claves de permiso habilitadas',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_por` int(11) DEFAULT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sgs_perfil_nombre` (`nombre`),
  KEY `idx_sgs_perfil_creado_por` (`creado_por`),
  CONSTRAINT `fk_sgs_perfil_creado_por` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles de acceso: roles personalizados con sus permisos';

CREATE TABLE IF NOT EXISTS `sgs_usuario_perfil` (
  `usuario_id` int(11) NOT NULL,
  `perfil_id` int(10) unsigned NOT NULL,
  `asignado_por` int(11) DEFAULT NULL,
  `fecha_asignacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`usuario_id`),
  KEY `idx_sgs_usuario_perfil_perfil_id` (`perfil_id`),
  KEY `idx_sgs_usuario_perfil_asignado_por` (`asignado_por`),
  CONSTRAINT `fk_sgs_usuario_perfil_asignado_por` FOREIGN KEY (`asignado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sgs_usuario_perfil_perfil_id` FOREIGN KEY (`perfil_id`) REFERENCES `sgs_perfil` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sgs_usuario_perfil_usuario_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfil de acceso asignado a cada usuario (uno por usuario)';

UPDATE usuarios SET turno = 'Mañana' WHERE turno = 'Día';
