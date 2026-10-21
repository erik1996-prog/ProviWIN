-- 04_DATABASE/02_CAMBIOS/2026-10-20_04_lluvia_reportada_por_zona/cambio.sql
-- Fecha: 2026-10-20
-- Qué cambia y por qué:
--   Los supervisores directos avisan desde "Mi turno" que está lloviendo en su zona y cuándo paró. La lluvia es de la
--   zona: se ve en la línea de tiempo de todos los supervisores de esa zona, y las supervisiones hechas mientras llovía
--   llevan la marca de lluvia. Una sola lluvia abierta por zona; cualquiera de la zona puede marcar que paró. Si nadie
--   la cierra, se cierra sola cuando quien la avisó termina su turno.
-- Tablas afectadas: lluvias_zona (nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_04_lluvia_reportada_por_zona
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS lluvias_zona (
  id int(11) NOT NULL AUTO_INCREMENT,
  departamento varchar(100) NOT NULL COMMENT 'Zona donde llueve',
  inicio datetime NOT NULL,
  fin datetime DEFAULT NULL COMMENT 'NULL = sigue lloviendo',
  reportado_por int(11) NOT NULL,
  cerrado_por int(11) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_lluvias_zona_departamento_inicio (departamento, inicio),
  KEY idx_lluvias_zona_fin (fin),
  KEY idx_lluvias_zona_reportado_por (reportado_por),
  KEY idx_lluvias_zona_cerrado_por (cerrado_por),
  CONSTRAINT fk_lluvias_zona_reportado_por FOREIGN KEY (reportado_por) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_lluvias_zona_cerrado_por FOREIGN KEY (cerrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lluvia avisada por los supervisores en su zona (línea de tiempo)';
