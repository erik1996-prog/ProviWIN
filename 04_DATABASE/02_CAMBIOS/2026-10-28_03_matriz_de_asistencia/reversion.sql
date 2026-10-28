-- 04_DATABASE/02_CAMBIOS/2026-10-28_03_matriz_de_asistencia/reversion.sql
-- Deshace el cambio: quita la tabla de correcciones y la fecha de cese. Las licencias LSG, LG y Subsidio vuelven a
-- "Otra licencia" (antes no existían esos tipos).

DROP TABLE IF EXISTS asistencia_ajustes;
ALTER TABLE usuarios DROP COLUMN IF EXISTS fecha_cese;
ALTER TABLE ausencias_supervisores MODIFY COLUMN tipo ENUM('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento',
    'Otra licencia','Licencia sin goce de haber','Licencia con goce de haber','Subsidio') NOT NULL;
UPDATE ausencias_supervisores SET tipo = 'Otra licencia' WHERE tipo IN ('Licencia sin goce de haber','Licencia con goce de haber','Subsidio');
ALTER TABLE ausencias_supervisores MODIFY COLUMN tipo ENUM('Vacaciones','Descanso médico','Licencia por paternidad','Licencia por fallecimiento','Otra licencia') NOT NULL;
