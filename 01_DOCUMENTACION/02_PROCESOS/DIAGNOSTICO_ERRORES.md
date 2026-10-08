# Diagnóstico de errores — SGS

Ejemplo: el usuario reporta *"no puedo guardar una supervisión"*.

1. **Pide el código de seguimiento** que muestra el mensaje (ej. `SGS-SUP-1A2B3C4D`). Si no lo tiene, pide la hora aproximada y el usuario.
2. **Busca el código en los logs:**
   `Select-String -Path 08_LOGS\*\*.log -Pattern "SGS-SUP-1A2B3C4D"`.
   El registro indica el endpoint, la acción, el usuario, el mensaje técnico y el archivo y la línea.
3. **Revisa la pantalla** (`03_FRONTEND/src/pages/supervision_lista.php`): validaciones del formulario y la llamada `fetch`. Busca en `08_LOGS/frontend` errores de JavaScript del mismo usuario y la misma hora.
4. **Revisa el endpoint** (`02_BACKEND/src/routes/api/supervisiones.php`, bloque `action === 'create'`): permisos (`Auth::can`), campos obligatorios y reglas.
5. **Revisa la configuración y las reglas compartidas** (`02_BACKEND/src/config/`): `auth.php`, `supervision_personal.php`, `territorial.php`.
6. **Revisa la BD:** tabla `supervisiones` y sus relaciones (diccionario en `04_BASE_DATOS/DICCIONARIO_DATOS.md`). Busca errores SQL en `08_LOGS/database`.
7. **¿Es infraestructura?** `api/health.php` responde si la aplicación y la BD están activas. Revisa `08_LOGS/errores` por `database_unavailable`.

## Códigos de módulo

`SUP` supervisiones · `OBS` observaciones · `DSH` dashboard · `TML` línea de tiempo · `AUT` login · `NOT` notificaciones · `FRT` navegador · `SYS` salud · `GEN` otros.

## Tipos de registro frecuentes

| `type` | Significado |
|---|---|
| `php_warning` | Aviso de PHP (variable no definida, clave inexistente…). No detiene la pantalla, pero hay que corregirlo. |
| `uncaught_exception` | Excepción no controlada. El usuario vio un error 500. |
| `php_fatal` | Error fatal de PHP. |
| `database_unavailable` / `database_connection_lost` | MySQL caído o reiniciado. |
| `login_error` | Fallo técnico durante el inicio de sesión (no es una contraseña incorrecta). |
