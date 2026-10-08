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
Los valores de cada entorno (local, hosting) van en su propio `.env`, a partir de `.env.example` (sin contraseñas en el repositorio).
- actualizar `VERSION` y `CHANGELOG.md`;
- revisar `08_LOGS/errores` del entorno de pruebas.

## Respaldo y restauración
El sistema respalda solo la base (cada 6 h) y el sistema completo con fotos (cada 24 h), y ambos apenas la base vuelve después de una caída. A mano: `C:
mpp\php\php.exe 07_SCRIPTSespaldar.php`. Archivos e instrucciones para restaurar: `10_RESPALDOS/LEEME.md`.
