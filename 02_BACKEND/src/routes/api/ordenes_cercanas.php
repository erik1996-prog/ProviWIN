<?php
// 02_BACKEND/src/routes/api/ordenes_cercanas.php - Órdenes de hoy para "Órdenes cercanas" (2026-11-08; estado a elegir 2026-11-10).
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

const ESTADOS = ['iniciada' => 'Iniciada', 'agendada' => 'Agendada', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada'];
// Si falla Winbo, internet o su configuración se responde 424: la ventana muestra el motivo. Con 502/503/504, el monitor global
// (error-monitor.js) pondría la franja roja de "Conexión interrumpida", como si se hubiera caído todo el SGS.

$salir = static function (int $http, array $datos): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};

$permitidas = WinboService::gruposDe($usuario);
$NOMBRES = ['GP' => 'Gestión provincia', 'SGI' => 'Lima · Instalaciones (SGI)', 'SGA' => 'Lima · Visitas técnicas (SGA)'];

$action = $_GET['action'] ?? '';
if ($action === 'fecha' || $action === 'inicio') {
    $hoy = WinboService::fechaInternet();
    if ($hoy === null) $salir(424, ['status' => 'error', 'message' => 'No cuenta con internet para detectar la fecha.']);
    if ($action === 'fecha') $salir(200, ['status' => 'success', 'fecha' => $hoy,
        'grupos' => array_map(static fn($c) => ['codigo' => $c, 'nombre' => $NOMBRES[$c]], $permitidas)]);
}
if (!WinboService::configurado()) $salir(424, ['status' => 'error', 'message' => 'Falta configurar el acceso a Winbo en el servidor.']);
$estado = ESTADOS[(string)($_GET['estado'] ?? 'iniciada')] ?? null;
if ($estado === null) $salir(400, ['status' => 'error', 'message' => 'Estado no válido.']);
$grupo = (string)($_GET['grupo'] ?? $permitidas[0]);
if (!in_array($grupo, $permitidas, true)) $salir(403, ['status' => 'error', 'message' => 'No tienes acceso a ese grupo de órdenes.']);

try {
    $winbo = new WinboService();
    if ($action === 'inicio') $salir(200, ['status' => 'success'] + $winbo->inicio($estado, (string)$hoy, $grupo));
    if ($action === 'pagina') {
        $fecha = (string)($_GET['fecha'] ?? '');
        if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $fecha)) $salir(400, ['status' => 'error', 'message' => 'Fecha no válida.']);
        $salir(200, ['status' => 'success'] + $winbo->pagina($estado, $fecha, (int)($_GET['n'] ?? 0), $grupo));
    }
    $salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
} catch (InvalidArgumentException | RuntimeException $e) {
    win_log_error('winbo', $e->getMessage());
    $salir(424, ['status' => 'error', 'message' => $e->getMessage()]);
}
