<?php
// 02_BACKEND/src/routes/api/inicio_ordenes.php - Inicio de órdenes (2026-11-19). Solo la cuenta principal (Auth::puedeInicioOrdenes).
//   GET  tablero     → primera atención, curva, órdenes en curso y cómo va la lectura de Winbo (y despierta un turno si toca).
//   POST actualizar  → empieza una vuelta nueva ya (después de responder).
//   GET  grupos      → grupos de Telegram configurados y reportes que se pueden enviar.
//   POST enviar      → manda ahora el reporte elegido (alertas o facilidades) a todos los grupos.
// Lógica: services/InicioOrdenesService.php, config/inicio_ordenes.php y config/inicio_ordenes_telegram.php.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/InicioOrdenesService.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
session_write_close();

$salir = static function (int $http, array $datos): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if (!Auth::puedeInicioOrdenes($usuario)) $salir(403, ['status' => 'error', 'message' => 'Solo la cuenta principal puede ver Inicio de órdenes.']);
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$svc = new InicioOrdenesService();

switch ((string)($_GET['action'] ?? '')) {
    case 'tablero':
        // Con una lectura en curso, cada pregunta de la pantalla (cada 5 s) la hace seguir sin esperar el minuto (2026-11-20).
        if (InicioOrdenesService::leyendo()) InicioOrdenesService::turnoTrasLaVisita(true);
        else win_run_periodic_task('inicio-ordenes', 60, static fn() => InicioOrdenesService::turnoTrasLaVisita());
        $t = $svc->tablero();
        unset($t['cancelaciones']);                    // solo las usa el reporte de facilidades
        $salir(200, ['status' => 'success', 'estado' => $svc->estado()] + $t);
    case 'actualizar':
        if (!$post) $salir(405, ['status' => 'error', 'message' => 'Método no permitido.']);
        if (InicioOrdenesService::sinSesion()) $salir(424, ['status' => 'error', 'message' => InicioOrdenesService::SIN_SESION]);
        InicioOrdenesService::turnoTrasLaVisita(true);
        $salir(200, ['status' => 'success']);
    case 'grupos':
        $salir(200, ['status' => 'success', 'configurado' => sgsIoTelegramConfigurado(), 'reportes' => SGS_IO_REPORTES,
            'grupos' => array_map(static fn($g) => ['nombre' => (string)($g['nombre'] ?? ''), 'partners' => array_values((array)($g['partners'] ?? [])),
                'tipo' => (string)($g['tipo'] ?? '')], sgsIoTelegramGrupos())]);
    case 'enviar':
        if (!$post) $salir(405, ['status' => 'error', 'message' => 'Método no permitido.']);
        try {
            $res = sgsIoEnviarATodos($svc->tablero(), (string)($_POST['reporte'] ?? 'alertas'));
        } catch (RuntimeException $e) {
            $salir(422, ['status' => 'error', 'message' => $e->getMessage()]);
        }
        $salir(200, ['status' => 'success', 'resultados' => $res]);
}
$salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
