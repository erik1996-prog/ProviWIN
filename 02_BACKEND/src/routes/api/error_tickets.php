<?php
// 02_BACKEND/src/routes/api/error_tickets.php - Recibe errores del navegador (error-monitor.js)
// y los guarda en 08_LOGS/frontend con el mismo formato que los del backend.
ob_start();
header('Content-Type: application/json; charset=utf-8');
define('WIN_JSON_RESPONSE', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

Auth::requireLogin();
Auth::requireCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_GET['action'] ?? '') !== 'report') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}

// Límite: 30 reportes por minuto por sesión (evita llenar el disco con un error en bucle).
$window = (int)floor(time() / 60);
if (($_SESSION['sgs_frontend_log_window'] ?? 0) !== $window) { $_SESSION['sgs_frontend_log_window'] = $window; $_SESSION['sgs_frontend_log_count'] = 0; }
if (++$_SESSION['sgs_frontend_log_count'] > 30) { ob_clean(); http_response_code(429); echo json_encode(['status' => 'error', 'message' => 'Demasiados reportes.']); exit; }

$input = json_decode((string)file_get_contents('php://input'), true) ?: [];
$clip = static fn($v, int $n): string => mb_substr(trim((string)$v), 0, $n);
$pagina = $clip($input['pagina'] ?? '', 500);
parse_str((string)parse_url($pagina, PHP_URL_QUERY), $query);

sgs_write_log('frontend', [
    'time' => date(DATE_ATOM),
    'request_id' => WIN_REQUEST_ID,
    'code' => SYSTEM_CODE . '-FRT-' . strtoupper(substr(WIN_REQUEST_ID, 0, 8)),
    'module' => 'FRT',
    'screen' => $query['page'] ?? 'index',
    'type' => $clip($input['tipo'] ?? 'JavaScript', 60),
    'message' => $clip($input['mensaje'] ?? '', 1000),
    'detail' => $clip($input['detalle'] ?? '', 8000),
    'http_code' => $clip($input['codigo_http'] ?? '', 10),
    'page' => $pagina,
    'user_id' => $_SESSION['user_id'] ?? null,
    'user' => $_SESSION['user_usuario'] ?? ($_SESSION['user_dni'] ?? null),
    'user_agent' => $clip($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
]);

ob_clean();
echo json_encode(['status' => 'success']);
