<?php
// 02_BACKEND/src/routes/api/partners.php - Registro de partners (contratas) (2026-10-15).
// Solo administración de usuarios (PERM_USERS_MANAGE). Tabla `partners` (nombre_partner, ruc, estado).
// - Un partner inactivo deja de salir en las listas para elegir, pero conserva su historial.
// - Eliminar solo si no tiene técnicos, usuarios ni supervisiones (para corregir uno creado por error).
// - WIN DIRECTO es el personal propio: no se renombra, desactiva ni elimina.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
Auth::requireCsrf();
$user = Auth::getLoggedInUser();
if (!Auth::can(Auth::PERM_USERS_MANAGE, $user)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Solo el administrador puede gestionar los partners.']);
    exit;
}
$pdo = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
$esWinDirecto = static fn(?string $nombre): bool => in_array(mb_strtoupper(trim((string)$nombre), 'UTF-8'), ['WIN', 'WIN DIRECTO'], true);
$partnerPorId = static function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT id, nombre_partner, ruc, estado FROM partners WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

try {
    if ($action === 'list') {
        $filas = $pdo->query("SELECT p.id, p.nombre_partner, p.ruc, p.estado, p.fecha_registro,
                (SELECT COUNT(*) FROM tecnicos t WHERE t.partner_id = p.id AND COALESCE(t.tipo_tecnico, 1) = 1 AND t.estado_operativo = 'Habilitado') AS lideres,
                (SELECT COUNT(*) FROM tecnicos t WHERE t.partner_id = p.id AND t.tipo_tecnico = 2) AS apoyos,
                (SELECT COUNT(*) FROM usuarios u WHERE u.partner_id = p.id AND u.activo = 1) AS usuarios,
                (SELECT COUNT(*) FROM supervisiones s WHERE s.partner_id = p.id) AS supervisiones,
                (SELECT COUNT(*) FROM tecnicos t WHERE t.partner_id = p.id) + (SELECT COUNT(*) FROM usuarios u WHERE u.partner_id = p.id) AS referencias_extra
            FROM partners p ORDER BY p.estado DESC, (UPPER(p.nombre_partner) IN ('WIN','WIN DIRECTO')), p.nombre_partner")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as &$f) {
            $f['protegido'] = $esWinDirecto($f['nombre_partner']);
            $f['puede_eliminar'] = !$f['protegido'] && (int)$f['referencias_extra'] === 0 && (int)$f['supervisiones'] === 0;
            unset($f['referencias_extra']);
        }
        unset($f);
        $responder(['status' => 'success', 'data' => $filas]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 405);
    $in = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    if ($action === 'guardar') {
        $id = (int)($in['id'] ?? 0);
        $nombre = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['nombre_partner'] ?? ''))), 'UTF-8');
        $ruc = preg_replace('/\D/', '', (string)($in['ruc'] ?? ''));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150 || !preg_match('/^[\p{L}\d .&\'-]+$/u', $nombre)) {
            $responder(['status' => 'error', 'field' => 'nombre_partner', 'message' => 'Escriba el nombre del partner (de 2 a 150 caracteres: letras, números, espacios, punto, & o guion).'], 422);
        }
        if ($ruc !== '' && !preg_match('/^(10|15|17|20)\d{9}$/', $ruc)) {
            $responder(['status' => 'error', 'field' => 'ruc', 'message' => 'El RUC debe tener 11 dígitos y empezar con 10, 15, 17 o 20. Puede dejarlo vacío.'], 422);
        }
        $previo = $id > 0 ? $partnerPorId($id) : null;
        if ($id > 0 && !$previo) $responder(['status' => 'error', 'message' => 'El partner ya no existe.'], 404);
        if ($previo && $esWinDirecto($previo['nombre_partner']) && $nombre !== mb_strtoupper($previo['nombre_partner'], 'UTF-8')) {
            $responder(['status' => 'error', 'field' => 'nombre_partner', 'message' => 'WIN DIRECTO es el personal propio de WIN: su nombre no se cambia.'], 422);
        }
        if (!$previo && $esWinDirecto($nombre)) $responder(['status' => 'error', 'field' => 'nombre_partner', 'message' => 'Ese nombre está reservado para el personal propio de WIN.'], 422);
        $dup = $pdo->prepare('SELECT nombre_partner FROM partners WHERE UPPER(TRIM(nombre_partner)) = :n AND id <> :id LIMIT 1');
        $dup->execute([':n' => $nombre, ':id' => $id]);
        if ($dup->fetchColumn()) $responder(['status' => 'error', 'field' => 'nombre_partner', 'message' => "Ya existe un partner llamado {$nombre}."], 422);
        if ($ruc !== '') {
            $dupRuc = $pdo->prepare('SELECT nombre_partner FROM partners WHERE ruc = :r AND id <> :id LIMIT 1');
            $dupRuc->execute([':r' => $ruc, ':id' => $id]);
            if ($otro = $dupRuc->fetchColumn()) $responder(['status' => 'error', 'field' => 'ruc', 'message' => "Ese RUC ya está registrado para {$otro}."], 422);
        }
        if ($previo) {
            $pdo->prepare('UPDATE partners SET nombre_partner = :n, ruc = :r WHERE id = :id')->execute([':n' => $nombre, ':r' => $ruc ?: null, ':id' => $id]);
            win_log_error('audit_partner_updated', "Partner actualizado: {$nombre}", ['target_id' => $id, 'actor_id' => (int)$user['id']]);
            $responder(['status' => 'success', 'message' => "Partner {$nombre} actualizado.", 'id' => $id]);
        }
        $pdo->prepare('INSERT INTO partners (nombre_partner, ruc, estado, fecha_registro) VALUES (:n, :r, 1, NOW())')->execute([':n' => $nombre, ':r' => $ruc ?: null]);
        $nuevo = (int)$pdo->lastInsertId();
        win_log_error('audit_partner_created', "Partner registrado: {$nombre}", ['target_id' => $nuevo, 'actor_id' => (int)$user['id']]);
        $responder(['status' => 'success', 'message' => "Partner {$nombre} registrado.", 'id' => $nuevo]);
    }

    if ($action === 'estado') {
        $id = (int)($in['id'] ?? 0);
        $activo = !empty($in['activo']) && $in['activo'] !== 'false' && $in['activo'] !== '0';
        $p = $partnerPorId($id) ?? $responder(['status' => 'error', 'message' => 'El partner ya no existe.'], 404);
        if ($esWinDirecto($p['nombre_partner']) && !$activo) $responder(['status' => 'error', 'message' => 'WIN DIRECTO no se puede desactivar.'], 422);
        $pdo->prepare('UPDATE partners SET estado = :e WHERE id = :id')->execute([':e' => $activo ? 1 : 0, ':id' => $id]);
        win_log_error('audit_partner_status', ($activo ? 'Partner activado: ' : 'Partner desactivado: ') . $p['nombre_partner'], ['target_id' => $id, 'actor_id' => (int)$user['id']]);
        $responder(['status' => 'success', 'message' => $activo ? "{$p['nombre_partner']} quedó activo." : "{$p['nombre_partner']} quedó inactivo: ya no aparecerá para elegir, pero conserva su historial."]);
    }

    if ($action === 'eliminar') {
        $id = (int)($in['id'] ?? 0);
        $p = $partnerPorId($id) ?? $responder(['status' => 'error', 'message' => 'El partner ya no existe.'], 404);
        if ($esWinDirecto($p['nombre_partner'])) $responder(['status' => 'error', 'message' => 'WIN DIRECTO no se puede eliminar.'], 422);
        $uso = $pdo->prepare('SELECT (SELECT COUNT(*) FROM tecnicos WHERE partner_id = :a) + (SELECT COUNT(*) FROM usuarios WHERE partner_id = :b) + (SELECT COUNT(*) FROM supervisiones WHERE partner_id = :c)');
        $uso->execute([':a' => $id, ':b' => $id, ':c' => $id]);
        if ((int)$uso->fetchColumn() > 0) $responder(['status' => 'error', 'message' => 'Este partner ya tiene técnicos, usuarios o supervisiones: no se puede eliminar. Puede desactivarlo.'], 422);
        $pdo->prepare('DELETE FROM partners WHERE id = :id')->execute([':id' => $id]);
        win_log_error('audit_partner_deleted', "Partner eliminado (sin registros): {$p['nombre_partner']}", ['target_id' => $id, 'actor_id' => (int)$user['id']]);
        $responder(['status' => 'success', 'message' => "Partner {$p['nombre_partner']} eliminado."]);
    }

    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    win_log_error('partners_error', $e->getMessage());
    $responder(['status' => 'error', 'message' => 'No se pudo completar la acción. Código: ' . sgs_error_code()], 500);
}
