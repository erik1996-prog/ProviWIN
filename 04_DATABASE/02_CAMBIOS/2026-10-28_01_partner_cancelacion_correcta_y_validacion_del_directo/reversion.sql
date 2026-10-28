-- 04_DATABASE/02_CAMBIOS/2026-10-28_01_partner_cancelacion_correcta_y_validacion_del_directo/reversion.sql
-- Deshace el cambio: vuelve a partner_rescatable, a "Propuesta del partner" y a "Rescate". Las validaciones registradas
-- quedan en el historial como "Avance" (con su comentario), porque antes no existía el tipo "Validación".

UPDATE supervisiones SET estado_cancelacion = 'Rescate' WHERE estado_cancelacion = 'Rescatado';

ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Propuesta del partner','Atención del partner','Validación','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';
UPDATE rescate_seguimientos SET tipo = 'Propuesta del partner' WHERE tipo = 'Atención del partner';
UPDATE rescate_seguimientos SET tipo = 'Avance' WHERE tipo = 'Validación';
ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Propuesta del partner','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';

ALTER TABLE supervisiones
    CHANGE COLUMN partner_cancelacion_incorrecta partner_rescatable TINYINT(1) NULL DEFAULT NULL
    COMMENT 'El partner indicó al atender que el servicio se puede rescatar (1) o no (0)';
