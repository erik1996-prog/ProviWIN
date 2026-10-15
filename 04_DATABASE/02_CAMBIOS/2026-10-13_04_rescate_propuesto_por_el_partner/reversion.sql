-- 04_DATABASE/02_CAMBIOS/2026-10-13_04_rescate_propuesto_por_el_partner/reversion.sql
-- Deshace cambio.sql. Se pierden las propuestas de rescate del partner (sus filas del historial y la marca
-- partner_rescatable) y el responsable propio del rescate (los rescates quedan a cargo de su supervisor asignado).
DELETE FROM rescate_seguimientos WHERE tipo = 'Propuesta del partner';
ALTER TABLE rescate_seguimientos
    MODIFY COLUMN tipo ENUM('Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance';
ALTER TABLE supervisiones DROP FOREIGN KEY IF EXISTS fk_supervisiones_rescate_responsable_id;
ALTER TABLE supervisiones
    DROP COLUMN IF EXISTS rescate_responsable_id,
    DROP COLUMN IF EXISTS partner_rescatable;
