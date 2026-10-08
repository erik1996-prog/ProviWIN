# Instalación y despliegue — SGS

## Requisitos
- XAMPP con PHP 8.1 o superior (extensiones `pdo_mysql`, `mbstring`, `fileinfo`) y MySQL/MariaDB.
- Apache con `mod_rewrite` y `mod_headers` activos, y `AllowOverride All` en `htdocs`.
- El sistema de origen en `C:\xampp\htdocs\SISTEMA-WIN-PROVINCIA`: comparte la BD y la carpeta `uploads/`.

## Pasos
1. Copiar el proyecto en `C:\xampp\htdocs\ProviWIN`.
2. Copiar `.env.example` como `.env` y completar:
   - `DB_USER` / `DB_PASSWORD`: se recomienda un usuario MySQL propio con permisos solo sobre `win_telecom_db`, no `root`.
   - `SHARED_FILES_PATH`: ruta del sistema de origen.
   - `APP_ENV=production` en el servidor real.
   - `PYTHON_BIN`: Python con `reportlab`, necesario para el PDF de observaciones.
3. Comprobar que el usuario de Apache pueda escribir en `08_LOGS/` y en `SHARED_FILES_PATH/uploads/observaciones/`.
4. Abrir `http://localhost/ProviWIN/api/health.php`: debe responder `"database":true`.
5. Ejecutar las pruebas:
   - `C:\xampp\php\php.exe 05_TEST\frontend\smoke_pages.php Administrador` (también `Supervisor:directo` y `Supervisor:partner`)
   - `C:\xampp\php\php.exe 05_TEST\api\permission_matrix.php`
   - `C:\xampp\php\php.exe 05_TEST\frontend\check_inline_js.php` (requiere Node.js)
6. Entrar a `http://localhost/ProviWIN/` con un usuario existente del sistema de origen.

## Entornos
`06_DEPLOY/development`, `testing` y `production` guardan las notas y los `.env` de referencia de cada entorno (sin contraseñas). Antes de publicar una versión:
- actualizar `VERSION` y `CHANGELOG.md`;
- revisar `08_LOGS/errores` del entorno de pruebas.

## Respaldo y restauración
SGS no tiene datos propios: se respalda `win_telecom_db` y `SISTEMA-WIN-PROVINCIA/uploads/`, como en el sistema de origen.
- Respaldo: `C:\xampp\mysql\bin\mysqldump.exe -u <usuario> -p --single-transaction win_telecom_db > 04_DATABASE\10_BACKUPS\win_telecom_db_AAAAMMDD.sql`
- Restauración: `C:\xampp\mysql\bin\mysql.exe -u <usuario> -p win_telecom_db < archivo.sql`. Detén antes los dos sistemas.
