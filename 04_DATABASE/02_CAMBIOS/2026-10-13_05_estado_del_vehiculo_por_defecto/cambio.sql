-- 04_DATABASE/02_CAMBIOS/2026-10-13_05_estado_del_vehiculo_por_defecto/cambio.sql
-- Fecha: 2026-10-13
-- Qué cambia y por qué:
--   Al registrar una supervisión, el vehículo de la cuadrilla (tipo, placa y estado) queda guardado en la ficha del
--   técnico líder y sale por defecto en la siguiente supervisión con ese técnico. Ya se guardaban tipo y placa; faltaba
--   el estado del vehículo. Si se cambia de vehículo o de estado, se edita y queda guardado para la próxima.
-- Tablas afectadas: vehiculos (columna nueva estado_conservacion; se completa con el último estado registrado en las
--   supervisiones de cada placa).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-13_05_estado_del_vehiculo_por_defecto
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE vehiculos ADD COLUMN IF NOT EXISTS estado_conservacion ENUM('Mal estado','Regular','Buen estado') NULL DEFAULT NULL COMMENT 'Último estado registrado del vehículo (sale por defecto en la siguiente supervisión)' AFTER tipo;

UPDATE vehiculos v
SET v.estado_conservacion = (
    SELECT s.unidad_estado_conservacion FROM supervisiones s
    WHERE s.vehiculo_id = v.id AND s.unidad_estado_conservacion IS NOT NULL
    ORDER BY s.fecha_registro DESC, s.id DESC LIMIT 1)
WHERE v.estado_conservacion IS NULL;
