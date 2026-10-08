# Trazabilidad y registro de errores — SGS

## Flujo general

```
Pantalla (03_FRONTEND/src/pages) ──fetch──> api/<endpoint>.php ──require──> 02_BACKEND/src/routes/api/<endpoint>.php
                                                                       │
                                                         02_BACKEND/src/config (Auth, Database, reglas)
                                                                       │
                                                                 win_telecom_db
```

**Fase 1 (actual):** la lógica de cada endpoint sigue en un solo archivo de `routes/api/`, como en el sistema de origen.
**Fase 2:** cada módulo se separa en `controllers/ → services/ → repositories/` (ver `02_BACKEND/README.md`).

## Mapa por módulo

### SUP — Supervisiones
```
supervision_lista.php (pantalla)
  ↓ GET/POST api/supervisiones.php?action=list|detail|create|delete|asignar_partner|partner_update|pending_map|supervisores_partner
  ↓ 02_BACKEND/src/routes/api/supervisiones.php
  ↓ config/auth.php (permisos) · config/supervision_personal.php · config/territorial.php · config/notifications.php
  ↓ supervisiones, checklists_tecnicos, checklist_respuestas, observaciones_supervision, tecnicos, vehiculos,
    supervision_coordenadas_historial, notificaciones
```
Importación de cancelaciones: `api/cancelaciones_import.php` → `config/asignacion_cancelaciones.php` → `supervisiones`.

### OBS — Observaciones
```
registro_observaciones.php / control_observaciones.php
  ↓ api/observaciones.php?action=catalogo|create|responder_partner|responder_checklist_partner|evaluar_checklist|evaluar_supervisor|reabrir_checklist|actualizar_plazo
  ↓ 02_BACKEND/src/routes/api/observaciones.php · config/observation_records.php
  ↓ observaciones_supervision, checklists_tecnicos, checklist_respuestas, categorias_observacion, subopciones_observacion
```
Regla SLA: al abrir la pantalla se vencen las observaciones con plazo pasado (como máximo una vez por minuto).

### DSH — Dashboard
```
admin_dashboard.php
  ↓ api/dashboard.php?action=monitoreo_realtime|matriz_mensual
  ↓ api/supervisiones.php?action=indicadores_dashboard
  ↓ api/asistencia.php?action=timeline_diaria|list_filters
  ↓ supervisiones, usuarios, partners, asistencia_supervisores
```

### TML — Línea de tiempo
```
timeline_supervisores.php
  ↓ api/dashboard.php?action=monitoreo_realtime · api/asistencia.php?action=timeline_diaria
  ↓ asistencia_supervisores, asistencia_eventos, supervisiones
```

### AUT — Autenticación
```
pages/login.php → POST api/auth.php?action=login {usuario, password}
               → login_attempts (límite) → Auth::login → usuarios
```

## Registro de errores

| Canal | Archivo | Qué guarda |
|---|---|---|
| backend | `08_LOGS/backend/SGS-BACKEND-AAAA-MM-DD.log` | Todo error o aviso de PHP |
| database | `08_LOGS/database/SGS-DATABASE-AAAA-MM-DD.log` | Errores SQL/PDO y caídas de MySQL |
| errores | `08_LOGS/errores/SGS-ERRORES-AAAA-MM-DD.log` | Solo fallos graves: excepciones no controladas, fatales, BD caída |
| frontend | `08_LOGS/frontend/SGS-FRONTEND-AAAA-MM-DD.log` | Errores JavaScript y respuestas HTTP fallidas vistas en el navegador |

Cada línea es un JSON con:

```json
{"time":"2026-10-04T15:20:00-05:00","request_id":"1a2b3c4d5e6f7a8b","code":"SGS-SUP-1A2B3C4D",
 "module":"SUP","type":"uncaught_exception","message":"…","user_id":12,"user":"jperez","role":"Supervisor",
 "endpoint":"POST /ProviWIN/api/supervisiones.php?action=create","action":"create","ip":"…",
 "context":{"class":"PDOException","file":"…","line":215,"trace":"…"}}
```

El usuario ve el **código** (`SGS-SUP-1A2B3C4D`) en el mensaje de error. Los 8 caracteres finales son el inicio del `request_id`. El navegador también recibe el `request_id` completo en la cabecera `X-Request-Id`.

### Buscar un error a partir del código que reporta el usuario

```powershell
Select-String -Path 08_LOGS\*\*.log -Pattern "SGS-SUP-1A2B3C4D"
```
