-- 04_DATABASE/02_CAMBIOS/2026-11-03_02_segmento_zav_por_distrito/cambio.sql
-- Fecha: 2026-11-03
-- Qué cambia y por qué (pedido del usuario): el "Segmento del servicio" (Regular / ZAV) ya no se elige a mano: sale del
--   distrito. Cada distrito del catálogo de ubicaciones dice a qué segmento pertenece:
--   - ZAV (Zona de Alto Valor): Barranco, Jesús María, La Molina, Lince, Magdalena del Mar, Miraflores, Pueblo Libre,
--     San Borja, San Isidro, San Miguel, Santiago de Surco y Surquillo (Lima, provincia de Lima);
--   - Regular: todos los demás distritos de todos los departamentos.
--   Las pendientes que aún no se inician (estado Pendiente) toman el segmento de su distrito; las supervisiones ya
--   registradas no se tocan.
-- Tablas afectadas: ubigeo (columna nueva segmento_servicio), supervisiones (tipo_servicio de las pendientes).
-- Cómo deshacerlo: reversion.sql (quita la columna; las pendientes conservan el segmento que tomaron).
-- Aplicar: respaldar antes (Mi información → Respaldo de la base) y luego importar este archivo en phpMyAdmin
--          (o php 07_SCRIPTS/migrar.php) y anotar la carpeta en sgs_migracion.

ALTER TABLE ubigeo
    ADD COLUMN segmento_servicio ENUM('Regular','ZAV') NOT NULL DEFAULT 'Regular'
    COMMENT 'Segmento del servicio del distrito: ZAV (Zona de Alto Valor) o Regular' AFTER distrito;

-- La comparación no distingue tildes (JESUS MARIA = JESÚS MARÍA)
UPDATE ubigeo SET segmento_servicio = 'ZAV'
WHERE departamento = 'LIMA' AND provincia = 'LIMA'
  AND distrito IN ('BARRANCO', 'JESUS MARIA', 'LA MOLINA', 'LINCE', 'MAGDALENA DEL MAR', 'MIRAFLORES', 'PUEBLO LIBRE',
                   'SAN BORJA', 'SAN ISIDRO', 'SAN MIGUEL', 'SANTIAGO DE SURCO', 'SURQUILLO');

UPDATE supervisiones s
JOIN ubigeo u ON u.departamento = s.departamento AND u.provincia = s.provincia AND u.distrito = s.distrito
SET s.tipo_servicio = u.segmento_servicio
WHERE s.estado_supervision = 'Pendiente';
