<?php
// 02_BACKEND/src/routes/api/auth.php - Autenticación (login, logout, sesión). Protección: bloqueo 15 min tras 5 intentos fallidos.
ob_start();
header('Content-Type: application/json; charset=utf-8');
define('WIN_JSON_RESPONSE', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Obtener datos tanto si vienen en JSON raw o FormData
$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : null;

$usuario = trim($jsonInput['usuario'] ?? $_POST['usuario'] ?? '');
$password = (string)($jsonInput['password'] ?? $_POST['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    if (empty($usuario) || empty($password)) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Ingrese su DNI/Correo y Contraseña.']);
        exit;
    }

    try {
        $pdo = Database::getConnection();
        $fingerprint = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mb_strtolower($usuario));
        $limit = $pdo->prepare("SELECT attempts,blocked_until FROM login_attempts WHERE fingerprint=:fingerprint AND last_attempt_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $limit->execute([':fingerprint' => $fingerprint]);
        $attempt = $limit->fetch();
        if ($attempt && (!empty($attempt['blocked_until']) && strtotime($attempt['blocked_until']) > time())) {
            http_response_code(429);
            $res = ['status'=>'error','message'=>'Demasiados intentos. Espere 15 minutos antes de volver a intentar.'];
            ob_clean(); echo json_encode($res); exit;
        }
        $res = Auth::login($usuario, $password);
        if (($res['status'] ?? '') === 'success') {
            $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint=:fingerprint')->execute([':fingerprint'=>$fingerprint]);
        } elseif (empty($res['cuenta'])) {
            // Usuario inexistente: límite por equipo (5 intentos, 15 min). Las cuentas reales usan el bloqueo de 3 intentos.
            $pdo->prepare("INSERT INTO login_attempts(fingerprint,attempts,first_attempt_at,last_attempt_at,blocked_until) VALUES(:fingerprint,1,NOW(),NOW(),NULL) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1),first_attempt_at=IF(last_attempt_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE),NOW(),first_attempt_at),last_attempt_at=NOW(),blocked_until=IF(attempts>=4,DATE_ADD(NOW(),INTERVAL 15 MINUTE),blocked_until)")->execute([':fingerprint'=>$fingerprint]);
        }
    } catch (Throwable $e) {
        win_log_error('login_error', $e->getMessage());
        $res = ['status'=>'error','message'=>'No se pudo completar el inicio de sesión. Contacte al administrador.'];
    }
    unset($res['cuenta']); // marca interna, no se envía al navegador
    ob_clean();
    echo json_encode($res);
    exit;
}

// La foto de perfil se gestiona en el sistema de origen; SGS no modifica usuarios.

if ($action === 'logout') {
    Auth::requireCsrf();
    Auth::logout();
    ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Sesión cerrada.']);
    exit;
}

if ($action === 'session') {
    ob_clean();
    if (Auth::isLoggedIn()) {
        echo json_encode(['status' => 'success', 'user' => Auth::getLoggedInUser()]);
    } else {
        echo json_encode(['status' => 'unauthenticated']);
    }
    exit;
}

ob_clean();
echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
