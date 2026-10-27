-- 04_DATABASE/02_CAMBIOS/2026-10-27_02_area_vt_visitas_tecnicas/cambio.sql
-- Fecha: 2026-10-27
-- Qué cambia y por qué:
--   En WIN a las averías se les dice VT (visitas técnicas). El área de trabajo de los usuarios pasa de "Averías" a
--   "VT (visitas técnicas)" y de "Instalaciones y averías" a "Instalaciones y VT". El tipo de supervisión "Avería" no cambia.
-- Tablas afectadas: usuarios (columna area_trabajo, solo esos dos valores).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-27_02_area_vt_visitas_tecnicas
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

UPDATE usuarios SET area_trabajo = 'VT (visitas técnicas)' WHERE area_trabajo = 'Averías';
UPDATE usuarios SET area_trabajo = 'Instalaciones y VT' WHERE area_trabajo = 'Instalaciones y averías';