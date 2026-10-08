# Casos de prueba manuales — SGS

| # | Caso | Pasos | Resultado esperado |
|---|---|---|---|
| L1 | (Retirado en v0.6.3: el login ya no tiene captcha) | Usuario → contraseña → código erróneo | Mensaje "El código de seguridad no coincide…", captcha nuevo; no se cuenta como intento de login |
| L2 | Contraseña incorrecta | Clave errónea | "Usuario o contraseña incorrectos. Te quedan N intentos." |
| L3 | Bloqueo | 3 intentos fallidos con un usuario existente | Usuario bloqueado: "Comunícate con el administrador…"; aparece "Bloqueado" en Usuarios y roles y solo el administrador lo libera. Prueba: `05_TEST/integration/login_bloqueo_test.php` |
| L4 | Login correcto | Datos y código correctos | Entra a Supervisiones (o a la pantalla permitida) |
| L5 | Sesión separada | Iniciar sesión en el sistema de origen y abrir SGS | SGS pide su propio login |
| S1 | Listado | Abrir Supervisiones, filtrar por fechas y estado | Lista coherente con el sistema de origen |
| S2 | Registro | Crear supervisión con fotos y firma | Se guarda; fotos visibles en ambos sistemas |
| O1 | Observación y descargo | Registrar observación; el partner responde | Estados y plazos correctos |
| O2 | Relación de observaciones | Filtrar por estado "Penalizados" y Descargar → Excel y PDF | Solo las penalizadas, con los filtros en el PDF. Prueba: `05_TEST/integration/observaciones_export_test.php` |
| D1 | Dashboard | Abrir como Administrador y filtrar | Indicadores, mapa y matriz cargan |
| T1 | Línea de tiempo | Abrir como Administrador | Turnos del día por supervisor |
| T2 | Cierre automático del turno | Dejar un turno abierto de un día anterior | Se cierra a las 23:59 con "Fuera de turno" y nota. Prueba: `05_TEST/integration/cierre_turnos_test.php` |
| E1 | Trazabilidad | Provocar un error (p. ej. detener MySQL) | Mensaje con código `SGS-…`, mismo código en `08_LOGS/errores` |
| P1 | Accesos | Abrir `/ProviWIN/08_LOGS/`, `/ProviWIN/.env`, `/ProviWIN/02_BACKEND/` | 403 en todos |
