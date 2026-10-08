<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
$pdo = Database::getConnection();
$user = Auth::getLoggedInUser();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// El sondeo de cada pestaña no debe convertir el SLA en una escritura masiva.
// El bloqueo global permite actualizarlo una sola vez por minuto en el servidor.
win_run_periodic_task('observation-sla', 60, static function () use ($pdo): void {
    $pdo->exec("UPDATE observaciones_supervision SET estado_respuesta='Penalidad Directa' WHERE estado_respuesta='Pendiente' AND fecha_limite_respuesta<NOW()");
});

if ($action === 'list') {
    // Retención de siete días, una sola vez por hora para todo el servidor.
    win_run_periodic_task('notification-retention', 3600, static function () use ($pdo): void {
        $pdo->exec("DELETE FROM notificaciones WHERE fecha_registro < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    });
    // Comprobar el rol vigente: una sesión antigua no concede acceso a avisos de errores.
    $stmt = $pdo->prepare("SELECT n.id,n.titulo,n.mensaje,n.url_destino,n.fecha_registro
        FROM notificaciones n JOIN usuarios u ON u.id=n.usuario_id
        WHERE n.usuario_id=:uid AND u.activo=1 AND n.leida=0
          AND (u.rol='Administrador' OR (
              COALESCE(n.titulo,'') <> 'Nuevo error del sistema'
              AND COALESCE(n.url_destino,'') NOT LIKE '%page=error_tickets%'
          ))
        ORDER BY n.fecha_registro DESC,n.id DESC LIMIT 10");
    $stmt->execute([':uid'=>(int)$user['id']]);
    $items = $stmt->fetchAll();
    echo json_encode(['status'=>'success','count'=>count($items),'items'=>$items], JSON_UNESCAPED_UNICODE);
    exit;
}

Auth::requireCsrf();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE notificaciones SET leida=1,fecha_lectura=NOW() WHERE usuario_id=:uid AND (:all_items=0 OR id=:id)");
    $stmt->execute([':uid'=>(int)$user['id'],':all_items'=>$id,':id'=>$id]);
    echo json_encode(['status'=>'success']);
    exit;
}

http_response_code(400);
echo json_encode(['status'=>'error','message'=>'Acción no válida.']);
