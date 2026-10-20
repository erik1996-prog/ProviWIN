-- 04_DATABASE/02_CAMBIOS/2026-10-20_02_examen_con_preguntas_y_resultados/cambio.sql
-- Fecha: 2026-10-20
-- Qué cambia y por qué:
--   El link general ya trae un examen real (primero: "Seguridad: trabajo en altura"). Cada técnico recibe 20 preguntas al
--   azar de un banco, tiene 10 minutos y aprueba con 90 %. Una sola oportunidad por link: si se le corta, retoma donde
--   quedó con su DNI (el reloj sigue). Se guarda su avance, su nota y, por pregunta, el tema y si acertó (para el
--   dashboard de exámenes: temas más fallados).
--   - examen_convocatorias.examen: qué examen trae el link (código del banco de preguntas).
--   - examen_convocatoria_tecnicos: inicio, preguntas que le tocaron, respuestas, nota y resultado; sesion_hash = huella
--     de la llave del equipo con el que rinde (al volver a entrar con el DNI se renueva).
--   - examen_respuestas (nueva): una fila por pregunta respondida al terminar.
-- Tablas afectadas: examen_convocatorias, examen_convocatoria_tecnicos, examen_respuestas (nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_02_examen_con_preguntas_y_resultados
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE examen_convocatorias
  ADD COLUMN IF NOT EXISTS examen varchar(40) NOT NULL DEFAULT 'altura' COMMENT 'Examen que trae el link (banco de preguntas)' AFTER titulo;

ALTER TABLE examen_convocatoria_tecnicos
  ADD COLUMN IF NOT EXISTS sesion_hash char(64) DEFAULT NULL COMMENT 'Huella de la llave del equipo con el que rinde' AFTER ingreso_en,
  ADD COLUMN IF NOT EXISTS inicio_en datetime DEFAULT NULL COMMENT 'Cuándo empezó a responder (corre el tiempo)' AFTER sesion_hash,
  ADD COLUMN IF NOT EXISTS preguntas longtext DEFAULT NULL COMMENT 'Preguntas que le tocaron y orden de alternativas (JSON)' AFTER inicio_en,
  ADD COLUMN IF NOT EXISTS respuestas longtext DEFAULT NULL COMMENT 'Respuestas guardadas mientras rinde (JSON)' AFTER preguntas,
  ADD COLUMN IF NOT EXISTS correctas tinyint(3) unsigned DEFAULT NULL AFTER usado_en,
  ADD COLUMN IF NOT EXISTS total_preguntas tinyint(3) unsigned DEFAULT NULL AFTER correctas,
  ADD COLUMN IF NOT EXISTS nota decimal(5,2) DEFAULT NULL COMMENT 'Porcentaje de aciertos (0 a 100)' AFTER total_preguntas,
  ADD COLUMN IF NOT EXISTS aprobado tinyint(1) DEFAULT NULL AFTER nota,
  ADD COLUMN IF NOT EXISTS segundos smallint(5) unsigned DEFAULT NULL COMMENT 'Tiempo que usó' AFTER aprobado,
  ADD KEY IF NOT EXISTS idx_examen_convocatoria_tecnicos_usado (usado_en);

CREATE TABLE IF NOT EXISTS examen_respuestas (
  id int(11) NOT NULL AUTO_INCREMENT,
  participacion_id int(11) NOT NULL COMMENT 'examen_convocatoria_tecnicos.id',
  pregunta varchar(20) NOT NULL COMMENT 'Código de la pregunta en el banco',
  tema varchar(60) NOT NULL,
  correcta tinyint(1) NOT NULL DEFAULT 0,
  respondida tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_examen_respuestas (participacion_id, pregunta),
  KEY idx_examen_respuestas_tema (tema, correcta),
  CONSTRAINT fk_examen_respuestas_participacion FOREIGN KEY (participacion_id) REFERENCES examen_convocatoria_tecnicos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resultado de cada pregunta de los exámenes rendidos';
