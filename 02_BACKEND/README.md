# 02_BACKEND — SGS

Flujo objetivo (guía, sección 8): **Frontend → Route → Controller → Service → Repository → BD**.

## Estado actual (fase 1)
| Carpeta | Contenido |
|---|---|
| `src/config/` | Configuración (`config.php`, `env.php`), registro de errores (`runtime.php`), conexión (`database.php`), sesión y permisos (`auth.php`), reglas compartidas |
| `src/routes/api/` | Un archivo por endpoint con su lógica completa, traída del sistema de origen sin cambiar reglas de negocio |
| `src/middlewares/` | `captcha.php` |
| `controllers/ services/ repositories/ models/ validators/ utils/` | Vacías: se llenan en la fase 2 |

## Plan de la fase 2 (un módulo a la vez)
Orden sugerido (de menor a mayor riesgo): `ubigeo` → `tecnicos` → `dashboard` → `observaciones` → `supervisiones`.

Por cada módulo:
1. `repositories/<Modulo>Repository.php`: mover allí todo el SQL. Es el único lugar que conoce los nombres de las tablas, lo que facilita el futuro cambio a `SGS_*`.
2. `services/<Modulo>Service.php`: reglas de negocio (estados, SLA, validaciones de flujo).
3. `validators/<Modulo>Validator.php`: validación de entrada.
4. `controllers/<Modulo>Controller.php`: lee la petición, llama al servicio y responde JSON.
5. `routes/api/<modulo>.php` queda reducido a: autenticación, CSRF y `Controller->handle($action)`.
6. Antes y después: `05_TEST/frontend/smoke_pages.php` y las pruebas manuales de `01_DOCUMENTACION/07_PRUEBAS`.
