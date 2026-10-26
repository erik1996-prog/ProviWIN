# Subir SGS a un hosting compartido (cPanel / phpMyAdmin)

Lista de control para la primera subida de prueba. Para un servidor propio (VPS) ver `PUBLICAR_Y_ACTUALIZAR.md`.

## 1. Antes de subir (en el hosting)
- [ ] **PHP 8.1 o superior** (cPanel → Seleccionar versión de PHP) con: `pdo_mysql`, `mbstring`, `fileinfo`, `phar`, `zlib`.
- [ ] **Apache** (lee los `.htaccess`). Si el hosting usa solo Nginx, los `.htaccess` no protegen las carpetas internas: avisar antes de publicar.
- [ ] **HTTPS activo** (certificado del hosting). Sin HTTPS el navegador no entrega el GPS a los supervisores.
- [ ] Base de datos creada desde cPanel y un usuario con todos los permisos sobre ella.

## 2. Base de datos
En phpMyAdmin seleccionar la base y, en la pestaña **Importar**, importar **`04_DATABASE/01_INSTALACION/00_instalacion_completa.sql`** (todas las tablas y datos en un solo archivo). La base se crea antes desde el panel. Si phpMyAdmin pusiera límite de tamaño, están también por partes (`02_…` a `24_…`, en orden). Detalle en su `LEEME.md`.

Después de subir, cada cambio de la base llega como carpeta con fecha en `04_DATABASE/02_CAMBIOS/` y se aplica con `php 07_SCRIPTS/migrar.php` (o importando su `cambio.sql`).

## 3. Archivos
- Subir la carpeta completa del sistema (por el Administrador de archivos o FTP), **excepto**: `.env`, `.git/`, `08_LOGS/` (solo la estructura vacía), `10_RESPALDOS/base/`, `09_CONFIG/*.local.txt`.
- Crear el `.env` en el servidor copiando `.env.example`:
  ```
  APP_ENV=production
  DB_HOST=localhost
  DB_NAME=<base del hosting>
  DB_USER=<usuario del hosting>
  DB_PASSWORD="<clave>"        # entre comillas si tiene espacios o el carácter #
  SHARED_FILES_PATH=          # vacío: las fotos se guardan en la carpeta del sistema, en uploads/
  PYTHON_BIN=                 # vacío: usa python3, si el hosting lo tiene
  ```
- Permisos: carpetas `755`, archivos `644`, y `.env` en `600`. `08_LOGS/` y `uploads/` tienen que tener permiso de escritura para PHP (normalmente ya lo tienen).

## 4. Usuarios
La instalación ya trae los usuarios actuales con sus contraseñas: se entra con el usuario de siempre. Lo que sigue solo hace falta si la base no tuviera ningún administrador.

Crear el primer administrador necesita ejecutar PHP en el servidor. Con **Terminal** de cPanel (o SSH):
```
cd ~/public_html/<carpeta>
php 07_SCRIPTS/crear_administrador.php <DNI> <usuario> <correo> "<Nombres>" "<Apellidos>"
```
La contraseña temporal queda en `09_CONFIG/<usuario>.local.txt` (bloqueado por web). Se lee desde el Administrador de archivos y luego se borra.
Sin Terminal: crear una tarea **Cron** con ese mismo comando para que corra una vez, y después borrarla.

## 5. Tareas programadas (cPanel → Cron Jobs)
| Cuándo | Comando | Para qué |
|---|---|---|
| Todos los días, 23:59 | `php ~/public_html/<carpeta>/07_SCRIPTS/cerrar_turnos.php` | Cierra los turnos olvidados |
| Todos los días, 02:00 | `php ~/public_html/<carpeta>/07_SCRIPTS/respaldar.php todo diario` | Respaldo de la base y del sistema (además de los automáticos) |
| Cada 15 minutos (`*/15 * * * *`) | `php ~/public_html/<carpeta>/07_SCRIPTS/corte_lluvias.php --programados --url=https://<dominio>/<carpeta>` | Corte de lluvias en el grupo de Teams a las horas de Clima › "Programar envíos" (necesita GD y `09_CONFIG/teams_lluvias.local.txt`) |

La hora del cron es la del servidor, que puede no ser la de Lima: revisarla en cPanel. El sistema trabaja en hora de Lima.

## 6. Comprobar
- [ ] `https://<dominio>/<carpeta>/api/health.php` responde `"database":true`.
- [ ] `https://<dominio>/<carpeta>/.env` y `/<carpeta>/08_LOGS/` responden **403** (prohibido).
- [ ] Entrar con el administrador, cambiar la contraseña, crear un supervisor de prueba.
- [ ] Como supervisor desde el celular: iniciar turno (pide GPS), registrar una supervisión con fotos y verlas en el detalle.
- [ ] Registro de observaciones → Descargar → **Excel**.

## Limitaciones conocidas en hosting compartido
- **PDF** (relación y checklist con evidencias): necesitan Python 3 + `reportlab` y que `exec()` esté permitido. Muchos hostings compartidos lo bloquean; en ese caso el PDF muestra un código de error y el **Excel sigue funcionando**.
- El **PDF de checklist con evidencias** usa además un script del sistema de origen: si solo se sube SGS, ese PDF no estará disponible.
- **Fotos:** si solo se sube SGS, se guardan en `<carpeta>/uploads/`. Las fotos antiguas del sistema de origen no se ven si ese sistema no está en el mismo hosting.
