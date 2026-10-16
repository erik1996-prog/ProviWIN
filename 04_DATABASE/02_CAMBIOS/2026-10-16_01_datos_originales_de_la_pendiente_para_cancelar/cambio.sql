-- 04_DATABASE/02_CAMBIOS/2026-10-16_01_datos_originales_de_la_pendiente_para_cancelar/cambio.sql
-- Fecha: 2026-10-16
-- Qué cambia y por qué:
--   Supervisión con guardado automático y botones Cancelar / Finalizar. Al iniciar una PENDIENTE se guarda una copia de
--   sus datos originales; si el supervisor la cancela, vuelve a "Pendiente" tal como estaba (se descarta lo avanzado).
--   Al finalizarla, la copia se borra. Una supervisión nueva (que no fue pendiente) al cancelarse se elimina.
-- Tablas afectadas: supervisiones (columna nueva datos_pendiente, vacía en los registros existentes).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-16_01_datos_originales_de_la_pendiente_para_cancelar
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones ADD COLUMN IF NOT EXISTS datos_pendiente longtext DEFAULT NULL
  COMMENT 'Copia (JSON) de la pendiente al iniciarla: si se cancela, vuelve a este estado. Se borra al finalizar.';
