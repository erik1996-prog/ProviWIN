# Endpoints — SGS

Base: `http://<servidor>/ProviWIN/api/`. Todas las respuestas son JSON (`{"status":"success"|"error", ...}`), salvo `observaciones_pdf.php` y `observaciones_export.php` (archivos).

**Autenticación:** cookie de sesión `SGS_SESSID`. **CSRF:** toda escritura envía la cabecera `X-CSRF-Token` con el valor de `<meta name="csrf-token">`; `03_FRONTEND/src/assets/js/main.js` la agrega sola.
**Errores:** `401/403` sin sesión o sin permiso · `422` datos inválidos · `429` demasiados intentos · `500` error interno (incluye `error_code`) · `503` BD no disponible (incluye `Retry-After`).

| Endpoint | Acción | Método | Permiso / rol | Uso |
|---|---|---|---|---|
| `auth.php` | `login` | POST | Público | `{usuario, password}`. Máximo 5 intentos fallidos por usuario e IP cada 15 minutos |
| `auth.php` | `logout` | POST | Sesión | Cierra la sesión |
| `auth.php` | `session` | GET | — | Usuario en sesión o `unauthenticated` |
| `supervisiones.php` | `list` | GET | `supervision.view` | Listado con filtros |
| `supervisiones.php` | `detail` | GET | `supervision.view` | Detalle de una supervisión |
| `supervisiones.php` | `create` | POST | `supervision.create` | Crear o editar (formulario de 4 pasos, checklist, fotos, firma) |
| `supervisiones.php` | `delete` | POST | `supervision.delete` | Eliminar |
| `supervisiones.php` | `asignar_partner` | POST | `supervision.assign` | Asignar a un partner o supervisor |
| `supervisiones.php` | `partner_update` | POST | Supervisor partner | Atención del partner |
| `supervisiones.php` | `supervisores_partner` | GET | Admin / supervisor directo | Supervisores de un partner |
| `supervisiones.php` | `pending_map` | GET | `supervision.map` | Pendientes en el mapa |
| `supervisiones.php` | `indicadores_dashboard` | GET | `dashboard.view` | Indicadores del dashboard |
| `cancelaciones_import.php` | — | POST | `supervision.import` | Importar cancelaciones desde Excel |
| `tecnicos.php` | `list`, `search`, `update`, `crear_apoyo` | GET/POST | Sesión (`crear_apoyo`: `supervision.create`) | Técnicos y vehículos de las cuadrillas; `crear_apoyo` registra un técnico de apoyo (T2) en un partner (`partner_id`, `nombre_tecnico`, `dni`) |
| `ubigeo.php` | `departamentos`, `provincias`, `distritos` | GET | Público (solo lectura) | Selector geográfico |
| `observaciones.php` | `catalogo` | GET | `observation.view` | Categorías y subopciones |
| `observaciones.php` | `create` | POST | `observation.view` | Registrar observación |
| `observaciones.php` | `responder_partner`, `responder_checklist_partner` | POST | Partner | Descargo con evidencias |
| `observaciones.php` | `evaluar_checklist`, `evaluar_supervisor`, `reabrir_checklist`, `actualizar_plazo` | POST | `observation.review` | Revisión |
| `observaciones_pdf.php` | — | GET | `observation.view` | Reporte PDF (usa el script Python del sistema de origen) |
| `observaciones_export.php` | — | GET | `observation.view` (solo personal WIN) | Relación de observaciones filtrada: `formato=xlsx` (todas las columnas) o `formato=pdf`; acepta los filtros del registro (`fecha_desde`, `fecha_hasta`, `tipo_registro`, `estado`, `partner_id`, `departamento`, `cuadrilla`, `codigo`). Máximo 50 000 filas en Excel y 3 000 en PDF |
| `dashboard.php` | `monitoreo_realtime`, `matriz_mensual` | GET | `dashboard.view` | Turnos y productividad del personal directo |
| `dashboard.php` | `cumplimiento_partners` | GET | `dashboard.view` | Cumplimiento del plazo de 24 h por partner (`fecha_inicio`, `fecha_fin`, `partner_id`) |
| `asistencia.php` | `status`, `my_history` | GET | Supervisor directo | Estado e historial de mi turno |
| `asistencia.php` | `start`, `end`, `start_event`, `end_event` | POST | Supervisor directo | Inicio, fin y pausas del turno |
| `asistencia.php` | `admin_summary`, `timeline_diaria`, `list_filters` | GET | Administración | Monitor de turnos y línea de tiempo (solo personal directo) |
| `usuarios.php` | `catalogos`, `listar`, `detalle` | GET | `users.manage` | Usuarios, roles, perfiles, partners, zonas de trabajo, permisos |
| `usuarios.php` | `guardar`, `estado`, `restablecer_password` | POST | `users.manage` | Alta y edición de usuarios, activar o desactivar, contraseña temporal |
| `usuarios.php` | `guardar_perfil`, `estado_perfil` | POST | `users.manage` | Perfiles de acceso (roles personalizados) |
| `usuarios.php` | `cambiar_password_propia` | POST | Sesión | El usuario cambia su contraseña (exige la actual) |
| `usuarios.php` | `mi_foto`, `quitar_mi_foto` | POST (multipart `foto`) | Sesión | El usuario cambia o quita su foto de perfil (JPG/PNG/WEBP ≤ 3 MB, `uploads/avatars`) |
| `notificaciones.php` | `list`, `read` | GET/POST | Sesión | Campana de notificaciones |
| `error_tickets.php` | `report` | POST | Sesión | Error del navegador → `08_LOGS/frontend` |
| `health.php` | — | GET | Público | Estado de la app y la BD |

## Ejemplo: login

```http
POST /ProviWIN/api/auth.php?action=login
Content-Type: application/json

{"usuario":"12345678","password":"••••••"}
```

```json
{"status":"error","message":"Usuario o contraseña incorrectos."}
```
