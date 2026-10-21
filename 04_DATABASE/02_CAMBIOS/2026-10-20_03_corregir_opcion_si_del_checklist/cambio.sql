-- 04_DATABASE/02_CAMBIOS/2026-10-20_03_corregir_opcion_si_del_checklist/cambio.sql
-- Fecha: 2026-10-20
-- Qué cambia y por qué:
--   En checklist_respuestas.estado la opción "Sí" quedó guardada con la tilde mal codificada ("S├¡"), así que la base
--   rechazaba el "Sí" de EPP, indumentaria y materiales. En el hosting (modo estricto) salía el error
--   "Data truncated for column 'estado'" al registrar la supervisión; en modo no estricto se guardaba vacío ('').
--   Se pasa la columna a texto, se recupera el "Sí" (lo mal codificado y lo vacío: el sistema solo envía opciones
--   válidas, así que lo único que pudo quedar vacío fue un "Sí") y se vuelve a la lista correcta.
-- Tablas afectadas: checklist_respuestas.
-- Cómo deshacerlo: reversion.sql (misma carpeta) o: php 07_SCRIPTS/migrar.php --revertir 2026-10-20_03_corregir_opcion_si_del_checklist
-- Aplicar: respaldar antes y luego php 07_SCRIPTS/migrar.php (o ejecutar este archivo en phpMyAdmin).

ALTER TABLE checklist_respuestas MODIFY estado varchar(20) NOT NULL;

UPDATE checklist_respuestas SET estado = 'Sí'
WHERE estado NOT IN ('Bueno','Regular','Malo','No tiene','No','Para cambio','No revisado','Mal estado');

ALTER TABLE checklist_respuestas
  MODIFY estado enum('Bueno','Regular','Malo','No tiene','Sí','No','Para cambio','No revisado','Mal estado') NOT NULL;
