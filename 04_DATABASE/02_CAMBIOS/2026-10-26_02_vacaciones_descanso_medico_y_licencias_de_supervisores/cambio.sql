-- 04_DATABASE/02_CAMBIOS/2026-10-26_02_vacaciones_descanso_medico_y_licencias_de_supervisores/cambio.sql
-- Fecha: 2026-10-26
-- Qué cambia y por qué:
--   Los supervisores directos registran desde "Mi turno" sus vacaciones, descansos médicos y licencias (fechas, comentario y
--   sustento; el certificado es obligatorio en descanso médico). Queda Pendiente hasta que administración, gerencia o
--   coordinación la apruebe o rechace (Monitor de turnos). Con una ausencia Aprobada, ese día no puede iniciar turno.
--   El sustento se guarda en una carpeta privada (11_PRIVADO/ausencias) y solo se abre por el sistema.
-- Tablas afectadas: ausencias_supervisores (nueva). No cambia ninguna tabla existente ni sus datos.
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-26_02_vacaciones_descanso_medico_y_licencias_de_supervisores
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS ausencias_supervisores (
  id int(11) NOT NULL AUTO_INCREMENT,
  supervisor_id int(11) NOT NULL,
  tipo enum('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento','Otra licencia') NOT NULL,
  fecha_inicio date NOT NULL,
  fecha_fin date NOT NULL,
  comentario varchar(300) DEFAULT NULL,
  sustento varchar(120) DEFAULT NULL COMMENT 'Archivo en 11_PRIVADO/ausencias (privado)',
  estado enum('Pendiente','Aprobada','Rechazada','Anulada') NOT NULL DEFAULT 'Pendiente',
  revisado_por int(11) DEFAULT NULL,
  revisado_en datetime DEFAULT NULL,
  motivo_revision varchar(300) DEFAULT NULL COMMENT 'Motivo del rechazo o de la anulación',
  creado_en datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_ausencias_supervisores_supervisor_fechas (supervisor_id, fecha_inicio, fecha_fin),
  KEY idx_ausencias_supervisores_estado_inicio (estado, fecha_inicio),
  KEY idx_ausencias_supervisores_revisado_por (revisado_por),
  CONSTRAINT fk_ausencias_supervisores_supervisor_id FOREIGN KEY (supervisor_id) REFERENCES usuarios (id) ON DELETE CASCADE,
  CONSTRAINT fk_ausencias_supervisores_revisado_por FOREIGN KEY (revisado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Vacaciones, descansos médicos y licencias de los supervisores (Mi turno)';
