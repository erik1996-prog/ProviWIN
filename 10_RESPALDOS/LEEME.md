# Respaldos de SGS

```
10_RESPALDOS/
├── base/      Base de datos completa (.sql.gz)
└── sistema/   Código, configuración (.env) y fotos (.tar.gz)
```

## Cuándo se crean (solos)
| Qué | Cuándo | Se conservan |
|---|---|---|
| Base de datos | Cada 6 horas mientras el sistema se usa | 30 días (mínimo los 5 últimos) |
| Sistema completo | Cada 24 horas | Los 5 últimos |
| Ambos | Apenas la base vuelve a responder después de una caída (motivo `tras-caida`) | Igual que arriba |

A mano (por ejemplo, antes de un cambio grande): `php 07_SCRIPTS/respaldar.php` (o `respaldar.php base`, `respaldar.php sistema`).
En el hosting conviene además un cron diario: `php ~/public_html/<carpeta>/07_SCRIPTS/respaldar.php todo diario`.
Cada respaldo queda anotado en `08_LOGS` (`db_backup`, `system_backup`; si falla: `backup_failed`).

## Restaurar la base de datos
1. Elegir el archivo de `base/` (el nombre lleva fecha, hora y motivo).
2. **phpMyAdmin:** seleccionar la base → Importar → elegir el `.sql.gz` (phpMyAdmin lo descomprime solo).
   **Consola:** `gunzip -c archivo.sql.gz | mysql -u usuario -p NOMBRE_BASE`
   En Windows (XAMPP): descomprimir con 7-Zip y `C:\xampp\mysql\bin\mysql.exe -u root -p win_telecom_db < archivo.sql`
3. **Atención:** reemplaza las tablas actuales por las del respaldo.

## Restaurar el sistema
1. Elegir el `.tar.gz` de `sistema/`.
2. Descomprimirlo (cPanel → Administrador de archivos → Extraer, o 7-Zip en Windows) en una carpeta nueva.
3. Copiar lo que se necesite sobre la carpeta del sistema: código, `.env` y `uploads/` (fotos).

Esta carpeta está bloqueada desde la web y no se sube a git.
