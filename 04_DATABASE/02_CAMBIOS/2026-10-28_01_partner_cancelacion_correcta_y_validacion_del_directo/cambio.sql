-- 04_DATABASE/02_CAMBIOS/2026-10-28_01_partner_cancelacion_correcta_y_validacion_del_directo/cambio.sql
-- Fecha: 2026-10-28
-- Qué cambia y por qué:
--   1. El supervisor del partner, al atender una cancelación transferida, ya no responde "¿se puede rescatar?": dice si la
--      cancelación fue correcta o incorrecta. La columna partner_rescatable pasa a llamarse partner_cancelacion_incorrecta
--      (1 = incorrecta, 0 = correcta). Los datos se conservan: "se puede rescatar" equivale a "cancelación incorrecta".
--   2. Después, el supervisor directo que se la transfirió valida esa atención: Cancelación correcta, En proceso de rescate
--      o Rescatado. El historial (rescate_seguimientos.tipo) gana el tipo "Validación" y "Propuesta del partner" pasa a
--      llamarse "Atención del partner" (ahora se registra siempre, no solo cuando se podía rescatar).
--   3. El resultado "Rescate" de una cancelación se llama "Rescatado" (supervisiones.estado_cancelacion).
-- Tablas afectadas: supervisiones (columna partner_rescatable renombrada; valor 'Rescate' de estado_cancelacion),
--   rescate_seguimientos (valores de la columna tipo).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-28_01_partner_cancelacion_correcta_y_validacion_del_directo
-- Aplicar: respaldar antes (php 07_SCRIPTS/respaldar.php base antes-de-cambio) y luego php 07_SCRIPTS/migrar.php
--          En el hosting también se puede importar este archivo en phpMyAdmin (y anotar la carpeta en sgs_migracion).

ALTER TABLE supervisiones
    CHANGE COLUMN partner_rescatable partner_cancelacion_incorrecta TINYINT(1) NULL DEFAULT NULL
    COMMENT 'Resultado del supervisor del partner al atender: cancelación incorrecta (1) o correcta (0)';

ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Propuesta del partner','Atención del partner','Validación','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';
UPDATE rescate_seguimientos SET tipo = 'Atención del partner' WHERE tipo = 'Propuesta del partner';
ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Atención del partner','Validación','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';

UPDATE supervisiones SET estado_cancelacion = 'Rescatado' WHERE estado_cancelacion = 'Rescate';
