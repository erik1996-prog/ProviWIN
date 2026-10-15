-- 04_DATABASE/02_CAMBIOS/2026-10-15_05_enlaces_de_examen_para_los_tecnicos/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   Links de examen para los técnicos (T1 y T2). Desde Cuadrillas se genera un link único con el nombre del técnico,
--   se envía (WhatsApp, copiar…), vence solo y se puede anular; al rendir el examen queda usado. Cada link queda en el
--   historial del técnico. Solo se guarda la huella (SHA-256) del código del link, nunca el código.
--   Las preguntas del examen se agregarán en un cambio posterior.
-- Tablas afectadas: examen_enlaces (nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_05_enlaces_de_examen_para_los_tecnicos
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS examen_enlaces (
  id int(11) NOT NULL AUTO_INCREMENT,
  tecnico_id int(11) NOT NULL COMMENT 'Técnico que rinde el examen (T1 o T2)',
  token_hash char(64) NOT NULL COMMENT 'SHA-256 del código del link (el código no se guarda)',
  titulo varchar(150) NOT NULL DEFAULT 'Evaluación técnica',
  generado_por int(11) DEFAULT NULL COMMENT 'Usuario que generó el link',
  creado_en datetime NOT NULL DEFAULT current_timestamp(),
  vence_en datetime NOT NULL,
  abierto_en datetime DEFAULT NULL COMMENT 'Primera vez que el técnico abrió el link',
  usado_en datetime DEFAULT NULL COMMENT 'Examen rendido: el link ya no sirve',
  anulado_en datetime DEFAULT NULL,
  anulado_por int(11) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_examen_enlaces_token_hash (token_hash),
  KEY idx_examen_enlaces_tecnico (tecnico_id, creado_en),
  KEY fk_examen_enlaces_generado_por (generado_por),
  KEY fk_examen_enlaces_anulado_por (anulado_por),
  CONSTRAINT fk_examen_enlaces_tecnico_id FOREIGN KEY (tecnico_id) REFERENCES tecnicos (id) ON DELETE CASCADE,
  CONSTRAINT fk_examen_enlaces_generado_por FOREIGN KEY (generado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_examen_enlaces_anulado_por FOREIGN KEY (anulado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links de examen enviados a los técnicos';
