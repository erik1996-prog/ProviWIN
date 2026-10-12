# Base de datos de SGS

23 tablas, todas en uso por el sistema. Diagrama: `03_ESTRUCTURA/diagrama_relaciones.svg` (se abre en el navegador).

```
04_DATABASE/
├── 01_INSTALACION/   Crear la base desde cero: 00_instalacion_completa.sql (todo junto) o un script por tabla
├── 02_CAMBIOS/       Una carpeta con fecha por cada cambio: cambio.sql + reversion.sql (plantilla en _PLANTILLA/)
└── 03_ESTRUCTURA/    Esquema completo en un archivo y diagrama de relaciones (solo para consulta)
```

Los respaldos ya no están aquí: van en `10_RESPALDOS/` (base y sistema completos, automáticos). Ver `10_RESPALDOS/LEEME.md`.

## ¿Qué hago si…?

**…instalo el sistema en un servidor nuevo?**
Importar **`01_INSTALACION/00_instalacion_completa.sql`** (todas las tablas y datos en un solo archivo): en phpMyAdmin, seleccionar la base → Importar. También están por partes (`02_tabla_…` a `24_tabla_…`). Traen los usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades actuales; vacías las de ingresos (supervisiones, turnos, observaciones).

**…cambio una tabla o creo una nueva?** (desde el 2026-10-08, todo cambio lleva su script)
1. Crear la carpeta del cambio, con la fecha de hoy: `php 07_SCRIPTS/nuevo_cambio.php "agregar columna X a supervisiones"`
   → `02_CAMBIOS/AAAA-MM-DD_NN_descripcion/` con `cambio.sql` (lo que se modifica) y `reversion.sql` (cómo deshacerlo).
2. Escribir el cambio en `cambio.sql` y cómo deshacerlo en `reversion.sql`.
3. Respaldar: `php 07_SCRIPTS/respaldar.php base antes-de-cambio`
4. Aplicar: `php 07_SCRIPTS/migrar.php` (aplica en orden solo los pendientes y los anota en `sgs_migracion`; `--estado` muestra cuáles faltan).
5. Si es una tabla nueva: agregarla a `$tablasSgs` en `07_SCRIPTS/generar_instalacion.php`. Regenerar: `php 07_SCRIPTS/generar_instalacion.php` y `php 07_SCRIPTS/generar_diagrama.php`. Anotar en `CHANGELOG.md`.

En el hosting: subir la carpeta nueva de `02_CAMBIOS/` y ejecutar `php 07_SCRIPTS/migrar.php` (o importar su `cambio.sql` en phpMyAdmin). Los datos no se tocan.
Para deshacer: `php 07_SCRIPTS/migrar.php --revertir AAAA-MM-DD_NN_descripcion`.

**…quiero revisar que la base esté bien?**
`php 07_SCRIPTS/auditar_bd.php` (solo lee). Debe dar ALTO: 0 y MEDIO: 0.

**…necesito volver atrás?**
Restaurar un respaldo de `10_RESPALDOS/base/` (instrucciones en `10_RESPALDOS/LEEME.md`) o ejecutar el `rollback/` del cambio.

## Reglas
- Nombres de tabla **en minúsculas** (`sgs_auditoria`, no `SGS_AUDITORIA`): en un servidor Linux MySQL distingue mayúsculas. La prueba `05_TEST/api/nombres_tablas_test.php` lo controla.
- Nunca editar a mano `01_INSTALACION/` ni `03_ESTRUCTURA/`: se regeneran.
- Los `.sql` de `01_INSTALACION/` llevan datos reales (usuarios, DNI): no se suben a git.
- MariaDB 10.4 (XAMPP) se cae con consultas que unen tablas de `information_schema` (`REFERENTIAL_CONSTRAINTS`, `TABLE_CONSTRAINTS`): en los scripts se usan consultas simples filtradas por la base.

## Historial
- 2026-10-08 · las tipificaciones de cancelación de cada supervisión (la del Excel de pendientes y la corregida por el supervisor) quedan enlazadas al catálogo `tipificaciones_cancelacion`. Al importar, una tipificación nueva se registra sola; si se renombra en el catálogo, se actualiza en las órdenes. Prueba: `05_TEST/integration/importar_tipificacion_test.php`.
- 2026-10-08 · se quitaron 13 tablas del sistema original que SGS no usa (exámenes, chat, flyers, recursos, tickets de error, catálogos de tipos, etc.). Respaldo previo en `10_RESPALDOS/base/win_telecom_db_20261008_173004_antes-de-limpiar-ingresos.sql.gz`.
- 2026-10-08 · Se vaciaron las supervisiones, observaciones, checklists e inicios de turno de prueba (`07_SCRIPTS/limpiar_ingresos.sql`), conservando usuarios y catálogos.
- 2026-10-08 · Punto de partida para el hosting: `00_instalacion_completa.sql`. Desde aquí, cada cambio va en su carpeta con fecha en `02_CAMBIOS/`.
