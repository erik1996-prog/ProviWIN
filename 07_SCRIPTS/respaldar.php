<?php
// 07_SCRIPTS/respaldar.php - Respaldo a mano o por cron (solo CLI). Reemplaza al antiguo respaldar_bd.php.
// Uso: php 07_SCRIPTS/respaldar.php [todo|base|sistema] [motivo]      (por defecto: todo, motivo "manual")
// Deja los archivos en 10_RESPALDOS/base (.sql.gz) y 10_RESPALDOS/sistema (.tar.gz). Cómo restaurar: 10_RESPALDOS/LEEME.md
// El sistema también respalda solo: la base cada 6 h, el sistema cada 24 h y ambos apenas la base vuelve tras una caída.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/database.php';

$que = in_array($argv[1] ?? '', ['todo', 'base', 'sistema'], true) ? $argv[1] : 'todo';
$motivo = $argv[$que === ($argv[1] ?? '') ? 2 : 1] ?? 'manual';
$error = false;
if ($que !== 'sistema') {
    try { $f = sgsRespaldarBase(Database::getConnection(), $motivo); echo 'Respaldo de la base OK: 10_RESPALDOS/base/' . basename($f) . ' (' . round(filesize($f) / 1024) . " KB)\n"; }
    catch (Throwable $e) { $error = true; fwrite(STDERR, 'ERROR en el respaldo de la base: ' . $e->getMessage() . "\n"); }
}
if ($que !== 'base') {
    try { $f = sgsRespaldarSistema($motivo); echo 'Respaldo del sistema OK: 10_RESPALDOS/sistema/' . basename($f) . ' (' . round(filesize($f) / 1024) . " KB)\n"; }
    catch (Throwable $e) { $error = true; fwrite(STDERR, 'ERROR en el respaldo del sistema: ' . $e->getMessage() . "\n"); }
}
exit($error ? 1 : 0);
