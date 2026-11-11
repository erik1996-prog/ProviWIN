-- 04_DATABASE/02_CAMBIOS/2026-11-11_01_acciones_de_administrador_pasan_a_permisos/cambio.sql
-- Fecha: 2026-11-11
-- Qué cambia y por qué (pedido del usuario: revisar todas las pantallas para poder dar cada permiso): acciones que eran fijas
--   del Administrador o del personal administrativo pasan a Permisos. Los perfiles administradores las tienen sin marcarlas;
--   para que nadie pierda nada, los perfiles de tipo Gerente y Coordinador reciben lo que ya hacían por su tipo:
--   - supervision.filter_people (filtrar Supervisiones por departamento y supervisor), si ya tienen los filtros;
--   - clima.teams (enviar el corte de lluvias a Teams), si ya ven Clima.
--   Las demás nuevas (turnos.reopen, supervision.correct, rescates.manage, observation.manage) eran solo del Administrador.
-- Tablas afectadas: perfil_permisos (filas nuevas).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-11-11_01_acciones_de_administrador_pasan_a_permisos
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) SELECT p.id, 'supervision.filter_people' FROM perfiles p WHERE p.rol_base IN ('Gerente', 'Coordinador') AND EXISTS (SELECT 1 FROM perfil_permisos x WHERE x.perfil_id = p.id AND x.permiso = 'supervision.filters');
INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) SELECT p.id, 'clima.teams' FROM perfiles p WHERE p.rol_base IN ('Gerente', 'Coordinador') AND EXISTS (SELECT 1 FROM perfil_permisos x WHERE x.perfil_id = p.id AND x.permiso = 'clima.view');
