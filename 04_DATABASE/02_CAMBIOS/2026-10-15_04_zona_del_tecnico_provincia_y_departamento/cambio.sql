-- 04_DATABASE/02_CAMBIOS/2026-10-15_04_zona_del_tecnico_provincia_y_departamento/cambio.sql
-- Fecha: 2026-10-15
-- Qué cambia y por qué:
--   Cada técnico tiene su zona. El Excel de técnicos líderes trae la PROVINCIA; el sistema busca a qué departamento
--   pertenece y guarda los dos. En el formulario de supervisión solo aparecen los técnicos líderes del departamento
--   elegido. Al volver a cargar el Excel, la zona se actualiza (los técnicos se mueven de zona).
-- Tablas afectadas: tecnicos (columnas nuevas provincia y departamento, vacías en los registros existentes).
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-15_04_zona_del_tecnico_provincia_y_departamento
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE tecnicos
  ADD COLUMN IF NOT EXISTS provincia VARCHAR(100) NULL DEFAULT NULL COMMENT 'Provincia de trabajo (tal como viene en el Excel de técnicos líderes)' AFTER partner_id,
  ADD COLUMN IF NOT EXISTS departamento VARCHAR(100) NULL DEFAULT NULL COMMENT 'Zona: departamento de la provincia; filtra los técnicos en el formulario de supervisión' AFTER provincia,
  ADD INDEX IF NOT EXISTS idx_tecnicos_departamento (departamento);
