-- 04_DATABASE/02_CAMBIOS/2026-10-13_03_rescate_de_pendientes_con_seguimiento/cambio.sql
-- Fecha: 2026-10-13
-- Qué cambia y por qué:
--   Rescate de pendientes: un supervisor directo toma una pendiente "como rescate" y le da seguimiento, sin plazo,
--   hasta cerrarla como "Rescatado" o "No rescatado". Mientras está en rescate sale de la bolsa común de la zona
--   (solo la ve y la trabaja quien la tomó; el administrador puede reasignarla en casos puntuales) y no ocupa la
--   "supervisión en proceso" del supervisor. Cada paso (inicio, avances, reasignación, cierre) queda en un historial.
--   Reemplaza el "rescate" a medias que había en la pantalla (mensajes sin botón).
-- Tablas afectadas: supervisiones (columnas nuevas rescate_estado, rescate_inicio, rescate_fin, vacías) y
--   rescate_seguimientos (tabla nueva, vacía). No toca datos.
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-13_03_rescate_de_pendientes_con_seguimiento
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones
    ADD COLUMN IF NOT EXISTS rescate_estado ENUM('En rescate','Rescatado','No rescatado') NULL DEFAULT NULL COMMENT 'Rescate de la pendiente: en curso o su resultado' AFTER estado_cancelacion,
    ADD COLUMN IF NOT EXISTS rescate_inicio DATETIME NULL DEFAULT NULL COMMENT 'Cuándo se tomó como rescate' AFTER rescate_estado,
    ADD COLUMN IF NOT EXISTS rescate_fin DATETIME NULL DEFAULT NULL COMMENT 'Cuándo se cerró el rescate' AFTER rescate_inicio;

CREATE INDEX IF NOT EXISTS idx_supervisiones_rescate_estado_supervisor_id ON supervisiones (rescate_estado, supervisor_id);

CREATE TABLE IF NOT EXISTS rescate_seguimientos (
    id INT(11) NOT NULL AUTO_INCREMENT,
    supervision_id INT(11) NOT NULL,
    usuario_id INT(11) NULL DEFAULT NULL,
    tipo ENUM('Inicio','Avance','Reasignación','Cierre') NOT NULL DEFAULT 'Avance',
    comentario TEXT NULL DEFAULT NULL,
    foto VARCHAR(255) NULL DEFAULT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rescate_seguimientos_supervision_id_fecha (supervision_id, fecha),
    KEY idx_rescate_seguimientos_usuario_id (usuario_id),
    CONSTRAINT fk_rescate_seguimientos_supervision_id FOREIGN KEY (supervision_id) REFERENCES supervisiones (id) ON DELETE CASCADE,
    CONSTRAINT fk_rescate_seguimientos_usuario_id FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre)';
