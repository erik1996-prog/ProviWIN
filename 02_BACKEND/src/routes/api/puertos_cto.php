<?php
// 02_BACKEND/src/routes/api/puertos_cto.php - Consulta de puertos de una CTO (2026-11-11). Lógica: config/puertos_cto.php.
//   Quien tiene "Consultar puertos": estado (GET), consultar (POST codigo), resultado (GET id).
//   Solo la cuenta principal, desde la ventana del vínculo: vincular, tomar (GET), responder (POST id, datos), desactivar.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/puertos_cto.php';

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
$hora = static fn(?int $t) => $t ? date('H:i', $t) : null;

$action = (string)($_GET['action'] ?? '');
$vinculo = in_array($action, ['vincular', 'tomar', 'responder', 'desactivar'], true);
if ($vinculo ? !Auth::puedeVincularWincore($usuario) : !Auth::puedeConsultarPuertos($usuario)) {
    $salir(403, ['status' => 'error', 'message' => 'Sin acceso a la consulta de puertos.']);
}

switch ($action) {
    case 'estado':
        $e = sgsPuenteEstado();
        $salir(200, ['status' => 'success', 'activo' => $e['activo'], 'vence' => $hora($e['vence'])]);
    case 'consultar':
        $codigo = sgsCodigoCto((string)($_POST['codigo'] ?? ''));
        if ($codigo === null) $salir(400, ['status' => 'error', 'message' => 'Escribe un código de CTO válido, por ejemplo WN-73651-1272741.']);
        // 424 (no 503): con 5xx el monitor de errores pondría la franja de "Conexión interrumpida".
        if (!sgsPuenteEstado()['activo']) $salir(424, ['status' => 'error', 'message' => 'La consulta de puertos no está activa en este momento.']);
        $id = sgsPuertosCrearConsulta($uid, $codigo);
        if ($id === null) $salir(429, ['status' => 'error', 'message' => 'Hay muchas consultas en este momento. Intenta de nuevo en un minuto.']);
        $salir(200, ['status' => 'success', 'id' => $id]);
    case 'resultado':
        $r = sgsPuertosResultado($uid, (string)($_GET['id'] ?? ''));
        if ($r === null) $salir(404, ['status' => 'error', 'message' => 'La consulta ya no existe. Vuelve a consultar.']);
        $salir(200, ['status' => 'success'] + $r);
    case 'vincular':
        $vence = sgsPuenteActivar($uid);
        $salir(200, ['status' => 'success', 'vence' => $hora($vence), 'sondeo' => SGS_PUENTE_SONDEO]);
    case 'tomar':
        $t = sgsPuenteTomar($uid);
        if ($t === null) $salir(200, ['status' => 'success', 'activo' => false]);
        $salir(200, ['status' => 'success', 'activo' => true, 'vence' => $hora($t['vence']), 'consulta' => $t['consulta'], 'en_espera' => $t['en_espera']]);
    case 'responder':
        $datos = json_decode((string)($_POST['datos'] ?? ''), true);
        $ok = is_array($datos) && sgsPuertosGuardarRespuesta((string)($_POST['id'] ?? ''), $datos);
        $salir($ok ? 200 : 404, ['status' => $ok ? 'success' : 'error']);
    case 'desactivar':
        sgsPuenteDesactivar();
        $salir(200, ['status' => 'success']);
}
$salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
