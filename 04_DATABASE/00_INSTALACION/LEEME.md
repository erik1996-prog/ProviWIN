# Instalación de la base de datos desde cero

Ejecutar **en orden de número**, uno por uno: phpMyAdmin → pestaña **Importar**, o por consola:
`C:\xampp\mysql\bin\mysql.exe -u root -p < 02_tabla_xxx.sql`

Cada tabla va después de las tablas de las que depende, por eso el orden importa. Cada archivo indica en su cabecera de cuáles depende.

| Archivo | Qué crea |
|---|---|
| `01_crear_base.sql` | La base `win_telecom_db` vacía |
| `02_tabla_backup_history.sql` | Tabla `backup_history` |
| `03_tabla_catalogo_tipos_supervision.sql` | Tabla `catalogo_tipos_supervision` + datos iniciales |
| `04_tabla_categorias_observacion.sql` | Tabla `categorias_observacion` + datos iniciales |
| `05_tabla_examen_seguridad_intentos.sql` | Tabla `examen_seguridad_intentos` |
| `06_tabla_login_attempts.sql` | Tabla `login_attempts` |
| `07_tabla_login_email_codes.sql` | Tabla `login_email_codes` |
| `08_tabla_partners.sql` | Tabla `partners` + datos iniciales |
| `09_tabla_schema_migrations.sql` | Tabla `schema_migrations` |
| `10_tabla_sgs_migracion.sql` | Tabla `sgs_migracion` + datos iniciales |
| `11_tabla_system_health_events.sql` | Tabla `system_health_events` |
| `12_tabla_tipificaciones_cancelacion.sql` | Tabla `tipificaciones_cancelacion` + datos iniciales |
| `13_tabla_ubigeo.sql` | Tabla `ubigeo` + datos iniciales |
| `14_tabla_vehiculos.sql` | Tabla `vehiculos` |
| `15_tabla_catalogo_subtipos_supervision.sql` | Tabla `catalogo_subtipos_supervision` + datos iniciales |
| `16_tabla_partner_ubigeos.sql` | Tabla `partner_ubigeos` + datos iniciales |
| `17_tabla_subopciones_observacion.sql` | Tabla `subopciones_observacion` + datos iniciales |
| `18_tabla_usuarios.sql` | Tabla `usuarios` |
| `19_tabla_asistencia_supervisores.sql` | Tabla `asistencia_supervisores` |
| `20_tabla_examen_intentos.sql` | Tabla `examen_intentos` |
| `21_tabla_flyers_programados.sql` | Tabla `flyers_programados` |
| `22_tabla_mensajes_chat.sql` | Tabla `mensajes_chat` |
| `23_tabla_recursos_documentos.sql` | Tabla `recursos_documentos` |
| `24_tabla_sgs_perfil.sql` | Tabla `sgs_perfil` |
| `25_tabla_tecnicos.sql` | Tabla `tecnicos` |
| `26_tabla_tickets_error.sql` | Tabla `tickets_error` |
| `27_tabla_usuario_departamentos_acceso.sql` | Tabla `usuario_departamentos_acceso` |
| `28_tabla_usuario_permisos.sql` | Tabla `usuario_permisos` |
| `29_tabla_asistencia_eventos.sql` | Tabla `asistencia_eventos` |
| `30_tabla_sgs_usuario_perfil.sql` | Tabla `sgs_usuario_perfil` |
| `31_tabla_supervisiones.sql` | Tabla `supervisiones` |
| `32_tabla_checklists_tecnicos.sql` | Tabla `checklists_tecnicos` |
| `33_tabla_observaciones_supervision.sql` | Tabla `observaciones_supervision` |
| `34_tabla_supervision_coordenadas_historial.sql` | Tabla `supervision_coordenadas_historial` |
| `35_tabla_supervision_estado_historial.sql` | Tabla `supervision_estado_historial` |
| `36_tabla_checklist_respuestas.sql` | Tabla `checklist_respuestas` |
| `37_tabla_notificaciones.sql` | Tabla `notificaciones` |

Último paso (no es SQL), desde la carpeta del sistema: crear el primer administrador.
`php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> "<Nombres>" "<Apellidos>"`
La contraseña temporal queda en `09_CONFIG/<usuario>.local.txt`.

**Verificar:** `php 07_SCRIPTS/auditar_bd.php` → 36 tablas y ALTO: 0.

**Importante:** solo para una base **nueva**. En una base con datos, los cambios se aplican con `php 07_SCRIPTS/migrar.php`.
Si cambia la estructura o los catálogos: `php 07_SCRIPTS/generar_instalacion.php` vuelve a generar esta carpeta.
