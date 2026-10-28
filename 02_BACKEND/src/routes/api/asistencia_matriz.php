<?php
// 02_BACKEND/src/routes/api/asistencia_matriz.php - Matriz de asistencia mensual (2026-10-28). Reglas en config/asistencia_matriz.php.
//   GET  ?action=matriz&mes=AAAA-MM&zona=&supervisor_id=   → días, filas con el código de cada día y totales
//   GET  ?action=excel&mes=…                                → la misma matriz en Excel
//   POST ?action=ajustar  supervisor_id, fecha, codigo ('' = volver a lo calculado), comentario   (solo con permiso de editar)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/dashboard.php';
require_once __DIR__ . '/../../config/asistencia_matriz.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$responder = static function (array $datos, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!Auth::puede(Auth::PERM_ASISTENCIA_MATRIZ_VIEW, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para ver la matriz de asistencia.'], 403);
$action = $_GET['action'] ?? '';
$pdo = Database::getConnection();

if ($action === 'matriz' || $action === 'excel') {
    $mes = (string)($_GET['mes'] ?? '');
    $zona = mb_strtoupper(trim((string)($_GET['zona'] ?? '')), 'UTF-8');
    $m = sgsMatrizAsistencia($pdo, $usuario, $mes, $zona, max(0, (int)($_GET['supervisor_id'] ?? 0)));
    if ($action === 'matriz') $responder(['status' => 'success', 'data' => $m]);
    $hoja = [array_merge(['SUPERVISOR', 'DNI'], array_map(static fn($d) => $d['dia'] . ' ' . $d['semana'], $m['dias']), array_keys(SGS_ASISTENCIA_CODIGOS))];
    foreach ($m['filas'] as $f) $hoja[] = array_merge([$f['nombre'], $f['dni']], array_column($f['celdas'], 'codigo'), array_values($f['totales']));
    $hoja[] = [];
    foreach (SGS_ASISTENCIA_CODIGOS as $codigo => $nombre) $hoja[] = [$codigo, $nombre];
    sgsEnviarXlsx($hoja, 'Asistencia ' . substr($m['desde'], 0, 7), 'matriz_asistencia_' . substr($m['desde'], 0, 7));
}

if ($action === 'ajustar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::puede(Auth::PERM_ASISTENCIA_MATRIZ_EDIT, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para corregir la matriz.'], 403);
    $supervisorId = (int)($_POST['supervisor_id'] ?? 0);
    $fecha = (string)($_POST['fecha'] ?? '');
    $codigo = (string)($_POST['codigo'] ?? '');
    $comentario = mb_substr(trim((string)($_POST['comentario'] ?? '')), 0, 200);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !checkdate((int)substr($fecha, 5, 2), (int)substr($fecha, 8, 2), (int)substr($fecha, 0, 4))) $responder(['status' => 'error', 'message' => 'Fecha no válida.'], 422);
    if ($codigo !== '' && !isset(SGS_ASISTENCIA_CODIGOS[$codigo])) $responder(['status' => 'error', 'message' => 'Elija un código de la leyenda.'], 422);
    $visible = array_filter(sgsSupervisoresMatriz($pdo, $usuario, substr($fecha, 0, 8) . '01', '', $supervisorId), static fn($s) => (int)$s['id'] === $supervisorId);
    if (!$visible) $responder(['status' => 'error', 'message' => 'El supervisor no está en su matriz.'], 403);
    if ($codigo === '') {
        $pdo->prepare('DELETE FROM asistencia_ajustes WHERE supervisor_id = :s AND fecha = :f')->execute([':s' => $supervisorId, ':f' => $fecha]);
    } else {
        $pdo->prepare('INSERT INTO asistencia_ajustes (supervisor_id, fecha, codigo, comentario, usuario_id) VALUES (:s, :f, :c, :m, :u)
            ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), comentario = VALUES(comentario), usuario_id = VALUES(usuario_id)')
            ->execute([':s' => $supervisorId, ':f' => $fecha, ':c' => $codigo, ':m' => $comentario ?: null, ':u' => (int)$usuario['id']]);
    }
    win_log_error('audit_asistencia_matriz', $codigo === '' ? 'Corrección quitada' : "Corrección: $codigo", ['supervisor_id' => $supervisorId, 'fecha' => $fecha, 'actor_id' => (int)$usuario['id']]);
    $responder(['status' => 'success', 'message' => $codigo === '' ? 'El día vuelve a lo calculado.' : 'Día corregido: ' . SGS_ASISTENCIA_CODIGOS[$codigo] . '.']);
}

$responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
