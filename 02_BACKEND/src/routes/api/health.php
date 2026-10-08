<?php
define('WIN_JSON_RESPONSE', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$started = microtime(true);
$database = false;
try {
    $pdo = Database::getConnection();
    $database = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;
} catch (Throwable $error) {
    // Evita una tormenta de logs si muchos navegadores detectan la misma caída.
    $marker = STORAGE_PATH . '/health-db-down.marker';
    $last = is_file($marker) ? (int)@filemtime($marker) : 0;
    if (time() - $last >= 300) {
        @touch($marker);
        win_log_error('health_database_down', $error->getMessage());
    }
}

$healthy = $database;
http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'degraded',
    'app' => true,
    'database' => $database,
    'response_ms' => round((microtime(true) - $started) * 1000, 1),
    'time' => date(DATE_ATOM),
    'request_id' => WIN_REQUEST_ID,
], JSON_UNESCAPED_SLASHES);
