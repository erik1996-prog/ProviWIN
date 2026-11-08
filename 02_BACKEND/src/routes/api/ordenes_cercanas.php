<?php
// 02_BACKEND/src/routes/api/ordenes_cercanas.php - Órdenes iniciadas de hoy para "Órdenes cercanas" (2026-11-08).
// Solo consulta. Cada petición lee una sola página de Winbo; el navegador pide las demás de a pocas.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/WinboService.php';

Auth::requireLogin();
$usuario = Auth::getLoggedInUser();
if (!Auth::puedeOrdenesCercanas($usuario)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Sin acceso a las órdenes cercanas.']);
    exit;
}
session_write_close();   // Winbo tarda: no retener la sesión mientras tanto, para no frenar las otras peticiones del usuario

const ORDENES_ESTADO = 'Iniciada';

$salir = static function (int $http, array $datos): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};

$action = $_GET['action'] ?? '';
if ($action === 'fecha' || $action === 'inicio') {
    $hoy = WinboService::fechaInternet();
    if ($hoy === null) $salir(503, ['status' => 'error', 'message' => 'No cuenta con internet para detectar la fecha.']);
    if ($action === 'fecha') $salir(200, ['status' => 'success', 'fecha' => $hoy]);
}
if (!WinboService::configurado()) $salir(503, ['status' => 'error', 'message' => 'Falta configurar el acceso a Winbo en el servidor.']);

try {
    $winbo = new WinboService();
    if ($action === 'inicio') $salir(200, ['status' => 'success'] + $winbo->inicio(ORDENES_ESTADO, (string)$hoy));
    if ($action === 'pagina') {
        $fecha = (string)($_GET['fecha'] ?? '');
        if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $fecha)) $salir(400, ['status' => 'error', 'message' => 'Fecha no válida.']);
        $salir(200, ['status' => 'success'] + $winbo->pagina(ORDENES_ESTADO, $fecha, (int)($_GET['n'] ?? 0)));
    }
    $salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
} catch (InvalidArgumentException | RuntimeException $e) {
    win_log_error('winbo', $e->getMessage());
    $salir(502, ['status' => 'error', 'message' => $e->getMessage()]);
}
