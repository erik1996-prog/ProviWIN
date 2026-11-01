-- 04_DATABASE/02_CAMBIOS/2026-10-31_01_perfiles_con_permisos_alcance_y_perfiles_que_puede_asignar/cambio.sql
-- Fecha: 2026-10-31
-- Qué cambia y por qué (perfiles al estilo del sistema de referencia, pedido del usuario):
--   1. perfiles: cada usuario tiene un perfil con nombre, código y descripción, un tipo base (uno de los 5 roles, que define
--      el comportamiento fijo: turno, partner, etc.), qué datos ve (todo el país, sus zonas y su área, o solo lo suyo) y
--      qué perfiles puede asignar a su equipo. Los crea y edita solo la cuenta principal.
--   2. perfil_permisos: los permisos de cada perfil (pantalla por pantalla: ver, registrar, editar, eliminar y opciones).
--   3. perfil_asignables: perfiles que puede asignar quien tiene ese perfil (antes: roles por debajo del suyo).
--   4. usuarios.perfil_id: el perfil de cada usuario. usuarios.rol se mantiene y es el tipo base de su perfil.
--   5. Se crean 5 perfiles iguales a los roles de hoy, con sus mismos permisos, y cada usuario queda en el de su rol.
--      Un usuario que tenía permisos personalizados recibe un perfil propio con exactamente esos permisos
--      (Supervisor · usuario): nadie gana ni pierde nada. Después, usuario_permisos deja de usarse y se elimina.
-- Tablas afectadas: perfiles, perfil_permisos, perfil_asignables (nuevas), usuarios (columna nueva), usuario_permisos (se elimina).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-31_01_perfiles_con_permisos_alcance_y_perfiles_que_puede_asignar
--          (al revertir, todos vuelven a los permisos de su rol: los perfiles propios no se pueden volver a personalizar solos).
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

