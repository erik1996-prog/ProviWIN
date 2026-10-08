# Instalación de la base de datos desde cero

Ejecutar **en orden de número**, uno por uno: phpMyAdmin → seleccionar la base → pestaña **Importar**, o por consola:
`mysql -u usuario -p NOMBRE_BASE < 02_tabla_xxx.sql`

Los scripts **no fijan el nombre de la base**. `01_crear_base.sql` solo hace falta si la base no existe; en un hosting la base se crea desde el panel y ese paso se omite.
Cada tabla va después de las tablas de las que depende, por eso el orden importa. Cada archivo indica en su cabecera de cuáles depende.

Se instalan **23 tablas**: con sus datos actuales (usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades) y **vacías** las de ingresos (supervisiones, observaciones, checklists, turnos, notificaciones).

| Archivo | Tabla | Datos |
|---|---|---|
| `01_crear_base.sql` | base vacía | — |
| `02_tabla_categorias_observacion.sql` | `categorias_observacion`: categorías de observación | 6 registros |
| `03_tabla_login_attempts.sql` | `login_attempts`: intentos de ingreso (bloqueo a los 3 intentos) | vacía |
| `04_tabla_partners.sql` | `partners`: contratas (incluye WIN DIRECTO, necesario para el personal propio) | 12 registros |
| `05_tabla_sgs_migracion.sql` | `sgs_migracion`: migraciones aplicadas (empieza vacía: la instalación ya trae la estructura al día) | vacía |
| `06_tabla_tipificaciones_cancelacion.sql` | `tipificaciones_cancelacion`: motivos de cancelación | 3 registros |
| `07_tabla_ubigeo.sql` | `ubigeo`: departamentos, provincias y distritos de operación | 47 registros |
| `08_tabla_vehiculos.sql` | `vehiculos`: vehículos de las cuadrillas | 2 registros |
| `09_tabla_subopciones_observacion.sql` | `subopciones_observacion`: tipificaciones con gravedad y penalidad | 83 registros |
| `10_tabla_usuarios.sql` | `usuarios`: usuarios del sistema | 10 registros |
| `11_tabla_asistencia_supervisores.sql` | `asistencia_supervisores`: inicios y cierres de turno | vacía |
| `12_tabla_sgs_perfil.sql` | `sgs_perfil`: perfiles de acceso | 0 registros |
| `13_tabla_tecnicos.sql` | `tecnicos`: técnicos líderes (T1) y de apoyo (T2) por partner | 196 registros |
| `14_tabla_usuario_departamentos_acceso.sql` | `usuario_departamentos_acceso`: zonas adicionales de cada usuario | 0 registros |
| `15_tabla_usuario_permisos.sql` | `usuario_permisos`: permisos personalizados por usuario | 0 registros |
| `16_tabla_asistencia_eventos.sql` | `asistencia_eventos`: pausas del turno (almuerzo, reunión) | vacía |
| `17_tabla_sgs_usuario_perfil.sql` | `sgs_usuario_perfil`: perfil asignado a cada usuario | 0 registros |
| `18_tabla_supervisiones.sql` | `supervisiones`: supervisiones | vacía |
| `19_tabla_checklists_tecnicos.sql` | `checklists_tecnicos`: checklists de cuadrilla | vacía |
| `20_tabla_observaciones_supervision.sql` | `observaciones_supervision`: observaciones en campo | vacía |
| `21_tabla_supervision_coordenadas_historial.sql` | `supervision_coordenadas_historial`: ubicaciones GPS registradas | vacía |
| `22_tabla_supervision_estado_historial.sql` | `supervision_estado_historial`: cambios de estado de cada supervisión | vacía |
| `23_tabla_checklist_respuestas.sql` | `checklist_respuestas`: criterios de cada checklist | vacía |
| `24_tabla_notificaciones.sql` | `notificaciones`: notificaciones a usuarios | vacía |

Si la base no trae ningún administrador, crear el primero (no es SQL), desde la carpeta del sistema:
`php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> "<Nombres>" "<Apellidos>"`

**Verificar:** `php 07_SCRIPTS/auditar_bd.php` → 23 tablas y ALTO: 0.

**Importante:** solo para una base **nueva**. En una base con datos, los cambios se aplican con `php 07_SCRIPTS/migrar.php`.
Los `.sql` de esta carpeta llevan datos reales (usuarios, DNI) y no se suben a git. Se regeneran con `php 07_SCRIPTS/generar_instalacion.php`.
