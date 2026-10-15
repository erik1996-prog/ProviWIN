-- 04_DATABASE/02_CAMBIOS/2026-10-13_02_supervision_remota_por_videollamada/reversion.sql
-- Deshace cambio.sql. Las supervisiones remotas pasan a "En caliente" (la opción "Remota" deja de existir).
UPDATE supervisiones SET tipo_supervision_inicial = 'En caliente' WHERE tipo_supervision_inicial = 'Remota';
ALTER TABLE supervisiones MODIFY COLUMN tipo_supervision_inicial ENUM('En caliente','En frío') NOT NULL;
