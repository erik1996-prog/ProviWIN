-- 04_DATABASE/02_CAMBIOS/2026-10-15_07_fechas_de_documentos_pendientes_hasta_finalizar_la_supervisi/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   Las fechas de SOAT, revisión técnica, extintor y brevete también se registran dentro del checklist de la supervisión.
--   Mientras la supervisión está en proceso quedan PENDIENTES (aplicado = 0) y no cambian la cuadrilla; al FINALIZARLA
--   se asignan al vehículo y al técnico líder (aplicado = 1) y aparecen en las siguientes supervisiones.
-- Tablas afectadas: documentos_vencimiento (columna nueva aplicado; los registros existentes quedan aplicados).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_07_fechas_de_documentos_pendientes_hasta_finalizar_la_supervisi
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE documentos_vencimiento
  ADD COLUMN IF NOT EXISTS aplicado tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = registrado en una supervisión en proceso: se asigna a la cuadrilla al finalizarla' AFTER supervision_id,
  ADD INDEX IF NOT EXISTS idx_documentos_vencimiento_supervision_aplicado (supervision_id, aplicado);
