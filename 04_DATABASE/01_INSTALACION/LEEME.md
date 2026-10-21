# Instalación de la base de datos desde cero

**Lo más simple: `00_instalacion_completa.sql`** trae todas las tablas y sus datos en un solo archivo: seleccionar la base en phpMyAdmin → Importar ese archivo, y listo.

O, si se prefiere por partes, ejecutar **en orden de número**, uno por uno: phpMyAdmin → seleccionar la base → pestaña **Importar**, o por consola:
`mysql -u usuario -p NOMBRE_BASE < 02_tabla_xxx.sql`

Los scripts **no fijan el nombre de la base**. `01_crear_base.sql` solo hace falta si la base no existe; en un hosting la base se crea desde el panel y ese paso se omite.
Cada tabla va después de las tablas de las que depende, por eso el orden importa. Cada archivo indica en su cabecera de cuáles depende.

Se instalan **28 tablas**: con sus datos actuales (usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades) y **vacías** las de ingresos (supervisiones, observaciones, checklists, turnos, notificaciones).

| Archivo | Tabla | Datos |
|---|---|---|
| `01_crear_base.sql` | base vacía | — |
| `02_tabla_categorias_observacion.sql` | `categorias_observacion`: Categorías de observación | 6 registros |
| `03_tabla_login_attempts.sql` | `login_attempts`: Intentos de ingreso (bloqueo a los 3 fallidos) | vacía |
| `04_tabla_partners.sql` | `partners`: Contratas; WIN DIRECTO = personal propio | 12 registros |
| `05_tabla_sgs_migracion.sql` | `sgs_migracion`: Cambios de base de datos ya aplicados (los registra 07_SCRIPTS/migrar.php) | vacía |
| `06_tabla_tipificaciones_cancelacion.sql` | `tipificaciones_cancelacion`: Motivos de cancelación (los del Excel de pendientes se agregan solos) | 6 registros |
| `07_tabla_ubigeo.sql` | `ubigeo`: Departamentos, provincias y distritos de operación | 47 registros |
| `08_tabla_vehiculos.sql` | `vehiculos`: Vehículos de las cuadrillas | 2 registros |
| `09_tabla_subopciones_observacion.sql` | `subopciones_observacion`: Tipificaciones de observación con gravedad y penalidad | 84 registros |
| `10_tabla_usuarios.sql` | `usuarios`: Usuarios del sistema (personal WIN y de partners) | 10 registros |
| `11_tabla_asistencia_supervisores.sql` | `asistencia_supervisores`: Turnos de los supervisores: inicio, fin y horas | vacía |
| `12_tabla_examen_convocatorias.sql` | `examen_convocatorias`: Links generales de examen (un link para un grupo de técnicos) | vacía |
| `13_tabla_lluvias_zona.sql` | `lluvias_zona`: Lluvia avisada por los supervisores en su zona (línea de tiempo) | vacía |
| `14_tabla_tecnicos.sql` | `tecnicos`: Técnicos por partner: líderes (T1) y de apoyo (T2) | 196 registros |
| `15_tabla_usuario_departamentos_acceso.sql` | `usuario_departamentos_acceso`: Zonas adicionales que puede ver cada usuario | 0 registros |
| `16_tabla_usuario_permisos.sql` | `usuario_permisos`: Permisos personalizados por usuario (además de los de su rol) | 0 registros |
| `17_tabla_asistencia_eventos.sql` | `asistencia_eventos`: Pausas dentro del turno (almuerzo, reunión, capacitación) | vacía |
| `18_tabla_examen_convocatoria_tecnicos.sql` | `examen_convocatoria_tecnicos`: Técnicos de cada link general de examen | vacía |
| `19_tabla_examen_enlaces.sql` | `examen_enlaces`: Links de examen enviados a los técnicos | vacía |
| `20_tabla_supervisiones.sql` | `supervisiones`: Supervisiones de campo y órdenes pendientes asignadas | vacía |
| `21_tabla_checklists_tecnicos.sql` | `checklists_tecnicos`: Checklist de cuadrilla por supervisión | vacía |
| `22_tabla_documentos_vencimiento.sql` | `documentos_vencimiento`: Vencimientos de la cuadrilla: SOAT, revisión técnica, extintor (vehículo) y brevete (técnico líder), con historial | vacía |
| `23_tabla_examen_respuestas.sql` | `examen_respuestas`: Resultado de cada pregunta de los exámenes rendidos | vacía |
| `24_tabla_observaciones_supervision.sql` | `observaciones_supervision`: Observaciones en campo, descargos del partner y plazos | vacía |
| `25_tabla_rescate_seguimientos.sql` | `rescate_seguimientos`: Historial del rescate de cada pendiente (inicio, avances, reasignación y cierre) | vacía |
| `26_tabla_supervision_coordenadas_historial.sql` | `supervision_coordenadas_historial`: Cambios de ubicación GPS de cada supervisión | vacía |
| `27_tabla_supervision_estado_historial.sql` | `supervision_estado_historial`: Cambios de estado de cada supervisión (horas reales de inicio y fin) | vacía |
| `28_tabla_checklist_respuestas.sql` | `checklist_respuestas`: Criterios evaluados en cada checklist | vacía |
| `29_tabla_notificaciones.sql` | `notificaciones`: Avisos a los usuarios | vacía |

Si la base no trae ningún administrador, crear el primero (no es SQL), desde la carpeta del sistema:
`php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> "<Nombres>" "<Apellidos>"`

**Verificar:** `php 07_SCRIPTS/auditar_bd.php` → 28 tablas y ALTO: 0.

**Importante:** solo para una base **nueva**. En una base con datos, los cambios se aplican con `php 07_SCRIPTS/migrar.php`.
Los `.sql` de esta carpeta llevan datos reales (usuarios, DNI) y no se suben a git. Se regeneran con `php 07_SCRIPTS/generar_instalacion.php`.
