# Seguridad — SGS

## Controles aplicados
- **Acceso web:** solo son públicos `index.php`, `api/*.php` y `03_FRONTEND/src/assets/`. Las carpetas internas tienen `Require all denied` además de las reglas del `.htaccess` raíz.
- **Login:** sin captcha desde v0.6.3; 3 contraseñas incorrectas bloquean la cuenta hasta que el administrador la libera (v0.10.0); para usuarios inexistentes, 5 fallos por IP → espera de 15 minutos; mensaje genérico ante usuario o contraseña incorrectos, y `session_regenerate_id` al entrar.
- **Sesión:** cookie `SGS_SESSID` `HttpOnly`, `SameSite=Lax`, `Secure` con HTTPS, y `use_strict_mode`. Cada minuto se revalida que la cuenta siga activa y con el mismo rol; si no, la sesión se cierra.
- **Seguimiento:** solo administración puede leer turnos y la línea de tiempo. El partner solo ve sus órdenes asignadas (verificado con `05_TEST/api/permission_matrix.php`).
- **Errores:** el usuario nunca ve detalles técnicos, solo el código `SGS-…`. Los logs se conservan 90 días.
- **CSRF:** token en todas las escrituras de la API.
- **Permisos:** cada ruta de `index.php` y cada acción de la API valida con `Auth::can`; el menú es solo presentación.
- **BD:** PDO con consultas preparadas. SGS no ejecuta DDL ni crea la base.
- **Archivos:** las cargas se validan por tipo real (`finfo`) y se guardan con nombre aleatorio; `assets/` no ejecuta PHP.
- **Secretos:** credenciales en `.env` (bloqueado por web y excluido en `.gitignore`).

## Pendientes / recomendaciones
- El captcha se retiró en v0.6.3 a pedido del usuario; la protección del login es el bloqueo por intentos. Si SGS se publica en internet, conviene volver a activar una verificación (p. ej. Cloudflare Turnstile).
- Crear un usuario MySQL exclusivo para SGS con los permisos mínimos (ver `04_BASE_DATOS/DICCIONARIO_DATOS.md`, columna "Uso en SGS").
- Servir el sistema solo por HTTPS en producción.
- Los hallazgos de la auditoría del sistema de origen (2026-10-04) que afectan a la BD compartida siguen pendientes allí.
