<?php
// 02_BACKEND/src/routes/api/puertos_agente.php - Programa "Vínculo Wincore" de la laptop (2026-11-19). Lógica: config/puertos_cto.php.
// Sin sesión del navegador: el programa se identifica con la clave que generó la cuenta principal (cabecera X-SGS-Clave).
// Mismas acciones y misma cola que la ventana del vínculo: vincular, tomar (GET), responder (POST id, datos), desactivar.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('WIN_JSON_RESPONSE', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/puertos_cto.php';
// Sin sesión del SGS (se identifica con la clave): la que abrió config.php se descarta para no dejar un archivo de sesión por
// cada pregunta (la ventanita pregunta cada pocos segundos y no guarda cookies).
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();

// Ventanita «Vínculo Wincore» del favorito (2026-11-20): corre en Wincore y llama aquí directo. Solo a Wincore se le permite
// (CORS); la clave va en la cabecera X-SGS-Clave y no se usan cookies.
$origen = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origen !== '' && $origen === sgsWincoreOrigen()) {
    header('Access-Control-Allow-Origin: ' . $origen);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: X-SGS-Clave');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$salir = static function (int $http, array $datos): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};

// La clave llega en la cabecera X-SGS-Clave y, por si el hosting o Cloudflare quitan esa cabecera, también en el cuerpo (2026-11-20).
$uid = sgsAgenteValidar(trim((string)(($_SERVER['HTTP_X_SGS_CLAVE'] ?? '') ?: ($_POST['clave'] ?? ''))));
if ($uid === null) {
    usleep(500000); // sin prisa para quien prueba claves
    $salir(401, ['status' => 'error', 'message' => 'La clave del programa no es válida o fue anulada. Genera una nueva en Consultar puertos → Vínculo con Wincore.']);
}
// La clave solo vale mientras su dueño siga siendo la cuenta principal, activa y con el permiso de consultar puertos.
$fila = Database::getConnection()->prepare('SELECT id, rol FROM usuarios WHERE id = ? AND activo = 1');
$fila->execute([$uid]);
$usuario = $fila->fetch(PDO::FETCH_ASSOC);
if (!$usuario || !Auth::puedeVincularWincore(['id' => (int)$usuario['id'], 'rol' => $usuario['rol'], 'is_win_directo' => true])) {
    $salir(403, ['status' => 'error', 'message' => 'Esta clave ya no tiene permiso para vincular Wincore.']);
}
$hora = static fn(?int $t) => $t ? date('H:i', $t) : null;
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

switch ((string)($_GET['action'] ?? '')) {
    case 'vincular':
        if (!$post) break;
        $salir(200, ['status' => 'success', 'vence' => $hora(sgsPuenteActivar($uid)), 'sondeo' => SGS_PUENTE_SONDEO]);
    case 'tomar':
        $t = sgsPuenteTomar($uid);
        // desactivado: lo apagó la persona (el programa no vuelve a entrar solo); si no, venció y puede volver a activarse.
        if ($t === null) $salir(200, ['status' => 'success', 'activo' => false, 'desactivado' => is_file(sgsPuertosDir() . '/desactivado.json')]);
        $salir(200, ['status' => 'success', 'activo' => true, 'vence' => $hora($t['vence']), 'consulta' => $t['consulta'], 'en_espera' => $t['en_espera']]);
    case 'responder':
        if (!$post) break;
        $datos = json_decode((string)($_POST['datos'] ?? ''), true);
        $ok = is_array($datos) && sgsPuertosGuardarRespuesta((string)($_POST['id'] ?? ''), $datos);
        $salir($ok ? 200 : 404, ['status' => $ok ? 'success' : 'error']);
    case 'desactivar':
        if (!$post) break;
        sgsPuenteDesactivar(!empty($_POST['usuario']));   // el programa al detenerse o la ventanita con su botón
        $salir(200, ['status' => 'success']);

    // Phoenix desde el mismo programa (2026-11-20, pedido del usuario): el vigilante de la laptop pregunta si la sesión de Phoenix
    // iniciada en el SGS está activa y, si no (pasó la medianoche o Phoenix la cerró), la inicia con el usuario y la contraseña
    // de Phoenix guardados cifrados en la laptop. Aquí la contraseña solo se usa para entrar (WinboService::iniciarSesionAqui):
    // no se guarda ni se escribe en registros; queda solo la sesión, como con la ventanita del indicador.
    case 'phoenix_estado':
    case 'phoenix_iniciar':
        require_once __DIR__ . '/../../config/puente_phoenix.php';
        if (!Auth::puedeVincularPhoenix(['id' => (int)$usuario['id'], 'rol' => $usuario['rol'], 'is_win_directo' => true])) $salir(403, ['status' => 'error', 'message' => 'Esta cuenta no puede conectar Phoenix.']);
        if ($_GET['action'] === 'phoenix_estado') {
            $s = sgsPhoenixSesion();
            $salir(200, ['status' => 'success', 'activa' => $s['activa'], 'vence' => $hora($s['vence'] ?? null)]);
        }
        if (!$post) break;
        $usuarioPhoenix = trim((string)($_POST['phoenix_usuario'] ?? ''));
        $clavePhoenix = (string)($_POST['phoenix_clave'] ?? '');
        unset($_POST['phoenix_clave'], $_REQUEST['phoenix_clave']);
        if ($usuarioPhoenix === '' || $clavePhoenix === '') $salir(422, ['status' => 'error', 'message' => 'Faltan el usuario o la contraseña de Phoenix.']);
        require_once __DIR__ . '/../../services/WinboService.php';
        try {
            $vence = WinboService::iniciarSesionAqui($usuarioPhoenix, $clavePhoenix, (int)$usuario['id']);
        } catch (Throwable $e) {
            $clavePhoenix = '';
            win_log_error('phoenix_sesion', 'El programa de la laptop no pudo iniciar Phoenix: ' . $e->getMessage());
            // 422 = Phoenix rechazó el usuario o la contraseña (el programa deja de intentar); 424 = otro problema (reintenta luego)
            $salir(str_contains($e->getMessage(), 'no aceptó') ? 422 : 424, ['status' => 'error', 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo entrar a Phoenix.']);
        }
        $clavePhoenix = '';
        $salir(200, ['status' => 'success', 'activa' => true, 'vence' => $hora($vence)]);
}
$salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
