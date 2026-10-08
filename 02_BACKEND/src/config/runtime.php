<?php
// 02_BACKEND/src/config/runtime.php - Trazabilidad y registro de errores de SGS.
//
// Cada registro (una línea JSON) lleva: fecha/hora, Request ID, código de error,
// usuario, módulo, endpoint, mensaje y detalle técnico. Destinos en 08_LOGS/:
//   backend/   todo lo que ocurre en PHP
//   database/  errores de MySQL/PDO
//   errores/   solo fallos graves (excepciones no controladas, fatales)
//   frontend/  errores reportados por el navegador (api/error_tickets.php)

if (!defined('WIN_REQUEST_ID')) {
    define('WIN_REQUEST_ID', bin2hex(random_bytes(8)));
}
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Request-Id: ' . WIN_REQUEST_ID);
}

/** Módulo funcional de la petición actual (ver 01_DOCUMENTACION/03_ARQUITECTURA/TRAZABILIDAD.md). */
function sgs_module(): string
{
    static $map = [
        'supervision_lista' => 'SUP', 'supervision_registro' => 'SUP', 'supervisiones' => 'SUP',
        'tecnicos' => 'SUP', 'ubigeo' => 'SUP', 'cancelaciones_import' => 'SUP',
        'admin_dashboard' => 'DSH', 'dashboard' => 'DSH',
        'timeline_supervisores' => 'TML', 'asistencia' => 'TUR',
        'registro_observaciones' => 'OBS', 'observaciones_partner' => 'OBS', 'control_observaciones' => 'OBS',
        'observaciones' => 'OBS', 'observaciones_pdf' => 'OBS', 'observaciones_export' => 'OBS',
        'auth' => 'AUT', 'login' => 'AUT',
        'notificaciones' => 'NOT', 'health' => 'SYS', 'error_tickets' => 'FRT',
        'usuarios' => 'USR',
    ];
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    $key = $script === 'index' ? (string)($_GET['page'] ?? 'supervision_lista') : $script;
    return $map[$key] ?? 'GEN';
}

/** Código que ve el usuario y que permite buscar el error en 08_LOGS. */
function sgs_error_code(): string
{
    return SYSTEM_CODE . '-' . sgs_module() . '-' . strtoupper(substr(WIN_REQUEST_ID, 0, 8));
}

function sgs_write_log(string $channel, array $record): void
{
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    $file = LOG_PATH . '/' . $channel . '/' . SYSTEM_CODE . '-' . strtoupper($channel) . '-' . date('Y-m-d') . '.log';
    if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log(trim($line));
    }
}

function win_log_error(string $type, string $message, array $context = []): void
{
    $record = [
        'time' => date(DATE_ATOM),
        'origin' => PHP_SAPI === 'cli' ? 'cli' : 'web',
        'request_id' => WIN_REQUEST_ID,
        'code' => sgs_error_code(),
        'module' => sgs_module(),
        'type' => $type,
        'message' => mb_substr($message, 0, 4000),
        'user_id' => $_SESSION['user_id'] ?? null,
        'user' => $_SESSION['user_usuario'] ?? ($_SESSION['user_dni'] ?? null),
        'role' => $_SESSION['user_rol'] ?? null,
        'endpoint' => ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
        'action' => $_GET['action'] ?? $_POST['action'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'context' => $context,
    ];
    sgs_write_log('backend', $record);

    $isDatabase = str_contains($type, 'database') || ($context['class'] ?? '') === 'PDOException'
        || str_starts_with($message, 'SQLSTATE');
    if ($isDatabase) sgs_write_log('database', $record);
    // 08_LOGS/errores es solo para fallos que vivieron los usuarios: lo ejecutado por consola (scripts y
    // diagnósticos de mantenimiento) queda en backend/ marcado con origin=cli.
    if (PHP_SAPI !== 'cli' && in_array($type, ['uncaught_exception', 'php_fatal', 'database_connection_lost', 'database_unavailable'], true)) {
        sgs_write_log('errores', $record);
    }
}

/**
 * Distingue una interrupción transitoria de MySQL de un error de aplicación.
 */
function win_is_database_unavailable(Throwable $error): bool
{
    if (!$error instanceof PDOException) return false;

    $driverCode = (int)($error->errorInfo[1] ?? 0);
    if (in_array($driverCode, [1040, 1158, 1159, 1160, 1161, 2002, 2003, 2006, 2013], true)) {
        return true;
    }

    $message = mb_strtolower($error->getMessage());
    foreach ([
        'server has gone away',
        'lost connection',
        'connection refused',
        'no connection could be made',
        'too many connections',
    ] as $fragment) {
        if (str_contains($message, $fragment)) return true;
    }

    return false;
}

/**
 * Ejecuta tareas ligeras de mantenimiento como máximo una vez por intervalo.
 */
function win_run_periodic_task(string $name, int $intervalSeconds, callable $task): bool
{
    $safeName = preg_replace('/[^a-z0-9_-]+/i', '-', $name) ?: 'task';
    $directory = STORAGE_PATH;
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) return false;

    $handle = @fopen($directory . '/' . $safeName . '.lock', 'c+');
    if (!$handle) return false;
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) return false;
        rewind($handle);
        $lastRun = (int)trim((string)stream_get_contents($handle));
        if ($lastRun > 0 && time() - $lastRun < max(1, $intervalSeconds)) return false;

        $task();
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)time());
        fflush($handle);
        return true;
    } finally {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    win_log_error('php_warning', $message, ['file' => $file, 'line' => $line, 'severity' => $severity]);
    return APP_ENV === 'production';
});

