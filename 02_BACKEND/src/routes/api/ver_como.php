<?php
// 02_BACKEND/src/routes/api/ver_como.php - "Ver como" (2026-10-28): la cuenta principal ve el sistema como otro usuario,
// solo para mirar. Reglas en Auth::verComo (config/auth.php).
//   POST ?action=iniciar  id   → entra como ese usuario
//   POST ?action=salir         → vuelve a su cuenta
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
Auth::requireCsrf();
$responder = static function (array $datos, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 405);
$action = $_GET['action'] ?? '';

if ($action === 'iniciar') {
    try {
        Auth::verComo((int)($_POST['id'] ?? 0));
    } catch (RuntimeException $e) {
        $responder(['status' => 'error', 'message' => $e->getMessage()], 403);
    }
    $responder(['status' => 'success', 'message' => 'Viendo como ' . (Auth::verComoActivo()['objetivo'] ?? 'el usuario') . '.', 'redirect' => 'index.php']);
}
if ($action === 'salir') {
    Auth::salirDeVerComo();
    $responder(['status' => 'success', 'message' => 'Volviste a tu cuenta.', 'redirect' => 'index.php?page=usuarios']);
}
$responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
