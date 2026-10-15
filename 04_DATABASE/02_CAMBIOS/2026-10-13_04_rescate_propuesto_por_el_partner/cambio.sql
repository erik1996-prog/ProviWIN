-- 04_DATABASE/02_CAMBIOS/2026-10-13_04_rescate_propuesto_por_el_partner/cambio.sql
-- Fecha: 2026-10-13
-- Qué cambia y por qué:
--   Una pendiente asignada a un partner: al atenderla, el supervisor del partner indica si "se puede rescatar" y sube
--   sus evidencias; el supervisor directo que la asignó la pasa a rescate y le da seguimiento hasta el cierre.
--   El partner solo propone (no rescata). Para no alterar el cumplimiento de 24 horas del partner, la orden conserva
--   su supervisor asignado y el rescate tiene su propio responsable (rescate_responsable_id).
-- Tablas afectadas: supervisiones (columnas nuevas partner_rescatable y rescate_responsable_id; en los rescates ya
--   registrados el responsable es su supervisor) y rescate_seguimientos (tipo nuevo "Propuesta del partner").
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-13_04_rescate_propuesto_por_el_partner
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones
    ADD COLUMN IF NOT EXISTS partner_rescatable TINYINT(1) NULL DEFAULT NULL COMMENT 'El partner indicó al atender que el servicio se puede rescatar (1) o no (0)' AFTER observacion_atencion_partner,
    ADD COLUMN IF NOT EXISTS rescate_responsable_id INT(11) NULL DEFAULT NULL COMMENT 'Supervisor directo a cargo del rescate' AFTER rescate_estado;

UPDATE supervisiones SET rescate_responsable_id = supervisor_id WHERE rescate_estado IS NOT NULL AND rescate_responsable_id IS NULL;

ALTER TABLE supervisiones
    ADD CONSTRAINT fk_supervisiones_rescate_responsable_id FOREIGN KEY IF NOT EXISTS (rescate_responsable_id) REFERENCES usuarios (id) ON DELETE SET NULL;

ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Propuesta del partner','Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';
