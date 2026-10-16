-- 04_DATABASE/02_CAMBIOS/2026-10-15_06_vencimientos_de_documentos_de_la_cuadrilla/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   Control de vencimientos de la cuadrilla: SOAT, revisión técnica y extintor (del VEHÍCULO, por placa) y brevete
--   (del TÉCNICO LÍDER). Cada registro guarda fecha de inicio, vencimiento y la foto del documento (obligatoria), quién
--   lo registró, desde dónde (supervisión o Cuadrillas) y en qué cuadrilla y zona estaba en ese momento. El vigente es el
--   último de cada documento; los anteriores son su historial. Las fechas viajan con la placa o con la persona.
--   Además, la falta "VEHÍCULO CON REVISIÓN TÉCNICA VENCIDA" (no existía) para la observación automática.
-- Tablas afectadas: documentos_vencimiento (nueva); subopciones_observacion (1 fila nueva en INCUMPLIMIENTO SEGURIDAD).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_06_vencimientos_de_documentos_de_la_cuadrilla
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS documentos_vencimiento (
  id int(11) NOT NULL AUTO_INCREMENT,
  tipo enum('SOAT','Revisión técnica','Extintor','Brevete') NOT NULL,
  vehiculo_id int(11) DEFAULT NULL COMMENT 'SOAT, revisión técnica y extintor',
  tecnico_id int(11) DEFAULT NULL COMMENT 'Brevete (técnico líder)',
  fecha_inicio date NOT NULL COMMENT 'Emisión del documento o última recarga del extintor',
  fecha_vencimiento date NOT NULL,
  foto varchar(255) NOT NULL COMMENT 'Foto del documento (evidencia)',
  origen enum('Supervisión','Cuadrillas') NOT NULL DEFAULT 'Cuadrillas',
  supervision_id int(11) DEFAULT NULL COMMENT 'Supervisión en la que se actualizó (si fue desde el checklist)',
  lider_id int(11) DEFAULT NULL COMMENT 'Cuadrilla (técnico líder) en ese momento',
  departamento varchar(100) DEFAULT NULL COMMENT 'Zona en ese momento',
  registrado_por int(11) DEFAULT NULL,
  registrado_en datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_documentos_vencimiento_vehiculo (vehiculo_id, tipo, id),
  KEY idx_documentos_vencimiento_tecnico (tecnico_id, tipo, id),
  KEY idx_documentos_vencimiento_vence (fecha_vencimiento),
  KEY fk_documentos_vencimiento_supervision (supervision_id),
  KEY fk_documentos_vencimiento_lider (lider_id),
  KEY fk_documentos_vencimiento_registrado_por (registrado_por),
  CONSTRAINT fk_documentos_vencimiento_vehiculo_id FOREIGN KEY (vehiculo_id) REFERENCES vehiculos (id) ON DELETE CASCADE,
  CONSTRAINT fk_documentos_vencimiento_tecnico_id FOREIGN KEY (tecnico_id) REFERENCES tecnicos (id) ON DELETE CASCADE,
  CONSTRAINT fk_documentos_vencimiento_supervision_id FOREIGN KEY (supervision_id) REFERENCES supervisiones (id) ON DELETE SET NULL,
  CONSTRAINT fk_documentos_vencimiento_lider_id FOREIGN KEY (lider_id) REFERENCES tecnicos (id) ON DELETE SET NULL,
  CONSTRAINT fk_documentos_vencimiento_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial';

INSERT INTO subopciones_observacion (categoria_id, descripcion_subopcion, gravedad, penalidad_monto)
SELECT c.id, 'VEHÍCULO CON REVISIÓN TÉCNICA VENCIDA', 'Grave', 0.00
FROM categorias_observacion c
WHERE c.nombre_categoria = 'INCUMPLIMIENTO SEGURIDAD'
  AND NOT EXISTS (SELECT 1 FROM subopciones_observacion s WHERE s.descripcion_subopcion = 'VEHÍCULO CON REVISIÓN TÉCNICA VENCIDA')
LIMIT 1;
