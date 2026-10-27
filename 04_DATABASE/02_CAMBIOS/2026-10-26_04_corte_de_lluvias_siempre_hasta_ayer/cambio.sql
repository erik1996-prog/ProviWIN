-- 04_DATABASE/02_CAMBIOS/2026-10-26_04_corte_de_lluvias_siempre_hasta_ayer/cambio.sql
-- Fecha: 2026-10-26
-- Qué cambia y por qué:
--   El corte de lluvias en Teams muestra siempre los últimos 7 días hasta el día anterior (pedido del usuario). La opción
--   de cada horario "Incluye hoy" deja de existir: se quita su columna. Los horarios programados se conservan.
-- Tablas afectadas: lluvias_cortes_programados (sin la columna incluye_hoy).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-26_04_corte_de_lluvias_siempre_hasta_ayer
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE lluvias_cortes_programados DROP COLUMN IF EXISTS incluye_hoy;
