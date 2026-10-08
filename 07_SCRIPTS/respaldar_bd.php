<?php
// 07_SCRIPTS/respaldar_bd.php - Respaldo completo de la base de datos (solo CLI). Funciona en Windows (XAMPP) y Linux.
// Uso: php 07_SCRIPTS/respaldar_bd.php [motivo]
// Deja el archivo en 04_DATABASE/10_BACKUPS/<BD>_<fecha>_<motivo>.sql (comprimido .sql.gz si hay zlib) y borra los de más de 30 días.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';

$motivo = preg_replace('/[^a-z0-9_-]+/i', '-', $argv[1] ?? 'manual');
$dir = BASE_PATH . '/04_DATABASE/10_BACKUPS';
$bin = getenv('MYSQLDUMP_BIN') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump');
$file = sprintf('%s/%s_%s_%s.sql', $dir, DB_NAME, date('Ymd_His'), $motivo);

// La contraseña va por variable de entorno (no aparece en la lista de procesos).
putenv('MYSQL_PWD=' . DB_PASS);
$cmd = sprintf('%s --host=%s --port=%s --user=%s --single-transaction --routines --triggers --default-character-set=utf8mb4 %s > %s 2>&1',
    escapeshellarg($bin), escapeshellarg(DB_HOST), escapeshellarg((string)DB_PORT), escapeshellarg(DB_USER), escapeshellarg(DB_NAME), escapeshellarg($file));
exec($cmd, $out, $code);
putenv('MYSQL_PWD');

$ok = $code === 0 && is_file($file) && str_contains((string)shell_exec('tail -c 200 ' . escapeshellarg($file) . ' 2>&1') ?: file_get_contents($file, false, null, max(0, filesize($file) - 200)), 'Dump completed');
if (!$ok) { @unlink($file); fwrite(STDERR, "ERROR: el respaldo falló.\n" . implode("\n", $out) . "\n"); exit(1); }

if (function_exists('gzencode')) { file_put_contents("$file.gz", gzencode(file_get_contents($file), 9)); unlink($file); $file .= '.gz'; }
foreach (glob("$dir/*.sql*") ?: [] as $old) if (filemtime($old) < time() - 30 * 86400) @unlink($old);
win_log_error('db_backup', 'Respaldo de base de datos creado', ['archivo' => basename($file), 'motivo' => $motivo]);
echo "Respaldo OK: 04_DATABASE/10_BACKUPS/" . basename($file) . ' (' . round(filesize($file) / 1024) . " KB)\n";
