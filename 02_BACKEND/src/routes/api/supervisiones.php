<?php
// 02_BACKEND/src/routes/api/supervisiones.php - API REST para Registro Operativo de Supervisiones (Flujo 4 Pasos)
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/uploads.php';
require_once __DIR__ . '/../../config/notifications.php';
require_once __DIR__ . '/../../config/supervision_personal.php';
require_once __DIR__ . '/../../config/territorial.php';

Auth::requireLogin();
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
Auth::requireCsrf();
if (!Auth::can(Auth::PERM_SUPERVISION_VIEW, $user)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'No tiene acceso al módulo de supervisiones.']);
    exit;
}

function getSupervisorTerritorialScope(array $user): string {
    if ($user['rol'] !== Auth::ROLE_SUPERVISOR) return '';

    static $scopes = [];
    $userId = (int)($user['id'] ?? 0);
    if (!array_key_exists($userId, $scopes)) {
        $scope = operationalDepartmentFromSite((string)($user['sede'] ?? $user['zona'] ?? ''));
        // Consultar la base evita exigir un nuevo inicio de sesión cuando un
        // administrador cambia el alcance territorial de un usuario conectado.
        $stmt = Database::getConnection()->prepare("SELECT sede,zona FROM usuarios WHERE id=:id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $dbUser = $stmt->fetch();
        if ($dbUser) $scope = operationalDepartmentFromSite((string)($dbUser['sede'] ?: $dbUser['zona']));
        $scopes[$userId] = mb_strtoupper($scope, 'UTF-8');
    }
    return $scopes[$userId];
}

/** ¿La orden es de la zona del supervisor (y de su área de trabajo, si la orden trae su clasificación)? Zona = departamento,
 *  salvo las provincias con zona propia (Norte Chico, 2026-10-27: config/territorial.php). */
function directSupervisorScopeAllows(array $user, array $supervision): bool {
    if (array_key_exists('clasificacion_supervision', $supervision) && !sgsAreaPermite((int)($user['id'] ?? 0), $supervision['clasificacion_supervision'])) return false;
    $scope = getSupervisorTerritorialScope($user);
    if ($scope === 'PROVINCIAS') return true;
    $department = sgsZonaDeUbicacion($supervision['departamento'] ?? '', $supervision['provincia'] ?? '');
    if ($department === '') return false;
    if ($scope !== '' && $department === $scope) return true;
    $stmt = Database::getConnection()->prepare("SELECT 1 FROM usuario_departamentos_acceso
        WHERE usuario_id=:usuario AND activo=1 AND UPPER(TRIM(departamento))=:departamento LIMIT 1");
    $stmt->execute([':usuario'=>(int)($user['id'] ?? 0), ':departamento'=>$department]);
    return (bool)$stmt->fetchColumn();
}

function pendingTerritorialCondition(string $alias, string $scope, int $userId, string $accessParam, string $departmentParam, array &$params): string {
    if ($scope === 'PROVINCIAS') return "({$alias}.estado_supervision = 'Pendiente' AND " . sgsAreaSql($alias, $userId) . ')';
    $params[$accessParam] = $userId;
    $zona = sgsZonaSql($alias);
    $scopeParts = ["EXISTS (SELECT 1 FROM usuario_departamentos_acceso uda WHERE uda.usuario_id={$accessParam} AND uda.activo=1 AND UPPER(TRIM(uda.departamento))={$zona})"];
    if ($scope !== '') {
        $params[$departmentParam] = $scope;
        array_unshift($scopeParts, "{$zona}={$departmentParam}");
    }
    // Área de trabajo (2026-10-27): el supervisor de Instalaciones no ve en la bolsa las pendientes de averías, y al revés
    return "({$alias}.estado_supervision = 'Pendiente' AND " . sgsAreaSql($alias, $userId) . ' AND (' . implode(' OR ', $scopeParts) . '))';
}

/** Mensaje al negar la edición: si otro supervisor ya tomó la pendiente de la bolsa común, se explica así. */
function mensajeSinAccesoSupervision(array $user, $supervision, string $porDefecto): string {
    $tomadaPorOtro = is_array($supervision) && ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR
        && (int)($supervision['supervisor_id'] ?? 0) !== (int)($user['id'] ?? 0)
        && ($supervision['estado_supervision'] ?? '') !== 'Pendiente';
    return $tomadaPorOtro
        ? 'Esta orden ya fue tomada por otro supervisor y salió de la lista de pendientes. Actualice la lista.'
        : $porDefecto;
}

function canAccessSupervision(array $user, array $supervision, bool $allowPending = false): bool {
    // Líder con zonas o área propias (2026-10-27): solo lo de sus zonas y su área
    if (Auth::can(Auth::PERM_SUPERVISION_VIEW_ALL, $user)) return sgsAlcancePermiteSupervision($user, $supervision);
    $isPendingPoolItem = $allowPending && ($supervision['estado_supervision'] ?? '') === 'Pendiente';
    if (Auth::isAdministrative()) return sgsAlcancePermiteSupervision($user, $supervision);
    if ($user['rol'] === Auth::ROLE_JEFE_OPERACIONES) {
        // Jefe de operaciones: consulta las órdenes asignadas a su partner (no las atiende).
        return (int)($supervision['partner_id'] ?? 0) === (int)($user['partner_id'] ?? 0) && !empty($supervision['fecha_asignacion']);
    }
    if ($user['rol'] === Auth::ROLE_SUPERVISOR) {
        $isOwner = (int)$supervision['supervisor_id'] === (int)$user['id'];
        if (empty($user['is_win_directo'])) {
            $samePartner = (int)($supervision['partner_id'] ?? 0) === (int)($user['partner_id'] ?? 0);
            return $samePartner && $isOwner && !empty($supervision['fecha_asignacion']);
        }
        // Todo lo ya gestionado pertenece al usuario aunque luego cambie de zona.
        // Solo las órdenes todavía pendientes se comparten por alcance territorial.
        // Rescate (2026-10-13): mientras está "En rescate" sale de la bolsa común; solo la trabaja quien la tomó.
        $esResponsableRescate = (int)($supervision['rescate_responsable_id'] ?? 0) === (int)$user['id'];
        if (($supervision['rescate_estado'] ?? '') === 'En rescate' || $esResponsableRescate) return $esResponsableRescate;
        return $isPendingPoolItem ? directSupervisorScopeAllows($user, $supervision) : $isOwner;
    }
    return false;
}

/**
 * Lado del partner (supervisor de partner y jefe de operaciones, 2026-10-27): revisa la orden sin saber qué partner la
 * canceló ni qué cuadrilla la atendió (técnicos y placa). Esos datos no se le envían (lista y detalle).
 */
function sgsSinOrigenParaPartner(array $user, array $fila): array {
    $ladoPartner = ($user['rol'] ?? '') === Auth::ROLE_JEFE_OPERACIONES
        || (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && empty($user['is_win_directo']) && !empty($user['partner_id']));
    if (!$ladoPartner) return $fila;
    foreach (['nombre_partner', 'tecnico1_id', 'tecnico1_nombre', 'tecnico1_dni', 'tecnico2_id', 'tecnico2_nombre', 'tecnico2_dni', 'vehiculo_id', 'unidad_placa'] as $c) {
        if (array_key_exists($c, $fila)) $fila[$c] = null;
    }
    return $fila;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'partner_update') {
    $isPartnerSupervisor = $user['rol'] === Auth::ROLE_SUPERVISOR && empty($user['is_win_directo']) && !empty($user['partner_id']);
    if (!$isPartnerSupervisor) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Esta acción corresponde únicamente al supervisor del partner.']); exit;
    }
    $supervisionId=(int)($_POST['supervision_id']??0);
    $stmt=$pdo->prepare('SELECT * FROM supervisiones WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$supervisionId]); $target=$stmt->fetch();
    if (!$target || !canAccessSupervision($user,$target,true)) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'La supervisión no está asignada a su usuario o no pertenece a su partner.']); exit;
    }
    if (($target['estado_supervision'] ?? '') !== 'Pendiente' || empty($target['fecha_asignacion'])) {
        http_response_code(422); echo json_encode(['status'=>'error','message'=>'Solo puede atender órdenes pendientes asignadas a su usuario.']); exit;
    }
    if (strtotime((string)$target['fecha_asignacion'].' +24 hours') < time()) {
        http_response_code(422); echo json_encode(['status'=>'error','message'=>'El plazo de atención de 24 horas ha vencido.']); exit;
    }
    $resumenResultado=trim((string)($_POST['observacion_atencion_partner']??''));
    if ($resumenResultado === '') { http_response_code(422); echo json_encode(['status'=>'error','message'=>'Ingrese la observación de la atención.']); exit; }
    // Rescate propuesto por el partner (2026-10-13): solo propone; el supervisor directo que la asignó decide pasarla a rescate.
    $rescatable = ($_POST['partner_rescatable'] ?? '0') === '1';
    $evidencias = [];
    if ($rescatable) {
        for ($i = 1; $i <= 3; $i++) {
            $clave = 'foto_rescate_' . $i;
            if (!isset($_FILES[$clave]) || ($_FILES[$clave]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            try {
                $ok = validateUploadedFile($_FILES[$clave], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1024 * 1024);
                $nombre = randomUploadName('rescate_partner_' . $supervisionId . '_', $ok['extension']);
                if (!move_uploaded_file($_FILES[$clave]['tmp_name'], UPLOAD_DIR . $nombre)) throw new RuntimeException('No se pudo guardar una evidencia.');
                $evidencias[] = UPLOAD_URL . $nombre;
            } catch (RuntimeException $e) { http_response_code(422); echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
        }
        if (!$evidencias) { http_response_code(422); echo json_encode(['status'=>'error','message'=>'Si el servicio se puede rescatar, suba al menos una foto de evidencia.']); exit; }
    }
    $pdo->beginTransaction();
    try {
    $update=$pdo->prepare("UPDATE supervisiones SET estado_atencion_partner='Atendida',observacion_atencion_partner=:resumen,fecha_atencion_partner=NOW(),partner_rescatable=:resc
      WHERE id=:id AND supervisor_id=:uid AND partner_id=:partner AND estado_supervision='Pendiente'");
    $update->execute([':resumen'=>$resumenResultado,':resc'=>$rescatable ? 1 : 0,':id'=>$supervisionId,':uid'=>(int)$user['id'],':partner'=>(int)$user['partner_id']]);
    if ($rescatable) {
        $propuesta = $pdo->prepare("INSERT INTO rescate_seguimientos (supervision_id, usuario_id, tipo, comentario, foto) VALUES (:s, :u, 'Propuesta del partner', :c, :f)");
        foreach ($evidencias as $i => $foto) $propuesta->execute([':s'=>$supervisionId, ':u'=>(int)$user['id'], ':c'=>$i === 0 ? $resumenResultado : null, ':f'=>$foto]);
        if (!empty($target['asignado_por'])) {
            $codigoAviso = $target['ticket_codigo'] ?: $target['codigo_supervision'];
            $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, 'Se puede rescatar', :m, :url)")
                ->execute([':u'=>(int)$target['asignado_por'], ':m'=>"El partner indica que la orden {$codigoAviso} se puede rescatar. Revise sus evidencias y pásela a rescate.",
                    ':url'=>'index.php?page=supervision_lista&highlight=' . $supervisionId]);
        }
    }
    $pdo->commit();
    } catch(Throwable $error) {
        if($pdo->inTransaction())$pdo->rollBack();
        http_response_code(422);echo json_encode(['status'=>'error','message'=>'No se pudo guardar la actualización: '.$error->getMessage()]);exit;
    }
    echo json_encode(['status'=>'success','message'=>$rescatable ? 'Atención registrada. Se avisó al supervisor de WIN que el servicio se puede rescatar.' : 'Atención registrada correctamente.','estado_atencion_partner'=>'Atendida'],JSON_UNESCAPED_UNICODE);exit;
}

if ($action === 'supervisores_partner') {
    $canAssignPartner = Auth::can(Auth::PERM_SUPERVISION_ASSIGN, $user)
        && (($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR
            || (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo'])));
    if (!$canAssignPartner) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Solo el administrador o un supervisor directo de WIN puede realizar asignaciones.']); exit;
    }
    $supervisionId = (int)($_GET['supervision_id'] ?? 0);
    $stmtOrder = $pdo->prepare("SELECT id,supervisor_id,partner_id,departamento,provincia,estado_supervision FROM supervisiones WHERE id=:id LIMIT 1");
    $stmtOrder->execute([':id'=>$supervisionId]);
    $order = $stmtOrder->fetch();
    if (!$order || $order['estado_supervision'] !== 'Pendiente' || !canAccessSupervision($user, $order, true)) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'La orden ya no está pendiente o no pertenece a una zona autorizada.']); exit;
    }
    $departmentSql = operationalDepartmentSql('u');
    $stmt = $pdo->prepare("SELECT u.id,u.nombre_completo,u.dni,u.sede,u.partner_id,p.nombre_partner
        FROM usuarios u JOIN partners p ON p.id=u.partner_id
        WHERE u.rol='Supervisor' AND u.activo=1 AND u.partner_id=:partner
          AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN','WIN DIRECTO')
          AND (UPPER(TRIM({$departmentSql}))=UPPER(TRIM(:departamento))
            OR EXISTS (SELECT 1 FROM usuario_departamentos_acceso uda
              WHERE uda.usuario_id=u.id AND uda.activo=1
                AND UPPER(TRIM(uda.departamento))=UPPER(TRIM(:departamento_acceso))))
        ORDER BY u.nombre_completo");
    $zonaOrden = sgsZonaDeUbicacion($order['departamento'], $order['provincia']);
    $stmt->execute([
        ':partner'=>(int)$order['partner_id'],
        ':departamento'=>$zonaOrden,
        ':departamento_acceso'=>$zonaOrden,
    ]);
    $partnerNameStmt = $pdo->prepare('SELECT nombre_partner FROM partners WHERE id=:id LIMIT 1');
    $partnerNameStmt->execute([':id'=>(int)$order['partner_id']]);
    echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(),'departamento'=>$order['departamento'],'partner'=>$partnerNameStmt->fetchColumn() ?: 'Sin partner'], JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'asignar_partner') {
    $isAdminAssigning = Auth::can(Auth::PERM_SUPERVISION_ASSIGN, $user)
        && ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR;
    $canAssignPartner = $isAdminAssigning || ($user['rol'] === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']));
    if (!$canAssignPartner) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Solo el administrador o un supervisor directo de WIN puede realizar asignaciones.']); exit;
    }
    $supervisionId = (int)($_POST['supervision_id'] ?? 0);
    $partnerSupervisorId = (int)($_POST['supervisor_partner_id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmtOrder = $pdo->prepare("SELECT id,codigo_supervision,supervisor_id,partner_id,departamento,provincia,estado_supervision,rescate_estado FROM supervisiones WHERE id=:id FOR UPDATE");
        $stmtOrder->execute([':id'=>$supervisionId]);
        $order = $stmtOrder->fetch();
        if ($order && ($order['rescate_estado'] ?? '') === 'En rescate') {
            throw new RuntimeException('La orden está en rescate: no se puede asignar a un partner mientras dure el rescate.');
        }
        if (!$order || $order['estado_supervision'] !== 'Pendiente' || (!$isAdminAssigning && !canAccessSupervision($user, $order, true))) {
            throw new RuntimeException('La orden ya no está pendiente o no pertenece a una zona autorizada.');
        }
        $departmentSql = operationalDepartmentSql('u');
        $stmtTarget = $pdo->prepare("SELECT u.id,u.partner_id,p.nombre_partner FROM usuarios u JOIN partners p ON p.id=u.partner_id
            WHERE u.id=:id AND u.rol='Supervisor' AND u.activo=1 AND u.partner_id=:partner
              AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN','WIN DIRECTO')
              AND (UPPER(TRIM({$departmentSql}))=UPPER(TRIM(:departamento))
                OR EXISTS (SELECT 1 FROM usuario_departamentos_acceso uda WHERE uda.usuario_id=u.id AND uda.activo=1
                  AND UPPER(TRIM(uda.departamento))=UPPER(TRIM(:departamento_acceso)))) LIMIT 1"); // 2026-10-13: faltaba cerrar el paréntesis del AND (la asignación fallaba con error de SQL)
        $zonaOrden = sgsZonaDeUbicacion($order['departamento'], $order['provincia']);
        $stmtTarget->execute([':id'=>$partnerSupervisorId,':partner'=>(int)$order['partner_id'],':departamento'=>$zonaOrden,':departamento_acceso'=>$zonaOrden]);
        $target = $stmtTarget->fetch();
        if (!$target) throw new RuntimeException('El supervisor seleccionado no pertenece al partner y departamento de la orden.');
        $stmtUpdate = $pdo->prepare("UPDATE supervisiones SET supervisor_id=:destino,partner_id=:partner,asignado_por=:origen,fecha_asignacion=NOW(),estado_atencion_partner='Pendiente',observacion_atencion_partner=NULL,fecha_atencion_partner=NULL WHERE id=:id AND estado_supervision='Pendiente'");
        $stmtUpdate->execute([':destino'=>$partnerSupervisorId, ':partner'=>(int)$target['partner_id'], ':origen'=>(int)$user['id'], ':id'=>$supervisionId]);
        $stmtNotify = $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,url_destino) VALUES (:usuario,'Nueva supervisión asignada',:mensaje,:url)");
        $stmtNotify->execute([':usuario'=>$partnerSupervisorId, ':mensaje'=>'Se le asignó la orden pendiente '.$order['codigo_supervision'].' para revisión.', ':url'=>'index.php?page=supervision_lista&highlight='.$supervisionId]);
        $pdo->commit();
        echo json_encode(['status'=>'success','message'=>'Orden asignada correctamente a '.$target['nombre_partner'].'.'], JSON_UNESCAPED_UNICODE); exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422); echo json_encode(['status'=>'error','message'=>'No se pudo asignar la orden: '.$error->getMessage()]); exit;
    }
}

// 1. Guardar Nueva Supervisión (Flujo 4 Pasos)
// ---------- Rescate de pendientes (2026-10-13) ----------
// Tomar: un supervisor directo toma una pendiente de su zona (no asignada a partner) y le da seguimiento sin plazo.
// Avance: comentario y foto opcional. Cerrar: Rescatado / No rescatado (la pendiente queda Finalizada).
// Reasignar: solo el administrador, a otro supervisor directo que trabaje esa zona.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['rescate_tomar', 'rescate_avance', 'rescate_cerrar', 'rescate_reasignar'], true)) {
    $esDirecto = ($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo']);
    $esAdmin = ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR;
    $id = (int)($_POST['supervision_id'] ?? 0);
    $comentario = trim((string)($_POST['comentario'] ?? ''));
    $responder = static function (int $code, string $msg) { http_response_code($code); echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE); exit; };
    $seguimiento = $pdo->prepare('INSERT INTO rescate_seguimientos (supervision_id, usuario_id, tipo, comentario, foto) VALUES (:s, :u, :t, :c, :f)');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM supervisiones WHERE id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $orden = $st->fetch();
        if (!$orden) $responder(404, 'La orden no existe.');
        $codigo = $orden['ticket_codigo'] ?: $orden['codigo_supervision'];

        if ($action === 'rescate_tomar') {
            if (!$esDirecto) $responder(403, 'Solo un supervisor directo puede tomar una pendiente como rescate.');
            if ($orden['estado_supervision'] !== 'Pendiente' || ($orden['rescate_estado'] ?? '') !== '' && $orden['rescate_estado'] !== null) {
                $responder(409, ($orden['rescate_estado'] ?? '') === 'En rescate' ? 'Esta pendiente ya está en rescate con otro supervisor.' : 'La orden ya no está pendiente. Actualice la lista.');
            }
            if (!empty($orden['fecha_asignacion'])) {
                // Asignada a un partner: solo si el partner la marcó como rescatable, y la pasa a rescate quien la asignó.
                // El supervisor asignado (del partner) no cambia, para no alterar su cumplimiento de 24 horas.
                if ((int)($orden['partner_rescatable'] ?? 0) !== 1 || ($orden['estado_atencion_partner'] ?? '') !== 'Atendida') {
                    $responder(409, 'La orden está asignada a un partner: solo pasa a rescate cuando el partner la atiende e indica que se puede rescatar.');
                }
                if ((int)$orden['asignado_por'] !== (int)$user['id']) $responder(403, 'Solo el supervisor que la asignó al partner puede pasarla a rescate.');
                $pdo->prepare("UPDATE supervisiones SET rescate_responsable_id = :u, rescate_estado = 'En rescate', rescate_inicio = NOW(), rescate_fin = NULL WHERE id = :id")
                    ->execute([':u' => (int)$user['id'], ':id' => $id]);
            } else {
                if (!directSupervisorScopeAllows($user, $orden)) $responder(403, 'La orden no pertenece a sus zonas de trabajo.');
                $pdo->prepare("UPDATE supervisiones SET supervisor_id = :u, rescate_responsable_id = :r, rescate_estado = 'En rescate', rescate_inicio = NOW(), rescate_fin = NULL WHERE id = :id")
                    ->execute([':u' => (int)$user['id'], ':r' => (int)$user['id'], ':id' => $id]);
            }
            $seguimiento->execute([':s' => $id, ':u' => (int)$user['id'], ':t' => 'Inicio', ':c' => $comentario ?: null, ':f' => null]);
            $mensaje = "Tomaste la pendiente {$codigo} como rescate. Registra los avances hasta cerrarla.";
        } else {
            if (($orden['rescate_estado'] ?? '') !== 'En rescate') $responder(409, 'Esta orden no tiene un rescate en curso.');
            $esDueno = (int)($orden['rescate_responsable_id'] ?? 0) === (int)$user['id'];
            if ($action === 'rescate_reasignar') {
                if (!$esAdmin) $responder(403, 'Solo el administrador puede reasignar un rescate.');
                $destino = (int)($_POST['supervisor_id'] ?? 0);
                if ($destino === (int)($orden['rescate_responsable_id'] ?? 0)) $responder(422, 'Elija un supervisor distinto al actual.');
                $sd = $pdo->prepare('SELECT u.id, u.nombre_completo, u.rol, u.activo, u.partner_id, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id WHERE u.id = :id');
                $sd->execute([':id' => $destino]);
                $dest = $sd->fetch();
                $destDirecto = $dest && $dest['rol'] === Auth::ROLE_SUPERVISOR && (int)$dest['activo'] === 1
                    && (empty($dest['partner_id']) || in_array(mb_strtoupper(trim((string)$dest['nombre_partner']), 'UTF-8'), ['WIN', 'WIN DIRECTO'], true));
                if (!$destDirecto) $responder(422, 'El rescate solo se puede reasignar a un supervisor directo activo.');
                $zonaOrden = sgsZonaDeUbicacion($orden['departamento'], $orden['provincia']);
                if (!in_array($zonaOrden, sgsUserZones($destino) ?? [$zonaOrden], true) && sgsUserZones($destino) !== []) {
                    $responder(422, 'El supervisor elegido no trabaja la zona de la orden (' . $zonaOrden . ').');
                }
                $sa = $pdo->prepare('SELECT nombre_completo FROM usuarios WHERE id = :id'); $sa->execute([':id' => (int)($orden['rescate_responsable_id'] ?? 0)]);
                $anterior = (string)$sa->fetchColumn();
                // En una orden de partner el supervisor asignado no cambia (solo el responsable del rescate).
                $pdo->prepare('UPDATE supervisiones SET rescate_responsable_id = :u' . (empty($orden['fecha_asignacion']) ? ', supervisor_id = :u2' : '') . ' WHERE id = :id')
                    ->execute(empty($orden['fecha_asignacion']) ? [':u' => $destino, ':u2' => $destino, ':id' => $id] : [':u' => $destino, ':id' => $id]);
                $seguimiento->execute([':s' => $id, ':u' => (int)$user['id'], ':t' => 'Reasignación',
                    ':c' => "Reasignado de {$anterior} a {$dest['nombre_completo']}" . ($comentario !== '' ? ". {$comentario}" : '.'), ':f' => null]);
                $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, 'Rescate asignado', :m, :url)")
                    ->execute([':u' => $destino, ':m' => "Se te asignó el rescate de la pendiente {$codigo}.", ':url' => 'index.php?page=supervision_lista&highlight=' . $id]);
                $mensaje = "Rescate reasignado a {$dest['nombre_completo']}.";
            } elseif (!$esDueno && !$esAdmin) {
                $responder(403, 'Solo el supervisor que tomó el rescate puede registrar avances o cerrarlo.');
            } elseif ($action === 'rescate_avance') {
                $foto = null;
                if (isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    try {
                        $ok = validateUploadedFile($_FILES['foto'], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8 * 1024 * 1024);
                        $nombre = randomUploadName('rescate_' . $id . '_', $ok['extension']);
                        if (!move_uploaded_file($_FILES['foto']['tmp_name'], UPLOAD_DIR . $nombre)) throw new RuntimeException('No se pudo guardar la foto.');
                        $foto = UPLOAD_URL . $nombre;
                    } catch (RuntimeException $e) { $responder(422, $e->getMessage()); }
                }
                if ($comentario === '' && $foto === null) $responder(422, 'Escriba el avance del rescate (o adjunte una foto).');
                $seguimiento->execute([':s' => $id, ':u' => (int)$user['id'], ':t' => 'Avance', ':c' => $comentario ?: null, ':f' => $foto]);
                $mensaje = 'Avance registrado.';
            } else { // rescate_cerrar
                $resultado = (string)($_POST['resultado'] ?? '');
                if (!in_array($resultado, ['Rescatado', 'No rescatado'], true)) $responder(422, 'Indique si el servicio se rescató o no.');
                if ($comentario === '') $responder(422, 'Escriba el comentario de cierre del rescate.');
                $pdo->prepare("UPDATE supervisiones SET rescate_estado = :r, rescate_fin = NOW(), estado_supervision = 'Finalizada',
                        estado_cancelacion = CASE WHEN es_cancelacion = 1 THEN (CASE WHEN :r2 = 'Rescatado' THEN 'Rescate' ELSE 'Cancelación correcta' END) ELSE estado_cancelacion END,
                        observacion_servicio = CASE WHEN COALESCE(observacion_servicio,'') = '' THEN :c ELSE observacion_servicio END WHERE id = :id")
                    ->execute([':r' => $resultado, ':r2' => $resultado, ':c' => "Rescate: {$resultado}. {$comentario}", ':id' => $id]);
                $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por, comentario) VALUES (:s, 'Pendiente', 'Finalizada', :u, :c)")
                    ->execute([':s' => $id, ':u' => (int)$user['id'], ':c' => "Cierre de rescate: {$resultado}"]);
                $seguimiento->execute([':s' => $id, ':u' => (int)$user['id'], ':t' => 'Cierre', ':c' => "{$resultado}. {$comentario}", ':f' => null]);
                $mensaje = $resultado === 'Rescatado' ? "Rescate cerrado: se rescató el servicio {$codigo}." : "Rescate cerrado: no se pudo rescatar {$codigo}.";
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    win_log_error('audit_rescate', $mensaje, ['supervision_id' => $id, 'accion' => $action, 'actor_id' => (int)$user['id']]);
    echo json_encode(['status' => 'success', 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $workflowUser = $user;
    $supervisionOwnerId = (int)($workflowUser['id'] ?? 0);
    $canCreateOperational = ($workflowUser['rol'] ?? '') === Auth::ROLE_SUPERVISOR
        && !empty($workflowUser['is_win_directo'])
        && Auth::can(Auth::PERM_SUPERVISION_CREATE, $workflowUser);
    // Corrección por el administrador (2026-10-15): solo el rol Administrador y solo sobre una supervisión ya
    // FINALIZADA. Sigue finalizada, a nombre del supervisor que la hizo y con su fecha; queda en la auditoría.
    $entradaPrevia = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $correccionId = (int)($entradaPrevia['supervision_id_edit'] ?? $entradaPrevia['supervision_id'] ?? 0);
    $esCorreccionAdmin = false;
    if (!$canCreateOperational && ($workflowUser['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR && $correccionId > 0) {
        $stmtCorr = $pdo->prepare('SELECT estado_supervision FROM supervisiones WHERE id = :id');
        $stmtCorr->execute([':id' => $correccionId]);
        $esCorreccionAdmin = in_array($stmtCorr->fetchColumn(), ['Finalizada', 'Culminada', 'Finalizado'], true);
    }
    if (!$canCreateOperational && !$esCorreccionAdmin) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'Las supervisiones operativas solo pueden ser registradas por un supervisor directo. El administrador puede visualizar, importar y asignar.']);
        exit;
    }
    if (($workflowUser['rol'] === Auth::ROLE_JEFE_OPERACIONES) || ($workflowUser['rol'] === Auth::ROLE_SUPERVISOR && empty($workflowUser['is_win_directo']))) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'El supervisor del partner no puede crear supervisiones. Solo puede atender las asignadas actualizando GPS, fotos y comentarios.']); exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Validar turno activo (Solo para Supervisores)
    if (($workflowUser['rol'] ?? '') === Auth::ROLE_SUPERVISOR) {
        $stmtTurno = $pdo->prepare("SELECT id, fin_turno FROM asistencia_supervisores WHERE supervisor_id = :sup_id AND fecha = CURRENT_DATE() LIMIT 1");
        $stmtTurno->execute([':sup_id' => $workflowUser['id']]);
        $turnoHoy = $stmtTurno->fetch();
        if (!$turnoHoy) {
            http_response_code(403);
            echo json_encode(['status'=>'error','message'=>'No puedes iniciar ni trabajar en una supervisión porque no has iniciado tu turno de hoy. Ve al panel de control y registra el inicio de tu turno.']);
            exit;
        }
        if (!empty($turnoHoy['fin_turno'])) {
            http_response_code(403);
            echo json_encode(['status'=>'error','message'=>'Ya finalizaste tu turno de hoy. No puedes seguir trabajando en supervisiones.']);
            exit;
        }
    }
    $canUseFindingsForm = Auth::can(Auth::PERM_FORM_FINDINGS, $workflowUser);
    $canUseChecklistForm = Auth::can(Auth::PERM_FORM_CHECKLIST, $workflowUser);
    if (!$canUseFindingsForm && !empty($input['chk_incluir_observacion'])) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Su usuario no tiene habilitado el formulario de hallazgos.']); exit;
    }
    if (!$canUseChecklistForm && (!empty($input['chk_incluir_checklist']) || ($input['tipo_supervision'] ?? '') === 'Checklist')) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Su usuario no tiene habilitado el checklist técnico.']); exit;
    }
    $requestedWorkflowState = (string)($input['estado_supervision'] ?? 'En proceso');
    if ($esCorreccionAdmin) $requestedWorkflowState = 'Finalizada'; // la corrección no reabre la supervisión
    if (in_array($requestedWorkflowState, ['Culminada','Finalizado'], true)) $requestedWorkflowState = 'Finalizada';
    // Guardado automático (2026-10-16): mientras está En proceso se guarda lo que haya (sin exigir ticket ni partner);
    // todo lo obligatorio se exige al finalizar.
    $esGuardadoParcial = $requestedWorkflowState === 'En proceso';
    $isInitialMinimalSave = $requestedWorkflowState === 'En proceso'
        && (int)($input['supervision_id_edit'] ?? $input['supervision_id'] ?? 0) === 0;

    // Validar con los datos bloqueados del registro, no con valores alterados en el navegador.
    $requestedEditId = (int)($input['supervision_id_edit'] ?? $input['supervision_id'] ?? 0);
    $targetRecord = null;
    if ($requestedEditId > 0) {
        $targetQuery = $pdo->prepare('SELECT * FROM supervisiones WHERE id=:id');
        $targetQuery->execute([':id'=>$requestedEditId]);
        $targetRecord = $targetQuery->fetch();
        if (!$targetRecord || !canAccessSupervision($user, $targetRecord, true)) {
            http_response_code(403); echo json_encode(['status'=>'error','message'=>mensajeSinAccesoSupervision($user, $targetRecord, 'No puede editar esta supervisión.')]); exit;
        }
        if (($targetRecord['rescate_estado'] ?? '') === 'En rescate') {
            http_response_code(409);
            echo json_encode(['status'=>'error','message'=>'Esta pendiente está en rescate: registre los avances o ciérrela desde el rescate.']); exit;
        }
    }

    // Guardado automático de una supervisión ya creada: lo que aún no se eligió en la sección 1 conserva lo guardado.
    if ($esGuardadoParcial && $targetRecord) {
        $desdeRegistro = ['tipo_supervision' => 'clasificacion_supervision', 'derivacion_orden' => 'derivacion_orden', 'tipo_servicio' => 'tipo_servicio',
            'tipo_supervision_inicial' => 'tipo_supervision_inicial', 'departamento' => 'departamento', 'provincia' => 'provincia', 'distrito' => 'distrito'];
        // Orden cargada por Excel: su ticket y su partner no cambian.
        if (!empty($targetRecord['es_cancelacion']) && !empty($targetRecord['origen_importacion'])) $desdeRegistro += ['ticket_codigo' => 'ticket_codigo', 'partner_id' => 'partner_id'];
        foreach ($desdeRegistro as $campo => $columna) {
            if (trim((string)($input[$campo] ?? '')) === '') $input[$campo] = (string)($targetRecord[$columna] ?? '');
        }
    }

    // Generar código de supervisión único (SUP-AAAAMMDD-RAND)
    $codigoSupervision = 'SUP-' . date('Ymd') . '-' . rand(1000, 9999);

    // PASO 1: Datos Generales
    $derivacionOrden = !empty($input['derivacion_orden']) ? $input['derivacion_orden'] : 'Autogestión';
    $tipoServicio = !empty($input['tipo_servicio']) ? $input['tipo_servicio'] : 'Regular';
    $departamento = !empty($input['departamento']) ? $input['departamento'] : 'LIMA';
    $provincia = !empty($input['provincia']) ? $input['provincia'] : 'LIMA';
    $distrito = !empty($input['distrito']) ? $input['distrito'] : 'LIMA';
    if ($requestedEditId === 0
        && $workflowUser['rol'] === Auth::ROLE_SUPERVISOR
        && !empty($workflowUser['is_win_directo'])
        && !directSupervisorScopeAllows($workflowUser, ['departamento' => $departamento, 'provincia' => $provincia])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'El departamento seleccionado está fuera del alcance territorial de su usuario.']);
        exit;
    }
    if ($requestedEditId === 0) {
        $stmtValidUbigeo = $pdo->prepare('SELECT 1 FROM ubigeo WHERE departamento=:departamento AND provincia=:provincia AND distrito=:distrito LIMIT 1');
        $stmtValidUbigeo->execute([
            ':departamento' => $departamento,
            ':provincia' => $provincia,
            ':distrito' => $distrito,
        ]);
        if (!$stmtValidUbigeo->fetchColumn()) {
            http_response_code(422);
            echo json_encode(['status'=>'error','message'=>'La provincia y el distrito no pertenecen al departamento autorizado. Seleccione nuevamente la ubicación.']);
            exit;
        }
    }
    $latitud = !empty($input['latitud']) ? $input['latitud'] : null;
    $longitud = !empty($input['longitud']) ? $input['longitud'] : null;
    // Coordenadas (2026-10-15): pueden escribirse a mano (supervisión remota). Se aceptan con coma decimal y se
    // validan: números, latitud entre -90 y 90, longitud entre -180 y 180, y las dos juntas.
    foreach (['latitud', 'longitud'] as $eje) {
        if ($$eje === null) continue;
        $valor = str_replace(',', '.', trim((string)$$eje));
        $limite = $eje === 'latitud' ? 90 : 180;
        if (!preg_match('/^-?\d{1,3}(\.\d{1,10})?$/', $valor) || abs((float)$valor) > $limite) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'field' => $eje, 'message' => "La {$eje} no es válida. Escríbala como número, por ejemplo " . ($eje === 'latitud' ? '-8.139075' : '-79.055686') . '.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $$eje = $valor;
    }
    if (($latitud === null) !== ($longitud === null)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'field' => $latitud === null ? 'latitud' : 'longitud', 'message' => 'Escriba la latitud y la longitud juntas, o deje ambas vacías.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // PASO 2: Solo se solicita el código de pedido. Los campos del cliente se
    // conservan en la base para una posible reactivación futura.
    $clienteNombre = trim($input['cliente_nombre'] ?? '');
    $clienteDni = trim($input['cliente_dni'] ?? '');
    $ticketCodigo = trim($input['ticket_codigo'] ?? '');
    $editId = intval($input['supervision_id_edit'] ?? $input['supervision_id'] ?? 0);
    $tipoSolicitado = $input['tipo_supervision'] ?? $input['clasificacion_supervision'] ?? 'Instalación';
    $tiposSinPedido = ['Incidencia / Casos atípicos', 'Proyecto de ordenamiento', 'Visita partner por capacitación', 'Conjunta PEXT', 'Checklist'];
    $tiposSinPartner = ['Incidencia / Casos atípicos'];
    $tiposSinCuadrilla = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'];
    $tiposConSubtipo = ['Postventa', 'Avería', 'Reiterada', 'Garantía'];
    $isImportedCancellationEdit = $requestedEditId > 0
        && !empty($targetRecord['es_cancelacion'])
        && !empty($targetRecord['origen_importacion']);

    $camposGeneralesObligatorios = [
        'tipo_supervision',
        'derivacion_orden',
        'tipo_servicio',
        'departamento',
        'provincia',
        'distrito'
    ];
    foreach ($camposGeneralesObligatorios as $campoGeneral) {
        if ($esGuardadoParcial && $targetRecord) break; // se completa al finalizar
        if (trim((string)($input[$campoGeneral] ?? '')) === '') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Complete todos los datos de Información general de la supervisión para iniciar.'
            ]);
            exit;
        }
    }
    if (!in_array($tipoSolicitado, ['Checklist', 'Visita partner por capacitación'], true) && !($esGuardadoParcial && $targetRecord)
        && trim((string)($input['tipo_supervision_inicial'] ?? '')) === '') {
        echo json_encode([
            'status' => 'error',
            'message' => 'Complete todos los datos de Información general de la supervisión para iniciar.'
        ]);
        exit;
    }

    if (!$esGuardadoParcial && !in_array($tipoSolicitado, $tiposSinPedido, true) && empty($ticketCodigo)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Coloque el número de pedido o ticket para iniciar la supervisión.'
        ]);
        exit;
    }
    if (in_array($tipoSolicitado, $tiposSinPedido, true)) {
        $ticketCodigo = null;
        $clienteNombre = null;
        $clienteDni = null;
    }

    // Evitar duplicados (2026-10-13): si el código ya fue cargado como pendiente, se atiende esa orden, no se crea otra.
    // Se compara sin espacios ni mayúsculas, como en la carga de cancelaciones.
    if (!empty($ticketCodigo)) {
        $stmtPend = $pdo->prepare("SELECT id, codigo_supervision, ticket_codigo, departamento FROM supervisiones
            WHERE estado_supervision = 'Pendiente' AND id <> :id
              AND UPPER(REPLACE(REPLACE(REPLACE(ticket_codigo, ' ', ''), '\t', ''), '\n', '')) = :ticket LIMIT 1");
        $stmtPend->execute([':id' => $editId, ':ticket' => mb_strtoupper(preg_replace('/\s+/u', '', $ticketCodigo), 'UTF-8')]);
        if ($pend = $stmtPend->fetch()) {
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'codigo' => 'en_pendientes',
                'pendiente_id' => (int)$pend['id'],
                'message' => "El código {$pend['ticket_codigo']} se encuentra en Pendientes" . ($pend['departamento'] ? " ({$pend['departamento']})" : '')
                    . '. No se guardó para evitar duplicarla: búsquela en Pendientes y atiéndala desde ahí.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($editId <= 0 && $supervisionOwnerId > 0) {
        // REGLA DE NEGOCIO: Un supervisor solo puede tener UNA sola supervisión en proceso a la vez
        $stmtCheckActive = $pdo->prepare("
            SELECT id, codigo_supervision
            FROM supervisiones
            WHERE supervisor_id = :sup_id
              AND estado_supervision IN ('Iniciada', 'En proceso')
            LIMIT 1
        ");
        $stmtCheckActive->execute([':sup_id' => $supervisionOwnerId]);
        $activeSup = $stmtCheckActive->fetch();

        if ($activeSup) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Restricción operativa: Ya tienes una supervisión en proceso (Código: ' . htmlspecialchars($activeSup['codigo_supervision']) . '). Debes finalizarla o culminarla antes de iniciar otra.'
            ]);
            exit;
        }

    }

    // PASO 3: Cuadrilla y Recursos
    $tipoSupervisionInicial = $input['tipo_supervision_inicial'] ?? 'En caliente';
    if (!in_array($tipoSupervisionInicial, ['En caliente', 'En frío', 'Remota'], true)) $tipoSupervisionInicial = 'En caliente';
    if ($tipoSolicitado === 'Checklist') $tipoSupervisionInicial = 'En caliente';
    // Supervisión remota (2026-10-13): por videollamada. Basta la modalidad: no pide técnicos, vehículo ni GPS (solo el partner).
    $partnerId = intval($input['partner_id'] ?? 0);
    if (in_array($tipoSolicitado, $tiposSinPartner, true)) {
        $partnerId = null;
    } elseif ($partnerId <= 0 && !$esGuardadoParcial) {
        echo json_encode(['status' => 'error', 'message' => 'Seleccione el partner o contrata para este tipo de supervisión.']);
        exit;
    } elseif ($partnerId <= 0) {
        $partnerId = null;
    }
    if ($partnerId !== null && $partnerId <= 0) {
        $stmtP = $pdo->query("SELECT id FROM partners WHERE estado = 1 ORDER BY id ASC LIMIT 1");
        $partnerId = intval($stmtP->fetchColumn() ?: 1);
    }
    $sinCuadrilla = in_array($tipoSupervisionInicial, ['En frío', 'Remota'], true) || in_array($tipoSolicitado, $tiposSinCuadrilla, true);
    $tecnico1Nombre = !$sinCuadrilla && !empty($input['tecnico1_nombre']) ? $input['tecnico1_nombre'] : null;
    $tecnico1Dni = !$sinCuadrilla && !empty($input['tecnico1_dni']) ? $input['tecnico1_dni'] : null;
    $tecnico2Nombre = !$sinCuadrilla ? ($input['tecnico2_nombre'] ?? null) : null;
    $tecnico2Dni = !$sinCuadrilla ? ($input['tecnico2_dni'] ?? null) : null;
    // Guardado automático (2026-10-19): si un técnico no llega (su DNI aún está incompleto en pantalla), se conserva el
    // que ya estaba guardado. Antes se borraba sin avisar. Un T2 quitado a propósito sí llega (vacío) y se quita.
    if ($esGuardadoParcial && $targetRecord && !$sinCuadrilla) {
        foreach ([1, 2] as $nTec) {
            if (array_key_exists("tecnico{$nTec}_nombre", $input)) continue;
            ${"tecnico{$nTec}Nombre"} = $targetRecord["tecnico{$nTec}_nombre"] ?? null;
            ${"tecnico{$nTec}Dni"} = $targetRecord["tecnico{$nTec}_dni"] ?? null;
        }
    }
    $brevetePoseedor = $input['brevete_poseedor'] ?? 'Tecnico 1';
    $unidadTipo = !$sinCuadrilla ? ($input['unidad_tipo'] ?? null) : null;
    $unidadPlaca = !$sinCuadrilla && !empty($input['unidad_placa']) ? strtoupper(trim($input['unidad_placa'])) : null;
    $unidadSoat = !$sinCuadrilla ? ($input['unidad_soat'] ?? null) : null;
    $unidadRevisionTecnica = !$sinCuadrilla ? ($input['unidad_revision_tecnica'] ?? null) : null;
    $unidadEstado = !$sinCuadrilla ? ($input['unidad_estado_conservacion'] ?? null) : null;

    // PASO 4: Servicio y Observaciones
    $rawTipoSup = $tipoSolicitado;
    $clasificacion = $rawTipoSup;
    $areaServicio = $rawTipoSup;
    $subTipoAtencion = trim($input['sub_tipo_atencion'] ?? '') ?: null;
    if ($rawTipoSup === 'Postventa' && $subTipoAtencion === null && !$isImportedCancellationEdit && !$esGuardadoParcial) {
        echo json_encode(['status' => 'error', 'message' => 'Seleccione el trabajo de Postventa.']);
        exit;
    }
    $observacionServicio = $input['observacion_servicio'] ?? '';
    if ($clasificacion === 'Checklist') {
        $tipoSupervisionInicial = 'En caliente'; // Valor técnico interno; no se solicita al usuario.
        $observacionServicio = '';
    }
    $estadoSupervision = $requestedWorkflowState;
    if (in_array($estadoSupervision, ['Finalizado', 'Culminada'], true)) $estadoSupervision = 'Finalizada';
    if (!in_array($estadoSupervision, ['Pendiente','Iniciada','En proceso','Finalizada'], true)) {
        http_response_code(422); echo json_encode(['status'=>'error','message'=>'Estado de supervisión no válido.']); exit;
    }
    // Resultado de la validación de una cancelación cargada (2026-10-13): "Cancelación correcta",
    // "En proceso de rescate" (pasa a rescate con seguimiento; no se finaliza aquí) o "Rescate" (se rescató al validar).
    // 2026-10-15: ya no hay valor por defecto; el supervisor debe elegirlo para finalizar (guardar avance no lo exige).
    $resultadoCancelacion = $isImportedCancellationEdit && in_array($input['resultado_cancelacion'] ?? '', ['Cancelación correcta', 'En proceso de rescate', 'Rescate'], true)
        ? $input['resultado_cancelacion'] : null;
    if ($resultadoCancelacion === 'En proceso de rescate' && $estadoSupervision === 'Finalizada') $estadoSupervision = 'En proceso';
    $esCierreDefinitivo = $estadoSupervision === 'Finalizada';
    if ($isImportedCancellationEdit && $esCierreDefinitivo && $resultadoCancelacion === null
        && !in_array($targetRecord['estado_supervision'] ?? '', ['Finalizada', 'Culminada', 'Finalizado'], true)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'field' => 'resultado_cancelacion', 'message' => 'Antes de finalizar, seleccione el resultado de la validación.']);
        exit;
    }
    if (in_array($rawTipoSup, ['Avería', 'Reiterada', 'Garantía'], true) && $esCierreDefinitivo && $subTipoAtencion === null && !$isImportedCancellationEdit) {
        echo json_encode(['status' => 'error', 'message' => 'Antes de finalizar, seleccione el resultado o cierre técnico encontrado.']);
        exit;
    }
    $editId = intval($input['supervision_id_edit'] ?? $input['supervision_id'] ?? 0);
    
    // Estado del servicio en caliente vs frío
    try {
        $estadoCaliente = estadoOrdenPresencial($tipoSupervisionInicial, $clasificacion, $input['estado_servicio_caliente'] ?? null, $esCierreDefinitivo);
    } catch (RuntimeException $error) {
        http_response_code(422); echo json_encode(['status'=>'error','message'=>$error->getMessage()]); exit;
    }

    // La cancelación observada del servicio es independiente del avance de la supervisión.
    $esCancelacion = ($areaServicio === 'Cancelación' || !empty($input['es_cancelacion']) || $estadoCaliente === 'Cancelada') ? 1 : 0;
    $tipificacionCancelacion = !empty($input['tipificacion_cancelacion']) ? $input['tipificacion_cancelacion'] : null;
    $resultadoTipificacionRaw = (string)($input['tipificacion_tecnico_correcta'] ?? '');
    $tipificacionCorrecta = in_array($resultadoTipificacionRaw, ['0', '1'], true) ? intval($resultadoTipificacionRaw) : null;
    $tipificacionCorregida = $input['tipificacion_corregida_supervisor'] ?? null;
    $estadoCancelacion = trim((string)($input['estado_cancelacion'] ?? '')) ?: null;
    // Las tipificaciones vienen del catálogo (clave foránea en la base): se valida antes para dar un mensaje claro.
    // Devuelve el texto exacto del catálogo, null si viene vacía, o false si no existe.
    $tipificacionDelCatalogo = static function ($valor) use ($pdo) {
        $valor = trim((string)$valor);
        if ($valor === '') return null;
        $stmt = $pdo->prepare('SELECT descripcion FROM tipificaciones_cancelacion WHERE descripcion = :d LIMIT 1');
        $stmt->execute([':d' => $valor]);
        return $stmt->fetchColumn();
    };
    $tipificacionCancelacion = $tipificacionDelCatalogo($tipificacionCancelacion);
    $tipificacionCorregida = $tipificacionDelCatalogo($tipificacionCorregida);
    if ($tipificacionCancelacion === false || $tipificacionCorregida === false) {
        echo json_encode(['status' => 'error', 'message' => 'La tipificación no existe en el catálogo. Elíjala de la lista.']);
        exit;
    }

    if ($isImportedCancellationEdit) {
        // La información de origen de la cancelación es solo informativa.
        $estadoCancelacion = $targetRecord['estado_cancelacion'] ?? null;
        $tipificacionCorrecta = $targetRecord['tipificacion_tecnico_correcta'] ?? null;
        $tipificacionCorregida = $targetRecord['tipificacion_corregida_supervisor'] ?? null;
        $esCierreDefinitivo = $estadoSupervision === 'Finalizada';
    }

    if ($esCierreDefinitivo) {
        $tieneFotoServicioNueva = false;
        for ($fotoServicioNumero = 1; $fotoServicioNumero <= 3; $fotoServicioNumero++) {
            $fotoServicioClave = 'foto_servicio_' . $fotoServicioNumero;
            if (isset($_FILES[$fotoServicioClave]) && $_FILES[$fotoServicioClave]['error'] === UPLOAD_ERR_OK) {
                $tieneFotoServicioNueva = true;
                break;
            }
        }
        $tieneFotoServicioGuardada = false;
        if ($editId > 0) {
            $stmtFotoExistente = $pdo->prepare("SELECT 1 FROM supervisiones WHERE id = :id AND (foto_servicio_1 IS NOT NULL OR foto_servicio_2 IS NOT NULL OR foto_servicio_3 IS NOT NULL) LIMIT 1");
            $stmtFotoExistente->execute([':id' => $editId]);
            $tieneFotoServicioGuardada = (bool)$stmtFotoExistente->fetchColumn();
        }
        if ($clasificacion !== 'Checklist' && !$tieneFotoServicioNueva && !$tieneFotoServicioGuardada) {
            echo json_encode(['status' => 'error', 'message' => $tipoSupervisionInicial === 'Remota'
                ? 'Suba la captura de la videollamada como foto de evidencia para finalizar la supervisión remota.'
                : 'Suba al menos una foto de evidencia para finalizar la supervisión.']);
            exit;
        }
        if ($clasificacion !== 'Checklist' && trim((string)($input['observacion_servicio'] ?? '')) === '') {
            echo json_encode(['status' => 'error', 'message' => 'Complete el resumen del resultado de la supervisión antes de finalizar.']);
            exit;
        }
        if ($tipoSupervisionInicial === 'En frío' && !empty($input['es_cancelacion'])) {
            if (trim((string)($input['observacion_servicio'] ?? '')) === '') {
                echo json_encode(['status' => 'error', 'message' => 'Registre el resumen u observación de la validación antes de finalizar.']);
                exit;
            }
        }
        $requiereCuadrillaCierre = !$sinCuadrilla && $tipoSupervisionInicial !== 'En frío';
        // En la remota el vehículo no siempre se ve por la cámara: solo se exige el técnico líder.
        $esRemota = $tipoSupervisionInicial === 'Remota';
        if ($requiereCuadrillaCierre && (
            trim((string)$tecnico1Nombre) === ''
            || !preg_match('/^\d{8}$/', trim((string)$tecnico1Dni))
            || (!$esRemota && (trim((string)$unidadTipo) === '' || trim((string)$unidadPlaca) === '' || trim((string)$unidadEstado) === ''))
        )) {
            echo json_encode(['status' => 'error', 'message' => $esRemota
                ? 'Complete el nombre y DNI del técnico líder antes de finalizar.'
                : 'Complete los datos del técnico líder y del vehículo antes de finalizar.']);
            exit;
        }
        // GPS: obligatorio en campo; en la remota el supervisor no está en el lugar (si el equipo lo tomó, igual se guarda).
        if (!$esRemota && ($latitud === null || $longitud === null)) {
            echo json_encode(['status' => 'error', 'message' => 'Registre las coordenadas GPS antes de finalizar la supervisión.']);
            exit;
        }
        if (!in_array($tipoSolicitado, ['Incidencia / Casos atípicos', 'Visita partner por capacitación'], true)
            && empty($estadoSupervision)) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione el estado del servicio antes de finalizar.']);
            exit;
        }
        if (!empty($input['chk_incluir_observacion'])) {
            $camposObservacionCompletos = trim((string)($input['obs_gravedad'] ?? '')) !== ''
                && intval($input['obs_categoria_id'] ?? 0) > 0
                && intval($input['obs_subopcion_id'] ?? 0) > 0
                && trim((string)($input['obs_detalle'] ?? '')) !== '';
            if (!$camposObservacionCompletos) {
                echo json_encode(['status' => 'error', 'message' => 'Complete todos los campos de la observación seleccionada.']);
                exit;
            }
            // 2026-10-19: al menos una foto (antes las 3 obligatorias).
            $fotosObservacion = array_filter([1, 2, 3], fn($n) => isset($_FILES['foto_' . $n]) && $_FILES['foto_' . $n]['error'] === UPLOAD_ERR_OK);
            if (!$fotosObservacion) {
                echo json_encode(['status' => 'error', 'message' => 'Adjunte al menos una foto de evidencia de la observación.']);
                exit;
            }
        }
    }

    // Procesar Carga de Fotos de Evidencia del Servicio General (Hasta 3 fotos)
    $fotoServicioRutas = [null, null, null];
    $fotoServicioComentarios = [
        trim((string)($input['foto_servicio_comentario_1'] ?? '')),
        trim((string)($input['foto_servicio_comentario_2'] ?? '')),
        trim((string)($input['foto_servicio_comentario_3'] ?? ''))
    ];
    for ($i = 1; $i <= 3; $i++) {
        $fileKey = "foto_servicio_" . $i;
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            try {
                $validated = validateUploadedFile($_FILES[$fileKey], [
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
                ], 8 * 1024 * 1024);
                $nombreArchivo = randomUploadName('serv_gen_' . $i . '_', $validated['extension']);
                $destino = UPLOAD_DIR . $nombreArchivo;
                if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $destino)) {
                    $fotoServicioRutas[$i - 1] = UPLOAD_URL . $nombreArchivo;
                }
            } catch (RuntimeException $e) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }
    try {
        $pdo->beginTransaction();

        if ($editId > 0) {
            $supervisionId = $editId;
            $stmtGet = $pdo->prepare("SELECT * FROM supervisiones WHERE id = :id FOR UPDATE");
            $stmtGet->execute([':id' => $editId]);
            $existingSupervision = $stmtGet->fetch();
            $allowPendingPool = $existingSupervision && ($existingSupervision['estado_supervision'] ?? '') === 'Pendiente';
            // Pendiente que se inicia (2026-10-16): se guarda su copia original; "Cancelar" la devuelve a este estado.
            if ($existingSupervision && ($existingSupervision['estado_supervision'] ?? '') === 'Pendiente' && $requestedWorkflowState !== 'Pendiente'
                && empty($existingSupervision['datos_pendiente'])) {
                $copiaPendiente = $existingSupervision;
                unset($copiaPendiente['datos_pendiente']);
                $copiaPendiente['__inicio'] = date('Y-m-d H:i:s');
                $pdo->prepare('UPDATE supervisiones SET datos_pendiente = :d WHERE id = :id')
                    ->execute([':d' => json_encode($copiaPendiente, JSON_UNESCAPED_UNICODE), ':id' => $editId]);
            }
            // Guardado automático: la ubicación que aún no se eligió conserva la guardada (antes quedaba "LIMA").
            if ($existingSupervision && $esGuardadoParcial) {
                foreach (['departamento', 'provincia', 'distrito'] as $campoUbicacion) {
                    if (empty($input[$campoUbicacion])) $$campoUbicacion = (string)$existingSupervision[$campoUbicacion];
                }
            }
            if (!$existingSupervision || !canAccessSupervision($user, $existingSupervision, $allowPendingPool)) {
                $pdo->rollBack();
                http_response_code(403);
                // Si otro supervisor la tomó un momento antes, se explica en lugar de un error de permisos.
                echo json_encode(['status' => 'error', 'message' => mensajeSinAccesoSupervision($user, $existingSupervision, 'No tiene permisos para editar esta supervisión.')]);
                exit;
            }
            $adminEnVistaNormal = Auth::isAdministrative();
            $estadoActualNormalizado = in_array($existingSupervision['estado_supervision'], ['Culminada','Finalizado'], true)
                ? 'Finalizada' : $existingSupervision['estado_supervision'];
            if (!$adminEnVistaNormal) {
                $transicionesPermitidas = [
                    'Pendiente' => ['Pendiente','Iniciada','En proceso','Finalizada'],
                    'Iniciada' => ['En proceso','Finalizada'],
                    'En proceso' => ['En proceso','Finalizada'],
                ];
                if (!in_array($estadoSupervision, $transicionesPermitidas[$estadoActualNormalizado] ?? [], true)) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode(['status'=>'error','message'=>'No se puede retroceder el avance de la supervisión. Actualice la lista e inténtelo nuevamente.']);
                    exit;
                }
            }
            if (in_array($existingSupervision['estado_supervision'], ['Finalizada', 'Culminada', 'Finalizado'], true) && !$esCorreccionAdmin) {
                $pdo->rollBack();
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Esta supervisión está finalizada y se encuentra en modo de consulta.']);
                exit;
            }
            if ($esCorreccionAdmin) {
                win_log_error('audit_supervision_corrected', 'Supervisión finalizada corregida por el administrador', ['target_id' => $editId, 'actor_id' => (int)($user['id'] ?? 0)]);
            }
            $codigoSupervision = $existingSupervision['codigo_supervision'] ?: $codigoSupervision;
            $supervisorActualizacionId = (int)$existingSupervision['supervisor_id'];
            if (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR
                && $existingSupervision['estado_supervision'] === 'Pendiente'
                && in_array($estadoSupervision, ['Iniciada', 'En proceso'], true)
                && $resultadoCancelacion !== 'En proceso de rescate') { // pasar a rescate no ocupa la supervisión en proceso
                $stmtOtraActiva = $pdo->prepare("
                    SELECT codigo_supervision, ticket_codigo
                    FROM supervisiones
                    WHERE supervisor_id = :supervisor_id
                      AND id <> :supervision_id
                      AND estado_supervision IN ('Iniciada', 'En proceso')
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtOtraActiva->execute([
                    ':supervisor_id' => $user['id'],
                    ':supervision_id' => $editId
                ]);
                $otraActiva = $stmtOtraActiva->fetch();
                if ($otraActiva) {
                    $pdo->rollBack();
                    $codigoActivo = $otraActiva['ticket_codigo'] ?: $otraActiva['codigo_supervision'];
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Ya tiene la supervisión {$codigoActivo} Iniciada o En proceso. Debe finalizarla antes de iniciar otra."
                    ]);
                    exit;
                }
                $supervisorActualizacionId = (int)$user['id'];
            }
            // Bolsa común (2026-10-11): quien saca la orden de Pendiente, en cualquier estado, se queda con ella
            // y desaparece de la lista de pendientes de los demás supervisores de la zona.
            if (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR
                && $existingSupervision['estado_supervision'] === 'Pendiente'
                && $estadoSupervision !== 'Pendiente') {
                $supervisorActualizacionId = (int)$user['id'];
            }
            if (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR) {
                if (in_array($existingSupervision['estado_supervision'], ['Finalizada', 'Culminada', 'Finalizado'], true)) {
                    $pdo->rollBack();
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Una supervisión finalizada solo puede ser modificada por el administrador.']);
                    exit;
                }
                if (!empty($existingSupervision['es_cancelacion']) && $existingSupervision['tipo_supervision_inicial'] === 'En frío') {
                    // En cancelaciones en frío solo se revisan coordenadas, tipificación, resultado y resumen.
                    $tecnico1Nombre = $existingSupervision['tecnico1_nombre'];
                    $tecnico1Dni = $existingSupervision['tecnico1_dni'];
                    $tecnico2Nombre = $existingSupervision['tecnico2_nombre'];
                    $tecnico2Dni = $existingSupervision['tecnico2_dni'];
                    $brevetePoseedor = $existingSupervision['brevete_poseedor'];
                    $unidadTipo = $existingSupervision['unidad_tipo'];
                    $unidadPlaca = $existingSupervision['unidad_placa'];
                    $unidadEstado = $existingSupervision['unidad_estado_conservacion'];
                    $tipoSupervisionInicial = 'En frío';
                }
            }
            if (!empty($existingSupervision['es_cancelacion']) && $existingSupervision['tipo_supervision_inicial'] === 'En frío') {
                // El motivo cargado originalmente nunca se sobrescribe durante la validación.
                $esCancelacion = 1;
                $tipificacionCancelacion = $existingSupervision['tipificacion_cancelacion'];
            }
            if ($existingSupervision['estado_supervision'] === 'Pendiente') {
                // Toda orden pendiente nace como revisión en frío: no existe personal
                // técnico presente y nunca corresponde abrir un checklist técnico.
                $tipoSupervisionInicial = 'En frío';
                $tecnico1Nombre = $tecnico1Dni = $tecnico2Nombre = $tecnico2Dni = null;
                // Cancelación cargada (2026-10-16): conserva el técnico líder de la columna CUADRILLA del Excel, para que
                // sus observaciones queden vinculadas a esa cuadrilla.
                if (!empty($existingSupervision['origen_importacion']) && !empty($existingSupervision['es_cancelacion'])) {
                    $tecnico1Nombre = $existingSupervision['tecnico1_nombre'];
                    $tecnico1Dni = $existingSupervision['tecnico1_dni'];
                }
                $unidadTipo = $unidadPlaca = $unidadSoat = $unidadRevisionTecnica = $unidadEstado = null;
                $brevetePoseedor = 'Ninguno';
            }
        } else {
            // Un supervisor operativo solo puede mantener una supervisión en proceso.
            // Los perfiles administrativos quedan exentos para gestión y pruebas.
            $stmtCheckActive = $pdo->prepare("
                SELECT id, codigo_supervision, ticket_codigo 
                FROM supervisiones 
                WHERE supervisor_id = :sup_id 
                  AND estado_supervision IN ('Iniciada', 'En proceso')
                LIMIT 1
            ");
            $stmtCheckActive->execute([':sup_id' => $supervisionOwnerId]);
            $activeRecord = $stmtCheckActive->fetch();

            if ($activeRecord) {
                $pdo->rollBack();
                $codAct = !empty($activeRecord['ticket_codigo']) ? $activeRecord['ticket_codigo'] : $activeRecord['codigo_supervision'];
                echo json_encode([
                    'status' => 'error',
                    'message' => "No puedes iniciar una nueva supervisión. Ya tienes una supervisión en proceso ({$codAct}). Debes finalizarla primero."
                ]);
                exit;
            }
        }

        // Re-evaluate using the authorized, persisted modality before writing.
        $estadoCaliente = estadoOrdenPresencial($tipoSupervisionInicial, $clasificacion, $input['estado_servicio_caliente'] ?? null, $esCierreDefinitivo);
        if ($editId > 0 && !empty($existingSupervision['origen_importacion']) && !empty($existingSupervision['es_cancelacion'])) {
            // El estado con el que llegó la orden desde la carga es informativo e inmutable.
            $estadoCaliente = $existingSupervision['estado_servicio_caliente'];
        }
        $esCancelacionImportada = $editId > 0 && !empty($existingSupervision['origen_importacion']) && !empty($existingSupervision['es_cancelacion']);
        $sinCuadrilla = (in_array($tipoSupervisionInicial, ['En frío', 'Remota'], true) || in_array($clasificacion, $tiposSinCuadrilla, true)) && !$esCancelacionImportada;
        if ($sinCuadrilla) {
            // Retain historical technician snapshots when viewing an already imported cold record.
            $conservarHistorico = $editId > 0 && ($existingSupervision['tipo_supervision_inicial'] ?? '') === 'En frío';
            $tecnico1Nombre = $conservarHistorico ? $existingSupervision['tecnico1_nombre'] : null;
            $tecnico1Dni = $conservarHistorico ? $existingSupervision['tecnico1_dni'] : null;
            $tecnico2Nombre = $conservarHistorico ? $existingSupervision['tecnico2_nombre'] : null;
            $tecnico2Dni = $conservarHistorico ? $existingSupervision['tecnico2_dni'] : null;
            $unidadTipo = $conservarHistorico ? $existingSupervision['unidad_tipo'] : null;
            $unidadPlaca = $conservarHistorico ? $existingSupervision['unidad_placa'] : null;
            $unidadSoat = $conservarHistorico ? $existingSupervision['unidad_soat'] : null;
            $unidadRevisionTecnica = $conservarHistorico ? $existingSupervision['unidad_revision_tecnica'] : null;
            $unidadEstado = $conservarHistorico ? $existingSupervision['unidad_estado_conservacion'] : null;
            $brevetePoseedor = $conservarHistorico ? $existingSupervision['brevete_poseedor'] : 'Ninguno';
        }

        // Campos que el formulario de trabajo no envía (SOAT y revisión técnica): se conservan al guardar
        // avance. Antes se escribían como NULL y el dato registrado se perdía.
        if ($editId > 0 && !$sinCuadrilla && !empty($existingSupervision)) {
            if (!array_key_exists('unidad_soat', $input)) $unidadSoat = $existingSupervision['unidad_soat'] ?? null;
            if (!array_key_exists('unidad_revision_tecnica', $input)) $unidadRevisionTecnica = $existingSupervision['unidad_revision_tecnica'] ?? null;
        }

        // Campos de lista fija (2026-10-15): un valor vacío o fuera de la lista se guarda como "sin dato" (NULL), o se
        // conserva el que ya tenía. El MySQL del hosting (modo estricto) rechazaba '' con "Data truncated for column
        // 'unidad_soat'" al corregir una supervisión sin SOAT registrado; en la PC no se notaba (MySQL permisivo).
        $deLista = static function ($valor, array $permitidos, $anterior = null) {
            $valor = is_string($valor) ? trim($valor) : $valor;
            if (in_array($valor, $permitidos, true)) return $valor;
            return in_array($anterior, $permitidos, true) ? $anterior : null;
        };
        $previa = $editId > 0 && !empty($existingSupervision) ? $existingSupervision : [];
        $unidadSoat = $deLista($unidadSoat, ['Vigente', 'No vigente'], $previa['unidad_soat'] ?? null);
        $unidadRevisionTecnica = $deLista($unidadRevisionTecnica, ['Vigente', 'No vigente'], $previa['unidad_revision_tecnica'] ?? null);
        $unidadTipo = $deLista($unidadTipo, ['Minivan', 'Auto', 'Moto', 'Otro'], $sinCuadrilla ? null : ($previa['unidad_tipo'] ?? null));
        $unidadEstado = $deLista($unidadEstado, ['Mal estado', 'Regular', 'Buen estado'], $sinCuadrilla ? null : ($previa['unidad_estado_conservacion'] ?? null));
        $brevetePoseedor = $deLista($brevetePoseedor, ['Tecnico 1', 'Tecnico 2', 'Ninguno'], $previa['brevete_poseedor'] ?? null) ?? 'Ninguno';

        if ($editId > 0) {
            $updateFechaRegistro = '';
            if (($existingSupervision['estado_supervision'] ?? '') === 'Pendiente' && $estadoSupervision !== 'Pendiente') {
                $updateFechaRegistro = 'fecha_registro = NOW(),';
            }
            $stmtUpd = $pdo->prepare("
                UPDATE supervisiones SET
                    supervisor_id = :sup_actualiza,
                    $updateFechaRegistro
                    derivacion_orden = :deriv, tipo_servicio = :tipo_serv, departamento = :dep, provincia = :prov, distrito = :dist,
                    cliente_nombre = :cli_nom, cliente_dni = :cli_dni, ticket_codigo = :ticket,
                    tipo_supervision_inicial = :tipo_sup,
                    partner_id = :part_id, tecnico1_nombre = :t1_nom, tecnico1_dni = :t1_dni,
                    tecnico2_nombre = :t2_nom, tecnico2_dni = :t2_dni, brevete_poseedor = :brevete, unidad_tipo = :u_tipo,
                    unidad_placa = :u_placa, unidad_soat = :u_soat, unidad_revision_tecnica = :u_revision,
                    unidad_estado_conservacion = :u_estado, clasificacion_supervision = :clasif,
                    area_servicio = :area, sub_tipo_atencion = :sub_tipo, observacion_servicio = :obs_serv,
                    estado_servicio_caliente = :est_cal, es_cancelacion = :es_canc, tipificacion_cancelacion = :tip_canc,
                    estado_cancelacion = :estado_cancelacion,
                    tipificacion_tecnico_correcta = :tip_corr, tipificacion_corregida_supervisor = :tip_corr_sup,
                    estado_supervision = :est_sup, latitud = :lat, longitud = :lng,
                    foto_servicio_1 = COALESCE(:f_serv1, foto_servicio_1),
                    foto_servicio_comentario_1 = COALESCE(NULLIF(:fc_serv1,''), foto_servicio_comentario_1),
                    foto_servicio_2 = COALESCE(:f_serv2, foto_servicio_2),
                    foto_servicio_comentario_2 = COALESCE(NULLIF(:fc_serv2,''), foto_servicio_comentario_2),
                    foto_servicio_3 = COALESCE(:f_serv3, foto_servicio_3),
                    foto_servicio_comentario_3 = COALESCE(NULLIF(:fc_serv3,''), foto_servicio_comentario_3)
                WHERE id = :id
            ");

            $latAnterior = trim((string)($existingSupervision['latitud'] ?? ''));
            $lngAnterior = trim((string)($existingSupervision['longitud'] ?? ''));
            $latNueva = trim((string)($latitud ?? ''));
            $lngNueva = trim((string)($longitud ?? ''));
            if ($latAnterior !== $latNueva || $lngAnterior !== $lngNueva) {
                $stmtHistorialGps = $pdo->prepare("INSERT INTO supervision_coordenadas_historial
                    (supervision_id,latitud_anterior,longitud_anterior,latitud_nueva,longitud_nueva,cambiado_por)
                    VALUES (:supervision,:lat_ant,:lng_ant,:lat_nueva,:lng_nueva,:usuario)");
                $stmtHistorialGps->execute([
                    ':supervision' => $editId,
                    ':lat_ant' => $latAnterior !== '' ? $latAnterior : null,
                    ':lng_ant' => $lngAnterior !== '' ? $lngAnterior : null,
                    ':lat_nueva' => $latNueva !== '' ? $latNueva : null,
                    ':lng_nueva' => $lngNueva !== '' ? $lngNueva : null,
                    ':usuario' => (int)($user['id'] ?? 0) ?: null
                ]);
            }

            $stmtUpd->execute([
                ':sup_actualiza' => $supervisorActualizacionId,
                ':deriv' => $derivacionOrden,
                ':tipo_serv' => $tipoServicio,
                ':dep' => $departamento,
                ':prov' => $provincia,
                ':dist' => $distrito,
                ':cli_nom' => $clienteNombre,
                ':cli_dni' => $clienteDni,
                ':ticket' => $ticketCodigo,
                ':tipo_sup' => $tipoSupervisionInicial,
                ':part_id' => $partnerId,
                ':t1_nom' => $tecnico1Nombre,
                ':t1_dni' => $tecnico1Dni,
                ':t2_nom' => $tecnico2Nombre,
                ':t2_dni' => $tecnico2Dni,
                ':brevete' => $brevetePoseedor,
                ':u_tipo' => $unidadTipo,
                ':u_placa' => $unidadPlaca,
                ':u_soat' => $unidadSoat,
                ':u_revision' => $unidadRevisionTecnica,
                ':u_estado' => $unidadEstado,
                ':clasif' => $clasificacion,
                ':area' => $areaServicio,
                ':sub_tipo' => $subTipoAtencion,
                ':obs_serv' => $observacionServicio,
                ':est_cal' => $estadoCaliente,
                ':es_canc' => $esCancelacion,
                ':tip_canc' => $tipificacionCancelacion,
                ':estado_cancelacion' => $estadoCancelacion,
                ':tip_corr' => $tipificacionCorrecta,
                ':tip_corr_sup' => $tipificacionCorregida,
                ':est_sup' => $estadoSupervision,
                ':lat' => $latitud,
                ':lng' => $longitud,
                ':f_serv1' => $fotoServicioRutas[0],
                ':fc_serv1' => $fotoServicioComentarios[0],
                ':f_serv2' => $fotoServicioRutas[1],
                ':fc_serv2' => $fotoServicioComentarios[1],
                ':f_serv3' => $fotoServicioRutas[2],
                ':fc_serv3' => $fotoServicioComentarios[2],
                ':id' => $editId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO supervisiones (
                    codigo_supervision, fecha_registro, supervisor_id,
                    derivacion_orden, tipo_servicio, departamento, provincia, distrito,
                    cliente_nombre, cliente_dni, ticket_codigo,
                    tipo_supervision_inicial, partner_id, tecnico1_nombre, tecnico1_dni,
                    tecnico2_nombre, tecnico2_dni, brevete_poseedor, unidad_tipo, unidad_placa,
                    unidad_soat, unidad_revision_tecnica, unidad_estado_conservacion, clasificacion_supervision, area_servicio,
                    sub_tipo_atencion, observacion_servicio, estado_servicio_caliente,
                    es_cancelacion, tipificacion_cancelacion, tipificacion_tecnico_correcta,
                    tipificacion_corregida_supervisor, estado_cancelacion, estado_supervision, latitud, longitud,
                    foto_servicio_1, foto_servicio_comentario_1, foto_servicio_2, foto_servicio_comentario_2, foto_servicio_3, foto_servicio_comentario_3
                ) VALUES (
                    :cod, NOW(), :sup_id,
                    :deriv, :tipo_serv, :dep, :prov, :dist,
                    :cli_nom, :cli_dni, :ticket,
                    :tipo_sup, :part_id, :t1_nom, :t1_dni,
                    :t2_nom, :t2_dni, :brevete, :u_tipo, :u_placa,
                    :u_soat, :u_revision, :u_estado, :clasif, :area,
                    :sub_tipo, :obs_serv, :est_cal,
                    :es_canc, :tip_canc, :tip_corr,
                    :tip_corr_sup, :estado_cancelacion, :est_sup, :lat, :lng,
                    :f_serv1, :fc_serv1, :f_serv2, :fc_serv2, :f_serv3, :fc_serv3
                )
            ");

            $stmt->execute([
                ':cod' => $codigoSupervision,
                ':sup_id' => $supervisionOwnerId,
                ':deriv' => $derivacionOrden,
                ':tipo_serv' => $tipoServicio,
                ':dep' => $departamento,
                ':prov' => $provincia,
                ':dist' => $distrito,
                ':cli_nom' => $clienteNombre,
                ':cli_dni' => $clienteDni,
                ':ticket' => $ticketCodigo,
                ':tipo_sup' => $tipoSupervisionInicial,
                ':part_id' => $partnerId,
                ':t1_nom' => $tecnico1Nombre,
                ':t1_dni' => $tecnico1Dni,
                ':t2_nom' => $tecnico2Nombre,
                ':t2_dni' => $tecnico2Dni,
                ':brevete' => $brevetePoseedor,
                ':u_tipo' => $unidadTipo,
                ':u_placa' => $unidadPlaca,
                ':u_soat' => $unidadSoat,
                ':u_revision' => $unidadRevisionTecnica,
                ':u_estado' => $unidadEstado,
                ':clasif' => $clasificacion,
                ':area' => $areaServicio,
                ':sub_tipo' => $subTipoAtencion,
                ':obs_serv' => $observacionServicio,
                ':est_cal' => $estadoCaliente,
                ':es_canc' => $esCancelacion,
                ':tip_canc' => $tipificacionCancelacion,
                ':tip_corr' => $tipificacionCorrecta,
                ':tip_corr_sup' => $tipificacionCorregida,
                ':estado_cancelacion' => $estadoCancelacion,
                ':est_sup' => $estadoSupervision,
                ':lat' => $latitud,
                ':lng' => $longitud,
                ':f_serv1' => $fotoServicioRutas[0],
                ':fc_serv1' => $fotoServicioComentarios[0],
                ':f_serv2' => $fotoServicioRutas[1],
                ':fc_serv2' => $fotoServicioComentarios[1],
                ':f_serv3' => $fotoServicioRutas[2],
                ':fc_serv3' => $fotoServicioComentarios[2]
            ]);

            $supervisionId = $pdo->lastInsertId();
        }

        if (!empty($esCancelacionImportada)) {
            // Cancelación cargada (2026-10-16, en frío): su técnico líder viene de la columna CUADRILLA del Excel; no hay
            // personal ni vehículo en la visita, así que no se toca (tampoco se borra).
        } elseif (!$sinCuadrilla) {
            guardarPersonalSupervision($pdo, (int)$supervisionId, $partnerId, [
                ['id'=>$input['tecnico1_id'] ?? null, 'nombre'=>$tecnico1Nombre, 'dni'=>$tecnico1Dni],
                ['id'=>$input['tecnico2_id'] ?? null, 'nombre'=>$tecnico2Nombre, 'dni'=>$tecnico2Dni]
            ], ['placa'=>$unidadPlaca, 'tipo'=>$unidadTipo, 'estado'=>$unidadEstado, 'soat'=>$unidadSoat, 'revision'=>$unidadRevisionTecnica],
                // La ficha del líder (T2, vehículo y su estado) se actualiza sola al CERRAR la supervisión (2026-10-16: sin casilla):
                // si cambió, queda lo nuevo; si es lo mismo, no cambia nada. Corregir una ya finalizada no toca la cuadrilla actual.
                $esCierreDefinitivo && empty($esCorreccionAdmin));
        } elseif (empty($conservarHistorico)) {
            $pdo->prepare('UPDATE supervisiones SET tecnico1_id=NULL,tecnico2_id=NULL,vehiculo_id=NULL WHERE id=:id')->execute([':id'=>$supervisionId]);
        }

        // 2. Si se activó la opción de registrar observación directamente
        if ($clasificacion !== 'Checklist' && !empty($input['chk_incluir_observacion'])) {
            $obsGravedad = $input['obs_gravedad'] ?? 'Grave';
            $obsCatId = intval($input['obs_categoria_id'] ?? 0);
            $obsSubId = intval($input['obs_subopcion_id'] ?? 0);
            $obsDetalle = trim($input['obs_detalle'] ?? '');
            $obsInmediata = false;
            $obsPlazoDias = 3;

            if ($obsCatId > 0 && $obsSubId > 0 && !empty($obsDetalle)) {
                $stmtObsSeverity = $pdo->prepare('SELECT gravedad FROM subopciones_observacion WHERE id=:id AND categoria_id=:categoria LIMIT 1');
                $stmtObsSeverity->execute([':id'=>$obsSubId, ':categoria'=>$obsCatId]);
                $catalogSeverity = $stmtObsSeverity->fetchColumn();
                if (!$catalogSeverity) throw new RuntimeException('La tipificación de la observación no corresponde a la categoría seleccionada.');
                $obsGravedad = (string)$catalogSeverity;
                $obsPenalidadDirecta = $obsGravedad === 'Muy Grave';
                $fotoRutas = [null, null, null];
                for ($i = 1; $i <= 3; $i++) {
                    $fileKey = "foto_" . $i;
                    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                        try {
                            $validated = validateUploadedFile($_FILES[$fileKey], [
                                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
                            ], 8 * 1024 * 1024);
                            $nombreArchivo = randomUploadName('obs_' . $supervisionId . '_' . $i . '_', $validated['extension']);
                            $destino = UPLOAD_DIR . $nombreArchivo;
                            if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $destino)) {
                                $fotoRutas[$i - 1] = UPLOAD_URL . $nombreArchivo;
                            }
                        } catch (RuntimeException $e) {
                            throw new RuntimeException($e->getMessage());
                        }
                    }
                }
                // 2026-10-12: la observación lleva un solo comentario general (obs_detalle); ya no se pide uno por foto.

                $fechaLimite = $obsPenalidadDirecta
                    ? date('Y-m-d H:i:s')
                    : ($obsInmediata
                    ? date('Y-m-d 23:59:59')
                    : observationBusinessDeadline($obsPlazoDias));
                $obsEstadoInicial = $obsPenalidadDirecta ? 'Penalidad Directa' : 'Pendiente';

                $stmtObsIns = $pdo->prepare("
                    INSERT INTO observaciones_supervision (
                        supervision_id, gravedad, categoria_id, subopcion_id,
                        detalle_observacion, foto_1, foto_comentario_1, foto_2, foto_comentario_2, foto_3, foto_comentario_3,
                        fecha_registro, fecha_limite_respuesta, plazo_subsanacion_dias, subsanacion_inmediata, estado_respuesta
                    ) VALUES (
                        :sup_id, :grav, :cat_id, :sub_id,
                        :det, :f1, :fc1, :f2, :fc2, :f3, :fc3,
                        NOW(), :f_lim, :plazo, :inmediata, :estado_respuesta
                    )
                ");

                $stmtObsIns->execute([
                    ':sup_id' => $supervisionId,
                    ':grav' => $obsGravedad,
                    ':cat_id' => $obsCatId,
                    ':sub_id' => $obsSubId,
                    ':det' => $obsDetalle,
                    ':f1' => $fotoRutas[0],
                    ':fc1' => trim((string)($input['foto_comentario_1'] ?? '')) ?: null,
                    ':f2' => $fotoRutas[1],
                    ':fc2' => trim((string)($input['foto_comentario_2'] ?? '')) ?: null,
                    ':f3' => $fotoRutas[2],
                    ':fc3' => trim((string)($input['foto_comentario_3'] ?? '')) ?: null,
                    ':f_lim' => $fechaLimite,
                    ':plazo' => $obsPlazoDias,
                    ':inmediata' => $obsInmediata ? 1 : 0,
                    ':estado_respuesta' => $obsEstadoInicial
                ]);
                $observacionId = (int)$pdo->lastInsertId();
                notifyPartnerObservation($pdo, $observacionId, $supervisionId, (int)$partnerId, $obsPlazoDias, $obsInmediata, $obsPenalidadDirecta);
            }
        }

        $tiposSinChecklist = ['Incidencia / Casos atípicos', 'Visita partner por capacitación'];
        $esOrdenPendienteOriginal = $editId > 0 && ($existingSupervision['estado_supervision'] ?? '') === 'Pendiente';
        $incluirChecklist = !$esOrdenPendienteOriginal && ($clasificacion === 'Checklist'
            || (!in_array($clasificacion, $tiposSinChecklist, true)
                && $tipoSupervisionInicial !== 'En frío'
                && !empty($input['chk_incluir_checklist'])));
        if ($incluirChecklist) {
            $criterios = $input['checklist_criterio'] ?? [];
            $estadosChecklist = $input['checklist_estado'] ?? [];
            $observacionesChecklist = $input['checklist_observacion'] ?? [];
            $estadosPermitidos = ['Bueno', 'Regular', 'Malo', 'No tiene', 'Sí', 'No', 'Para cambio', 'No revisado', 'Mal estado'];
            if ($editId > 0 && empty($criterios)) {
                $criterios = null; // La edición general conserva el checklist ya registrado.
            }
            if ($criterios !== null) {
            if (empty($input['tecnico1_nombre']) || !preg_match('/^\d{8}$/', trim($input['tecnico1_dni'] ?? '')) || empty($input['unidad_placa']) || count($criterios) < 1 || count($criterios) !== count($estadosChecklist)) {
                throw new Exception('Complete los datos del técnico, vehículo y todos los estados del checklist.');
            }
            foreach ($estadosChecklist as $estadoItem) {
                if (!in_array($estadoItem, $estadosPermitidos, true)) {
                    throw new Exception('Seleccione un estado válido en todos los criterios del checklist.');
                }
            }
            // Cada respuesta debe llevar el nombre de su ítem (2026-10-16: el formulario los borraba al ocultar el checklist).
            foreach ($criterios as $criterioItem) {
                if (trim((string)$criterioItem) === '') throw new Exception('El checklist llegó sin el nombre de sus ítems. Recargue la página (Ctrl + F5) y vuelva a llenarlo.');
            }

            $observacionGeneralChecklist = trim((string)($input['checklist_observacion_general'] ?? ''));
            if ($observacionGeneralChecklist === '') {
                throw new Exception('Complete el comentario general del checklist técnico.');
            }
            $firmaData = (string)($input['checklist_firma_data'] ?? '');
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $firmaData, $firmaCoincidencia)) {
                throw new Exception('Dibuje la firma del supervisor para validar el checklist.');
            }
            $firmaBinaria = base64_decode($firmaCoincidencia[1], true);
            if ($firmaBinaria === false || strlen($firmaBinaria) < 200 || strlen($firmaBinaria) > 2*1024*1024) {
                throw new Exception('La firma dibujada no es válida. Inténtelo nuevamente.');
            }
            $nombreFirma = randomUploadName('firma_checklist_', 'png');
            if (file_put_contents(UPLOAD_DIR.$nombreFirma, $firmaBinaria, LOCK_EX) === false) throw new Exception('No se pudo guardar la firma del supervisor.');
            $firmaChecklist = UPLOAD_URL.$nombreFirma;

            $pdo->prepare("DELETE FROM checklists_tecnicos WHERE supervision_id = :sid")->execute([':sid' => $supervisionId]);
            $stmtChecklist = $pdo->prepare("INSERT INTO checklists_tecnicos (supervision_id,supervisor_id,tecnico_nombre,tecnico_dni,unidad_tipo,unidad_placa,brevete_poseedor,observacion_general,firma_supervisor) VALUES (:sid,:uid,:nom,:dni,:tipo,:placa,:brevete,:obs_general,:firma)");
            $stmtChecklist->execute([':sid'=>$supervisionId, ':uid'=>$user['id'], ':nom'=>$tecnico1Nombre, ':dni'=>$tecnico1Dni, ':tipo'=>$unidadTipo, ':placa'=>$unidadPlaca, ':brevete'=>$brevetePoseedor, ':obs_general'=>$observacionGeneralChecklist, ':firma'=>$firmaChecklist]);
            $checklistId = (int)$pdo->lastInsertId();
            $stmtRespuesta = $pdo->prepare("INSERT INTO checklist_respuestas (checklist_id,criterio,estado,observacion,foto_evidencia) VALUES (:cid,:criterio,:estado,:obs,:foto)");
            // Vencimientos (2026-10-15): SOAT, revisión técnica, extintor y brevete no se marcan a mano: su estado sale de
            // las fechas registradas del vehículo y del técnico líder de esta supervisión (sin fechas = "No revisado").
            require_once __DIR__ . '/../../config/vencimientos.php';
            $stCuadrilla = $pdo->prepare('SELECT tecnico1_id, vehiculo_id, partner_id FROM supervisiones WHERE id = :id');
            $stCuadrilla->execute([':id' => $supervisionId]);
            $cuadrillaSup = $stCuadrilla->fetch(PDO::FETCH_ASSOC) ?: [];
            // Fechas registradas en el propio checklist (inicio, vence y foto): quedan pendientes con esta supervisión y
            // pasan a la cuadrilla al finalizarla (más abajo). Una fila sin datos no cambia nada.
            foreach (SGS_VENCIMIENTOS as $tipoVenc => $cfgVenc) {
                $iniVenc = trim((string)($input['venc_inicio'][$tipoVenc] ?? ''));
                $finVenc = trim((string)($input['venc_fin'][$tipoVenc] ?? ''));
                $errFoto = $_FILES['venc_foto']['error'][$tipoVenc] ?? UPLOAD_ERR_NO_FILE;
                if ($iniVenc === '' && $finVenc === '' && $errFoto === UPLOAD_ERR_NO_FILE) continue;
                $sujetoVenc = $cfgVenc['sujeto'] === 'vehiculo' ? (int)($cuadrillaSup['vehiculo_id'] ?? 0) : (int)($cuadrillaSup['tecnico1_id'] ?? 0);
                if (!$sujetoVenc) {
                    throw new Exception($cfgVenc['sujeto'] === 'vehiculo' ? "Registre la placa del vehículo para guardar las fechas del {$tipoVenc}." : 'Seleccione al técnico líder para guardar las fechas del brevete.');
                }
                $archivoVenc = $errFoto === UPLOAD_ERR_NO_FILE ? null : ['name' => $_FILES['venc_foto']['name'][$tipoVenc], 'type' => $_FILES['venc_foto']['type'][$tipoVenc],
                    'tmp_name' => $_FILES['venc_foto']['tmp_name'][$tipoVenc], 'error' => $errFoto, 'size' => $_FILES['venc_foto']['size'][$tipoVenc]];
                try {
                    sgsRegistrarVencimiento($pdo, $tipoVenc, $sujetoVenc, $iniVenc, $finVenc, $archivoVenc, 'Supervisión', (int)$supervisionId, (int)($user['id'] ?? 0), false);
                } catch (Exception $e) {
                    throw new Exception("{$tipoVenc}: " . $e->getMessage());
                }
            }
            $estadoVenc = [];
            if (!empty($cuadrillaSup['tecnico1_id']) || !empty($cuadrillaSup['vehiculo_id'])) {
                foreach (sgsVencimientosCuadrilla($pdo, (int)$cuadrillaSup['tecnico1_id'], $cuadrillaSup['vehiculo_id'] ? (int)$cuadrillaSup['vehiculo_id'] : null, false, (int)$supervisionId) as $itemVenc) {
                    $estadoVenc[$itemVenc['tipo']] = $itemVenc['checklist'];
                }
            }
            foreach ($criterios as $indice => $criterio) {
                if (isset(SGS_VENCIMIENTOS[trim($criterio)])) $estadosChecklist[$indice] = $estadoVenc[trim($criterio)] ?? 'No revisado';
            }
            foreach ($criterios as $indice => $criterio) {
                $fotoChecklist=null;
                if(isset($_FILES['checklist_foto']['error'][$indice])&&$_FILES['checklist_foto']['error'][$indice]===UPLOAD_ERR_OK){
                    $archivoChecklist=['name'=>$_FILES['checklist_foto']['name'][$indice],'type'=>$_FILES['checklist_foto']['type'][$indice],'tmp_name'=>$_FILES['checklist_foto']['tmp_name'][$indice],'error'=>$_FILES['checklist_foto']['error'][$indice],'size'=>$_FILES['checklist_foto']['size'][$indice]];
                    $validated=validateUploadedFile($archivoChecklist,['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'],8*1024*1024);
                    $nombreFoto=randomUploadName('checklist_'.$checklistId.'_'.$indice.'_',$validated['extension']);
                    if(!move_uploaded_file($archivoChecklist['tmp_name'],UPLOAD_DIR.$nombreFoto))throw new Exception('No se pudo guardar una foto opcional del checklist.');
                    $fotoChecklist=UPLOAD_URL.$nombreFoto;
                }
                $stmtRespuesta->execute([':cid'=>$checklistId, ':criterio'=>trim($criterio), ':estado'=>$estadosChecklist[$indice], ':obs'=>trim($observacionesChecklist[$indice] ?? '') ?: null,':foto'=>$fotoChecklist]);
            }
            // Documento vencido → observación automática con la falta del catálogo (una por documento y supervisión).
            sgsObservacionesPorVencimiento($pdo, (int)$supervisionId, !empty($cuadrillaSup['partner_id']) ? (int)$cuadrillaSup['partner_id'] : null,
                !empty($cuadrillaSup['tecnico1_id']) ? (int)$cuadrillaSup['tecnico1_id'] : null, !empty($cuadrillaSup['vehiculo_id']) ? (int)$cuadrillaSup['vehiculo_id'] : null, (int)($user['id'] ?? 0));
            }
        }

        // Vencimientos (2026-10-15): al FINALIZAR, las fechas registradas en el checklist pasan a la cuadrilla (vehículo y
        // técnico líder con los que terminó la supervisión) y salen en las siguientes supervisiones.
        if ($esCierreDefinitivo) {
            require_once __DIR__ . '/../../config/vencimientos.php';
            $stFinal = $pdo->prepare('SELECT tecnico1_id, vehiculo_id FROM supervisiones WHERE id = :id');
            $stFinal->execute([':id' => $supervisionId]);
            $finalSup = $stFinal->fetch(PDO::FETCH_ASSOC) ?: [];
            sgsAplicarPendientesSupervision($pdo, (int)$supervisionId, !empty($finalSup['tecnico1_id']) ? (int)$finalSup['tecnico1_id'] : null,
                !empty($finalSup['vehiculo_id']) ? (int)$finalSup['vehiculo_id'] : null);
        }

        // Resultado de la cancelación cargada: "En proceso de rescate" pasa la orden a rescate (vuelve a Pendiente, a cargo
        // de quien la validó, sin ocupar su supervisión en proceso); "Rescate" al finalizar la cierra como rescatada.
        if ($isImportedCancellationEdit && $editId > 0) {
            $quien = (int)($user['id'] ?? 0);
            if ($resultadoCancelacion === 'En proceso de rescate') {
                $pdo->prepare("UPDATE supervisiones SET estado_supervision = 'Pendiente', supervisor_id = :u, rescate_responsable_id = :r, rescate_estado = 'En rescate',
                        rescate_inicio = NOW(), rescate_fin = NULL, estado_cancelacion = 'En proceso de rescate' WHERE id = :id")
                    ->execute([':u' => $quien, ':r' => $quien, ':id' => $editId]);
                $pdo->prepare("INSERT INTO rescate_seguimientos (supervision_id, usuario_id, tipo, comentario) VALUES (:s, :u, 'Inicio', :c)")
                    ->execute([':s' => $editId, ':u' => $quien, ':c' => trim((string)$observacionServicio) ?: null]);
                $estadoSupervision = 'Pendiente';
            } elseif ($esCierreDefinitivo) {
                $rescatada = $resultadoCancelacion === 'Rescate';
                $pdo->prepare('UPDATE supervisiones SET estado_cancelacion = :e' . ($rescatada
                        ? ", rescate_estado = 'Rescatado', rescate_responsable_id = :r, rescate_inicio = COALESCE(rescate_inicio, NOW()), rescate_fin = NOW()" : '') . ' WHERE id = :id')
                    ->execute($rescatada ? [':e' => 'Rescate', ':r' => $quien, ':id' => $editId] : [':e' => 'Cancelación correcta', ':id' => $editId]);
                if ($rescatada && ($targetRecord["rescate_estado"] ?? "") !== "Rescatado") { // una corrección del administrador no repite el cierre
                    $pdo->prepare("INSERT INTO rescate_seguimientos (supervision_id, usuario_id, tipo, comentario) VALUES (:s, :u, 'Cierre', :c)")
                        ->execute([':s' => $editId, ':u' => $quien, ':c' => 'Rescatado al validar la cancelación.' . (trim((string)$observacionServicio) !== '' ? ' ' . trim((string)$observacionServicio) : '')]);
                }
            }
        }

        // La copia de la pendiente (para Cancelar) se borra al finalizar o si vuelve a Pendiente (2026-10-16).
        if ($esCierreDefinitivo || $estadoSupervision === 'Pendiente') {
            $pdo->prepare('UPDATE supervisiones SET datos_pendiente = NULL WHERE id = :id')->execute([':id' => $supervisionId]);
        }

        // Historial de estados: da la hora real de inicio y de finalización (línea de tiempo, indicadores).
        $estadoAnterior = $editId > 0 ? (string)($existingSupervision['estado_supervision'] ?? '') : null;
        if ($estadoAnterior !== $estadoSupervision) {
            $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por) VALUES (:sid, :antes, :nuevo, :por)")
                ->execute([':sid' => (int)$supervisionId, ':antes' => $estadoAnterior ?: null, ':nuevo' => $estadoSupervision, ':por' => (int)($user['id'] ?? 0) ?: null]);
        }

        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => ($resultadoCancelacion === 'En proceso de rescate')
                ? 'La orden pasó a rescate: registra los avances hasta cerrarla.'
                : (($estadoSupervision === 'Pendiente' && $estadoCancelacion === 'En proceso de rescate')
                ? 'Cancelación guardada en proceso de rescate. La orden continúa pendiente.'
                : (($estadoSupervision === 'En proceso' && $estadoCancelacion === 'En proceso de rescate')
                    ? 'Rescate abierto. La supervisión ahora está En proceso.'
                    : (($estadoSupervision === 'En proceso') ? 'Supervisión guardada en proceso.' : 'Supervisión finalizada y cerrada exitosamente.'))),
            'supervision_id' => $supervisionId,
            'codigo' => $codigoSupervision,
            'estado_supervision' => $estadoSupervision
        ]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Error al registrar supervisión: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * Construye el alcance de lectura del listado sin mezclarlo con los filtros de
 * presentación. Se reutiliza para la página incremental, los contadores y el
 * mapa de pendientes, evitando que cada endpoint aplique reglas distintas.
 *
 * @return array{0:array<int,string>,1:array<string,mixed>}
 */
