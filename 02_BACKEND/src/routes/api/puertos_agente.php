<?php
// 02_BACKEND/src/routes/api/puertos_agente.php - Programa "Vínculo Wincore" de la laptop (2026-11-19). Lógica: config/puertos_cto.php.
// Sin sesión del navegador: el programa se identifica con la clave de su dueño (cabecera X-SGS-Clave).
// Acciones: vincular, tomar, responder (id, datos), desactivar; phoenix_estado/iniciar y telegram_estado/token (2026-11-20/21).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
define('WIN_JSON_RESPONSE', true);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/puertos_cto.php';
// Sin sesión del SGS (se identifica con la clave): la que abrió config.php se descarta para no dejar un archivo de sesión por
// cada pregunta (el programa pregunta cada pocos segundos y no guarda cookies).
if (session_status() === PHP_SESSION_ACTIVE) session_destroy();

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
// 2026-11-21 (pedido del usuario: "que los reportes salgan solos con la laptop"): el programa de la laptop pregunta aquí cada pocos
// segundos (y, sin VPN, cada 2 min por Phoenix y Telegram), así que también despierta la lectura de Inicio de órdenes y sus reportes
// y la señal de vida de Phoenix, como cualquier pantalla abierta. Sin costo extra: corren como mucho una vez por minuto para todos.
sgsDespertarInicioOrdenes();
sgsMantenerPhoenix();
$hora = static fn(?int $t) => $t ? date('H:i', $t) : null;
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

switch ((string)($_GET['action'] ?? '')) {
    case 'vincular':
        if (!$post) break;
        // 2026-11-21: si otra laptop tiene el vínculo, esta espera (409: el programa reintenta cada minuto, sin pelearse).
        if (sgsPuenteDeOtro($uid)) $salir(409, ['status' => 'error', 'message' => 'Otra laptop ya tiene activo el vínculo con Wincore: se reintenta cuando esa se desconecte.']);
        $salir(200, ['status' => 'success', 'vence' => $hora(sgsPuenteActivar($uid)), 'sondeo' => SGS_PUENTE_SONDEO]);
    case 'tomar':
        $t = sgsPuenteTomar($uid);
        // desactivado: lo apagó la persona (el programa no vuelve a entrar solo); si no, venció y puede volver a activarse.
        if ($t === null) $salir(200, ['status' => 'success', 'activo' => false, 'desactivado' => is_file(sgsPuertosDir() . '/desactivado.json'),
            'otra_laptop' => sgsPuenteDeOtro($uid)]);   // 2026-11-21: el programa espera sin entrar a Wincore
        $salir(200, ['status' => 'success', 'activo' => true, 'vence' => $hora($t['vence']), 'consulta' => $t['consulta'], 'en_espera' => $t['en_espera']]);
    case 'responder':
        if (!$post) break;
        $datos = json_decode((string)($_POST['datos'] ?? ''), true);
        $ok = is_array($datos) && sgsPuertosGuardarRespuesta((string)($_POST['id'] ?? ''), $datos);
        $salir($ok ? 200 : 404, ['status' => $ok ? 'success' : 'error']);
    case 'desactivar':
        if (!$post) break;
        if (!sgsPuenteDeOtro($uid)) sgsPuenteDesactivar(!empty($_POST['usuario']));   // el programa al detenerse (solo el suyo)
        $salir(200, ['status' => 'success']);

    // Phoenix desde el mismo programa (2026-11-20, pedido del usuario): el vigilante de la laptop pregunta si la sesión de Phoenix
    // iniciada en el SGS está activa y, si no (pasó la medianoche o Phoenix la cerró), la inicia con el usuario y la contraseña
    // de Phoenix guardados cifrados en la laptop. Aquí la contraseña solo se usa para entrar (WinboService::iniciarSesionAqui):
    // no se guarda ni se escribe en registros; queda solo la sesión.
    case 'phoenix_estado':
    case 'phoenix_iniciar':
        require_once __DIR__ . '/../../config/phoenix_sesion.php';
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

    // Token del bot de Telegram desde el mismo programa (2026-11-21, pedido del usuario: "como Phoenix"). El vigilante pregunta si el
    // SGS ya tiene el token de hoy y, si no, lo entrega. Se comprueba con Telegram (getMe, no envía nada) y se guarda solo hasta las
    // 23:59 (config/telegram_token.php). Nunca se escribe en registros.
    case 'telegram_estado':
    case 'telegram_token':
        require_once __DIR__ . '/../../config/telegram_token.php';
        if ($_GET['action'] === 'telegram_estado') {
            $t = sgsTelegramTokenEstado();
            $salir(200, ['status' => 'success', 'activo' => $t['activo'], 'vence' => $hora($t['vence'])]);
        }
        if (!$post) break;
        $token = trim((string)($_POST['telegram_token'] ?? ''));
        unset($_POST['telegram_token'], $_REQUEST['telegram_token']);
        if (!sgsTelegramTokenValido($token)) $salir(422, ['status' => 'error', 'message' => 'El token de Telegram no tiene el formato correcto.']);
        try {
            $bot = sgsTelegramBotDe($token);
        } catch (Throwable $e) {
            $salir(424, ['status' => 'error', 'message' => 'No se pudo conectar con Telegram; se reintenta luego.']);
        }
        if ($bot === null) $salir(422, ['status' => 'error', 'message' => 'Telegram no aceptó el token.']);
        $vence = sgsTelegramTokenGuardar($token, (int)$usuario['id']);
        $token = '';
        $salir(200, ['status' => 'success', 'activo' => true, 'bot' => $bot, 'vence' => $hora($vence)]);
}
$salir(400, ['status' => 'error', 'message' => 'Acción no válida.']);
