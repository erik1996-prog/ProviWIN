# Mapa del sistema SGS

Índice de dónde está cada cosa. Si algo no está aquí, falta documentarlo.

## Documentación (01_DOCUMENTACION)

| Carpeta | Contenido |
|---|---|
| `00_REFERENCIAS/` | Guía de estructura (PDF), guía de diseño WIN (`DISENO-WIN.md`), prototipo del login |
| `01_REQUERIMIENTOS/` | `REQUERIMIENTOS.md`: alcance de SGS y reglas de negocio conocidas |
| `02_PROCESOS/` | `DIAGNOSTICO_ERRORES.md`: cómo investigar un fallo paso a paso |
| `03_ARQUITECTURA/` | `ESTANDAR_Y_PLAN.md` (diagnóstico y estándar de desarrollo y BD), `TRAZABILIDAD.md` (de la pantalla a la tabla; logs) y `GUIA_VISUAL.md` (colores y formatos) |
| `04_BASE_DATOS/` | `DICCIONARIO_DATOS.md`: tablas que usa SGS y su nombre SGS propuesto |
| `05_API/` | `ENDPOINTS.md`: endpoints, acciones, método y permisos |
| `06_SEGURIDAD/` | `SEGURIDAD.md`: controles aplicados y pendientes |
| `07_PRUEBAS/` | Casos de prueba manuales |
| `08_DESPLIEGUE/` | `SUBIR_A_HOSTING.md` (hosting compartido) ·  `INSTALACION.md` (instalación local) y `PUBLICAR_Y_ACTUALIZAR.md` (internet, actualizaciones, respaldos) |
| `09_MANUALES/` | Manuales de usuario (pendiente) |

## Código

| Capa | Ruta |
|---|---|
| Enrutador de pantallas | `index.php` |
| Entradas públicas de API | `api/*.php` (cada una solo hace `require` de su ruta en el backend) |
| Rutas de API (lógica actual) | `02_BACKEND/src/routes/api/` |
| Configuración, sesión, BD, permisos | `02_BACKEND/src/config/` |
| Pantallas | `03_FRONTEND/src/pages/` |
| Menú lateral y barra superior | `03_FRONTEND/src/layouts/` |
| Estilos | `03_FRONTEND/src/assets/css/` (`sgs-theme.css` es la capa de diseño WIN) |
| JavaScript | `03_FRONTEND/src/assets/js/` |
| Logs | `08_LOGS/{backend,frontend,database,errores}/SGS-<CANAL>-AAAA-MM-DD.log` |

## Módulos y códigos

| Código | Módulo | Pantallas | Endpoints |
|---|---|---|---|
| `SUP` | Supervisiones | `supervision_lista`, `supervision_registro` | `supervisiones`, `tecnicos`, `ubigeo`, `cancelaciones_import` |
| `OBS` | Observaciones | `registro_observaciones`, `observaciones_partner`, `control_observaciones` | `observaciones`, `observaciones_pdf`, `observaciones_export` |
| `DSH` | Dashboard | `admin_dashboard` | `dashboard`, `supervisiones?action=indicadores_dashboard` |
| `TML` | Línea de tiempo | `timeline_supervisores` | `asistencia?action=timeline_diaria`, `dashboard` |
| `USR` | Usuarios y roles | `usuarios` | `usuarios` (controller/service/repository) |
| `TUR` | Turnos | `asistencia` (Mi turno / Monitor de turnos) | `asistencia` |
| `AUT` | Autenticación | `login` | `auth` |
| `NOT` | Notificaciones | barra superior | `notificaciones` |
| `FRT` | Errores del navegador | todas | `error_tickets` |
| `SYS` | Salud del sistema | — | `health` |

## Sistema de diseño (v1.0)
| Qué | Dónde |
|---|---|
| Reglas para crear pantallas | `.claude/skills/sgs-diseno/SKILL.md` |
| Estilos (fuente única) | `03_FRONTEND/src/assets/css/sgs-theme.css` |
| Guía en vivo | `index.php?page=guia_estilo` (administración) |
| Revisor automático | `05_TEST/frontend/design_lint.php` |
| Regresión visual (CSS) | `05_TEST/frontend/visual_snapshot.js` |
| Documento | `01_DOCUMENTACION/03_ARQUITECTURA/GUIA_VISUAL.md` |
