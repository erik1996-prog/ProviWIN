<?php
// 02_BACKEND/src/routes/api/ausencias.php - Vacaciones, descansos médicos y licencias (2026-10-26). Ver config/ausencias.php.
// Supervisor directo (las suyas):
//   GET  ?action=mias                      → sus solicitudes (las últimas 20) y la ausencia aprobada de hoy
//   POST ?action=registrar                 → tipo, fecha_inicio, fecha_fin, comentario, sustento (archivo; obligatorio en
//                                            descanso médico). Queda Pendiente y avisa a los jefes.
//   POST ?action=anular   id               → anula una suya que sigue Pendiente
// Administración, gerencia y coordinación (todas):
//   GET  ?action=lista&estado=Pendiente|vigentes|todas
//   POST ?action=decidir  id, decision=Aprobada|Rechazada|Anulada, motivo (obligatorio al rechazar o anular)
// Ambos: GET ?action=sustento&id → abre el archivo (solo el dueño o un jefe).
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/uploads.php';
require_once __DIR__ . '/../../config/ausencias.php';

Auth::requireLogin();
$usuario = Auth::getLoggedInUser();
$uid = (int)$usuario['id'];
$esJefe = Auth::isAdministrative();
$esSupervisorDirecto = ($usuario['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($usuario['is_win_directo']);
$action = (string)($_GET['action'] ?? '');
$pdo = Database::getConnection();
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
$buscar = static function (int $id) use ($pdo): ?array {
    $st = $pdo->prepare('SELECT * FROM ausencias_supervisores WHERE id = :id');
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

// Sustento: archivo privado, solo para el dueño o un jefe
if ($action === 'sustento') {
    $a = $buscar((int)($_GET['id'] ?? 0));
    if (!$a || !$a['sustento'] || (!$esJefe && (int)$a['supervisor_id'] !== $uid)) $responder(['status' => 'error', 'message' => 'No encontrado.'], 404);
    $ruta = sgsCarpetaAusencias() . '/' . basename($a['sustento']);
    if (!is_file($ruta)) $responder(['status' => 'error', 'message' => 'El archivo ya no está disponible.'], 404);
    $tipos = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($tipos[pathinfo($ruta, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Content-Disposition: inline; filename="sustento-' . (int)$a['id'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;
}

Auth::requireCsrf();
$columnas = "a.id, a.supervisor_id, u.nombre_completo AS supervisor, u.dni, a.tipo, a.fecha_inicio, a.fecha_fin,
    DATEDIFF(a.fecha_fin, a.fecha_inicio) + 1 AS dias, a.comentario, (a.sustento IS NOT NULL) AS tiene_sustento, a.estado,
    a.motivo_revision, r.nombre_completo AS revisado_por, a.revisado_en, a.creado_en";
$desde = "FROM ausencias_supervisores a JOIN usuarios u ON u.id = a.supervisor_id LEFT JOIN usuarios r ON r.id = a.revisado_por";

if ($action === 'mias') {
    if (!$esSupervisorDirecto) $responder(['status' => 'error', 'message' => 'Solo para supervisores directos.'], 403);
    $st = $pdo->prepare("SELECT $columnas $desde WHERE a.supervisor_id = :u ORDER BY a.fecha_inicio DESC, a.id DESC LIMIT 20");
    $st->execute([':u' => $uid]);
    $hoy = sgsAusenciaAprobada($pdo, $uid, date('Y-m-d'));
    $responder(['status' => 'success', 'data' => $st->fetchAll(PDO::FETCH_ASSOC), 'hoy' => $hoy ? sgsTextoAusencia($hoy) : null]);
}

if ($action === 'registrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esSupervisorDirecto) $responder(['status' => 'error', 'message' => 'Solo los supervisores directos registran sus ausencias.'], 403);
    $tipo = (string)($_POST['tipo'] ?? '');
    $ini = (string)($_POST['fecha_inicio'] ?? ''); $fin = (string)($_POST['fecha_fin'] ?? '');
    $comentario = trim(preg_replace('/\s+/u', ' ', (string)($_POST['comentario'] ?? '')));
    $fecha = static fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!in_array($tipo, SGS_TIPOS_AUSENCIA, true)) $responder(['status' => 'error', 'message' => 'Elija el tipo de ausencia.'], 422);
    if (!$fecha($ini) || !$fecha($fin)) $responder(['status' => 'error', 'message' => 'Elija la fecha de inicio y la de fin.'], 422);
    if ($fin < $ini) $responder(['status' => 'error', 'message' => 'La fecha de fin no puede ser anterior a la de inicio.'], 422);
    $dias = (int)((strtotime($fin) - strtotime($ini)) / 86400) + 1;
    if ($dias > SGS_AUSENCIA_MAX_DIAS) $responder(['status' => 'error', 'message' => 'Una solicitud cubre hasta ' . SGS_AUSENCIA_MAX_DIAS . ' días. Regístrela en partes.'], 422);
    if ($ini < date('Y-m-d', strtotime('-' . SGS_AUSENCIA_DIAS_ATRAS . ' days'))) $responder(['status' => 'error', 'message' => 'Solo se registra hasta ' . SGS_AUSENCIA_DIAS_ATRAS . ' días hacia atrás.'], 422);
    if (mb_strlen($comentario) > 300) $responder(['status' => 'error', 'message' => 'El comentario admite hasta 300 caracteres.'], 422);
    $cruce = $pdo->prepare("SELECT tipo, fecha_inicio, fecha_fin FROM ausencias_supervisores
        WHERE supervisor_id = :u AND estado IN ('Pendiente', 'Aprobada') AND fecha_inicio <= :fin AND fecha_fin >= :ini LIMIT 1");
    $cruce->execute([':u' => $uid, ':fin' => $fin, ':ini' => $ini]);
    if ($otra = $cruce->fetch(PDO::FETCH_ASSOC)) $responder(['status' => 'error', 'message' => 'Esas fechas se cruzan con otra solicitud suya: ' . sgsTextoAusencia($otra) . '.'], 409);
    // Vacaciones (2026-10-29): no pueden dejar dos zonas sin supervisor ni coincidir con otro de su misma zona.
    if ($tipo === 'Vacaciones' && ($choque = sgsChoqueVacaciones($pdo, $uid, $ini, $fin))) $responder(['status' => 'error', 'message' => $choque], 409);
    $archivo = $_FILES['sustento'] ?? null;
    $hayArchivo = $archivo && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($tipo === 'Descanso médico' && !$hayArchivo) $responder(['status' => 'error', 'message' => 'Adjunte el certificado médico (foto o PDF).'], 422);
    $nombre = null;
    if ($hayArchivo) {
        try {
            $ok = validateUploadedFile($archivo, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 8 * 1024 * 1024);
        } catch (RuntimeException $e) {
            $responder(['status' => 'error', 'message' => 'El sustento debe ser una foto (JPG, PNG, WEBP) o un PDF de hasta 8 MB.'], 422);
        }
        $nombre = randomUploadName('ausencia_', $ok['extension']);
        if (!move_uploaded_file($archivo['tmp_name'], sgsCarpetaAusencias() . '/' . $nombre)) $responder(['status' => 'error', 'message' => 'No se pudo guardar el sustento. Intente de nuevo.'], 500);
    }
    $pdo->prepare('INSERT INTO ausencias_supervisores (supervisor_id, tipo, fecha_inicio, fecha_fin, comentario, sustento) VALUES (:u, :t, :i, :f, :c, :s)')
        ->execute([':u' => $uid, ':t' => $tipo, ':i' => $ini, ':f' => $fin, ':c' => $comentario ?: null, ':s' => $nombre]);
    $texto = sgsTextoAusencia(['tipo' => $tipo, 'fecha_inicio' => $ini, 'fecha_fin' => $fin]);
    $jefes = $pdo->query("SELECT id FROM usuarios WHERE activo = 1 AND rol IN ('Administrador', 'Gerente', 'Coordinador')")->fetchAll(PDO::FETCH_COLUMN);
    sgsAvisarAusencia($pdo, $jefes, 'Solicitud de ausencia', "{$usuario['nombre']} solicita {$texto}. Revísela en el Monitor de turnos.", 'index.php?page=asistencia');
    win_log_error('audit_absence_requested', 'Ausencia solicitada', ['actor_id' => $uid, 'tipo' => $tipo, 'dias' => $dias]);
    $responder(['status' => 'success', 'message' => "Solicitud registrada: {$texto}. Queda pendiente de aprobación."]);
}

if ($action === 'anular' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $buscar((int)($_POST['id'] ?? 0));
    if (!$a || (int)$a['supervisor_id'] !== $uid) $responder(['status' => 'error', 'message' => 'No encontrado.'], 404);
    if ($a['estado'] !== 'Pendiente') $responder(['status' => 'error', 'message' => 'Solo se anula una solicitud pendiente. Si ya fue aprobada, pida a su jefe que la anule.'], 409);
    $pdo->prepare("UPDATE ausencias_supervisores SET estado = 'Anulada', revisado_por = :u, revisado_en = NOW(), motivo_revision = 'Anulada por el supervisor' WHERE id = :id")
        ->execute([':u' => $uid, ':id' => $a['id']]);
    $responder(['status' => 'success', 'message' => 'Solicitud anulada.']);
}

if (!$esJefe) $responder(['status' => 'error', 'message' => 'No tiene acceso.'], 403);

if ($action === 'lista') {
    $estado = (string)($_GET['estado'] ?? 'Pendiente');
    $donde = match ($estado) {
        'vigentes' => "a.estado = 'Aprobada' AND a.fecha_fin >= CURDATE()",   // de vacaciones hoy o por salir
        'todas' => 'a.creado_en >= CURDATE() - INTERVAL 90 DAY',
        default => "a.estado = 'Pendiente'",
    };
    $filas = $pdo->query("SELECT $columnas $desde WHERE $donde ORDER BY a.fecha_inicio, a.id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $pendientes = (int)$pdo->query("SELECT COUNT(*) FROM ausencias_supervisores WHERE estado = 'Pendiente'")->fetchColumn();
    $responder(['status' => 'success', 'data' => $filas, 'pendientes' => $pendientes]);
}

if ($action === 'decidir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $buscar((int)($_POST['id'] ?? 0));
    $decision = (string)($_POST['decision'] ?? '');
    $motivo = trim(preg_replace('/\s+/u', ' ', (string)($_POST['motivo'] ?? '')));
    if (!$a) $responder(['status' => 'error', 'message' => 'Esa solicitud ya no existe.'], 404);
    $valida = ($a['estado'] === 'Pendiente' && in_array($decision, ['Aprobada', 'Rechazada'], true)) || ($a['estado'] === 'Aprobada' && $decision === 'Anulada');
    if (!$valida) $responder(['status' => 'error', 'message' => 'La solicitud cambió de estado. Actualice la lista.'], 409);
    if ($decision !== 'Aprobada' && ($motivo === '' || mb_strlen($motivo) > 300)) $responder(['status' => 'error', 'message' => 'Escriba el motivo (hasta 300 caracteres).'], 422);
    $pdo->prepare('UPDATE ausencias_supervisores SET estado = :e, revisado_por = :u, revisado_en = NOW(), motivo_revision = :m WHERE id = :id AND estado = :antes')
        ->execute([':e' => $decision, ':u' => $uid, ':m' => $motivo ?: null, ':id' => $a['id'], ':antes' => $a['estado']]);
    $texto = sgsTextoAusencia($a);
    $verbo = ['Aprobada' => 'aprobó', 'Rechazada' => 'rechazó', 'Anulada' => 'anuló'][$decision];
    sgsAvisarAusencia($pdo, [(int)$a['supervisor_id']], "Ausencia {$decision}", "{$usuario['nombre']} {$verbo} su solicitud: {$texto}" . ($motivo ? ". Motivo: {$motivo}" : '') . '.', 'index.php?page=asistencia');
    win_log_error('audit_absence_decided', "Ausencia {$decision}", ['actor_id' => $uid, 'ausencia_id' => (int)$a['id']]);
    $responder(['status' => 'success', 'message' => "Solicitud {$decision}."]);
}

$responder(['status' => 'error', 'message' => 'Acción no válida.'], 404);
