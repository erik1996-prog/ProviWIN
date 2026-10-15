-- 04_DATABASE/02_CAMBIOS/2026-10-13_02_supervision_remota_por_videollamada/cambio.sql
-- Fecha: 2026-10-13
-- Qué cambia y por qué:
--   Nueva modalidad "Remota": el supervisor supervisa por videollamada y da el GO a distancia. Basta con indicar la
--   modalidad: no pide técnicos, vehículo ni GPS (solo el partner) y para finalizar exige la captura de la
--   videollamada como foto de evidencia. Cuenta para la meta de productividad igual que una presencial.
--   (Simplificado el mismo día a pedido: ya no guarda medio ni horas de la llamada.)
-- Tablas afectadas: supervisiones (opción nueva en tipo_supervision_inicial; no toca datos).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-13_02_supervision_remota_por_videollamada
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE supervisiones MODIFY COLUMN tipo_supervision_inicial ENUM('En caliente','En frío','Remota') NOT NULL;
