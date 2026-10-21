-- 04_DATABASE/02_CAMBIOS/2026-10-20_03_corregir_opcion_si_del_checklist/reversion.sql
-- Fecha: 2026-10-20
-- Deshace cambio.sql: vuelve a la lista anterior (con la tilde mal codificada). Los "Sí" pasan a ese valor.

ALTER TABLE checklist_respuestas MODIFY estado varchar(20) NOT NULL;

UPDATE checklist_respuestas SET estado = 'S├¡' WHERE estado = 'Sí';

ALTER TABLE checklist_respuestas
  MODIFY estado enum('Bueno','Regular','Malo','No tiene','S├¡','No','Para cambio','No revisado','Mal estado') NOT NULL;
