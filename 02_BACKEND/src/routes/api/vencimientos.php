<?php
// 02_BACKEND/src/routes/api/vencimientos.php - Vencimientos de la cuadrilla (2026-10-15). Ver config/vencimientos.php.
// Pueden usarlo el personal administrativo y los supervisores directos; el supervisor, solo en su zona (y lo que no tiene zona).
//   GET  ?action=cuadrilla&lider_id=N[&placa=ABC-123][&supervision_id=S]  → los 4 documentos con historial (con S, lo pendiente de esa supervisión)
//   POST ?action=registrar   → tipo, sujeto_id, fecha_inicio, fecha_vencimiento, foto (obligatoria), lider_id, [supervision_id]
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/vencimientos.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative() && !sgsAlcanceLimitado($usuario); // líder con zonas: solo las suyas
$esDirecto = ($usuario['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($usuario['is_win_directo']);
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$esAdmin && !$esDirecto) $responder(['status' => 'error', 'message' => 'Solo el personal administrativo y los supervisores directos ven los vencimientos.'], 403);
$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';
$zonas = $esAdmin ? null : sgsUserZones((int)$usuario['id']);

/** Técnico líder visible para el usuario, con su vehículo (o el de la placa indicada). */
$cuadrilla = static function (int $liderId, string $placa = '') use ($pdo, $zonas, $responder): array {
    $st = $pdo->prepare("SELECT t.id, t.nombre_tecnico, t.departamento, t.vehiculo_id, v.placa, p.nombre_partner
        FROM tecnicos t LEFT JOIN vehiculos v ON v.id = t.vehiculo_id LEFT JOIN partners p ON p.id = t.partner_id
        WHERE t.id = :id AND COALESCE(t.tipo_tecnico, 1) = 1");
    $st->execute([':id' => $liderId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) $responder(['status' => 'error', 'message' => 'El técnico líder ya no existe.'], 404);
    $dep = mb_strtoupper(trim((string)$l['departamento']), 'UTF-8');
    if ($zonas !== null && $dep !== '' && !in_array($dep, $zonas, true)) $responder(['status' => 'error', 'message' => 'Esa cuadrilla es de otra zona.'], 403);
    $placa = strtoupper(trim($placa));
    if ($placa !== '' && $placa !== (string)$l['placa']) { // en la supervisión puede venir con otro vehículo
        $sv = $pdo->prepare('SELECT id, placa FROM vehiculos WHERE placa = :p');
        $sv->execute([':p' => $placa]);
        if ($v = $sv->fetch(PDO::FETCH_ASSOC)) { $l['vehiculo_id'] = $v['id']; $l['placa'] = $v['placa']; }
        else { $l['vehiculo_id'] = null; $l['placa'] = $placa; $l['placa_nueva'] = true; }
    }
    return $l;
};

try {
    if ($action === 'cuadrilla') {
        $l = $cuadrilla((int)($_GET['lider_id'] ?? 0), (string)($_GET['placa'] ?? ''));
        $responder(['status' => 'success', 'data' => [
            'lider' => ['id' => (int)$l['id'], 'nombre' => $l['nombre_tecnico'], 'partner' => $l['nombre_partner'], 'departamento' => $l['departamento']],
            'vehiculo' => ['id' => $l['vehiculo_id'] ? (int)$l['vehiculo_id'] : null, 'placa' => $l['placa'], 'nueva' => !empty($l['placa_nueva'])],
            'items' => sgsVencimientosCuadrilla($pdo, (int)$l['id'], $l['vehiculo_id'] ? (int)$l['vehiculo_id'] : null, true, (int)($_GET['supervision_id'] ?? 0) ?: null),
        ]]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'registrar') {
        // Desde Cuadrillas hace falta "editar cuadrillas"; desde una supervisión basta poder registrar supervisiones.
        if (!Auth::puede(empty($_POST['supervision_id']) ? Auth::PERM_CUADRILLAS_EDIT : Auth::PERM_SUPERVISION_CREATE, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para registrar vencimientos.'], 403);
        $tipo = (string)($_POST['tipo'] ?? '');
        if (!isset(SGS_VENCIMIENTOS[$tipo])) throw new Exception('Documento no válido.');
        $l = $cuadrilla((int)($_POST['lider_id'] ?? 0), (string)($_POST['placa'] ?? ''));
        // El documento debe ser de esta cuadrilla: el brevete del líder o el vehículo que usa.
        $sujetoId = SGS_VENCIMIENTOS[$tipo]['sujeto'] === 'vehiculo' ? (int)$l['vehiculo_id'] : (int)$l['id'];
        if ($sujetoId <= 0) throw new Exception('Primero registre el vehículo (placa) de la cuadrilla.');
        $supervisionId = (int)($_POST['supervision_id'] ?? 0) ?: null;
        if ($supervisionId) {
            $ss = $pdo->prepare('SELECT id FROM supervisiones WHERE id = :id');
            $ss->execute([':id' => $supervisionId]);
            if (!$ss->fetchColumn()) $supervisionId = null;
        }
        $pdo->beginTransaction();
        try {
            sgsRegistrarVencimiento($pdo, $tipo, $sujetoId, (string)($_POST['fecha_inicio'] ?? ''), (string)($_POST['fecha_vencimiento'] ?? ''),
                $_FILES['foto'] ?? null, $supervisionId ? 'Supervisión' : 'Cuadrillas', $supervisionId, (int)$usuario['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $responder(['status' => 'success', 'message' => "{$tipo} actualizado.", 'data' => [
            'items' => sgsVencimientosCuadrilla($pdo, (int)$l['id'], $l['vehiculo_id'] ? (int)$l['vehiculo_id'] : null),
        ]]);
    }


    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
} catch (Exception $e) {
    $responder(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    win_log_error('expiry_api_error', $e->getMessage(), ['action' => $action]);
    $responder(['status' => 'error', 'message' => 'No se pudo completar la acción.', 'code' => function_exists('sgs_error_code') ? sgs_error_code() : null], 500);
}
