-- 04_DATABASE/02_CAMBIOS/2026-10-23_01_envios_programados_del_corte_de_lluvias/cambio.sql
-- Fecha: 2026-10-23
-- Qué cambia y por qué:
--   El corte de lluvias (matriz mensual que se publica en el grupo de Teams) se envía a las horas que programe el
--   personal administrativo desde Indicadores › Clima › "Programar envíos". Cada horario dice si el corte llega hasta ayer
--   o incluye el día de hoy. Una sola tarea de cPanel (07_SCRIPTS/corte_lluvias.php --programados, cada 15 minutos)
--   revisa esta tabla y envía lo que toque; ultimo_envio evita repetir un envío el mismo día.
--   Queda programado el envío de las 08:00 (hasta ayer) acordado.
-- Tablas afectadas: lluvias_cortes_programados (nueva).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-23_01_envios_programados_del_corte_de_lluvias
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

CREATE TABLE IF NOT EXISTS lluvias_cortes_programados (
  id int(11) NOT NULL AUTO_INCREMENT,
  hora time NOT NULL COMMENT 'Hora de Lima en que se envía cada día',
  incluye_hoy tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = matriz hasta ayer; 1 = incluye hoy hasta la hora del envío',
  activo tinyint(1) NOT NULL DEFAULT 1,
  ultimo_envio datetime DEFAULT NULL COMMENT 'Último envío hecho (no se repite el mismo día)',
  creado_por int(11) DEFAULT NULL,
  creado_en datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_lluvias_cortes_programados_hora (hora),
  KEY idx_lluvias_cortes_programados_creado_por (creado_por),
  CONSTRAINT fk_lluvias_cortes_programados_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Horarios del corte de lluvias en Teams (Clima)';

INSERT IGNORE INTO lluvias_cortes_programados (hora, incluye_hoy) VALUES ('08:00:00', 0);
