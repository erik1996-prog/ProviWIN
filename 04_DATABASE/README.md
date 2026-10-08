# 04_DATABASE — SGS

SGS usa `win_telecom_db`, la misma base que el sistema de origen (ver `01_DOCUMENTACION/04_BASE_DATOS/DICCIONARIO_DATOS.md`).

## Qué hay en cada carpeta

| Carpeta | Para qué | Cómo se mantiene |
|---|---|---|
| `00_INSTALACION/` | **Crear la base desde cero**: `01_crear_base.sql` y luego un script por tabla (`02_tabla_…` a `37_tabla_…`), en orden de dependencias. Los catálogos traen sus datos. Ver `LEEME.md`. | Generada: `php 07_SCRIPTS/generar_instalacion.php` |
| `01_SCHEMA/` | La misma estructura en un solo archivo (`win_telecom_db_esquema.sql`) y el diagrama de relaciones (`esquema_relaciones.png/.svg`). | El `.sql` también se genera con `generar_instalacion.php` |
| `09_MIGRATIONS/` | **Cambios sobre una base existente**: `NNN_DESCRIPCION.sql` + su reversión en `rollback/`. Plantilla: `PLANTILLA_MIGRACION.sql`. | Se escriben a mano, uno por cambio |
| `10_BACKUPS/` | Respaldos (`php 07_SCRIPTS/respaldar_bd.php`). Excluidos de git. | Automático, se borran a los 30 días |
| `03_VIEWS` … `08_SEEDS` | Reservadas para vistas, procedimientos, funciones, triggers, índices y datos si llegan a necesitarse. Hoy vacías. | — |

**Regla:** nada se edita a mano en `00_INSTALACION` ni en `01_SCHEMA`; se regeneran.

## Hacer un cambio en la base
1. Escribir `09_MIGRATIONS/NNN_DESCRIPCION.sql` y su `rollback/NNN_DESCRIPCION.sql` a partir de la plantilla.
2. Respaldar: `php 07_SCRIPTS/respaldar_bd.php`.
3. Aplicar: `php 07_SCRIPTS/migrar.php`. Queda registrada en `SGS_MIGRACION`.
4. Regenerar la instalación: `php 07_SCRIPTS/generar_instalacion.php`.
5. Verificar con `php 07_SCRIPTS/auditar_bd.php` y anotar el cambio en `CHANGELOG.md`.

Antes de tocar una tabla existente, buscar su nombre en `SISTEMA-WIN-PROVINCIA`: el sistema de origen la usa.

## Migraciones
- `001_SGS_PERFIL.sql` (aplicada el 2026-10-04): tablas nuevas `SGS_PERFIL` y `SGS_USUARIO_PERFIL`.
- `002_INDICES_LIMPIEZA.sql` (aplicada el 2026-10-08): quita 5 índices repetidos y agrega 2.

## Crear la base en un servidor nuevo
Ejecutar en orden los archivos de `00_INSTALACION/` (phpMyAdmin → Importar, o por consola). Después, crear el primer administrador con `php 07_SCRIPTS/crear_administrador.php`. Detalle en `00_INSTALACION/LEEME.md`.
**No usar sobre una base con datos:** ahí solo se usa `migrar.php`.

## Nomenclatura a futuro
Las tablas existentes no se renombran mientras el sistema de origen las use. Las tablas nuevas llevan el prefijo `SGS_` (ver `01_DOCUMENTACION/03_ARQUITECTURA/ESTANDAR_Y_PLAN.md`).
