-- 04_DATABASE/02_CAMBIOS/2026-10-28_02_prioridad_alta_en_pendientes/cambio.sql
-- Fecha: 2026-10-28
-- Qué cambia y por qué:
--   El administrador (o quien carga las cancelaciones) puede marcar una pendiente como "Prioridad alta". En la pestaña
--   Pendientes de los supervisores directos esas órdenes salen primero, con la etiqueta "Prioridad alta". El partner no la ve.
-- Tablas afectadas: supervisiones (columna nueva prioridad_alta).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-28_02_prioridad_alta_en_pendientes
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

ALTER TABLE supervisiones
    ADD COLUMN IF NOT EXISTS prioridad_alta TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Pendiente marcada como prioridad alta (sale primero en Pendientes de los supervisores directos)' AFTER estado_supervision;
