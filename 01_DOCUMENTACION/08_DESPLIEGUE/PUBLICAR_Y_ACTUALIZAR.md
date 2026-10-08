# Publicar SGS en internet y seguir actualizándolo

## La idea en una frase
**El código viaja con Git; la base de datos de producción nunca se reemplaza, solo recibe "migraciones".**

```
 Tu PC (XAMPP)                 GitHub (privado)                Servidor (producción)
 ─────────────                 ────────────────                ─────────────────────
 programas y pruebas  ──push──▶ historial del código ──pull──▶ código nuevo
 04_DATABASE/02_CAMBIOS ─────────────────────────────────▶ migrar.php aplica solo lo nuevo
 BD de pruebas (local)                                          BD REAL (turnos, supervisiones)
                                                                └─ respaldo automático antes de cada cambio
```

| Qué | Cómo se actualiza | Qué NO se hace nunca |
|---|---|---|
| Código (PHP, CSS, JS) | `git push` en tu PC → `actualizar.sh` en el servidor | Editar archivos directamente en el servidor |
| Estructura de la BD (tablas, columnas) | Nuevo archivo `04_DATABASE/02_CAMBIOS/00N_descripcion.sql` → se aplica solo | Copiar la BD local encima de la de producción |
| Datos (usuarios, turnos, supervisiones) | Los usuarios trabajan en producción | Borrarlos o sobrescribirlos |
| Fotos y firmas (`uploads/`) | Viven en el servidor | Subirlas a Git |
| Contraseñas (`.env`) | Se crean a mano una vez en cada lugar | Subirlas a Git (ya están en `.gitignore`) |

---

## Parte 1 · Publicar por primera vez

