<?php
// 02_BACKEND/src/routes/api/examenes.php - Links de examen de los técnicos (2026-10-15). Ver config/examen_enlaces.php.
// Pueden usarlo el personal administrativo y los supervisores directos (los mismos que ven Cuadrillas); el supervisor,
// solo para técnicos de su zona (o que aún no tienen zona).
//   GET  ?action=tecnico&id=N   → datos del técnico, su T2 (si es líder) e historial de links de ambos
//   POST ?action=generar        → tecnico_id, horas (2|24|72), titulo → link para enviar (el código se muestra una sola vez)
//   POST ?action=anular         → id del link
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/examen_enlaces.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
$esDirecto = ($usuario['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($usuario['is_win_directo']);
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$esAdmin && !$esDirecto) $responder(['status' => 'error', 'message' => 'Solo el personal administrativo y los supervisores directos generan links de examen.'], 403);
$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';

/** Técnico visible para el usuario (zona), o error. */
$tecnico = static function (int $id) use ($pdo, $usuario, $esAdmin, $responder): array {
    $st = $pdo->prepare("SELECT t.id, t.nombre_tecnico, COALESCE(t.tipo_tecnico, 1) AS tipo_tecnico, t.dni, t.celular, t.estado_operativo,
            t.departamento, t.apoyo_id, p.nombre_partner
        FROM tecnicos t LEFT JOIN partners p ON p.id = t.partner_id WHERE t.id = :id");
    $st->execute([':id' => $id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) $responder(['status' => 'error', 'message' => 'El técnico ya no existe.'], 404);
    if (!$esAdmin) {
        $zonas = sgsUserZones((int)$usuario['id']);
        $dep = mb_strtoupper(trim((string)$t['departamento']), 'UTF-8');
        if ($zonas !== null && $dep !== '' && !in_array($dep, $zonas, true)) {
            $responder(['status' => 'error', 'message' => 'Ese técnico es de otra zona.'], 403);
        }
    }
    $t['tipo_tecnico'] = (int)$t['tipo_tecnico'];
    $t['nombre_persona'] = sgsNombrePersonaTecnico($t['nombre_tecnico'], $t['tipo_tecnico']);
    return $t;
};

try {
    if ($action === 'tecnico') {
        $t = $tecnico((int)($_GET['id'] ?? 0));
        $personas = [$t];
        if ($t['tipo_tecnico'] === 1 && $t['apoyo_id']) $personas[] = $tecnico((int)$t['apoyo_id']);
        foreach ($personas as &$p) {
            $p['historial'] = sgsHistorialEnlaces($pdo, (int)$p['id']);
            unset($p['apoyo_id'], $p['departamento']);
        }
        $responder(['status' => 'success', 'data' => $personas, 'vigencias' => SGS_EXAMEN_VIGENCIAS]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generar') {
        $t = $tecnico((int)($_POST['tecnico_id'] ?? 0));
        if ($t['estado_operativo'] === 'Blacklist') throw new Exception('El técnico está en lista negra: no se le genera examen.');
        $titulo = trim(preg_replace('/\s+/u', ' ', (string)($_POST['titulo'] ?? '')));
        if ($titulo === '') $titulo = 'Evaluación técnica';
        if (mb_strlen($titulo) > 150) throw new Exception('El nombre del examen admite hasta 150 caracteres.');
        $horas = (int)($_POST['horas'] ?? 24);
        $e = sgsGenerarEnlaceExamen($pdo, (int)$t['id'], $horas, $titulo, (int)$usuario['id']);
        $url = sgsUrlSistema() . '/examen.php?tecnico=' . sgsSlugNombre($t['nombre_persona']) . '&t=' . $e['codigo'];
        $vence = date('d/m/Y H:i', strtotime($e['vence_en']));
        $mensaje = "Hola {$t['nombre_persona']}, este es tu link para rendir \"{$titulo}\" de WIN:\n{$url}\n\nEs personal: no lo compartas. Vence el {$vence}.";
        win_log_error('audit_exam_link_created', 'Link de examen generado', ['actor_id' => (int)$usuario['id'], 'target_id' => (int)$t['id'], 'enlace_id' => $e['id'], 'horas' => $horas]);
        $responder(['status' => 'success', 'message' => 'Link generado.', 'data' => [
            'id' => $e['id'], 'url' => $url, 'mensaje' => $mensaje, 'vence' => $vence, 'nombre' => $t['nombre_persona'],
            'celular' => preg_match('/^9\d{8}$/', (string)$t['celular']) ? $t['celular'] : null,
            'historial' => sgsHistorialEnlaces($pdo, (int)$t['id']),
        ]]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'anular') {
        $st = $pdo->prepare('SELECT id, tecnico_id, generado_por, vence_en, abierto_en, usado_en, anulado_en FROM examen_enlaces WHERE id = :id');
        $st->execute([':id' => (int)($_POST['id'] ?? 0)]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) throw new Exception('El link ya no existe.');
        $t = $tecnico((int)$e['tecnico_id']); // valida la zona
        if (!in_array(sgsEstadoEnlace($e), ['activo', 'abierto'], true)) throw new Exception('Ese link ya no está vigente.');
        if (!$esAdmin && (int)$e['generado_por'] !== (int)$usuario['id']) throw new Exception('Solo quien generó el link (o la administración) puede anularlo.');
        $pdo->prepare('UPDATE examen_enlaces SET anulado_en = NOW(), anulado_por = :u WHERE id = :id AND anulado_en IS NULL AND usado_en IS NULL')
            ->execute([':u' => (int)$usuario['id'], ':id' => (int)$e['id']]);
        win_log_error('audit_exam_link_revoked', 'Link de examen anulado', ['actor_id' => (int)$usuario['id'], 'target_id' => (int)$t['id'], 'enlace_id' => (int)$e['id']]);
        $responder(['status' => 'success', 'message' => 'Link anulado: ya no se puede abrir.', 'data' => ['historial' => sgsHistorialEnlaces($pdo, (int)$t['id'])]]);
    }

    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
} catch (Exception $e) {
    $responder(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    win_log_error('exam_links_error', $e->getMessage(), ['action' => $action]);
    $responder(['status' => 'error', 'message' => 'No se pudo completar la acción.', 'code' => function_exists('sgs_error_code') ? sgs_error_code() : null], 500);
}