function supervisionListVisibility(array $user, ?string $fechaInicio = null, ?string $fechaFin = null, int $highlightId = 0, int $supervisorIdFilter = 0): array {
    $where = [];
    $params = [];

    if ($fechaInicio !== null && $fechaFin !== null) {
        $params[':f_inicio'] = $fechaInicio;
        $params[':f_fin'] = $fechaFin;
        if ($highlightId > 0) {
            $where[] = "((s.fecha_registro >= :f_inicio AND s.fecha_registro < DATE_ADD(:f_fin, INTERVAL 1 DAY)) OR s.id = :highlight_id)";
            $params[':highlight_id'] = $highlightId;
        } else {
            $where[] = "s.fecha_registro >= :f_inicio AND s.fecha_registro < DATE_ADD(:f_fin, INTERVAL 1 DAY)";
        }
    }

    // Los registros ya gestionados se conservan por usuario, sin depender de que
    // posteriormente cambie su zona. Las pendientes funcionan como una bolsa común
    // de la zona y dejan de ser compartidas cuando un supervisor las inicia.
    $canViewAllSupervisions = Auth::can(Auth::PERM_SUPERVISION_VIEW_ALL, $user);
    if (Auth::isAdministrative() || $canViewAllSupervisions) $where[] = sgsAlcanceSupervisionSql($user, 's', $params); // líder: sus zonas y su área
    if ($canViewAllSupervisions && $supervisorIdFilter > 0) {
        $where[] = "s.supervisor_id = :filter_sup_id";
        $params[':filter_sup_id'] = $supervisorIdFilter;
    } else if ($canViewAllSupervisions) {
        // Visibilidad global otorgada explícitamente por el administrador.
    } else if ($user['rol'] === Auth::ROLE_JEFE_OPERACIONES) {
        // Jefe de operaciones (2026-10-12): todas las órdenes asignadas a su partner, en cualquier estado y zona.
        $where[] = "s.partner_id = :my_partner_id AND s.fecha_asignacion IS NOT NULL";
        $params[':my_partner_id'] = (int)($user['partner_id'] ?? 0);
    } else if ($user['rol'] === Auth::ROLE_SUPERVISOR) {
        $directScope = getSupervisorTerritorialScope($user);
        $isPartnerSupervisor = empty($user['is_win_directo']) && !empty($user['partner_id']);
        if ($isPartnerSupervisor) {
            $where[] = "s.supervisor_id=:my_user_id AND s.partner_id=:scope_partner_id AND s.estado_supervision='Pendiente' AND s.fecha_asignacion IS NOT NULL";
            $params[':my_user_id'] = (int)$user['id'];
            $params[':scope_partner_id'] = (int)$user['partner_id'];
        } else {
            $pendingVisibility = pendingTerritorialCondition('s', $directScope, (int)$user['id'], ':scope_access_user', ':direct_department', $params);
            $where[] = "((s.supervisor_id = :my_user_id AND s.estado_supervision <> 'Pendiente') OR s.asignado_por=:assigned_by_me"
                . " OR ({$pendingVisibility} AND COALESCE(s.rescate_estado,'') <> 'En rescate')"
                . " OR s.rescate_responsable_id = :my_rescue_id)";
            $params[':my_user_id'] = (int)$user['id'];
            $params[':my_rescue_id'] = (int)$user['id'];
            $params[':assigned_by_me'] = (int)$user['id'];
        }
    } else if ($supervisorIdFilter > 0) {
        // Administradores y coordinadores pueden filtrar por supervisor específico
        $where[] = "s.supervisor_id = :filter_sup_id";
        $params[':filter_sup_id'] = $supervisorIdFilter;
    }

    return [$where ?: ['1=1'], $params];
}

