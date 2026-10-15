-- 04_DATABASE/02_CAMBIOS/2026-10-13_01_fecha_de_carga_de_las_pendientes/cambio.sql
-- Fecha: 2026-10-13
-- Qué cambia y por qué:
--   Se guarda la FECHA DE CARGA de cada pendiente subida por Excel (cancelaciones), para el indicador
--   "Pendientes cargadas" del Dashboard. Antes no quedaba: fecha_registro se reemplaza cuando un supervisor la toma.
--   Para las pendientes ya cargadas se calcula la más temprana conocida (registro, asignación o primer cambio
--   de estado); es aproximada solo en las que ya se tomaron.
-- Tablas afectadas: supervisiones (columna nueva fecha_carga; se llena solo en las pendientes importadas).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-13_01_fecha_de_carga_de_las_pendientes
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones ADD COLUMN IF NOT EXISTS fecha_carga DATETIME NULL DEFAULT NULL COMMENT 'Cuándo se subió la pendiente por Excel (solo órdenes importadas)' AFTER fecha_registro;

UPDATE supervisiones s
SET s.fecha_carga = LEAST(
    s.fecha_registro,
    COALESCE(s.fecha_asignacion, s.fecha_registro),
    COALESCE((SELECT MIN(h.fecha_cambio) FROM supervision_estado_historial h WHERE h.supervision_id = s.id), s.fecha_registro)
)
WHERE s.origen_importacion = 1 AND s.fecha_carga IS NULL;

CREATE INDEX IF NOT EXISTS idx_supervisiones_origen_importacion_fecha_carga ON supervisiones (origen_importacion, fecha_carga);
