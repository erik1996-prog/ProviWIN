# 07_SCRIPTS

Scripts de mantenimiento. Reglas: solo CLI (empiezan con `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`), sin contraseñas en el código, y documentados aquí antes de usarse.

| Script | Qué hace |
|---|---|
| `nuevo_cambio.php "descripción"` | Crea la carpeta con fecha de un cambio de base: `04_DATABASE/02_CAMBIOS/AAAA-MM-DD_NN_descripcion/` con `cambio.sql` y `reversion.sql` |
| `migrar.php [--estado | --revertir CARPETA]` | Aplica en orden los cambios pendientes de `04_DATABASE/02_CAMBIOS` (respaldar antes) o deshace uno |
| `auditar_bd.php [--sql]` | **Solo lectura.** Revisa la BD contra el estándar (motor, utf8mb4, PK, FK, índices, auditoría); `--sql` imprime correcciones sugeridas sin ejecutarlas |
| `respaldar.php [todo|base|sistema] [motivo]` | Respaldo a mano o por cron de la base (.sql.gz) y del sistema con fotos (.tar.gz) en `10_RESPALDOS/`. El sistema además respalda solo: base cada 6 h, sistema cada 24 h y ambos tras una caída |
| `cerrar_turnos.php` | Cierra turnos olvidados a las 23:59 (programar a diario a esa hora) |
| `restablecer_contrasena.php` | Restablece la contraseña de un usuario |
| `crear_usuario_prueba.php` | Crea un usuario temporal para pruebas |
| `render_observaciones_lista.py` | Lo llama `api/observaciones_export.php` para la relación de observaciones en PDF |
| `crear_administrador.php` | Crea el primer administrador en una base nueva (solo si no hay ninguno activo) |
| `generar_instalacion.php` | Regenera `04_DATABASE/01_INSTALACION` (base + un script por tabla en orden) desde la base actual; solo lee la BD |
| `limpiar_ingresos.sql` | **Borra** supervisiones, observaciones, checklists, notificaciones e inicios de turno; conserva usuarios, técnicos y catálogos. Respaldar antes |
| `generar_diagrama.php` | Regenera `04_DATABASE/03_ESTRUCTURA/diagrama_relaciones.svg` desde la base (requiere Node.js) |
| `actualizar_servidor.sh` | En el servidor Linux: respaldo, `git pull`, migraciones pendientes y verificación |