// 2. Listado paginado y filtrado estrictamente por alcance del usuario.
if ($action === 'list') {
    $fechaInicio = (string)($_GET['fecha_inicio'] ?? '2000-01-01');
    $fechaFin = (string)($_GET['fecha_fin'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) $fechaInicio = '2000-01-01';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) $fechaFin = date('Y-m-d');
    if ($fechaInicio > $fechaFin) [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];

    $page = max(1, (int)($_GET['page_number'] ?? 1));
    $limit = min(100, max(20, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $highlightId = max(0, (int)($_GET['highlight'] ?? 0));
    $supervisorIdFilter = max(0, (int)($_GET['supervisor_id'] ?? 0));
    $departamentoFilter = trim((string)($_GET['departamento'] ?? ''));
    $estadoFilter = strtolower(trim((string)($_GET['estado'] ?? 'todos')));

    [$baseWhere, $baseParams] = supervisionListVisibility(
        $user,
        $fechaInicio,
        $fechaFin,
        $highlightId,
        $supervisorIdFilter
    );

    // El departamento es un filtro de presentación; se conserva el alcance de
    // seguridad construido arriba y nunca lo reemplaza.
    $filteredWhere = $baseWhere;
    $filteredParams = $baseParams;
    if ($departamentoFilter !== '') {
        // La colación utf8mb4 del catálogo ya es insensible a mayúsculas; evitar
        // funciones sobre la columna permite aprovechar su índice territorial.
        $filteredWhere[] = 's.departamento=:list_departamento';
        $filteredParams[':list_departamento'] = $departamentoFilter;
    }
    // Búsqueda por código de pedido o ticket (mismo campo) y por código de supervisión (2026-10-12).
    $codigoFilter = mb_substr(trim((string)($_GET['codigo'] ?? '')), 0, 60);
    if ($codigoFilter !== '') {
        $codigoLike = '%' . addcslashes($codigoFilter, '%_\\') . '%';
        $filteredWhere[] = '(s.ticket_codigo LIKE :list_codigo_ticket OR s.codigo_supervision LIKE :list_codigo_sup)';
        $filteredParams[':list_codigo_ticket'] = $codigoLike;
        $filteredParams[':list_codigo_sup'] = $codigoLike;
    }

    $countWhere = $filteredWhere;
    $countParams = $filteredParams;
    $stateSql = "CASE
        WHEN s.rescate_estado='En rescate' THEN 'rescate'
        WHEN LOWER(TRIM(s.estado_supervision))='pendiente' THEN 'pendiente'
        WHEN LOWER(TRIM(s.estado_supervision)) IN ('culminada','finalizada','finalizado','cancelada') THEN 'finalizada'
        ELSE 'iniciada' END";
    // "Mi agenda" del partner (2026-10-12), según el plazo de 24 horas desde la asignación:
    // Pendientes = sin atender y en plazo · En proceso = el partner ya registró su atención · No revisados = vencidas sin atender.
    $partnerAgendaSql = [
        'partner_pendiente' => "(s.fecha_asignacion IS NOT NULL AND s.estado_atencion_partner='Pendiente' AND NOW()<=DATE_ADD(s.fecha_asignacion,INTERVAL 24 HOUR))",
        'partner_en_proceso' => "(s.fecha_asignacion IS NOT NULL AND s.estado_atencion_partner='Atendida')",
        'partner_vencida' => "(s.fecha_asignacion IS NOT NULL AND s.estado_atencion_partner='Pendiente' AND NOW()>DATE_ADD(s.fecha_asignacion,INTERVAL 24 HOUR))",
    ];
    if ($estadoFilter === 'pendiente') {
        $filteredWhere[] = "s.estado_supervision='Pendiente' AND COALESCE(s.rescate_estado,'') <> 'En rescate'";
    } elseif ($estadoFilter === 'rescate') {
        $filteredWhere[] = "s.rescate_estado='En rescate'";
    } elseif ($estadoFilter === 'finalizada') {
        $filteredWhere[] = "s.estado_supervision IN ('Culminada','Finalizada','Finalizado','Cancelada')";
    } elseif ($estadoFilter === 'iniciada') {
        $filteredWhere[] = "s.estado_supervision IN ('Iniciada','En proceso')";
    } elseif (isset($partnerAgendaSql[$estadoFilter])) {
        $filteredWhere[] = $partnerAgendaSql[$estadoFilter];
    }

    $filteredWhereSql = implode(' AND ', $filteredWhere);
    $countWhereSql = implode(' AND ', $countWhere);

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM supervisiones s WHERE {$filteredWhereSql}");
    $stmtTotal->execute($filteredParams);
    $total = (int)$stmtTotal->fetchColumn();

    $stmtCounts = $pdo->prepare("SELECT
        COUNT(*) total,
        SUM({$stateSql}='iniciada') iniciada,
        SUM({$stateSql}='finalizada') finalizada,
        SUM({$stateSql}='pendiente') pendiente,
        SUM({$stateSql}='rescate') rescate,
        SUM({$partnerAgendaSql['partner_pendiente']}) partner_pendiente,
        SUM({$partnerAgendaSql['partner_en_proceso']}) partner_en_proceso,
        SUM({$partnerAgendaSql['partner_vencida']}) partner_vencida
        FROM supervisiones s WHERE {$countWhereSql}");
    $stmtCounts->execute($countParams);
    $counts = $stmtCounts->fetch() ?: [];

    // Opciones pequeñas y exactas para que los filtros sigan encontrando datos
    // aunque el valor todavía no aparezca dentro de la primera página.
    [$optionsWhere, $optionsParams] = supervisionListVisibility($user,$fechaInicio,$fechaFin,$highlightId,0);
    $baseWhereSql = implode(' AND ', $optionsWhere);
    $stmtDepartments = $pdo->prepare("SELECT DISTINCT s.departamento FROM supervisiones s
        WHERE {$baseWhereSql} AND s.departamento IS NOT NULL AND TRIM(s.departamento)<>'' ORDER BY s.departamento");
    $stmtDepartments->execute($optionsParams);
    $departments = $stmtDepartments->fetchAll(PDO::FETCH_COLUMN);

    $stmtSupervisors = $pdo->prepare("SELECT DISTINCT u.id,u.nombre_completo
        FROM supervisiones s JOIN usuarios u ON u.id=s.supervisor_id
        WHERE {$baseWhereSql} ORDER BY u.nombre_completo");
    $stmtSupervisors->execute($optionsParams);
    $supervisors = $stmtSupervisors->fetchAll();

    $activeOwnerId = 0;
    if (($user['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($user['is_win_directo'])) {
        $activeOwnerId = (int)$user['id'];
    }
    $activeSupervision = null;
    if ($activeOwnerId > 0) {
        $stmtActive = $pdo->prepare("SELECT id,codigo_supervision,ticket_codigo FROM supervisiones
            WHERE supervisor_id=:owner AND estado_supervision IN ('Iniciada','En proceso')
            ORDER BY fecha_registro DESC LIMIT 1");
        $stmtActive->execute([':owner' => $activeOwnerId]);
        $activeSupervision = $stmtActive->fetch() ?: null;
    }

    [$pendingMapWhere, $pendingMapParams] = supervisionListVisibility($user);
    $pendingMapWhere[] = "s.estado_supervision='Pendiente'";
    $stmtPendingMapTotal = $pdo->prepare('SELECT COUNT(*) FROM supervisiones s WHERE ' . implode(' AND ', $pendingMapWhere));
    $stmtPendingMapTotal->execute($pendingMapParams);
    $pendingMapTotal = (int)$stmtPendingMapTotal->fetchColumn();
    $highlightOrderSql = $highlightId > 0 ? 's.id=' . $highlightId . ' DESC,' : '';

    $sql = "
        SELECT s.*, u.nombre_completo AS supervisor_nombre, u.dni AS supervisor_dni, p.nombre_partner,
               IF(s.fecha_asignacion IS NOT NULL AND s.partner_id=u.partner_id AND UPPER(TRIM(COALESCE(p.nombre_partner,''))) NOT IN ('WIN','WIN DIRECTO'),1,0) AS es_asignacion_partner,
               DATE_ADD(s.fecha_asignacion, INTERVAL 24 HOUR) AS fecha_limite_partner,
               IF(s.fecha_asignacion IS NOT NULL AND s.estado_atencion_partner='Pendiente' AND NOW()>DATE_ADD(s.fecha_asignacion,INTERVAL 24 HOUR),1,0) AS plazo_partner_vencido,
               -- 2026-10-26: se cuentan solo las de la página (por índice); antes se agrupaba toda la tabla en cada carga
               (SELECT COUNT(*) FROM observaciones_supervision o WHERE o.supervision_id = s.id) AS total_observaciones
        FROM supervisiones s
        JOIN usuarios u ON s.supervisor_id = u.id
        LEFT JOIN partners p ON s.partner_id = p.id
        WHERE {$filteredWhereSql}
        ORDER BY {$highlightOrderSql}s.fecha_registro DESC,s.id DESC
        LIMIT :page_limit OFFSET :page_offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($filteredParams as $key => $value) $stmt->bindValue($key, $value);
    $stmt->bindValue(':page_limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':page_offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll();
    // Pendientes de la bolsa común: sin tomar, sin rescate y sin asignar a un partner. 2026-10-16: se muestran como
    // "Aún no gestionado" (antes, todos los supervisores de la zona: parecía asignada a uno). Al tomarla, sale su nombre.
    foreach ($data as &$fila) {
        // Asignada a partner = es_asignacion_partner (no basta fecha_asignacion: la carga del Excel la llena siempre).
        $fila['sin_gestionar'] = ($fila['estado_supervision'] ?? '') === 'Pendiente' && empty($fila['rescate_estado']) && empty($fila['es_asignacion_partner']) ? 1 : 0;
        $fila = sgsSinOrigenParaPartner($user, $fila);
    }
    unset($fila);

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'meta' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'has_more' => $offset + count($data) < $total,
            'counts' => [
                'todos' => (int)($counts['total'] ?? 0),
                'iniciada' => (int)($counts['iniciada'] ?? 0),
                'partner_pendiente' => (int)($counts['partner_pendiente'] ?? 0),
                'partner_en_proceso' => (int)($counts['partner_en_proceso'] ?? 0),
                'partner_vencida' => (int)($counts['partner_vencida'] ?? 0),
                'finalizada' => (int)($counts['finalizada'] ?? 0),
                'pendiente' => (int)($counts['pendiente'] ?? 0),
                'rescate' => (int)($counts['rescate'] ?? 0),
            ],
            'departamentos' => $departments,
            'supervisores' => $supervisors,
            'active_supervision' => $activeSupervision,
            'pending_map_total' => $pendingMapTotal,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// El mapa se carga solo al abrirlo y transporta únicamente los campos que usa.
if ($action === 'pending_map') {
    [$where, $params] = supervisionListVisibility($user);
    $where[] = "s.estado_supervision='Pendiente'";
    $stmt = $pdo->prepare("SELECT s.id,s.codigo_supervision,s.ticket_codigo,s.latitud,s.longitud,s.departamento,s.distrito,s.direccion
        FROM supervisiones s WHERE " . implode(' AND ', $where) . " ORDER BY s.fecha_registro DESC,s.id DESC");
    $stmt->execute($params);
    $data = $stmt->fetchAll();
    echo json_encode(['status'=>'success','data'=>$data,'total'=>count($data)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 3. Detalle de una supervisión específica
if ($action === 'detail') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT s.*, u.nombre_completo AS supervisor_nombre, u.dni AS supervisor_dni, p.nombre_partner,
               rr.nombre_completo AS rescate_responsable_nombre, ua.nombre_completo AS asignado_por_nombre
        FROM supervisiones s
        JOIN usuarios u ON s.supervisor_id = u.id
        LEFT JOIN partners p ON s.partner_id = p.id
        LEFT JOIN usuarios rr ON rr.id = s.rescate_responsable_id
        LEFT JOIN usuarios ua ON ua.id = s.asignado_por
        WHERE s.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $supervision = $stmt->fetch();

    if (!$supervision) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Supervisión no encontrada.']);
        exit;
    }

    if (!canAccessSupervision($user, $supervision, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tiene acceso a esta supervisión.']);
        exit;
    }

    // Historial del rescate (2026-10-13)
    $stmtRes = $pdo->prepare("SELECT r.id, r.tipo, r.comentario, r.foto, r.fecha, u.nombre_completo AS usuario
        FROM rescate_seguimientos r LEFT JOIN usuarios u ON u.id = r.usuario_id WHERE r.supervision_id = :id ORDER BY r.fecha ASC, r.id ASC");
    $stmtRes->execute([':id' => $id]);
    $rescateSeguimientos = $stmtRes->fetchAll();

    // Obtener observaciones vinculadas
    $stmtObs = $pdo->prepare("
        SELECT o.*, c.nombre_categoria, sub.descripcion_subopcion
        FROM observaciones_supervision o
        LEFT JOIN categorias_observacion c ON o.categoria_id = c.id
        LEFT JOIN subopciones_observacion sub ON o.subopcion_id = sub.id
        WHERE o.supervision_id = :sup_id
        ORDER BY o.id ASC
    ");
    $stmtObs->execute([':sup_id' => $id]);
    $observaciones = $stmtObs->fetchAll();

    echo json_encode([
        'status' => 'success',
        'supervision' => sgsSinOrigenParaPartner($user, $supervision),
        'observaciones' => $observaciones,
        'rescate_seguimientos' => $rescateSeguimientos,
    ]);
    exit;
}

// 4. Eliminar supervisión
// Supervisión con guardado automático (2026-10-16): "Nueva supervisión" la crea al instante En proceso (sin exigir la
// sección 1); el formulario se va guardando solo con action=create; solo hay "Cancelar" y "Finalizar".
// Mismas reglas: supervisor directo con turno iniciado y una sola supervisión en proceso (si ya tiene una, se retoma).
$sgsPuedeTrabajar = static function () use ($pdo, $user): ?string {
    if (($user['rol'] ?? '') !== Auth::ROLE_SUPERVISOR || empty($user['is_win_directo']) || !Auth::can(Auth::PERM_SUPERVISION_CREATE, $user)) {
        return 'Las supervisiones operativas solo pueden ser registradas por un supervisor directo.';
    }
    $st = $pdo->prepare('SELECT fin_turno FROM asistencia_supervisores WHERE supervisor_id = :s AND fecha = CURRENT_DATE() LIMIT 1');
    $st->execute([':s' => (int)$user['id']]);
    $turno = $st->fetch();
    if (!$turno) return 'Inicia tu turno de hoy para registrar supervisiones.';
    if (!empty($turno['fin_turno'])) return 'Ya finalizaste tu turno de hoy. No puedes seguir trabajando en supervisiones.';
    return null;
};
$sgsBorrarArchivo = static function (?string $ruta): void {
    $ruta = (string)$ruta;
    if ($ruta !== '' && str_starts_with($ruta, 'uploads/') && !str_contains($ruta, '..')) @unlink(SHARED_FILES_PATH . '/' . $ruta);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'iniciar_nueva') {
    if ($motivo = $sgsPuedeTrabajar()) { http_response_code(403); echo json_encode(['status' => 'error', 'message' => $motivo], JSON_UNESCAPED_UNICODE); exit; }
    $st = $pdo->prepare("SELECT id, codigo_supervision, ticket_codigo FROM supervisiones WHERE supervisor_id = :s AND estado_supervision IN ('Iniciada', 'En proceso') ORDER BY id DESC LIMIT 1");
    $st->execute([':s' => (int)$user['id']]);
    if ($activa = $st->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode(['status' => 'activa', 'id' => (int)$activa['id'], 'codigo' => $activa['codigo_supervision'],
            'message' => 'Ya tienes una supervisión en proceso (' . ($activa['ticket_codigo'] ?: $activa['codigo_supervision']) . '): se abre para continuar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $zonas = sgsUserZones((int)$user['id']);
    $departamento = is_array($zonas) && count($zonas) === 1 ? sgsDepartamentoDeZona($zonas[0]) : '';
    $codigo = 'SUP-' . date('Ymd') . '-' . random_int(1000, 9999);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
                tipo_supervision_inicial, clasificacion_supervision, area_servicio, estado_supervision)
            VALUES (:c, NOW(), :s, 'Autogestión', 'Regular', :d, '', '', 'En caliente', 'Instalación', 'Instalación', 'En proceso')")
            ->execute([':c' => $codigo, ':s' => (int)$user['id'], ':d' => $departamento]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por) VALUES (:id, NULL, 'En proceso', :u)")
            ->execute([':id' => $id, ':u' => (int)$user['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        win_log_error('supervision_start_error', $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo iniciar la supervisión. Inténtelo otra vez.', 'code' => sgs_error_code()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['status' => 'success', 'id' => $id, 'codigo' => $codigo, 'message' => 'Supervisión iniciada: se guarda sola mientras la llenas.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancelar') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM supervisiones WHERE id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $sup = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sup) throw new RuntimeException('Esta supervisión ya no existe: fue cancelada o eliminada (quizá desde otro equipo).', 404);
        if ((int)$sup['supervisor_id'] !== (int)($user['id'] ?? 0)) throw new RuntimeException('Solo quien trabaja la supervisión puede cancelarla.');
        if (!in_array($sup['estado_supervision'], ['Iniciada', 'En proceso'], true)) throw new RuntimeException('Solo se puede cancelar una supervisión en proceso.');
        // Fechas de documentos registradas en este intento (pendientes) y fotos que no estaban antes.
        $st = $pdo->prepare('SELECT foto FROM documentos_vencimiento WHERE supervision_id = :id AND aplicado = 0');
        $st->execute([':id' => $id]);
        $archivos = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM documentos_vencimiento WHERE supervision_id = :id AND aplicado = 0')->execute([':id' => $id]);
        $copia = !empty($sup['datos_pendiente']) ? json_decode((string)$sup['datos_pendiente'], true) : null;
        if (is_array($copia)) {
            // Fue una pendiente: vuelve a como estaba (se descarta lo avanzado: datos, fotos, checklist y observaciones del intento).
            $inicio = (string)($copia['__inicio'] ?? date('Y-m-d H:i:s'));
            foreach (['foto_servicio_1', 'foto_servicio_2', 'foto_servicio_3'] as $k) if (($sup[$k] ?? null) && ($sup[$k] !== ($copia[$k] ?? null))) $archivos[] = $sup[$k];
            $st = $pdo->prepare('SELECT foto_1, foto_2, foto_3 FROM observaciones_supervision WHERE supervision_id = :id AND fecha_registro >= :i');
            $st->execute([':id' => $id, ':i' => $inicio]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $fila) foreach ($fila as $ruta) if ($ruta) $archivos[] = $ruta;
            $pdo->prepare('DELETE FROM observaciones_supervision WHERE supervision_id = :id AND fecha_registro >= :i')->execute([':id' => $id, ':i' => $inicio]);
            $pdo->prepare('DELETE FROM checklists_tecnicos WHERE supervision_id = :id AND fecha_registro >= :i')->execute([':id' => $id, ':i' => $inicio]);
            $columnas = array_flip(array_column($pdo->query('SHOW COLUMNS FROM supervisiones')->fetchAll(PDO::FETCH_ASSOC), 'Field'));
            $sets = []; $vals = [':id' => $id];
            foreach ($copia as $col => $valor) {
                if ($col === 'id' || $col === 'datos_pendiente' || !isset($columnas[$col])) continue;
                $sets[] = "`$col` = :c_" . count($sets);
                $vals[':c_' . (count($sets) - 1)] = $valor;
            }
            $sets[] = 'datos_pendiente = NULL';
            $pdo->prepare('UPDATE supervisiones SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($vals);
            $pdo->prepare('INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por) VALUES (:id, :a, :n, :u)')
                ->execute([':id' => $id, ':a' => $sup['estado_supervision'], ':n' => $copia['estado_supervision'] ?? 'Pendiente', ':u' => (int)$user['id']]);
            $mensaje = 'Supervisión cancelada: la orden volvió a Pendiente, tal como estaba.';
        } else {
            // Nueva: se elimina (sus observaciones se borran con ella).
            foreach (['foto_servicio_1', 'foto_servicio_2', 'foto_servicio_3'] as $k) if (!empty($sup[$k])) $archivos[] = $sup[$k];
            $st = $pdo->prepare('SELECT foto_1, foto_2, foto_3 FROM observaciones_supervision WHERE supervision_id = :id');
            $st->execute([':id' => $id]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $fila) foreach ($fila as $ruta) if ($ruta) $archivos[] = $ruta;
            $st = $pdo->prepare('SELECT firma_supervisor FROM checklists_tecnicos WHERE supervision_id = :id');
            $st->execute([':id' => $id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ruta) if ($ruta) $archivos[] = $ruta;
            $pdo->prepare('DELETE FROM checklists_tecnicos WHERE supervision_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM supervisiones WHERE id = :id')->execute([':id' => $id]);
            $mensaje = 'Supervisión cancelada y eliminada.';
        }
        $pdo->commit();
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'codigo' => $e->getCode() === 404 ? 'no_existe' : null], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        win_log_error('supervision_cancel_error', $e->getMessage(), ['supervision_id' => $id]);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo cancelar la supervisión.', 'code' => sgs_error_code()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach (array_unique($archivos) as $ruta) $sgsBorrarArchivo($ruta);
    win_log_error('audit_supervision_cancelled', $mensaje, ['supervision_id' => $id, 'codigo' => $sup['codigo_supervision'], 'ticket' => $sup['ticket_codigo'], 'era_pendiente' => is_array($copia)]);
    echo json_encode(['status' => 'success', 'message' => $mensaje, 'volvio_a_pendiente' => is_array($copia)], JSON_UNESCAPED_UNICODE);
    exit;
}
// Corregir una pendiente cargada (2026-10-27): quien carga las cancelaciones (el administrador) corrige los datos que
// vinieron del Excel mientras la pendiente no se inicia ni se toma como rescate. La ubicación se valida contra el
// catálogo; si cambia el departamento, la pendiente pasa a la bolsa de la nueva zona y se avisa a sus supervisores.
// tecnico1: "actual" = se deja la cuadrilla como está · "" = sin cuadrilla · id = técnico líder del partner.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'corregir_pendiente') {
    require_once __DIR__ . '/../../config/asignacion_cancelaciones.php';
    require_once __DIR__ . '/../../config/zonas_peru.php';
    $responder = static function (int $codigo, string $mensaje, string $estado = 'error'): void {
        http_response_code($codigo);
        echo json_encode(['status' => $estado, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!Auth::can(Auth::PERM_SUPERVISION_IMPORT, $user)) $responder(403, 'Solo quien carga las cancelaciones puede corregir las pendientes.');
    $id = (int)($_POST['id'] ?? 0);
    $texto = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $max);
    $ticket = normalizarTicket((string)($_POST['ticket'] ?? ''));
    $tipo = (string)($_POST['tipo'] ?? '');
    $fecha = (string)($_POST['fecha_cancelacion'] ?? '');
    $motivo = $texto('motivo', 200);
    $direccion = $texto('direccion', 255);
    $obsPrevia = trim((string)($_POST['observacion_previa'] ?? ''));
    $tecnico = (string)($_POST['tecnico1'] ?? 'actual');
    $errores = [];
    if ($ticket === '') $errores[] = 'Escriba el código de pedido o ticket.';
    if (!in_array($tipo, ['Instalación', 'Avería', 'Postventa', 'Reiterada', 'Garantía'], true)) $errores[] = 'Elija la gestión o actividad.';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $f) || !checkdate((int)$f[2], (int)$f[3], (int)$f[1])) $errores[] = 'La fecha de cancelación no es válida.';
    [$lat, $lng] = [null, null];
    $coordenadas = trim((string)($_POST['coordenadas'] ?? ''));
    if ($coordenadas !== '') {
        if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\/\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $coordenadas, $c) && abs((float)$c[1]) <= 90 && abs((float)$c[2]) <= 180) [$lat, $lng] = [$c[1], $c[2]];
        else $errores[] = 'Las coordenadas deben ir como "latitud, longitud" (por ejemplo: -16.3988, -71.5369).';
    }
    $ubicacion = sgsValidarUbicacion($pdo, (string)($_POST['departamento'] ?? ''), (string)($_POST['provincia'] ?? ''), (string)($_POST['distrito'] ?? ''));
    if ($ubicacion['error'] !== '') $errores[] = $ubicacion['error'];
    $st = $pdo->prepare('SELECT id, nombre_partner FROM partners WHERE id = :id AND estado = 1');
    $st->execute([':id' => (int)($_POST['partner_id'] ?? 0)]);
    $partner = $st->fetch(PDO::FETCH_ASSOC);
    if (!$partner) $errores[] = 'Elija un partner activo.';
    $lider = null;
    if ($partner && ctype_digit($tecnico)) {
        $st = $pdo->prepare('SELECT id, nombre_tecnico, dni FROM tecnicos WHERE id = :id AND partner_id = :p AND COALESCE(tipo_tecnico, 1) = 1');
        $st->execute([':id' => (int)$tecnico, ':p' => $partner['id']]);
        $lider = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lider) $errores[] = 'La cuadrilla elegida no es un técnico líder de ese partner.';
    }
    if ($errores) $responder(422, implode(' ', $errores));

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT s.*, p.nombre_partner AS partner_supervisor FROM supervisiones s JOIN usuarios u ON u.id = s.supervisor_id
            LEFT JOIN partners p ON p.id = u.partner_id WHERE s.id = :id FOR UPDATE');
        $st->execute([':id' => $id]);
        $sup = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sup) throw new RuntimeException('Esta pendiente ya no existe.', 404);
        if ($sup['estado_supervision'] !== 'Pendiente' || !(int)$sup['es_cancelacion'] || !(int)$sup['origen_importacion'] || ($sup['rescate_estado'] ?? '') === 'En rescate') {
            throw new RuntimeException('Solo se corrigen pendientes cargadas que aún no se iniciaron ni están en rescate. Actualice la lista.', 409);
        }
        $st = $pdo->prepare('SELECT codigo_supervision FROM supervisiones WHERE id <> :id AND UPPER(TRIM(ticket_codigo)) = :t LIMIT 1');
        $st->execute([':id' => $id, ':t' => $ticket]);
        if ($otra = $st->fetchColumn()) throw new RuntimeException("El ticket {$ticket} ya está en la supervisión {$otra}.", 409);
        $zonaNueva = sgsZonaDeUbicacion($ubicacion['departamento'], $ubicacion['provincia']);
        $cambiaZona = sgsZonaDeUbicacion($sup['departamento'], $sup['provincia']) !== $zonaNueva;
        // Asignada a un supervisor de partner: el partner y la zona quedan como están (la asignación depende de ellos)
        $asignadaPartner = trim((string)$sup['partner_supervisor']) !== '' && !in_array(mb_strtoupper(trim((string)$sup['partner_supervisor']), 'UTF-8'), ['WIN', 'WIN DIRECTO'], true);
        if ($asignadaPartner && ($cambiaZona || (int)$partner['id'] !== (int)$sup['partner_id'])) {
            throw new RuntimeException('Esta pendiente está asignada a un supervisor de partner: no se puede cambiar su partner ni su departamento.', 409);
        }
        $supervisorId = (int)$sup['supervisor_id'];
        if ($cambiaZona) {
            $nuevo = elegirSupervisorCancelacion($pdo, $zonaNueva, $tipo);
            if (!$nuevo) throw new RuntimeException("No hay supervisor directo activo para {$zonaNueva}.", 422);
            $supervisorId = (int)$nuevo['id'];
        }
        if ($motivo !== '') { // el motivo debe existir en el catálogo (como en la carga)
            $pdo->prepare('INSERT IGNORE INTO tipificaciones_cancelacion (descripcion, estado) VALUES (:d, 1)')->execute([':d' => $motivo]);
            $st = $pdo->prepare('SELECT descripcion FROM tipificaciones_cancelacion WHERE descripcion = :d LIMIT 1');
            $st->execute([':d' => $motivo]);
            $motivo = $st->fetchColumn() ?: $motivo;
        }
        [$t1Id, $t1Nombre, $t1Dni] = $tecnico === 'actual' ? [$sup['tecnico1_id'], $sup['tecnico1_nombre'], $sup['tecnico1_dni']]
            : ($lider ? [(int)$lider['id'], $lider['nombre_tecnico'], preg_match('/^\d{8}$/', (string)$lider['dni']) ? $lider['dni'] : null] : [null, null, null]);
        $pdo->prepare('UPDATE supervisiones SET ticket_codigo = :ticket, partner_id = :partner, clasificacion_supervision = :tipo, area_servicio = :tipo2,
                departamento = :dep, provincia = :prov, distrito = :dist, zona_asignacion = :zona, direccion = :dir, supervisor_id = :sup,
                tecnico1_id = :t1id, tecnico1_nombre = :t1n, tecnico1_dni = :t1d, fecha_cancelacion = :fecha, tipificacion_cancelacion = :motivo,
                observacion_previa = :obs, latitud = :lat, longitud = :lng WHERE id = :id')
            ->execute([':ticket' => $ticket, ':partner' => $partner['id'], ':tipo' => $tipo, ':tipo2' => $tipo, ':dep' => $ubicacion['departamento'],
                ':prov' => $ubicacion['provincia'] ?: null, ':dist' => $ubicacion['distrito'] ?: null,
                ':zona' => zonaAsignacionCancelacion($ubicacion['departamento'], $ubicacion['provincia'], $ubicacion['distrito']),
                ':dir' => $direccion !== '' ? $direccion : null, ':sup' => $supervisorId, ':t1id' => $t1Id, ':t1n' => $t1Nombre, ':t1d' => $t1Dni,
                ':fecha' => $fecha, ':motivo' => $motivo !== '' ? $motivo : null, ':obs' => $obsPrevia !== '' ? $obsPrevia : null,
                ':lat' => $lat, ':lng' => $lng, ':id' => $id]);
        if ($cambiaZona) {
            $aviso = $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, url_destino) VALUES (:u, 'Nueva cancelación pendiente en tu zona', :m, 'index.php?page=supervision_lista')");
            foreach (supervisoresZonaCancelacion($pdo, $zonaNueva, false, $tipo) as $z) {
                $aviso->execute([':u' => (int)$z['id'], ':m' => "La pendiente {$sup['codigo_supervision']} (ticket {$ticket}) se corrigió y ahora es de {$zonaNueva}."]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$e instanceof RuntimeException) win_log_error('pendiente_corregir', $e->getMessage(), ['id' => $id]);
        $responder(in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 500, $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar la corrección.');
    }
    $responder(200, 'Pendiente corregida' . ($cambiaZona ? " y movida a {$zonaNueva}." : '.'), 'success');
}
if ($action === 'delete') {
    // Solo POST (2026-10-13): por GET no se revisa el token CSRF y bastaba con abrir un enlace para borrar.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Use POST para eliminar.']);
        exit;
    }
    if (!Auth::can(Auth::PERM_SUPERVISION_DELETE, $user)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Solo el administrador puede eliminar supervisiones.']);
        exit;
    }
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de supervisión no válido.']);
        exit;
    }

    $stmtAccess = $pdo->prepare("SELECT id, supervisor_id, partner_id, estado_supervision FROM supervisiones WHERE id = :id LIMIT 1");
    $stmtAccess->execute([':id' => $id]);
    $target = $stmtAccess->fetch();
    if (!$target || !canAccessSupervision($user, $target, false)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tiene permisos para eliminar esta supervisión.']);
        exit;
    }

    // Copia completa en el registro antes de borrar (2026-10-12): antes no quedaba rastro de quién eliminó qué.
    $stmtCopia = $pdo->prepare('SELECT * FROM supervisiones WHERE id = :id');
    $stmtCopia->execute([':id' => $id]);
    $copia = $stmtCopia->fetch(PDO::FETCH_ASSOC) ?: [];
    $stmtDel = $pdo->prepare("DELETE FROM supervisiones WHERE id = :id");
    $stmtDel->execute([':id' => $id]);
    win_log_error('audit_supervision_deleted', 'Supervisión eliminada: ' . ($copia['ticket_codigo'] ?: ($copia['codigo_supervision'] ?? $id)),
        ['supervision_id' => $id, 'codigo' => $copia['codigo_supervision'] ?? null, 'ticket' => $copia['ticket_codigo'] ?? null, 'copia' => $copia]);

    echo json_encode(['status' => 'success', 'message' => 'Supervisión eliminada correctamente.']);
    exit;
}

// 5. Endpoint de Indicadores de Supervisión & Mapa de Calor (Filtros: Partner, Fechas, Supervisor, Provincia)
if ($action === 'indicadores_dashboard') {
    if (!Auth::can(Auth::PERM_DASHBOARD_VIEW, $user)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Se requieren permisos administrativos.']);
        exit;
    }
    $partnerId = intval($_GET['partner_id'] ?? 0);
    $fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
    $fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');
    $supervisorId = intval($_GET['supervisor_id'] ?? 0);
    $departamento = trim($_GET['departamento'] ?? '');
    $provincia = trim($_GET['provincia'] ?? '');

    $where = ["DATE(s.fecha_registro) BETWEEN :f_ini AND :f_fin", "s.estado_supervision IN ('Finalizada', 'Culminada', 'Finalizado')"];
    $params = [
        ':f_ini' => $fechaInicio,
        ':f_fin' => $fechaFin
    ];

    if ($partnerId > 0) {
        $where[] = "s.partner_id = :partner_id";
        $params[':partner_id'] = $partnerId;
    }
    if ($supervisorId > 0) {
        $where[] = "s.supervisor_id = :sup_id";
        $params[':sup_id'] = $supervisorId;
    }
    if (!empty($departamento)) {
        $where[] = "s.departamento = :dep";
        $params[':dep'] = $departamento;
    }
    if (!empty($provincia)) {
        $where[] = "s.provincia = :prov";
        $params[':prov'] = $provincia;
    }

    $whereSql = implode(' AND ', $where);

    // 1. Total General
    $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM supervisiones s WHERE {$whereSql}");
    $stmtTot->execute($params);
    $totalSupervisiones = intval($stmtTot->fetchColumn() ?: 0);

    // 1.1 Modalidad (2026-10-12): cuántas en caliente y cuántas en frío. El checklist y la visita de capacitación
    //     no tienen modalidad (se guarda un valor interno), por eso no cuentan aquí.
    $stmtMod = $pdo->prepare("SELECT s.tipo_supervision_inicial AS modalidad, COUNT(*) AS total FROM supervisiones s
        WHERE {$whereSql} AND s.tipo_supervision_inicial IN ('En caliente', 'En frío', 'Remota')
          AND COALESCE(s.clasificacion_supervision, '') NOT IN ('Checklist', 'Visita partner por capacitación')
        GROUP BY s.tipo_supervision_inicial");
    $stmtMod->execute($params);
    $modalidad = ['En caliente' => 0, 'En frío' => 0, 'Remota' => 0];
    foreach ($stmtMod->fetchAll() as $fila) $modalidad[$fila['modalidad']] = (int)$fila['total'];

    // 2. Ranking Partners Más Supervisados
    $stmtP = $pdo->prepare("
        SELECT COALESCE(p.nombre_partner, 'WIN / Directo') AS nombre_partner, COUNT(*) AS total
        FROM supervisiones s
        LEFT JOIN partners p ON s.partner_id = p.id
        WHERE {$whereSql}
        GROUP BY s.partner_id, p.nombre_partner
        ORDER BY total DESC
        LIMIT 7
    ");
    $stmtP->execute($params);
    $topPartners = $stmtP->fetchAll();

    // 3. Ranking Distritos / Zonas Más Supervisadas
    $stmtD = $pdo->prepare("
        SELECT s.distrito, s.provincia, s.departamento, COUNT(*) AS total
        FROM supervisiones s
        WHERE {$whereSql} AND s.distrito IS NOT NULL AND s.distrito != ''
        GROUP BY s.distrito, s.provincia, s.departamento
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmtD->execute($params);
    $topDistritos = $stmtD->fetchAll();

    // 4. Ranking Cuadrillas / Técnicos Más Supervisados
    $stmtC = $pdo->prepare("
        SELECT COALESCE(s.tecnico1_nombre, 'Técnico Líder') AS tecnico, COALESCE(p.nombre_partner, 'WIN') AS nombre_partner, COUNT(*) AS total
        FROM supervisiones s
        LEFT JOIN partners p ON s.partner_id = p.id
        WHERE {$whereSql} AND s.tecnico1_nombre IS NOT NULL AND s.tecnico1_nombre != ''
        GROUP BY s.tecnico1_nombre, p.nombre_partner
        ORDER BY total DESC
        LIMIT 10
    ");
    $stmtC->execute($params);
    $topCuadrillas = $stmtC->fetchAll();

    // 5. Puntos Geográficos con Coordenadas Lat/Lng para el Mapa Interactivo
    $geoCentroids = [
        'Surco' => [-12.1345, -76.9822],
        'Miraflores' => [-12.1211, -77.0297],
        'San Isidro' => [-12.0975, -77.0364],
        'Los Olivos' => [-11.9711, -77.0706],
        'San Borja' => [-12.1072, -77.0019],
        'Ate' => [-12.0264, -76.9181],
        'Comas' => [-11.9358, -77.0544],
        'Chorrillos' => [-12.1764, -77.0164],
        'Trujillo' => [-8.1159, -79.0299],
        'Víctor Larco Herrera' => [-8.1361, -79.0436],
        'El Porvenir' => [-8.0833, -79.0000],
        'La Esperanza' => [-8.0744, -79.0417],
        'Yanahuara' => [-16.3889, -71.5431],
        'Cayma' => [-16.3686, -71.5544],
        'Cerro Colorado' => [-16.3542, -71.5647],
        'Bustamante y Rivero' => [-16.4264, -71.5175],
        'Chiclayo' => [-6.7714, -79.8409],
        'Pimentel' => [-6.8367, -79.9342],
        'La Victoria' => [-6.7933, -79.8444],
        'Piura' => [-5.1945, -80.6328],
        'Castilla' => [-5.1978, -80.6133],
        'Catacaos' => [-5.2667, -80.6833],
        'Huancayo' => [-12.0651, -75.2049],
        'El Tambo' => [-12.0533, -75.2133],
        'Chilca' => [-12.0833, -75.2000],
        'Cusco' => [-13.5319, -71.9675],
        'Wanchaq' => [-13.5256, -71.9567],
        'San Sebastián' => [-13.5367, -71.9286],
        'Chimbote' => [-9.0853, -78.5783],
        'Nuevo Chimbote' => [-9.1242, -78.5303],
        'Ica' => [-14.0678, -75.7286],
        'Parcona' => [-14.0556, -75.7111],
        'Subtanjalla' => [-14.0194, -75.7528],
        'Tacna' => [-18.0147, -70.2525],
        'Pocollay' => [-18.0056, -70.2222],
    ];

    $stmtPoints = $pdo->prepare("
        SELECT s.id, s.codigo_supervision, s.fecha_registro, s.distrito, s.provincia, s.departamento,
               s.cliente_nombre, s.ticket_codigo, s.tecnico1_nombre, s.latitud, s.longitud,
               p.nombre_partner
        FROM supervisiones s
        LEFT JOIN partners p ON s.partner_id = p.id
        WHERE {$whereSql}
        ORDER BY s.fecha_registro DESC
        LIMIT 200
    ");
    $stmtPoints->execute($params);
    $pointsRaw = $stmtPoints->fetchAll();

    $mapPoints = [];
    foreach ($pointsRaw as $pt) {
        $lat = !empty($pt['latitud']) ? floatval($pt['latitud']) : null;
        $lng = !empty($pt['longitud']) ? floatval($pt['longitud']) : null;

        if (!$lat || !$lng) {
            $dist = $pt['distrito'];
            if (isset($geoCentroids[$dist])) {
                $lat = $geoCentroids[$dist][0] + (rand(-15, 15) / 1000.0);
                $lng = $geoCentroids[$dist][1] + (rand(-15, 15) / 1000.0);
            } else {
                $lat = -12.04637 + (rand(-50, 50) / 100.0);
                $lng = -77.04279 + (rand(-50, 50) / 100.0);
            }
        }

        $mapPoints[] = [
            'id' => $pt['id'],
            'codigo' => $pt['codigo_supervision'],
            'ticket' => $pt['ticket_codigo'] ?? null,
            'fecha' => date('d/m/Y H:i', strtotime($pt['fecha_registro'])),
            'distrito' => $pt['distrito'],
            'provincia' => $pt['provincia'],
            'departamento' => $pt['departamento'],
            'cliente' => $pt['cliente_nombre'],
            'partner' => $pt['nombre_partner'] ?? 'WIN',
            'tecnico' => $pt['tecnico1_nombre'] ?: null, // sin técnico: la fila no se muestra (antes decía "Técnico: Técnico")
            'lat' => round($lat, 6),
            'lng' => round($lng, 6)
        ];
    }

    $maxCount = 1;
    foreach ($topDistritos as $td) {
        if ($td['total'] > $maxCount) $maxCount = $td['total'];
    }

    $heatmapData = array_map(function($item) use ($maxCount) {
        $ratio = $item['total'] / $maxCount;
        $nivel = 'Bajo';
        $color = '#3b82f6'; // Azul
        if ($ratio >= 0.7) {
            $nivel = 'Alto (Caliente)';
            $color = '#ef4444'; // Rojo
        } elseif ($ratio >= 0.35) {
            $nivel = 'Medio';
            $color = '#f59e0b'; // Naranja / Amarillo
        }
        return [
            'distrito' => $item['distrito'],
            'provincia' => $item['provincia'],
            'departamento' => $item['departamento'],
            'total' => $item['total'],
            'ratio' => round($ratio, 2),
            'nivel' => $nivel,
            'color' => $color
        ];
    }, $topDistritos);

    echo json_encode([
        'status' => 'success',
        'totales' => [
            'total_supervisiones' => $totalSupervisiones,
            'total_partners' => count($topPartners),
            'total_distritos' => count($topDistritos),
            'total_cuadrillas' => count($topCuadrillas)
        ],
        'top_partners' => $topPartners,
        'top_distritos' => $topDistritos,
        'top_cuadrillas' => $topCuadrillas,
        'modalidad' => $modalidad,
        'heatmap' => $heatmapData,
        'map_points' => $mapPoints
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
