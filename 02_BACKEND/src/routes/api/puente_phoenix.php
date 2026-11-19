<?php
// 02_BACKEND/src/routes/api/puente_phoenix.php - Vínculo con Phoenix para Órdenes cercanas (2026-11-13). Lógica: config/puente_phoenix.php.
// Solo la cuenta principal, desde la ventana del vínculo: vincular, tomar (GET), responder (POST id, datos), desactivar.
// 2026-11-19: también sesion_estado, sesion_iniciar (POST usuario, clave) y sesion_cerrar (sesión de Phoenix hasta medianoche).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/puente_phoenix.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$uid = (int)$usuario['id'];
session_write_close();

$salir = static function (int $http, array $datos): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!Auth::puedeVincularPhoenix($usuario)) $salir(403, ['status' => 'error', 'message' => 'Solo la cuenta principal puede vincular Phoenix.']);
$hora = static fn(?int $t) => $t ? date('H:i', $t) : null;

switch ((string)($_GET['action'] ?? '')) {
    case 'vincular':
        $salir(200, ['status' => 'success', 'vence' => $hora(sgsPhoenixActivar($uid))]);
    case 'tomar':
        $t = sgsPhoenixTomar($uid);
        if ($t === null) $salir(200, ['status' => 'success', 'activo' => false]);
        $salir(200, ['status' => 'success', 'activo' => true, 'vence' => $hora($t['vence']), 'pedido' => $t['pedido']]);
    case 'responder':
        $datos = json_decode((string)($_POST['datos'] ?? ''), true);
        $ok = is_array($datos) && sgsPhoenixResponder((string)($_POST['id'] ?? ''), $datos);
        $salir($ok ? 200 : 404, ['status' => $ok ? 'success' : 'error']);
    case 'desactivar':
        sgsPhoenixDesactivar();
        $salir(200, ['status' => 'success']);

    // Sesión de Phoenix iniciada aquí, hasta la medianoche (2026-11-19). La contraseña solo se usa para entrar a Phoenix:
    // no se guarda ni se escribe en logs. Fallos de Phoenix con 424 (no 502/503) para no encender la franja de "Conexión interrumpida".
    case 'sesion_estado':
        $s = sgsPhoenixSesion();
        $salir(200, ['status' => 'success', 'activa' => $s['activa'], 'vence' => $hora($s['vence'] ?? null),
            'usuario' => $s['usuario'] ?? null, 'vinculo' => sgsPhoenixEstado()['activo']]);
    case 'sesion_iniciar':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $salir(405, ['status' => 'error', 'message' => 'Método no permitido.']);
        $usuarioPhoenix = trim((string)($_POST['usuario'] ?? ''));
        $clave = (string)($_POST['clave'] ?? '');
        unset($_POST['clave'], $_REQUEST['clave']);
        if ($usuarioPhoenix === '' || $clave === '') $salir(422, ['status' => 'error', 'message' => 'Escribe tu usuario y tu contraseña de Phoenix.']);
        require_once __DIR__ . '/../../services/WinboService.php';
        try {
            $vence = WinboService::iniciarSesionAqui($usuarioPhoenix, $clave, $uid);
        } catch (Throwable $e) {
            $clave = '';
            win_log_error('phoenix_sesion', $e->getMessage());
            $salir(424, ['status' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo entrar a Phoenix. Inténtalo de nuevo.']);
        }
        $clave = '';
        $salir(200, ['status' => 'success', 'activa' => true, 'vence' => $hora($vence), 'usuario' => $usuarioPhoenix]);
    case 'sesion_cerrar':
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $salir(405, ['status' => 'error', 'message' => 'Método no permitido.']);
        sgsPhoenixSesionCerrar();
        $salir(200, ['status' => 'success', 'activa' => false]);
}
$salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
