-- Reversión de 2026-11-21_01_anulacion_observaciones_y_penalidades (las anuladas vuelven a verse: se pierde la marca).
DELETE FROM perfil_permisos WHERE permiso = 'observation.void';

ALTER TABLE `observaciones_supervision`
  DROP INDEX IF EXISTS `idx_observaciones_supervision_anulado`,
  DROP COLUMN IF EXISTS `anulado_en`,
  DROP COLUMN IF EXISTS `anulado_por`,
  DROP COLUMN IF EXISTS `anulado_motivo`,
  DROP COLUMN IF EXISTS `anulado`;

ALTER TABLE `checklists_tecnicos`
  DROP INDEX IF EXISTS `idx_checklists_tecnicos_anulado`,
  DROP COLUMN IF EXISTS `anulado_en`,
  DROP COLUMN IF EXISTS `anulado_por`,
  DROP COLUMN IF EXISTS `anulado_motivo`,
  DROP COLUMN IF EXISTS `anulado`;
