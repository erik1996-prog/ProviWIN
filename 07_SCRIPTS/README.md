# 07_SCRIPTS

Scripts de mantenimiento. Reglas: solo CLI (empiezan con `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`), sin contraseñas en el código, y documentados aquí antes de usarse.

| Script | Qué hace |
|---|---|
| `migrar.php [--estado]` | Aplica las migraciones pendientes de `04_DATABASE/09_MIGRATIONS` (respaldar antes) |
| `auditar_bd.php [--sql]` | **Solo lectura.** Revisa la BD contra el estándar (motor, utf8mb4, PK, FK, índices, auditoría); `--sql` imprime correcciones sugeridas sin ejecutarlas |
| `respaldar_bd.php` | Respaldo de la base de datos |
| `cerrar_turnos.php` | Cierra turnos olvidados a las 23:59 (programar a diario a esa hora) |
| `restablecer_contrasena.php` | Restablece la contraseña de un usuario |
| `crear_usuario_prueba.php` | Crea un usuario temporal para pruebas |
| `render_observaciones_lista.py` | Lo llama `api/observaciones_export.php` para la relación de observaciones en PDF |
| `crear_administrador.php` | Crea el primer administrador en una base nueva (solo si no hay ninguno activo) |
| `generar_instalacion.php` | Regenera `04_DATABASE/00_INSTALACION` (base + un script por tabla en orden) desde la base actual; solo lee la BD |
