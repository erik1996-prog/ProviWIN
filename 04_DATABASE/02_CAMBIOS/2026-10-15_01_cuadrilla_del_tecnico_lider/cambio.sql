-- 04_DATABASE/02_CAMBIOS/2026-10-15_01_cuadrilla_del_tecnico_lider/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   La "cuadrilla" se arma sola mientras se usa el sistema: al registrar una supervisión, el supervisor puede guardar el
--   técnico de apoyo (T2) y el vehículo del técnico líder; la próxima vez que elija a ese líder, salen por defecto.
--   El vehículo ya se guardaba (tecnicos.vehiculo_id); faltaba el técnico de apoyo habitual.
-- Tablas afectadas: tecnicos (columna nueva apoyo_id; se completa con el T2 de la última supervisión de cada líder).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_01_cuadrilla_del_tecnico_lider
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE tecnicos ADD COLUMN IF NOT EXISTS apoyo_id INT(11) NULL DEFAULT NULL COMMENT 'Técnico de apoyo (T2) habitual del líder: sale por defecto en la siguiente supervisión' AFTER vehiculo_id;

UPDATE tecnicos t
SET t.apoyo_id = (
    SELECT s.tecnico2_id FROM supervisiones s
    WHERE s.tecnico1_id = t.id AND s.tecnico2_id IS NOT NULL AND s.tecnico2_id <> t.id
    ORDER BY s.fecha_registro DESC, s.id DESC LIMIT 1)
WHERE t.apoyo_id IS NULL AND COALESCE(t.tipo_tecnico, 1) = 1;

ALTER TABLE tecnicos ADD CONSTRAINT fk_tecnicos_apoyo_id FOREIGN KEY IF NOT EXISTS (apoyo_id) REFERENCES tecnicos (id) ON DELETE SET NULL;
