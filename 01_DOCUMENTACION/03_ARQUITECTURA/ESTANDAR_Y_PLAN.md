# Estándar de desarrollo — SGS

Versión 1.0 · 2026-10-08. Cómo está construido SGS y las reglas para seguir agregando funciones sin romper lo existente.

## 1. Diagnóstico

| Tema | Estado | Evaluación |
|---|---|---|
| Versiones | PHP 8.2 · MariaDB 10.4 (compatible MySQL) · Apache (XAMPP) | Vigentes. En el servidor final conviene MariaDB 10.11 LTS. |
| Arquitectura | PHP nativo. `index.php` sirve las pantallas; `api/*.php` son entradas finas hacia `02_BACKEND/src/routes/api/`. | Adecuada. No hace falta un framework. |
| Capas | Usuarios: `Controller → Service → Repository` + `Validator`. El resto son rutas procedimentales (`supervisiones.php`, `asistencia.php` son las más grandes). | Se separan en capas solo cuando se modifiquen. |
| Base de datos | `win_telecom_db`, compartida con el sistema de origen. 36 tablas, 44 claves foráneas, todas InnoDB + utf8mb4 y con clave primaria. | Sana. Pendiente: migración 002 (índices). |
| Consultas | PDO con consultas preparadas; ninguna arma SQL con datos del usuario. | Cumple. |
| Seguridad | `password_hash`, sesión segura, bloqueo a los 3 intentos, CSRF en escrituras, permisos `Auth::can()` validados en el servidor. | Cumple. |
| Errores | `win_log_error()` → `08_LOGS`, código rastreable `SGS-MOD-xxxx`; acciones sensibles como `audit_*`. | Cumple. |
| Respuestas JSON | `{"status":"success"|"error","message":"…"}` en toda la API. | Es el formato estándar del sistema. |
| Frontend | Bootstrap 5.3, Bootstrap Icons, Leaflet; una hoja de estilos (`sgs-theme.css`) y skill `sgs-diseno`. | Cumple. |

## 2. Cómo programar un cambio

```
api/<modulo>.php                                   entrada pública (solo require de la ruta)
02_BACKEND/src/routes/api/<modulo>.php             o controllers/: sesión, CSRF, permiso, respuesta JSON
02_BACKEND/src/services/<Modulo>Service.php        reglas de negocio y transacciones
02_BACKEND/src/repositories/<Modulo>Repository.php SQL con PDO preparado
02_BACKEND/src/validators/<Modulo>Validator.php    validación de entrada
03_FRONTEND/src/pages/<modulo>.php                 vista con el sistema de diseño
05_TEST/integration/<modulo>_test.php              prueba con datos temporales que se borran al final
```
Modelo a copiar: el módulo de Usuarios.

**Reglas:**
- Toda escritura pasa por `Auth::requireCsrf()` y `Auth::can()`.
- Escritura de varios registros → transacción.
- Funciones reutilizables en `config/` o `utils/`, sin copiarlas entre archivos.
- Errores: al usuario solo el mensaje y el código `SGS-…`; el detalle va al log.
- Cada cambio se anota en `CHANGELOG.md`.

## 3. Base de datos

- **No se renombran ni se borran tablas o columnas existentes**: las usa el sistema de origen.
- Columna nueva en tabla existente: `NULL` o con `DEFAULT`.
- Tabla nueva: **todo en minúsculas** con prefijo `sgs_` (`sgs_auditoria`, `sgs_parametro`), InnoDB, `utf8mb4_unicode_ci`; restricciones `PK_`/`FK_`/`UQ_`/`IX_`. En Linux MySQL distingue mayúsculas en los nombres de tabla: `SGS_PERFIL` y `sgs_perfil` serían tablas distintas, por eso nunca mayúsculas en nombres de tabla.
  - Índice en cada clave foránea y `UNIQUE` en lo que no debe repetirse.
  - `FECHA_CREACION`, el usuario que la crea y `ACTIVO` en lugar de borrar.
- Cambios solo con scripts en `04_DATABASE/02_CAMBIOS` (`NNN_DESCRIPCION.sql`, plantilla `PLANTILLA_MIGRACION.sql`, reversión en `rollback/`).
  - Se aplican con `php 07_SCRIPTS/migrar.php`, nunca desde la aplicación.
  - Antes de cada uno: respaldo y buscar los nombres tocados en `SISTEMA-WIN-PROVINCIA`.
- **Todo cambio deja su script** (tabla nueva, columna, índice, datos de catálogo): migración numerada + su `rollback/`. Después de aplicarla, `php 07_SCRIPTS/generar_instalacion.php` actualiza `01_INSTALACION`.
- Revisión: `php 07_SCRIPTS/auditar_bd.php` (solo lectura).

## 4. Publicar una actualización

Respaldo de BD y archivos → subir PHP (sin `.env`, `08_LOGS`, `uploads`) → `migrar.php` → verificar ingreso, una supervisión y los logs del día. Si falla: volver a la copia de archivos y ejecutar el `rollback/`. Detalle: `08_DESPLIEGUE/PUBLICAR_Y_ACTUALIZAR.md`.

## 5. Pendientes y riesgos

| Tema | Acción |
|---|---|
| Migración `002_INDICES_LIMPIEZA.sql` | Propuesta, sin aplicar: quita 5 índices repetidos y agrega 2. Aplicar cuando se autorice. |
| BD compartida | El sistema de origen corre DDL al iniciar y puede recrear índices o columnas: revisarlo antes de cada migración. |
| PDF con Python | El servidor necesita Python + reportlab (`PYTHON_BIN` en `.env`). |
| HTTPS | Sin HTTPS el navegador no entrega el GPS. |
| Usuario MySQL | Usar uno propio de SGS con permisos mínimos, no `root`. |