set_exception_handler(static function (Throwable $error): void {
    $databaseUnavailable = win_is_database_unavailable($error);
    win_log_error($databaseUnavailable ? 'database_connection_lost' : 'uncaught_exception', $error->getMessage(), [
        'class' => get_class($error), 'file' => $error->getFile(), 'line' => $error->getLine(),
        'trace' => mb_substr($error->getTraceAsString(), 0, 12000),
    ]);
    $isJson = defined('WIN_JSON_RESPONSE') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    $statusCode = $databaseUnavailable ? 503 : 500;
    $publicMessage = $databaseUnavailable
        ? 'El servicio de datos no está disponible temporalmente. Intente nuevamente en unos segundos.'
        : 'Error interno del sistema.';
    $code = sgs_error_code();
    if (!headers_sent()) {
        http_response_code($statusCode);
        if ($databaseUnavailable) header('Retry-After: 15');
        header('Content-Type: ' . ($isJson ? 'application/json' : 'text/html') . '; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo $isJson
        ? json_encode(['status' => 'error', 'message' => $publicMessage . ' Código: ' . $code, 'request_id' => WIN_REQUEST_ID, 'error_code' => $code], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '<div style="font-family:Nunito,Segoe UI,sans-serif;max-width:640px;margin:10vh auto;padding:32px;border:1px solid #FF5A0A;border-radius:30px;background:#fff">'
          . '<h2 style="color:#141414;font-weight:800">' . ($databaseUnavailable ? 'Estamos restableciendo la conexión' : 'El sistema tuvo un inconveniente') . '</h2>'
          . '<p style="color:#301D19;line-height:1.6">' . htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p style="color:#7C7E80">Código de seguimiento: <strong style="color:#FF5A0A">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</strong></p>'
          . '<a href="index.php" style="display:inline-block;background:#000;color:#fff;text-decoration:none;font-weight:900;padding:10px 18px;border-radius:10px">Reintentar</a></div>';
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        win_log_error('php_fatal', (string)$error['message'], ['file' => $error['file'] ?? '', 'line' => $error['line'] ?? 0]);
    }
});

/**
 * Retención de logs: borra archivos de 08_LOGS con más de SGS_LOG_RETENTION_DAYS días (90 por defecto).
 * Se ejecuta como máximo una vez al día desde index.php.
 */
function sgs_purge_old_logs(): void
{
    $days = max(7, (int)(getenv('SGS_LOG_RETENTION_DAYS') ?: 90));
    $limit = time() - $days * 86400;
    foreach (['backend', 'frontend', 'database', 'errores'] as $channel) {
        foreach (glob(LOG_PATH . "/$channel/" . SYSTEM_CODE . '-*.log') ?: [] as $file) {
            if (@filemtime($file) < $limit) @unlink($file);
        }
    }
}
