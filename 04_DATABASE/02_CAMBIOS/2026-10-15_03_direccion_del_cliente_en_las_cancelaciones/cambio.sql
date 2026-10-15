-- 04_DATABASE/02_CAMBIOS/2026-10-15_03_direccion_del_cliente_en_las_cancelaciones/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   La carga de cancelaciones por Excel trae una columna nueva "DIRECCIÓN" (dirección del cliente) para que el
--   supervisor sepa a dónde ir. Se guarda en la supervisión y se muestra en su detalle.
-- Tablas afectadas: supervisiones (columna nueva direccion, vacía en los registros existentes).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_03_direccion_del_cliente_en_las_cancelaciones
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones ADD COLUMN IF NOT EXISTS direccion VARCHAR(255) NULL DEFAULT NULL COMMENT 'Dirección del cliente (carga de cancelaciones por Excel)' AFTER distrito;
