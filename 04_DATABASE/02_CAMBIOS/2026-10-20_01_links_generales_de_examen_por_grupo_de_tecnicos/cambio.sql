-- 04_DATABASE/02_CAMBIOS/2026-10-20_01_links_generales_de_examen_por_grupo_de_tecnicos/cambio.sql
-- Fecha: 2026-10-20
-- Qué cambia y por qué:
--   Link general de examen: en Cuadrillas se filtran las cuadrillas y se genera UN link para todos esos técnicos (T1 y
--   T2). Cada técnico entra con su DNI; solo pueden entrar los del grupo y cada uno rinde una vez. Solo se genera si
--   todos tienen DNI. Reemplaza al link individual por técnico (examen_enlaces queda para los links ya enviados).
--   Solo se guarda la huella (SHA-256) del código del link, nunca el código.
-- Tablas afectadas: examen_convocatorias (nueva), examen_convocatoria_tecnicos (nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_01_links_generales_de_examen_por_grupo_de_tecnicos
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS examen_convocatorias (
  id int(11) NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL COMMENT 'SHA-256 del código del link general (el código no se guarda)',
  titulo varchar(150) NOT NULL DEFAULT 'Evaluación técnica',
  filtro varchar(255) DEFAULT NULL COMMENT 'Filtros de Cuadrillas con los que se armó el grupo',
  generado_por int(11) DEFAULT NULL COMMENT 'Usuario que generó el link',
  creado_en datetime NOT NULL DEFAULT current_timestamp(),
  vence_en datetime NOT NULL,
  anulado_en datetime DEFAULT NULL,
  anulado_por int(11) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_examen_convocatorias_token_hash (token_hash),
  KEY idx_examen_convocatorias_creado_en (creado_en),
  KEY fk_examen_convocatorias_generado_por (generado_por),
  KEY fk_examen_convocatorias_anulado_por (anulado_por),
  CONSTRAINT fk_examen_convocatorias_generado_por FOREIGN KEY (generado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
  CONSTRAINT fk_examen_convocatorias_anulado_por FOREIGN KEY (anulado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Links generales de examen (un link para un grupo de técnicos)';

CREATE TABLE IF NOT EXISTS examen_convocatoria_tecnicos (
  id int(11) NOT NULL AUTO_INCREMENT,
  convocatoria_id int(11) NOT NULL,
  tecnico_id int(11) NOT NULL,
  tipo tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = técnico líder (T1), 2 = técnico de apoyo (T2)',
  ingreso_en datetime DEFAULT NULL COMMENT 'Primera vez que entró con su DNI',
  usado_en datetime DEFAULT NULL COMMENT 'Examen rendido: ya no puede volver a entrar',
  PRIMARY KEY (id),
  UNIQUE KEY uq_examen_convocatoria_tecnico (convocatoria_id, tecnico_id),
  KEY idx_examen_convocatoria_tecnicos_tecnico (tecnico_id),
  CONSTRAINT fk_examen_convocatoria_tecnicos_convocatoria FOREIGN KEY (convocatoria_id) REFERENCES examen_convocatorias (id) ON DELETE CASCADE,
  CONSTRAINT fk_examen_convocatoria_tecnicos_tecnico FOREIGN KEY (tecnico_id) REFERENCES tecnicos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Técnicos de cada link general de examen';
