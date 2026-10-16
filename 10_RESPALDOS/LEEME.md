# Respaldos de SGS

```
10_RESPALDOS/
├── base/      Base de datos completa (.sql.gz)
├── sistema/   Código y configuración (.env), sin fotos (.tar.gz)
└── fotos/     Fotos de supervisiones, observaciones, checklists y documentos: carpeta uploads (.tar.gz)
```

## Cuándo se crean (2026-10-16)
Las visitas de los usuarios **ya no** hacen respaldos: comprimir el sistema con todas las fotos dentro de una visita
tardaba minutos y, con varios usuarios conectados, el servidor dejaba de responder (error 504 de Cloudflare).
Ahora los hace una **tarea programada (cron) de cPanel** que corre cada 6 horas:

| Qué | Cuándo | Se conservan |
|---|---|---|
| Base de datos | En cada corrida (cada 6 h) | 7 días (mínimo los 5 últimos) |
| Sistema (sin fotos) | Una vez al día, solo de madrugada (antes de las 7:00) | Los 3 últimos |
| Fotos | Una vez por semana, solo de madrugada | Los 2 últimos |
| Base | Apenas la base vuelve a responder después de una caída (motivo `tras-caida`, desde la web; es liviano) | Igual que la base |

### Tarea programada en cPanel (una sola vez)
cPanel → **Trabajos de cron (Cron Jobs)** → Agregar nuevo trabajo de cron:
- Configuración común: **"Una vez cada 6 horas"** (minuto 0, hora `*/6`, el resto `*`).
- Comando (cambiar la ruta por la de su cuenta; se ve en el Administrador de archivos):
  ```
  php /home/USUARIO/RUTA/ProviWIN/07_SCRIPTS/respaldar.php automatico >/dev/null 2>&1
  ```
Si `php` no funciona, usar `/usr/local/bin/php` en su lugar. **Sin esta tarea no se crean respaldos nuevos**
(el sistema funciona igual, pero sin copias).

### A mano (por ejemplo, antes de un cambio grande)
`php 07_SCRIPTS/respaldar.php` (todo: base + sistema + fotos) o `respaldar.php base`, `respaldar.php sistema`, `respaldar.php fotos`.
Cada respaldo queda anotado en `08_LOGS` (`db_backup`, `system_backup`, `photos_backup`; si falla: `backup_failed`).

## Restaurar la base de datos
1. Elegir el archivo de `base/` (el nombre lleva fecha, hora y motivo).
2. **phpMyAdmin:** seleccionar la base → Importar → elegir el `.sql.gz` (phpMyAdmin lo descomprime solo).
   **Consola:** `gunzip -c archivo.sql.gz | mysql -u usuario -p NOMBRE_BASE`
   En Windows (XAMPP): descomprimir con 7-Zip y `C:\xampp\mysql\bin\mysql.exe -u root -p win_telecom_db < archivo.sql`
3. **Atención:** reemplaza las tablas actuales por las del respaldo.

## Restaurar el sistema o las fotos
1. Elegir el `.tar.gz` de `sistema/` o de `fotos/`.
2. Descomprimirlo (cPanel → Administrador de archivos → Extraer, o 7-Zip en Windows) en una carpeta nueva.
3. Copiar lo que se necesite sobre la carpeta del sistema: código y `.env` (de `sistema/`) o `uploads/` (de `fotos/`).

Esta carpeta está bloqueada desde la web y no se sube a git.
