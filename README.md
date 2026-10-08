# ProviWIN · SGS — Sistema de Gestión de Supervisión

Módulo de **supervisión** extraído del sistema *SISTEMA-WIN-PROVINCIA*. Incluye sus tableros, rediseñado con la guía visual WIN y organizado según la *Guía de estructura y nomenclatura* (`01_DOCUMENTACION/00_REFERENCIAS/`).

- **Código del sistema:** `SGS` (Sistema de Gestión de Supervisión)
- **Versión:** ver `VERSION` · cambios en `CHANGELOG.md`
- **Tecnologías:** PHP 8 + MySQL/MariaDB (XAMPP), Bootstrap 5.3, Leaflet, JavaScript sin frameworks

## Secciones incluidas

| Sección | Ruta | Quién la ve |
|---|---|---|
| Supervisiones (lista, registro, checklist, mapa, asignación, importar cancelaciones) | `index.php?page=supervision_lista` | Supervisores, partners, administración |
| Observaciones (registro, descargos del partner, PDF) | `index.php?page=registro_observaciones` | Supervisores, partners, administración |
| Dashboard de supervisión (indicadores, mapa de calor, matriz mensual) | `index.php?page=admin_dashboard` | Administrador, Gerente, Coordinador |
| Línea de tiempo de supervisores | `index.php?page=timeline_supervisores` | Administración |
| Control de observaciones | `index.php?page=control_observaciones` | Administración |
| Mi información | `index.php?page=mi_informacion` | Todos |

**No se incluyen:** usuarios, asistencia (marcar turno), recursos, examen técnico, flyers, chat ni la tiquetera. Siguen en el sistema de origen.

## Instalación rápida (XAMPP)

1. Copiar la carpeta en `C:\xampp\htdocs\ProviWIN` (Apache con `mod_rewrite` y `AllowOverride All`).
2. Copiar `.env.example` como `.env` y completar los datos de conexión.
3. Abrir `http://localhost/ProviWIN/`.

Detalle en `01_DOCUMENTACION/08_DESPLIEGUE/INSTALACION.md`.

## Datos compartidos con el sistema de origen

- **Base de datos:** usa la misma `win_telecom_db`. SGS **no crea ni modifica tablas**: no hay migraciones automáticas y se quitó la creación automática de la BD.
- **Fotos y firmas:** se guardan y leen en `SISTEMA-WIN-PROVINCIA/uploads/`. `.htaccess` redirige las URLs `uploads/...` hacia esa carpeta (`SHARED_FILES_PATH` en `.env`).
- **Sesión:** cookie propia (`SGS_SESSID`). Iniciar sesión en un sistema no inicia sesión en el otro.

## Estructura

```
ProviWIN/
├── index.php                 Enrutador de pantallas (único PHP público junto con api/)
├── api/                      Entradas públicas mínimas -> 02_BACKEND/src/routes/api/
├── 01_DOCUMENTACION/         Requerimientos, arquitectura, BD, API, seguridad, despliegue
├── 02_BACKEND/src/           config/, routes/api/, controllers/, services/, repositories/, validators/, utils/
├── 03_FRONTEND/src/          pages/, layouts/, assets/ (css, js, img, templates)
├── 04_DATABASE/              Instalación por tabla, cambios (migraciones), estructura y respaldos
├── 05_TEST/                  Pruebas (frontend/smoke_pages.php)
├── 07_SCRIPTS/               Scripts de mantenimiento (solo CLI)
├── 08_LOGS/                  backend/ frontend/ database/ errores/
├── 09_CONFIG/                Plantillas de configuración de servidor
├── .env.example  .gitignore  README.md  CHANGELOG.md  VERSION
```

Solo son públicos `index.php`, `api/*.php` y `03_FRONTEND/src/assets/`. El resto lo bloquea `.htaccess`.

## Diagnóstico de errores

Cada error muestra al usuario un **código de seguimiento** como `SGS-SUP-1A2B3C4D` (sistema, módulo e identificador de la petición). Para encontrarlo, busca ese código en `08_LOGS/`. Ver `01_DOCUMENTACION/03_ARQUITECTURA/TRAZABILIDAD.md`.

## Usuario de prueba

```
C:\xampp\php\php.exe 07_SCRIPTS\crear_usuario_prueba.php            (crea o renueva la contraseña; rol Administrador)
C:\xampp\php\php.exe 07_SCRIPTS\crear_usuario_prueba.php Supervisor (otro rol)
C:\xampp\php\php.exe 07_SCRIPTS\crear_usuario_prueba.php --eliminar
```

Las credenciales se guardan en `09_CONFIG/usuario_prueba.local.txt` (no se sirve por web). El usuario también existe en el sistema de origen porque la BD es compartida: **elimínalo antes de pasar a producción.**