### Requisitos del servidor
- Linux con Apache 2.4 (`mod_rewrite`, `mod_headers`), PHP 8.1+ (`pdo_mysql`, `mbstring`, `fileinfo`, `zlib`), MySQL 8 o MariaDB 10.6+, Git y HTTPS (Let's Encrypt).
- Opcional: Python 3 con `reportlab` para el PDF de observaciones.
- **SGS comparte BD y `uploads/` con SISTEMA-WIN-PROVINCIA**: si el sistema de origen se sigue usando, debe publicarse en el mismo servidor (y antes corregir su auditoría de seguridad, secciones S1–S6).

### Pasos
1. **Subir el código a GitHub (privado)** — en tu PC, una sola vez:
   ```
   cd C:\xampp\htdocs\ProviWIN
   git init
   git add .
   git commit -m "SGS v0.5.7"
   git remote add origin https://github.com/<tu-usuario>/proviwin-sgs.git
   git push -u origin main
   ```
   `.gitignore` ya excluye `.env`, logs, respaldos y credenciales locales.
2. **Respaldar la BD local** (será la base inicial de producción):
   `C:\xampp\php\php.exe 07_SCRIPTS\respaldar.php publicacion-inicial`
3. **En el servidor**:
   ```
   cd /var/www
   git clone https://github.com/<tu-usuario>/proviwin-sgs.git sgs
   cd sgs && cp .env.example .env   # completar con los datos del servidor
   mysql -u root -p -e "CREATE DATABASE win_telecom_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p -e "CREATE USER 'sgs_app'@'localhost' IDENTIFIED BY '<clave-larga>'; GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,REFERENCES ON win_telecom_db.* TO 'sgs_app'@'localhost';"
   gunzip -c win_telecom_db_<fecha>_publicacion-inicial.sql.gz | mysql -u root -p win_telecom_db
   php 07_SCRIPTS/migrar.php
   ```
4. **Apache**: apuntar el sitio a la carpeta, `AllowOverride All`, HTTPS obligatorio (ver `09_CONFIG/apache-sgs.conf.example`). En `.env`: `APP_ENV=production`.
5. **Comprobar**: `https://<dominio>/api/health.php` debe responder `"database":true`. Entrar con `eplasencia`.

---

## Parte 2 · Actualizar (cada vez que haya mejoras)

1. **En tu PC**: se hace el cambio, se prueba en XAMPP y se corren las pruebas:
   ```
   C:\xampp\php\php.exe 05_TEST\frontend\smoke_pages.php
   C:\xampp\php\php.exe 05_TEST\api\permission_matrix.php
   ```
2. Se sube la versión en `VERSION`, se anota en `CHANGELOG.md` y:
   ```
   git add . && git commit -m "v0.5.8: <qué cambió>" && git push
   ```
3. **En el servidor** (un solo comando):
   ```
   bash 07_SCRIPTS/actualizar_servidor.sh
   ```
   Hace, en orden: respaldo de la BD → `git pull` → migraciones pendientes → comprobación de salud.
   Los usuarios siguen trabajando; los navegadores toman los estilos nuevos solos (la versión va en la URL de CSS/JS).

### Cuando un cambio necesita tocar la base de datos
Nunca se edita la BD de producción a mano. Se crea un archivo nuevo numerado:
```
04_DATABASE/02_CAMBIOS/002_agregar_motivo_bloqueo.sql
```
con sentencias que **agregan** sin borrar (por ejemplo `ALTER TABLE ... ADD COLUMN ...`). `migrar.php` lo aplica una sola vez en tu PC y una sola vez en el servidor (lleva la cuenta en `SGS_MIGRACION`). Los datos existentes se conservan.

---

## Volver atrás si algo sale mal
- **Código**: `git reset --hard <commit-anterior>` (el script lo muestra si la comprobación falla).
- **Base de datos**: restaurar el respaldo que el script creó justo antes:
  ```
  gunzip -c 10_RESPALDOS/base/win_telecom_db_<fecha>_antes-de-actualizar.sql.gz | mysql -u root -p win_telecom_db
  ```
  Ojo: se pierde lo registrado después de ese respaldo. Por eso las actualizaciones se hacen fuera del horario de operación.

## Respaldos periódicos (recomendado)
Programar en el servidor un respaldo diario (`crontab -e`):
```
30 2 * * * cd /var/www/sgs && php 07_SCRIPTS/respaldar.php diario
```
Conserva 30 días. Copiar periódicamente esos archivos fuera del servidor (otro disco o la nube).

---

## Capacidad: 100 usuarios

Resultados medidos el 2026-10-06 en la PC de desarrollo (XAMPP en Windows):

| Prueba | Resultado |
|---|---|
| 100 usuarios simultáneos, 1.500 peticiones | 0 errores; 95% responde en menos de 0,24 s; más de 370 pet/s en la pantalla más pesada |
| Un año de datos (31.400 supervisiones y 31.400 turnos de 100 supervisores) | Ninguna consulta pasa de 0,41 s |
| 200 usuarios simultáneos | 0 errores, pero esperas de hasta 7 s: es el límite de 150 peticiones a la vez de Apache en XAMPP |

Para referencia: 100 usuarios reales generan unas 2-10 peticiones por segundo; el sistema atiende más de 300.

**En el servidor de producción** aplicar los ajustes de `09_CONFIG/`:
- `php-produccion.ini`: **OPcache activo** (en XAMPP está desactivado), límites y seguridad.
- `mysql-produccion.cnf`: memoria de datos de MySQL (XAMPP trae 16 MB; usar 512 MB o más) y registro de consultas lentas.
- `apache-sgs.conf.example`: HTTPS, PHP-FPM y `mpm_event`, que no pone en fila las conexiones como XAMPP.

Servidor sugerido para 100 usuarios: 2 vCPU, 4 GB de RAM y disco SSD.

Repetir las pruebas tras cambios grandes:
```
php 05_TEST/integration/carga_100_usuarios.php 100 3 https://<dominio>
php 05_TEST/integration/volumen_bd_prueba.php        # solo en la PC de desarrollo (crea y borra una BD de prueba)
```
