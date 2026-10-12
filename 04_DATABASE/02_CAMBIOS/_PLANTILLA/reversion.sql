-- 04_DATABASE/02_CAMBIOS/{{CARPETA}}/reversion.sql
-- Deshace el cambio del {{FECHA}}: {{DESCRIPCION}}
-- Ejecutar con: php 07_SCRIPTS/migrar.php --revertir {{CARPETA}}
-- (los datos que se pierdan al deshacer se recuperan del respaldo tomado antes del cambio, en 10_RESPALDOS/base)

-- Ejemplo (borrar y escribir la reversión real):
-- ALTER TABLE supervisiones DROP COLUMN IF EXISTS nueva_columna;
