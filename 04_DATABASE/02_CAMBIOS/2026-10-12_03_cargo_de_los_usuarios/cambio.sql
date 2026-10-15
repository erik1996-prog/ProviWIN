-- 04_DATABASE/02_CAMBIOS/2026-10-12_03_cargo_de_los_usuarios/cambio.sql
-- Fecha: 2026-10-12
-- Qué cambia y por qué:
--   Se agrega el CARGO de cada persona (su puesto en la empresa: Supervisor de campo, Analista, Gerente…), distinto del
--   rol del sistema (lo que puede hacer en SGS) y del área de trabajo (Instalaciones, Averías, Almacén…).
--   Los procesos que se le copian en los correos (Liquidaciones, Penalidades, Gestión Phoenix) se guardan en la columna
--   existente procesos_copiados, que no se usaba: no necesita cambio.
-- Tablas afectadas: usuarios (columna nueva cargo, vacía; no toca datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-12_03_cargo_de_los_usuarios
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cargo VARCHAR(60) NULL DEFAULT NULL COMMENT 'Puesto en la empresa (lista fija en el sistema); no da permisos' AFTER area_trabajo;