CREATE TABLE IF NOT EXISTS perfiles (
    id INT(11) NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(60) NOT NULL,
    codigo VARCHAR(10) NOT NULL,
    descripcion VARCHAR(255) DEFAULT NULL,
    rol_base VARCHAR(30) NOT NULL COMMENT 'Tipo base: Administrador, Gerente, Coordinador, Jefe de operaciones o Supervisor',
    alcance ENUM('todo','zonas','propio') NOT NULL DEFAULT 'todo' COMMENT 'Qué datos ve: todo el país, sus zonas y su área, o solo lo suyo',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_perfiles_nombre (nombre),
    UNIQUE KEY uq_perfiles_codigo (codigo),
    KEY idx_perfiles_rol_base (rol_base)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles de acceso: tipo base, alcance y permisos (2026-10-31)';

CREATE TABLE IF NOT EXISTS perfil_permisos (
    perfil_id INT(11) NOT NULL,
    permiso VARCHAR(100) NOT NULL,
    PRIMARY KEY (perfil_id, permiso),
    CONSTRAINT fk_perfil_permisos_perfil FOREIGN KEY (perfil_id) REFERENCES perfiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Permisos de cada perfil (Auth::permissionCatalog)';

CREATE TABLE IF NOT EXISTS perfil_asignables (
    perfil_id INT(11) NOT NULL,
    asignable_id INT(11) NOT NULL,
    PRIMARY KEY (perfil_id, asignable_id),
    KEY idx_perfil_asignables_asignable (asignable_id),
    CONSTRAINT fk_perfil_asignables_perfil FOREIGN KEY (perfil_id) REFERENCES perfiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_perfil_asignables_asignable FOREIGN KEY (asignable_id) REFERENCES perfiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Perfiles que puede asignar quien tiene el perfil';

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perfil_id INT(11) DEFAULT NULL COMMENT 'Perfil de acceso (perfiles.id); rol = su tipo base' AFTER rol;
ALTER TABLE usuarios ADD KEY idx_usuarios_perfil (perfil_id);
ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_perfil FOREIGN KEY (perfil_id) REFERENCES perfiles (id);

INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance) VALUES
    ('Administrador', 'ADMIN', 'Dueño del sistema: ve y configura todo (usuarios, permisos, cargas, eliminaciones).', 'Administrador', 'todo'),
    ('Consulta', 'CONS', 'Vista general: indicadores, supervisiones y observaciones. No opera ni valida.', 'Gerente', 'todo'),
    ('Gestión', 'GEST', 'Líder técnico: revisa y valida observaciones, monitorea turnos y gestiona a su equipo.', 'Coordinador', 'zonas'),
    ('Partner', 'PART', 'Jefe de operaciones de la contrata: consulta las órdenes, observaciones y personal de su partner.', 'Jefe de operaciones', 'todo'),
    ('Supervisor', 'SUP', 'Trabaja en campo: registra supervisiones y su turno (directo) o atiende las órdenes asignadas (partner).', 'Supervisor', 'propio');

INSERT INTO perfil_permisos (perfil_id, permiso)
SELECT p.id, x.permiso FROM perfiles p JOIN (
    SELECT 'ADMIN' AS codigo, 'supervision.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.view_all' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.assign' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'observation.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'observation.review' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'users.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'dashboard.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.filters' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.map' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.form.findings' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.form.checklist' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'users.staff' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'timeline.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.filter.date' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.filter.status' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'supervision.filter.columns' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'observation.filter.date' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'observation.filter.advanced' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'dashboard.partners' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'pendientes.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'clima.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'clima.delete' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'asistencia_matriz.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'asistencia_matriz.edit' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'examenes.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'examenes.edit' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'examenes.delete' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'cuadrillas.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'cuadrillas.edit' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'cuadrillas.import' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'partners.view' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'partners.edit' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'partners.delete' AS permiso
    UNION ALL SELECT 'ADMIN' AS codigo, 'observation.delete' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.view_all' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'observation.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'users.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'dashboard.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.filters' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.filter.date' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.filter.status' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'supervision.filter.columns' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'observation.filter.date' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'observation.filter.advanced' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'dashboard.partners' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'pendientes.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'clima.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'asistencia_matriz.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'examenes.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'examenes.edit' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'examenes.delete' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'cuadrillas.view' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'cuadrillas.edit' AS permiso
    UNION ALL SELECT 'CONS' AS codigo, 'cuadrillas.import' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.view_all' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'observation.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'observation.review' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'users.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'dashboard.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.filters' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.map' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'timeline.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.filter.date' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.filter.status' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'supervision.filter.columns' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'observation.filter.date' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'observation.filter.advanced' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'dashboard.partners' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'pendientes.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'clima.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'asistencia_matriz.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'examenes.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'examenes.edit' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'examenes.delete' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'cuadrillas.view' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'cuadrillas.edit' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'cuadrillas.import' AS permiso
    UNION ALL SELECT 'GEST' AS codigo, 'users.team' AS permiso
    UNION ALL SELECT 'PART' AS codigo, 'supervision.view' AS permiso
    UNION ALL SELECT 'PART' AS codigo, 'observation.view' AS permiso
    UNION ALL SELECT 'PART' AS codigo, 'observation.filter.date' AS permiso
    UNION ALL SELECT 'PART' AS codigo, 'users.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.create' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.assign' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'observation.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'observation.respond' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'observation.review' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'attendance.self' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.filters' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.map' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.form.findings' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.form.checklist' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.filter.date' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.filter.status' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'supervision.filter.columns' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'observation.filter.date' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'pendientes.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'clima.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'clima.edit' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'examenes.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'examenes.edit' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'examenes.delete' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'cuadrillas.view' AS permiso
    UNION ALL SELECT 'SUP' AS codigo, 'cuadrillas.edit' AS permiso
) x ON x.codigo = p.codigo;

-- El administrador asigna todos los perfiles; Gestión (líder), supervisores y jefes de operaciones de un partner.
INSERT INTO perfil_asignables (perfil_id, asignable_id)
SELECT a.id, b.id FROM perfiles a JOIN perfiles b
WHERE a.codigo = 'ADMIN' OR (a.codigo = 'GEST' AND b.codigo IN ('SUP', 'PART'));

UPDATE usuarios u JOIN perfiles p ON p.rol_base = u.rol AND p.codigo IN ('ADMIN', 'CONS', 'GEST', 'PART', 'SUP') SET u.perfil_id = p.id;

-- Permisos personalizados: un perfil propio por usuario, con exactamente los permisos que tenía.
INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance)
SELECT LEFT(CONCAT(b.nombre, ' · ', COALESCE(NULLIF(u.usuario, ''), u.dni)), 60), CONCAT('U', u.id),
    LEFT(CONCAT('Permisos personalizados de ', u.nombre_completo, ' (antes de los perfiles).'), 255), b.rol_base, b.alcance
FROM usuarios u JOIN perfiles b ON b.id = u.perfil_id
WHERE EXISTS (SELECT 1 FROM usuario_permisos up WHERE up.usuario_id = u.id);

INSERT INTO perfil_permisos (perfil_id, permiso)
SELECT pu.id, bp.permiso FROM perfiles pu JOIN usuarios u ON pu.codigo = CONCAT('U', u.id) JOIN perfil_permisos bp ON bp.perfil_id = u.perfil_id
WHERE NOT EXISTS (SELECT 1 FROM usuario_permisos up WHERE up.usuario_id = u.id AND up.permiso = bp.permiso AND up.permitido = 0);

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
SELECT pu.id, up.permiso FROM perfiles pu JOIN usuarios u ON pu.codigo = CONCAT('U', u.id) JOIN usuario_permisos up ON up.usuario_id = u.id AND up.permitido = 1;

INSERT INTO perfil_asignables (perfil_id, asignable_id)
SELECT pu.id, a.asignable_id FROM perfiles pu JOIN usuarios u ON pu.codigo = CONCAT('U', u.id) JOIN perfil_asignables a ON a.perfil_id = u.perfil_id;

UPDATE usuarios u JOIN perfiles pu ON pu.codigo = CONCAT('U', u.id) SET u.perfil_id = pu.id;

DROP TABLE IF EXISTS usuario_permisos;
