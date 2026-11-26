-- 2026-11-21_01_anulacion_observaciones_y_penalidades (revisado 2026-11-25)
-- Anular observaciones y penalidades: desaparecen de la bandeja del supervisor y del partner; el administrador las ve como «Anulada».
-- Quién: permiso «Anular» (observation.void): supervisores de WIN y administrador; el partner nunca. Eliminar sigue siendo del
-- administrador (observation.delete), que además ve y restaura las anuladas.
-- Tablas afectadas: observaciones_supervision, checklists_tecnicos (columnas e índice nuevos), perfil_permisos (filas nuevas).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-11-21_01_anulacion_observaciones_y_penalidades
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (se puede repetir sin problema: todo es IF NOT EXISTS / IGNORE).

ALTER TABLE `observaciones_supervision`
  ADD COLUMN IF NOT EXISTS `anulado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `estado_respuesta`,
  ADD COLUMN IF NOT EXISTS `anulado_motivo` VARCHAR(500) NULL AFTER `anulado`,
  ADD COLUMN IF NOT EXISTS `anulado_por` INT(11) NULL AFTER `anulado_motivo`,
  ADD COLUMN IF NOT EXISTS `anulado_en` DATETIME NULL AFTER `anulado_por`,
  ADD INDEX IF NOT EXISTS `idx_observaciones_supervision_anulado` (`anulado`);

ALTER TABLE `checklists_tecnicos`
  ADD COLUMN IF NOT EXISTS `anulado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `estado_respuesta`,
  ADD COLUMN IF NOT EXISTS `anulado_motivo` VARCHAR(500) NULL AFTER `anulado`,
  ADD COLUMN IF NOT EXISTS `anulado_por` INT(11) NULL AFTER `anulado_motivo`,
  ADD COLUMN IF NOT EXISTS `anulado_en` DATETIME NULL AFTER `anulado_por`,
  ADD INDEX IF NOT EXISTS `idx_checklists_tecnicos_anulado` (`anulado`);

-- Permiso «Anular» para los perfiles de supervisor de WIN que ven Observaciones (no el de partner; el administrador ya tiene todos).
INSERT IGNORE INTO perfil_permisos (perfil_id, permiso)
  SELECT p.id, 'observation.void' FROM perfiles p
  WHERE p.rol_base = 'Supervisor' AND p.nombre NOT LIKE '%partner%' AND EXISTS (SELECT 1 FROM perfil_permisos x WHERE x.perfil_id = p.id AND x.permiso = 'observation.view');
