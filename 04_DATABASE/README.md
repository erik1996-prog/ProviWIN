# Base de datos de SGS

23 tablas, todas en uso por el sistema. Diagrama: `03_ESTRUCTURA/diagrama_relaciones.svg` (se abre en el navegador).

```
04_DATABASE/
├── 01_INSTALACION/   Crear la base desde cero en un servidor nuevo: un script por tabla, en orden
├── 02_CAMBIOS/       Cada cambio a la base (tabla nueva, columna, índice) + su reversión en rollback/
└── 03_ESTRUCTURA/    Esquema completo en un archivo y diagrama de relaciones (solo para consulta)
```

Los respaldos ya no están aquí: van en `10_RESPALDOS/` (base y sistema completos, automáticos). Ver `10_RESPALDOS/LEEME.md`.

## ¿Qué hago si…?

**…instalo el sistema en un servidor nuevo?**
Importar en orden los archivos de `01_INSTALACION/` (en phpMyAdmin: seleccionar la base → Importar). Traen los usuarios, permisos, partners, técnicos, vehículos, catálogos y penalidades actuales, y las tablas de ingresos (supervisiones, turnos, observaciones) vacías. Detalle en `01_INSTALACION/LEEME.md`.

**…cambio una tabla o creo una nueva?**

*Mientras el sistema esté solo en la PC (antes de subirlo):* el cambio se aplica directo en la base, sin script de cambios. Después:
1. Si es una tabla nueva: agregarla a la lista `$tablasSgs` de `07_SCRIPTS/generar_instalacion.php` como `datos` (catálogo) o `vacia` (ingresos). Si se olvida, el generador avisa.
2. Regenerar: `php 07_SCRIPTS/generar_instalacion.php` y `php 07_SCRIPTS/generar_diagrama.php`.
3. Anotar el cambio en `CHANGELOG.md`.

*Cuando el sistema ya esté en el hosting:* cada cambio va además como script en `02_CAMBIOS/`.
1. Copiar `02_CAMBIOS/PLANTILLA_MIGRACION.sql` como `02_CAMBIOS/001_descripcion.sql` (el siguiente número) y escribir su reversión en `02_CAMBIOS/rollback/` con el mismo nombre.
2. Respaldar: `php 07_SCRIPTS/respaldar.php`
3. Aplicar: `php 07_SCRIPTS/migrar.php` (solo ejecuta los que faltan y los anota en la tabla `sgs_migracion`). En el hosting: subir el archivo y ejecutar `migrar.php`, o importarlo en phpMyAdmin. Los datos no se tocan.
4. Regenerar instalación y diagrama, y anotar en `CHANGELOG.md`.

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
- Mientras el sistema no esté subido, los cambios no llevan script: la instalación (`01_INSTALACION`) siempre refleja la estructura actual. Los scripts de cambios empiezan en `001` cuando el sistema esté en el hosting.
