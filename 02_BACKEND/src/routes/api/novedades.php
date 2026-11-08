<?php
// 02_BACKEND/src/routes/api/novedades.php - Marca como vistas las novedades del sistema (2026-11-08, config/novedades.php).
//   POST ?action=vistas → el usuario ya vio el aviso "El sistema se actualizó".
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/novedades.php';

Auth::requireLogin();
Auth::requireCsrf();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_GET['action'] ?? '') !== 'vistas') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}
sgsNovedadesMarcarVistas(Database::getConnection(), (int)Auth::getLoggedInUser()['id']);
echo json_encode(['status' => 'success']);
