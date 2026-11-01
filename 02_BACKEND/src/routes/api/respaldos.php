<?php
// 02_BACKEND/src/routes/api/respaldos.php - Respaldo de la base de datos desde Mi información (2026-11-01, pedido del usuario).
// Solo la cuenta principal (administrador general): el respaldo trae todos los datos, también las contraseñas cifradas.
//   GET  ?action=lista                 → los respaldos de la base que hay (los hace el cron cada 6 h; config/respaldos.php)
//   POST ?action=crear                 → hace uno ahora (liviano: solo la base, sin fotos ni sistema); uno cada 10 min como mucho
//   GET  ?action=descargar&archivo=…   → lo descarga (.sql.gz: se restaura en phpMyAdmin → Importar)
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$responder = static function (array $datos, int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (Auth::verComoActivo() || !Auth::esCuentaPrincipal((int)$usuario['id'])) $responder(['status' => 'error', 'message' => 'Solo el administrador general descarga respaldos de la base.'], 403);

$dir = SGS_RESPALDOS_PATH . '/base';
$respaldos = static function () use ($dir): array {
    $archivos = array_merge(glob("$dir/*.sql.gz") ?: [], glob("$dir/*.sql") ?: []);
    usort($archivos, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $archivos;
};
$action = $_GET['action'] ?? '';

if ($action === 'lista') {
    $responder(['status' => 'success', 'data' => array_map(static fn($f) => ['archivo' => basename($f), 'fecha' => date('Y-m-d H:i', filemtime($f)),
        'kb' => (int)ceil(filesize($f) / 1024)], array_slice($respaldos(), 0, 10))]);
}

if ($action === 'crear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ultimo = $respaldos()[0] ?? null;
    if ($ultimo && filemtime($ultimo) > time() - 600) {
        $responder(['status' => 'error', 'message' => 'Hay un respaldo de hace menos de 10 minutos: descargue ese.'], 429);
    }
    try {
        $archivo = basename(sgsRespaldarBase(Database::getConnection(), 'manual-web'));
    } catch (Throwable $e) {
        win_log_error('backup_failed', 'No se pudo crear el respaldo desde la web', ['error' => $e->getMessage()]);
        $responder(['status' => 'error', 'message' => 'No se pudo crear el respaldo. Inténtelo de nuevo en unos minutos.'], 500);
    }
    $responder(['status' => 'success', 'message' => 'Respaldo creado. Ya puede descargarlo.', 'archivo' => $archivo]);
}

if ($action === 'descargar') {
    $archivo = basename((string)($_GET['archivo'] ?? ''));
    $ruta = "$dir/$archivo";
    if (!preg_match('/^[A-Za-z0-9_.-]+\.sql(\.gz)?$/', $archivo) || !is_file($ruta)) $responder(['status' => 'error', 'message' => 'Ese respaldo ya no existe.'], 404);
    win_log_error('audit_backup_download', 'Respaldo de la base descargado', ['archivo' => $archivo, 'actor_id' => (int)$usuario['id']]);
    header('Content-Type: ' . (str_ends_with($archivo, '.gz') ? 'application/gzip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . $archivo . '"');
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: no-store');
    readfile($ruta);
    exit;
}

$responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
