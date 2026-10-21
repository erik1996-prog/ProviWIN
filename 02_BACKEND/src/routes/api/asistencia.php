<?php
// 02_BACKEND/src/routes/api/asistencia.php - Turnos del supervisor directo WIN (inicio, pausas, fin, historial)
// y lectura para administración (monitor, Línea de tiempo, Dashboard). Permisos, calendario y vacaciones siguen en el sistema de origen.
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();
require_once __DIR__ . '/../../config/turnos.php';
require_once __DIR__ . '/../../config/lluvias.php';
win_run_periodic_task('cierre-turnos', 300, static fn() => sgsCerrarTurnosVencidos($pdo)); // turnos olvidados: se cierran a las 23:59
$action = $_GET['action'] ?? $_POST['action'] ?? '';
Auth::requireCsrf();

if (!in_array($action, ['status', 'start', 'end', 'reabrir', 'start_event', 'end_event', 'my_history', 'admin_summary', 'timeline_diaria', 'list_filters'], true)) {
    ob_clean();
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Acción no disponible en ' . SYSTEM_CODE . '.']);
    exit;
}

// Restricción: El registro de turno es exclusivo para personal directo de WIN
// Lecturas de seguimiento (línea de tiempo y filtros): solo administración. Antes cualquier usuario
// con sesión, incluido un supervisor de partner, podía ver la jornada de todo el personal directo.
if (in_array($action, ['timeline_diaria', 'list_filters'], true)
    && !Auth::can(Auth::PERM_TIMELINE_VIEW, $user) && !Auth::can(Auth::PERM_DASHBOARD_VIEW, $user)) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

if (in_array($action, ['start', 'end', 'start_event', 'end_event'], true) && empty($user['is_win_directo'])) {
    ob_clean();
    echo json_encode([
        'status' => 'error',
        'message' => 'El registro de asistencia e inicio de turno es exclusivo para los supervisores directos de WIN.'
    ]);
    exit;
}

// Control del turno: lo registra SOLO el propio supervisor directo, con su sesión.
if (in_array($action, ['start', 'end', 'start_event', 'end_event'], true)) {
    if (($user['rol'] ?? '') !== Auth::ROLE_SUPERVISOR || empty($user['is_win_directo'])) {
        ob_clean(); http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'El turno solo lo registra el supervisor directo de WIN desde su propia cuenta.']);
        exit;
    }
}
// Reabrir un turno: el propio supervisor directo o el administrador (reglas en la acción 'reabrir').
if ($action === 'reabrir' && ($user['rol'] ?? '') !== Auth::ROLE_ADMINISTRADOR
    && (($user['rol'] ?? '') !== Auth::ROLE_SUPERVISOR || empty($user['is_win_directo']))) {
    ob_clean(); http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Solo el supervisor o el administrador pueden reabrir un turno.']);
    exit;
}
$sgsShiftUserId = (int)$user['id'];

// Helper para obtener datos JSON o POST
/** El propio supervisor puede reabrir su turno de hoy hasta 15 minutos después de finalizarlo (no si lo cerró el sistema). */
function sgsPuedeReabrirTurnoPropio(?array $turno): bool {
    if (!$turno || empty($turno['fin_turno']) || ($turno['fecha'] ?? '') !== date('Y-m-d')) return false;
    if (str_contains((string)($turno['observaciones'] ?? ''), 'Cierre automático')) return false;
    return time() - strtotime((string)$turno['fin_turno']) <= 15 * 60;
}

function getRequestData() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return is_array($json) ? array_merge($_POST, $json) : $_POST;
}

if ($action === 'my_history') {
    if (empty($user['is_win_directo']) || ($user['rol'] ?? '') !== Auth::ROLE_SUPERVISOR) {
        http_response_code(403); ob_clean(); echo json_encode(['status'=>'error','message'=>'Acceso restringido al historial personal.']); exit;
    }
    $stmt=$pdo->prepare("SELECT fecha,TIME_FORMAT(inicio_turno,'%H:%i') inicio,TIME_FORMAT(fin_turno,'%H:%i') fin,COALESCE(horas_trabajadas,0) horas,estado_turno estado,DATE_FORMAT(fecha,'%d/%m/%Y') fecha_formateada FROM asistencia_supervisores WHERE supervisor_id=:uid ORDER BY fecha DESC,id DESC LIMIT 60");
    $historyUserId = (int)$user['id'];
    $stmt->execute([':uid'=>$historyUserId]); ob_clean(); echo json_encode(['status'=>'success','data'=>$stmt->fetchAll()],JSON_UNESCAPED_UNICODE); exit;
}

// -------------------------------------------------------------
// 1. ESTADO ACTUAL DEL TURNO (Para el Supervisor en Sesión)
// -------------------------------------------------------------
if ($action === 'status') {
    $stmt = $pdo->prepare("
        SELECT * FROM asistencia_supervisores 
        WHERE supervisor_id = :sup_id AND fecha = CURRENT_DATE()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':sup_id' => $sgsShiftUserId]);
    $asistencia = $stmt->fetch();

    if (!$asistencia) {
        ob_clean();
        echo json_encode([
            'status' => 'success',
            'estado_turno' => 'Fuera de turno',
            'asistencia' => null,
            'evento_activo' => null,
            'eventos' => [],
            'tipos_registrados' => [],
            'minutos_almuerzo' => 0,
            'minutos_reunion' => 0,
            'horas_ordinarias' => 0,
            'horas_extras' => 0,
            'total_horas' => 0
        ]);
        exit;
    }

    // Obtener eventos del turno
    $stmtEventos = $pdo->prepare("
        SELECT * FROM asistencia_eventos 
        WHERE asistencia_id = :asist_id 
        ORDER BY id ASC
    ");
    $stmtEventos->execute([':asist_id' => $asistencia['id']]);
    $eventos = $stmtEventos->fetchAll();

    // Evento activo si existe
    $eventoActivo = null;
    $minutosAlmuerzo = 0;
    $minutosReunion = 0;
    $minutosRemoto = 0;

    foreach ($eventos as $e) {
        $inicioE = new DateTime($e['inicio_evento']);
        $finE = $e['fin_evento'] ? new DateTime($e['fin_evento']) : new DateTime();
        $duracion = max(0, round(($finE->getTimestamp() - $inicioE->getTimestamp()) / 60));

        if ($e['tipo_evento'] === 'almuerzo') {
            $minutosAlmuerzo += $duracion;
        } elseif ($e['tipo_evento'] === 'reunion') {
            $minutosReunion += $duracion;
        } elseif ($e['tipo_evento'] === 'remoto') {
            $minutosRemoto += $duracion;
        }

        if ($e['fin_evento'] === null) {
            $eventoActivo = $e;
            $eventoActivo['duracion_actual_min'] = $duracion;
        }
    }

    // Tiempos globales
    $inicio = new DateTime($asistencia['inicio_turno']);
    $fin = $asistencia['fin_turno'] ? new DateTime($asistencia['fin_turno']) : new DateTime();
    $diffSegundos = max(0, $fin->getTimestamp() - $inicio->getTimestamp());
    $totalHorasShift = round($diffSegundos / 3600, 2);

    $horasDescuentoAlmuerzo = round($minutosAlmuerzo / 60, 2);
    $jornadaNeta = max(0, $totalHorasShift - $horasDescuentoAlmuerzo);

    $horasOrdinarias = min(8.0, $jornadaNeta);
    $horasExtras = max(0.0, $jornadaNeta - 8.0);

    // Tipos de eventos ya registrados hoy en este turno
    $tiposRegistrados = [];
    if ($asistencia) {
        $stmtTipos = $pdo->prepare("SELECT DISTINCT tipo_evento FROM asistencia_eventos WHERE asistencia_id = :aid");
        $stmtTipos->execute([':aid' => $asistencia['id']]);
        $tiposRegistrados = $stmtTipos->fetchAll(PDO::FETCH_COLUMN);
    }

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'estado_turno' => $asistencia['estado_turno'],
        'asistencia' => $asistencia,
        'evento_activo' => $eventoActivo,
        'eventos' => $eventos,
        'tipos_registrados' => $tiposRegistrados,
        'minutos_almuerzo' => $minutosAlmuerzo,
        'minutos_reunion' => $minutosReunion,
        'minutos_remoto' => $minutosRemoto,
        'horas_ordinarias' => round($horasOrdinarias, 2),
        'horas_extras' => round($horasExtras, 2),
        'total_horas' => $totalHorasShift,
        // Si lo finalizó por error, puede reabrirlo durante 15 minutos (no aplica al cierre automático de las 23:59).
        'puede_reabrir' => sgsPuedeReabrirTurnoPropio($asistencia)
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. INICIAR TURNO
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start') {
    $data = getRequestData();

    $stmtCheck = $pdo->prepare("
        SELECT id, fin_turno FROM asistencia_supervisores
        WHERE supervisor_id = :sup_id AND fecha = CURRENT_DATE()
        LIMIT 1
    ");
    $stmtCheck->execute([':sup_id' => $user['id']]);
    $turnoHoy = $stmtCheck->fetch();
    if ($turnoHoy) {
        $mensaje = empty($turnoHoy['fin_turno'])
            ? 'Ya tienes un turno activo iniciado hoy.'
            : 'Ya registraste y finalizaste tu turno de hoy. Solo se permite iniciar turno una vez al día.';
        echo json_encode(['status' => 'error', 'message' => $mensaje]);
        exit;
    }

    $lat = !empty($data['latitud']) ? trim($data['latitud']) : null;
    $lng = !empty($data['longitud']) ? trim($data['longitud']) : null;
    $obs = !empty($data['observaciones']) ? trim($data['observaciones']) : null;

    $stmtInsert = $pdo->prepare("
        INSERT INTO asistencia_supervisores 
        (supervisor_id, fecha, inicio_turno, estado_turno, latitud_inicio, longitud_inicio, observaciones)
        VALUES (:sup_id, CURRENT_DATE(), NOW(), 'En turno', :lat, :lng, :obs)
    ");
    $stmtInsert->execute([
        ':sup_id' => $user['id'],
        ':lat' => $lat,
        ':lng' => $lng,
        ':obs' => $obs
    ]);

    echo json_encode(['status' => 'success', 'message' => '¡Inicio de turno registrado correctamente!']);
    exit;
}

// -------------------------------------------------------------
// 3. FINALIZAR TURNO
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'end') {
    $data = getRequestData();

    $stmt = $pdo->prepare("
        SELECT * FROM asistencia_supervisores 
        WHERE supervisor_id = :sup_id AND fecha = CURRENT_DATE() AND fin_turno IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':sup_id' => $user['id']]);
    $asistencia = $stmt->fetch();

    if (!$asistencia) {
        echo json_encode(['status' => 'error', 'message' => 'No tienes un turno activo para finalizar.']);
        exit;
    }

    // Cerrar cualquier evento activo si estaba en almuerzo/reunión/capacitación
    $stmtCerrarEventos = $pdo->prepare("
        UPDATE asistencia_eventos 
        SET fin_evento = NOW(),
            duracion_minutos = TIMESTAMPDIFF(MINUTE, inicio_evento, NOW())
        WHERE asistencia_id = :asist_id AND fin_evento IS NULL
    ");
    $stmtCerrarEventos->execute([':asist_id' => $asistencia['id']]);

    // Calcular minutos totales de almuerzo acumulados
    $stmtAlm = $pdo->prepare("
        SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, inicio_evento, fin_evento)), 0) 
        FROM asistencia_eventos 
        WHERE asistencia_id = :asist_id AND tipo_evento = 'almuerzo'
    ");
    $stmtAlm->execute([':asist_id' => $asistencia['id']]);
    $minAlmuerzo = (int)$stmtAlm->fetchColumn();

    // Calcular tiempos finales
    $inicio = new DateTime($asistencia['inicio_turno']);
    $fin = new DateTime();
    $totalHorasShift = ($fin->getTimestamp() - $inicio->getTimestamp()) / 3600;
    
    $horasAlmuerzo = round($minAlmuerzo / 60, 2);
    // Si no registró almuerzo formal, asumir mínimo 1 hora por ley si superó 6h
    if ($horasAlmuerzo < 0.5 && $totalHorasShift > 6) {
        $horasAlmuerzo = 1.0;
    }

    $horasTrabajadas = max(0, round($totalHorasShift - $horasAlmuerzo, 2));
    $horasExtras = max(0, round($horasTrabajadas - 8.0, 2));

    $lat = !empty($data['latitud']) ? trim($data['latitud']) : null;
    $lng = !empty($data['longitud']) ? trim($data['longitud']) : null;

    $stmtUpdate = $pdo->prepare("
        UPDATE asistencia_supervisores SET 
            fin_turno = NOW(),
            horas_trabajadas = :h_trab,
            horas_almuerzo = :h_alm,
            horas_extras = :h_ext,
            estado_turno = 'Fuera de turno',
            latitud_fin = :lat,
            longitud_fin = :lng
        WHERE id = :id
    ");
    $stmtUpdate->execute([
        ':h_trab' => $horasTrabajadas,
        ':h_alm' => $horasAlmuerzo,
        ':h_ext' => $horasExtras,
        ':lat' => $lat,
        ':lng' => $lng,
        ':id' => $asistencia['id']
    ]);
    sgsCerrarLluviasDe($pdo, (int)$user['id']); // la lluvia que avisó y nadie cerró

    echo json_encode([
        'status' => 'success', 
        'message' => "Fin de turno registrado. Horas trabajadas netas: {$horasTrabajadas}h. Horas extras: {$horasExtras}h."
    ]);
    exit;
}

// -------------------------------------------------------------
// 3.1 REABRIR TURNO FINALIZADO POR ERROR (2026-10-12)
//     - El supervisor: su turno de hoy, dentro de los 15 minutos siguientes a finalizarlo.
//     - El administrador: el turno de hoy de cualquier supervisor.
//     El turno vuelve a "En turno"; las pausas que se cerraron al finalizar quedan como estaban.
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reabrir') {
    $data = getRequestData();
    $esAdmin = ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR;
    $supervisorId = $esAdmin && !empty($data['supervisor_id']) ? (int)$data['supervisor_id'] : (int)$user['id'];
    $stmt = $pdo->prepare("SELECT * FROM asistencia_supervisores WHERE supervisor_id = :s AND fecha = CURRENT_DATE() ORDER BY id DESC LIMIT 1");
    $stmt->execute([':s' => $supervisorId]);
    $turno = $stmt->fetch();
    if (!$turno || empty($turno['fin_turno'])) {
        echo json_encode(['status' => 'error', 'message' => 'No hay un turno finalizado hoy para reabrir.']); exit;
    }
    if (!$esAdmin && !sgsPuedeReabrirTurnoPropio($turno)) {
        echo json_encode(['status' => 'error', 'message' => 'Pasaron más de 15 minutos desde que finalizaste el turno. Pide al administrador que lo reabra.']); exit;
    }
    $nota = trim(($turno['observaciones'] ?? '') . "\nTurno reabierto a las " . date('H:i') . ' por ' . ($user['nombre_completo'] ?? 'el usuario')
        . ' (se había finalizado a las ' . substr((string)$turno['fin_turno'], 11, 5) . ').');
    // La pausa que el fin de turno cortó (capacitación, reunión, almuerzo o remoto) sigue abierta, como si el error no hubiera
    // pasado: si no, la capacitación quedaría en 0 minutos y no se podría registrar otra el mismo día.
    $stEv = $pdo->prepare("SELECT id, tipo_evento FROM asistencia_eventos WHERE asistencia_id = :a
        AND fin_evento BETWEEN DATE_SUB(:f1, INTERVAL 5 SECOND) AND DATE_ADD(:f2, INTERVAL 5 SECOND) ORDER BY inicio_evento DESC LIMIT 1");
    $stEv->execute([':a' => $turno['id'], ':f1' => $turno['fin_turno'], ':f2' => $turno['fin_turno']]);
    $pausaCortada = $stEv->fetch();
    $estadosPausa = ['almuerzo' => 'En almuerzo', 'reunion' => 'En reunión', 'capacitacion' => 'En capacitación', 'remoto' => 'En remoto'];
    $estadoNuevo = $pausaCortada ? ($estadosPausa[$pausaCortada['tipo_evento']] ?? 'En turno') : 'En turno';
    $pdo->beginTransaction();
    try {
        if ($pausaCortada && $estadoNuevo !== 'En turno') {
            $pdo->prepare('UPDATE asistencia_eventos SET fin_evento = NULL, duracion_minutos = NULL WHERE id = :id')->execute([':id' => $pausaCortada['id']]);
        }
        $pdo->prepare("UPDATE asistencia_supervisores SET fin_turno = NULL, horas_trabajadas = 0, horas_extras = 0, estado_turno = :estado,
            latitud_fin = NULL, longitud_fin = NULL, observaciones = :obs WHERE id = :id AND fin_turno IS NOT NULL")
            ->execute([':estado' => $estadoNuevo, ':obs' => $nota, ':id' => $turno['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    win_log_error('audit_shift_reopened', 'Turno reabierto', ['asistencia_id' => (int)$turno['id'], 'target_id' => $supervisorId,
        'actor_id' => (int)$user['id'], 'fin_anterior' => $turno['fin_turno']]);
    echo json_encode(['status' => 'success', 'message' => $estadoNuevo === 'En turno' ? 'Turno reabierto. Puedes seguir registrando tus actividades.' : "Turno reabierto. Sigues {$estadoNuevo}, como estabas antes del cierre."]);
    exit;
}

// -------------------------------------------------------------
// 4. REGISTRAR ACTIVIDAD DEL TURNO (Almuerzo / Reunión / Capacitación / Trabajo remoto)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'start_event') {
    $data = getRequestData();
    $tipo = $data['tipo_evento'] ?? '';
    $nota = !empty($data['nota']) ? trim($data['nota']) : '';
    $lat = !empty($data['latitud']) ? trim($data['latitud']) : null;
    $lng = !empty($data['longitud']) ? trim($data['longitud']) : null;

    $tiposPermitidos = ['almuerzo', 'reunion', 'capacitacion', 'campo', 'remoto'];
    if (!in_array($tipo, $tiposPermitidos)) {
        echo json_encode(['status' => 'error', 'message' => 'Tipo de actividad no válido.']);
        exit;
    }

    $asistencia = sgsTurnoAbiertoHoy($pdo, (int)$user['id']);

    if (!$asistencia) {
        echo json_encode(['status' => 'error', 'message' => 'Debes iniciar turno antes de registrar un cambio de estado.']);
        exit;
    }
    // Una actividad a la vez (2026-10-20): con remoto por lluvia, almuerzo, reunión… abierta, primero se termina.
    if ($abierta = sgsActividadAbierta($pdo, (int)$asistencia['id'])) {
        echo json_encode(['status' => 'error', 'message' => "Primero termine su {$abierta['nombre']}."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Las reuniones pueden repetirse durante el turno; almuerzo y capacitación conservan su límite.
    if (in_array($tipo, ['almuerzo', 'capacitacion'], true)) {
        $stmtCheckDup = $pdo->prepare("
            SELECT COUNT(*) FROM asistencia_eventos 
            WHERE asistencia_id = :asist_id AND tipo_evento = :tipo
        ");
        $stmtCheckDup->execute([':asist_id' => $asistencia['id'], ':tipo' => $tipo]);
        if ($stmtCheckDup->fetchColumn() > 0) {
            $nombres = [
                'almuerzo' => 'almuerzo',
                'capacitacion' => 'capacitación'
            ];
            $nomStr = $nombres[$tipo] ?? $tipo;
            echo json_encode(['status' => 'error', 'message' => "Solo puedes registrar 1 {$nomStr} al día."]);
            exit;
        }
    }

    // Registra la nueva actividad y cambia el estado del turno
    $nuevoEstado = sgsIniciarActividad($pdo, (int)$asistencia['id'], $tipo, $nota, $lat, $lng);

    echo json_encode([
        'status' => 'success',
        'message' => "Estado actualizado a '{$nuevoEstado}' correctamente."
    ]);
    exit;
}

// -------------------------------------------------------------
// 5. REANUDAR TRABAJO / FINALIZAR PAUSA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'end_event') {
    $asistencia = sgsTurnoAbiertoHoy($pdo, (int)$user['id']);
    if (!$asistencia) {
        echo json_encode(['status' => 'error', 'message' => 'No tienes un turno activo.']);
        exit;
    }
    sgsTerminarActividad($pdo, (int)$asistencia['id']);

    echo json_encode(['status' => 'success', 'message' => 'Pausa finalizada. Has reanudado tu turno activo.']);
    exit;
}

// -------------------------------------------------------------
// 6. VISTA GENERAL DE ASISTENCIA (ADMINISTRADOR / GERENCIA / COORDINADOR)
// -------------------------------------------------------------
if ($action === 'admin_summary') {
    // Verificar permisos
    if (!in_array($user['rol'], ['Administrador', 'Gerente', 'Coordinador'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
        exit;
    }

    $fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');
    $search = trim($_GET['search'] ?? '');
    $partnerFiltro = $_GET['partner_id'] ?? '';
    $estadoFiltro = $_GET['estado'] ?? '';

    // Obtener todos los supervisores registrados en el sistema
    $sqlSupervisores = "
        SELECT u.id, u.nombre_completo, u.email, u.dni, u.rol, u.avatar, p.nombre_partner
        FROM usuarios u
        LEFT JOIN partners p ON u.partner_id = p.id
        WHERE u.activo = 1 AND u.rol IN ('Supervisor', 'Coordinador', 'Administrador') AND " . sgsDirectStaffSql() . "
    ";
    $paramsSup = [];

    if (!empty($search)) {
        $sqlSupervisores .= " AND (u.nombre_completo LIKE :search OR u.dni LIKE :search OR u.email LIKE :search)";
        $paramsSup[':search'] = "%{$search}%";
    }
    if (!empty($partnerFiltro)) {
        $sqlSupervisores .= " AND u.partner_id = :part_id";
        $paramsSup[':part_id'] = $partnerFiltro;
    }

    $sqlSupervisores .= " ORDER BY u.nombre_completo ASC";
    $stmtSup = $pdo->prepare($sqlSupervisores);
    $stmtSup->execute($paramsSup);
    $listaSupervisores = $stmtSup->fetchAll();

    // Obtener registros de asistencia para la fecha seleccionada
    $stmtAsist = $pdo->prepare("
        SELECT a.*, TIMESTAMPDIFF(MINUTE, a.inicio_turno, COALESCE(a.fin_turno, NOW())) as minutos_transcurridos
        FROM asistencia_supervisores a
        WHERE a.fecha = :fecha
    ");
    $stmtAsist->execute([':fecha' => $fechaFiltro]);
    $asistenciasBySup = [];
    foreach ($stmtAsist->fetchAll() as $row) {
        $asistenciasBySup[$row['supervisor_id']] = $row;
    }

    // Obtener todos los eventos del día
    $stmtEvs = $pdo->prepare("
        SELECT e.*, a.supervisor_id 
        FROM asistencia_eventos e
        JOIN asistencia_supervisores a ON e.asistencia_id = a.id
        WHERE a.fecha = :fecha
        ORDER BY e.inicio_evento ASC
    ");
    $stmtEvs->execute([':fecha' => $fechaFiltro]);
    $eventosBySup = [];
    foreach ($stmtEvs->fetchAll() as $ev) {
        $eventosBySup[$ev['supervisor_id']][] = $ev;
    }

    // Armar reporte consolidado y métricas KPI
    $reporte = [];
    $kpis = [
        'total_personal' => count($listaSupervisores),
        'en_turno' => 0,
        'en_almuerzo' => 0,
        'en_reunion' => 0,
        'en_capacitacion' => 0,
        'en_remoto' => 0,
        'turno_finalizado' => 0,
        'sin_iniciar' => 0
    ];

    // Inicio de las supervisiones del día de cada supervisor (para la primera acción del turno)
    $iniciosSupBySup = [];
    $stmtIniSup = $pdo->prepare("SELECT supervisor_id, fecha_registro FROM supervisiones WHERE fecha_registro >= :desde AND fecha_registro < DATE_ADD(:hasta, INTERVAL 1 DAY)");
    $stmtIniSup->execute([':desde' => $fechaFiltro, ':hasta' => $fechaFiltro]);
    foreach ($stmtIniSup->fetchAll() as $row) $iniciosSupBySup[(int)$row['supervisor_id']][] = strtotime($row['fecha_registro']);

    foreach ($listaSupervisores as $sup) {
        $supId = $sup['id'];
        $asist = $asistenciasBySup[$supId] ?? null;
        $eventos = $eventosBySup[$supId] ?? [];

        $estadoActual = 'Sin iniciar';
        $eventoActivo = null;
        $minAlmuerzo = 0;
        $minReunion = 0;

        if ($asist) {
            $estadoActual = $asist['estado_turno'];

            foreach ($eventos as $e) {
                $inicioE = new DateTime($e['inicio_evento']);
                $finE = $e['fin_evento'] ? new DateTime($e['fin_evento']) : new DateTime();
                $durMin = max(0, round(($finE->getTimestamp() - $inicioE->getTimestamp()) / 60));

                if ($e['tipo_evento'] === 'almuerzo') $minAlmuerzo += $durMin;
                if ($e['tipo_evento'] === 'reunion') $minReunion += $durMin;

                if ($e['fin_evento'] === null) {
                    $eventoActivo = $e;
                    $eventoActivo['duracion_minutos_actual'] = $durMin;
                }
            }
        }

        // Aplicar filtro de estado en respuesta
        if (!empty($estadoFiltro) && $estadoActual !== $estadoFiltro) {
            continue;
        }

        // Conteo KPI
        if ($estadoActual === 'En turno') $kpis['en_turno']++;
        elseif ($estadoActual === 'En almuerzo') $kpis['en_almuerzo']++;
        elseif ($estadoActual === 'En reunión') $kpis['en_reunion']++;
        elseif ($estadoActual === 'En capacitación') $kpis['en_capacitacion']++;
        elseif ($estadoActual === 'En remoto') $kpis['en_remoto']++;
        elseif ($estadoActual === 'Fuera de turno') $kpis['turno_finalizado']++;
        else $kpis['sin_iniciar']++;

        $reporte[] = [
            'supervisor' => [
                'id' => $sup['id'],
                'nombre' => $sup['nombre_completo'],
                'dni' => $sup['dni'],
                'email' => $sup['email'],
                'rol' => $sup['rol'],
                'avatar' => $sup['avatar'],
                'partner' => $sup['nombre_partner'] ?: 'WIN Interno'
            ],
            'asistencia' => $asist,
            'estado_actual' => $estadoActual,
            'evento_activo' => $eventoActivo,
            'minutos_almuerzo' => $minAlmuerzo,
            'minutos_reunion' => $minReunion,
            'primera_accion' => $asist ? sgsPrimeraAccion($asist['inicio_turno'], $eventos, $iniciosSupBySup[(int)$supId] ?? []) : null,
            'eventos' => $eventos
        ];
    }

    echo json_encode([
        'status' => 'success',
        'fecha' => $fechaFiltro,
        'kpis' => $kpis,
        'reporte' => $reporte
    ]);
    exit;
}

// -------------------------------------------------------------
// 7. LISTA DE SUPERVISORES PARA FILTROS
// -------------------------------------------------------------
if ($action === 'list_filters') {
    $stmtSups = $pdo->query("
        SELECT u.id, u.nombre_completo, u.dni, u.partner_id, p.nombre_partner
        FROM usuarios u
        LEFT JOIN partners p ON u.partner_id = p.id
        WHERE u.activo = 1 AND u.rol IN ('Supervisor', 'Coordinador', 'Administrador') AND " . sgsDirectStaffSql() . "
        ORDER BY u.nombre_completo ASC
    ");
    $supervisores = $stmtSups->fetchAll();
    // Zonas de cada supervisor (null = todas) para filtrar la lista por departamento en pantalla
    foreach ($supervisores as &$s) $s['zonas'] = sgsUserZones((int)$s['id']);
    unset($s);
    // Partners (2026-10-11: no se enviaban y el filtro del Dashboard quedaba vacío)
    $partners = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN', 'WIN DIRECTO') ORDER BY nombre_partner")->fetchAll();

    echo json_encode([
        'status' => 'success',
        'supervisores' => $supervisores,
        'partners' => $partners,
        'departamentos' => sgsOperationalDepartments()
    ]);
    exit;
}

// -------------------------------------------------------------
// 8. CALENDARIO MES DE ASISTENCIA CON REGLAS DE COLOR Y SUPERVISIONES
// -------------------------------------------------------------
if ($action === 'asistencia_calendario') {
    $mes = intval($_GET['mes'] ?? date('m'));
    $anio = intval($_GET['anio'] ?? date('Y'));
    $targetSupId = intval($_GET['supervisor_id'] ?? 0);

    // Si es supervisor estándar (no admin), restringir estrictamente a su propio ID
    if (!in_array($user['rol'], ['Administrador', 'Gerente', 'Coordinador'])) {
        $targetSupId = $user['id'];
    }

    // Calcular días en el mes usando función estándar de PHP (date 't')
    $diasEnMes = intval(date('t', strtotime(sprintf('%04d-%02d-01', $anio, $mes))));
    $hoyStr = date('Y-m-d');

    $asistenciasDict = [];
    $supervisionesDict = [];
    $supervisorInfo = null;

    if ($targetSupId > 0) {
        // Datos del supervisor
        $stmtSupInfo = $pdo->prepare("
            SELECT u.id, u.nombre_completo, u.dni, u.rol, u.avatar, p.nombre_partner
            FROM usuarios u
            LEFT JOIN partners p ON u.partner_id = p.id
            WHERE u.id = :id
        ");
        $stmtSupInfo->execute([':id' => $targetSupId]);
        $supervisorInfo = $stmtSupInfo->fetch() ?: null;

        // Obtener asistencias del supervisor
        $stmtAsist = $pdo->prepare("
            SELECT fecha, inicio_turno, fin_turno, horas_trabajadas, horas_extras, estado_turno
            FROM asistencia_supervisores
            WHERE supervisor_id = :sup_id 
              AND MONTH(fecha) = :mes 
              AND YEAR(fecha) = :anio
        ");
        $stmtAsist->execute([':sup_id' => $targetSupId, ':mes' => $mes, ':anio' => $anio]);
        foreach ($stmtAsist->fetchAll() as $row) {
            $asistenciasDict[$row['fecha']] = $row;
        }

        // Obtener supervisiones del supervisor
        $stmtSupsCount = $pdo->prepare("
            SELECT DATE(fecha_registro) as fecha_dia, COUNT(*) as total
            FROM supervisiones
            WHERE supervisor_id = :sup_id 
              AND estado_supervision IN ('Culminada', 'Finalizado')
              AND MONTH(fecha_registro) = :mes 
              AND YEAR(fecha_registro) = :anio
            GROUP BY DATE(fecha_registro)
        ");
        $stmtSupsCount->execute([':sup_id' => $targetSupId, ':mes' => $mes, ':anio' => $anio]);
        foreach ($stmtSupsCount->fetchAll() as $row) {
            $supervisionesDict[$row['fecha_dia']] = intval($row['total']);
        }
    } else {
        // Modo sin supervisor seleccionado: totalizar supervisiones globales del mes
        $stmtSupsAll = $pdo->prepare("
            SELECT DATE(fecha_registro) as fecha_dia, COUNT(*) as total
            FROM supervisiones
            WHERE estado_supervision IN ('Culminada', 'Finalizado')
              AND MONTH(fecha_registro) = :mes AND YEAR(fecha_registro) = :anio
            GROUP BY DATE(fecha_registro)
        ");
        $stmtSupsAll->execute([':mes' => $mes, ':anio' => $anio]);
        foreach ($stmtSupsAll->fetchAll() as $row) {
            $supervisionesDict[$row['fecha_dia']] = intval($row['total']);
        }
    }

    $primerDiaSemana = intval(date('w', strtotime(sprintf('%04d-%02d-01', $anio, $mes))));
    $diasCalendario = [];

    $diasEsp = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    $totalAsistencias = 0;
    $totalFaltas = 0;
    $totalDomingos = 0;
    $totalVacaciones = 0;
    $totalDescansoMedico = 0;
    $totalPaternidad = 0;
    $totalFallecimiento = 0;
    $totalSupervisionesMes = 0;

    for ($d = 1; $d <= $diasEnMes; $d++) {
        $fechaStr = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
        $w = intval(date('w', strtotime($fechaStr))); // 0 = Domingo
        $esDomingo = ($w === 0);
        $esFuturo = ($fechaStr > $hoyStr);

        $asistenciaData = $asistenciasDict[$fechaStr] ?? null;
        $estTurno = $asistenciaData ? $asistenciaData['estado_turno'] : '';
        $esLicencia = in_array($estTurno, ['Vacaciones', 'Descanso Médico', 'Licencia Paternidad', 'Permiso Fallecimiento']);
        $asistio = ($asistenciaData !== null && !$esLicencia);
        $countSups = $supervisionesDict[$fechaStr] ?? 0;
        $totalSupervisionesMes += $countSups;

        $estadoColor = 'futuro';
        $etiquetaEstado = 'Pendiente';

        if ($esDomingo) {
            $estadoColor = 'plomo';
            $etiquetaEstado = 'Domingo (Descanso)';
            $totalDomingos++;
        } elseif ($estTurno === 'Vacaciones') {
            $estadoColor = 'vacaciones';
            $etiquetaEstado = 'Vacaciones';
            $totalVacaciones++;
        } elseif ($estTurno === 'Descanso Médico') {
            $estadoColor = 'medico';
            $etiquetaEstado = 'Descanso Médico';
            $totalDescansoMedico++;
        } elseif ($estTurno === 'Licencia Paternidad') {
            $estadoColor = 'paternidad';
            $etiquetaEstado = 'Licencia Paternidad';
            $totalPaternidad++;
        } elseif ($estTurno === 'Permiso Fallecimiento') {
            $estadoColor = 'fallecimiento';
            $etiquetaEstado = 'Permiso Fallecimiento';
            $totalFallecimiento++;
        } elseif ($targetSupId === 0) {
            $estadoColor = 'futuro';
            $etiquetaEstado = 'Seleccione supervisor';
        } elseif ($esFuturo) {
            $estadoColor = 'futuro';
            $etiquetaEstado = 'Futuro';
        } elseif ($asistio) {
            $estadoColor = 'verde';
            $etiquetaEstado = 'Asistió';
            $totalAsistencias++;
        } else {
            $estadoColor = 'rojo';
            $etiquetaEstado = 'Falta';
            $totalFaltas++;
        }

        $diasCalendario[] = [
            'dia' => $d,
            'fecha' => $fechaStr,
            'dia_semana_num' => $w,
            'dia_semana_nombre' => $diasEsp[$w],
            'es_domingo' => $esDomingo,
            'es_licencia' => $esLicencia,
            'tipo_licencia' => $esLicencia ? $estTurno : null,
            'es_futuro' => $esFuturo,
            'asistio' => $asistio,
            'estado_color' => $estadoColor,
            'etiqueta_estado' => $etiquetaEstado,
            'total_supervisiones' => $countSups,
            'asistencia_detail' => $asistenciaData
        ];
    }

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'mes' => $mes,
        'anio' => $anio,
        'primer_dia_semana' => $primerDiaSemana,
        'dias_en_mes' => $diasEnMes,
        'supervisor_id' => $targetSupId,
        'supervisor' => $supervisorInfo,
        'dias' => $diasCalendario,
        'resumen' => [
            'asistencias' => $totalAsistencias,
            'faltas' => $totalFaltas,
            'domingos' => $totalDomingos,
            'vacaciones' => $totalVacaciones,
            'descanso_medico' => $totalDescansoMedico,
            'paternidad' => $totalPaternidad,
            'fallecimiento' => $totalFallecimiento,
            'total_permisos' => ($totalVacaciones + $totalDescansoMedico + $totalPaternidad + $totalFallecimiento),
            'total_supervisiones' => $totalSupervisionesMes
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 9. REGISTRAR PERMISOS Y LICENCIAS (Vacaciones, Descanso Médico, Paternidad, Fallecimiento)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'registrar_permiso' || $action === 'registrar_vacaciones')) {
    $data = getRequestData();
    $supId = intval($data['supervisor_id'] ?? 0);
    $tipoPermiso = trim($data['tipo_permiso'] ?? 'Vacaciones');
    $fechaInicio = $data['fecha_inicio'] ?? '';
    $fechaFin = $data['fecha_fin'] ?? '';
    $obs = !empty($data['observaciones']) ? trim($data['observaciones']) : "Permiso Autorizado ({$tipoPermiso})";

    $tiposValidos = ['Vacaciones', 'Descanso Médico', 'Licencia Paternidad', 'Permiso Fallecimiento'];
    if (!in_array($tipoPermiso, $tiposValidos)) {
        $tipoPermiso = 'Vacaciones';
    }

    if (!in_array($user['rol'], ['Administrador', 'Gerente', 'Coordinador'])) {
        $supId = $user['id']; // Supervisor solo su propia solicitud
    }

    if ($supId <= 0 || empty($fechaInicio) || empty($fechaFin)) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Complete el supervisor, tipo de permiso y el rango de fechas.']);
        exit;
    }

    $dtInicio = new DateTime($fechaInicio);
    $dtFin = new DateTime($fechaFin);

    if ($dtInicio > $dtFin) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'La fecha de inicio no puede ser posterior a la fecha de fin.']);
        exit;
    }

    $interval = new DateInterval('P1D');
    $period = new DatePeriod($dtInicio, $interval, $dtFin->modify('+1 day'));

    $stmtUpsert = $pdo->prepare("
        INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, fin_turno, estado_turno, observaciones)
        VALUES (:sup_id, :fecha, CONCAT(:fecha2, ' 08:00:00'), CONCAT(:fecha3, ' 17:00:00'), :tipo, :obs)
        ON DUPLICATE KEY UPDATE estado_turno = VALUES(estado_turno), observaciones = VALUES(observaciones)
    ");

    $diasRegistrados = 0;
    foreach ($period as $dt) {
        $fStr = $dt->format('Y-m-d');
        $stmtUpsert->execute([
            ':sup_id' => $supId,
            ':fecha' => $fStr,
            ':fecha2' => $fStr,
            ':fecha3' => $fStr,
            ':tipo' => $tipoPermiso,
            ':obs' => $obs
        ]);
        $diasRegistrados++;
    }

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'message' => "Permiso ({$tipoPermiso}) registrado exitosamente ({$diasRegistrados} día(s))."
    ]);
    exit;
}

// -------------------------------------------------------------
// 10. LÍNEA DE TIEMPO DIARIA DE SUPERVISORES Y DESPLAZAMIENTOS
// -------------------------------------------------------------
if ($action === 'timeline_diaria') {
    $fechaSel = $_GET['fecha'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaSel)) $fechaSel = date('Y-m-d');
    
    // Obtener todos los supervisores
    $stmtSups = $pdo->prepare("
        SELECT u.id, u.nombre_completo, u.rol, u.avatar, p.nombre_partner
        FROM usuarios u
        LEFT JOIN partners p ON u.partner_id = p.id
        WHERE u.rol IN ('Supervisor', 'Coordinador') AND u.activo=1 AND " . sgsDirectStaffSql() . "
        ORDER BY u.nombre_completo ASC
    ");
    $stmtSups->execute();
    $listSupervisores = $stmtSups->fetchAll();

    // Carga por lotes: evita ejecutar varias consultas por cada supervisor.
    $asistenciasPorSupervisor = [];
    $eventosPorAsistencia = [];
    $supervisionesPorSupervisor = [];
    $supervisorIds = array_map('intval', array_column($listSupervisores, 'id'));
    if ($supervisorIds) {
        $idPlaceholders = [];
        $idParams = [':timeline_fecha' => $fechaSel];
        foreach ($supervisorIds as $index => $supervisorId) {
            $key = ':timeline_sup_' . $index;
            $idPlaceholders[] = $key;
            $idParams[$key] = $supervisorId;
        }
        $inClause = implode(',', $idPlaceholders);

        $stmtAsistencias = $pdo->prepare("SELECT * FROM asistencia_supervisores WHERE fecha=:timeline_fecha AND supervisor_id IN ({$inClause})");
        $stmtAsistencias->execute($idParams);
        foreach ($stmtAsistencias->fetchAll() as $attendanceRow) {
            $asistenciasPorSupervisor[(int)$attendanceRow['supervisor_id']] = $attendanceRow;
        }

        $stmtEventos = $pdo->prepare("SELECT e.* FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id=e.asistencia_id WHERE a.fecha=:timeline_fecha AND a.supervisor_id IN ({$inClause}) ORDER BY e.inicio_evento ASC");
        $stmtEventos->execute($idParams);
        foreach ($stmtEventos->fetchAll() as $eventRow) {
            $eventosPorAsistencia[(int)$eventRow['asistencia_id']][] = $eventRow;
        }

        $stmtSupervisionesDia = $pdo->prepare("SELECT s.id,s.codigo_supervision,s.ticket_codigo,s.tipo_supervision_inicial,s.fecha_registro,s.departamento,s.partner_id,s.tecnico1_nombre,s.supervisor_id,p.nombre_partner,s.estado_supervision,(SELECT MIN(h.fecha_cambio) FROM supervision_estado_historial h WHERE h.supervision_id=s.id AND h.estado_nuevo='Finalizada') AS fecha_fin FROM supervisiones s LEFT JOIN partners p ON s.partner_id=p.id WHERE s.supervisor_id IN ({$inClause}) AND s.estado_supervision != 'Pendiente' AND s.fecha_registro>=:timeline_inicio AND s.fecha_registro<DATE_ADD(:timeline_fin,INTERVAL 1 DAY) ORDER BY s.fecha_registro ASC");
        $supervisionParams = $idParams;
        unset($supervisionParams[':timeline_fecha']);
        $supervisionParams[':timeline_inicio'] = $fechaSel;
        $supervisionParams[':timeline_fin'] = $fechaSel;
        $stmtSupervisionesDia->execute($supervisionParams);
        foreach ($stmtSupervisionesDia->fetchAll() as $supervisionRow) {
            $supervisionesPorSupervisor[(int)$supervisionRow['supervisor_id']][] = $supervisionRow;
        }
    }

    $resultTimeline = [];
    $lluviasDelDia = sgsLluviasDelDia($pdo, $fechaSel);

    foreach ($listSupervisores as $sup) {
        $supId = $sup['id'];

        // Avatar (si la columna existe)
        $avatarPath = $sup['avatar'] ?? null;

        // Nombre corto (1er nombre + 1er apellido)
        $parts = array_values(array_filter(explode(' ', trim($sup['nombre_completo']))));
        $shortName = count($parts) >= 2 ? ($parts[0] . ' ' . ($parts[count($parts) >= 4 ? 2 : 1])) : $sup['nombre_completo'];

        // Datos precargados del día.
        $asistencia = $asistenciasPorSupervisor[(int)$supId] ?? null;

        // Estado General
        $estadoGeneral = 'Inactivo';
        $subEstado = 'Fuera de turno';
        $esLicencia = false;

        if ($asistencia) {
            $est = $asistencia['estado_turno'];
            if (in_array($est, ['Vacaciones', 'Descanso Médico', 'Licencia Paternidad', 'Permiso Fallecimiento'])) {
                $estadoGeneral = "Inactivo por {$est}";
                $subEstado = $est;
                $esLicencia = true;
            } elseif ($asistencia['fin_turno'] === null) {
                $estadoGeneral = 'Activo';
                $subEstado = $est;
            } else {
                $estadoGeneral = 'Inactivo';
                $subEstado = 'Turno Finalizado';
            }
        }

        // Eventos de pausa
        $eventos = $asistencia ? ($eventosPorAsistencia[(int)$asistencia['id']] ?? []) : [];

        // Supervisiones del día
        $supervisiones = $supervisionesPorSupervisor[(int)$supId] ?? [];
        // Lluvia de su zona en el día (2026-10-20): se muestra como una nube junto a su nombre
        $lluvias = $esLicencia ? [] : sgsLluviasDelSupervisor($lluviasDelDia, (int)$supId, array_column($supervisiones, 'departamento'));

        // Armar bloques horizontales unificados de la jornada (Turno, Supervisiones, Traslados, Pausas)
        $timelineBlocks = [];

        if ($asistencia) {
            $hIni = date('H:i', strtotime($asistencia['inicio_turno']));
            $timelineBlocks[] = [
                'type' => 'shift_start',
                'title' => 'Inicio de Turno',
                'hora' => $hIni,
                'icon' => 'arrow-right-circle-fill',
                'color_class' => 'bg-primary text-white',
                'timestamp' => strtotime($asistencia['inicio_turno'])
            ];
        }

        // Intercalar supervisiones y desplazamientos
        $listItems = [];
        $totalSupsCount = count($supervisiones);

        for ($i = 0; $i < $totalSupsCount; $i++) {
            $curr = $supervisiones[$i];
            $tInicio = strtotime($curr['fecha_registro']);
            // Horas reales: inicio = fecha de registro; fin = cambio a "Finalizada" en el historial de estados.
            // (Antes se asumían 35 minutos fijos.) Registros antiguos finalizados sin historial: fin no registrado.
            $finalizada = in_array($curr['estado_supervision'] ?? '', ['Finalizada', 'Culminada', 'Finalizado'], true);
            $tFin = ($finalizada && !empty($curr['fecha_fin'])) ? strtotime($curr['fecha_fin']) : null;
            if ($tFin !== null) {
                $duracionSupMin = max(1, (int)round(($tFin - $tInicio) / 60));
            } elseif (!$finalizada && $fechaSel === date('Y-m-d')) {
                $duracionSupMin = max(0, (int)round((time() - $tInicio) / 60)); // en curso: tiempo transcurrido
            } else {
                $duracionSupMin = null;
            }

            $timelineBlocks[] = [
                'type' => 'supervision',
                'id' => $curr['id'],
                'codigo' => $curr['codigo_supervision'],
                'estado' => $finalizada ? 'finalizada' : 'en_curso',
                'hora_inicio' => date('H:i', $tInicio),
                'hora_fin' => $tFin !== null ? date('H:i', $tFin) : null,
                'duracion_minutos' => $duracionSupMin,
                'tecnico' => $curr['tecnico1_nombre'] ?? 'Personal Técnico',
                'partner' => $curr['nombre_partner'] ?? ($sup['nombre_partner'] ?? 'WIN'),
                'color_class' => $finalizada ? 'sgs-tl-sup is-fin' : 'sgs-tl-sup is-curso',
                'timestamp' => $tInicio,
                'ts_inicio' => $tInicio, 'ts_fin' => $tFin,
                'ticket' => $curr['ticket_codigo'] ?? null,
                'modalidad' => $curr['tipo_supervision_inicial'] ?? null,
            ];

            // Traslado = desde el fin real de esta supervisión hasta el inicio de la siguiente (sin fin registrado, no se calcula)
            $trasladoMin = 0;
            if ($i < $totalSupsCount - 1 && $tFin !== null) {
                $next = $supervisiones[$i + 1];
                $tNextInicio = strtotime($next['fecha_registro']);
                $trasladoMin = (int)round(max(0, $tNextInicio - $tFin) / 60);

                if ($trasladoMin > 0) {
                    $timelineBlocks[] = [
                        'type' => 'travel',
                        'duracion_minutos' => $trasladoMin,
                        'title' => "{$trasladoMin} min de traslado",
                        'color_class' => 'bg-warning text-dark fw-bold',
                        'timestamp' => $tFin,
                        'ts_inicio' => $tFin, 'ts_fin' => $tNextInicio,
                    ];
                }
            }

            $listItems[] = [
                'id' => $curr['id'],
                'codigo' => $curr['codigo_supervision'],
                'estado' => $finalizada ? 'finalizada' : 'en_curso',
                'hora_inicio' => date('H:i', $tInicio),
                'hora_fin' => $tFin !== null ? date('H:i', $tFin) : null,
                'duracion_minutos' => $duracionSupMin,
                'desplazamiento_siguiente_minutos' => $trasladoMin,
                'tecnico' => $curr['tecnico1_nombre'] ?? 'Personal Técnico',
                'partner' => $curr['nombre_partner'] ?? ($sup['nombre_partner'] ?? 'WIN')
            ];
        }

        // Agregar eventos de pausas (Almuerzo / Reunión / Capacitación)
        foreach ($eventos as $ev) {
            $tEvIni = strtotime($ev['inicio_evento']);
            $hEvIni = date('H:i', $tEvIni);
            $hEvFin = $ev['fin_evento'] ? date('H:i', strtotime($ev['fin_evento'])) : 'En curso';
            $durEv = $ev['duracion_minutos'] ?: 60;
            $nombresEv = ['almuerzo' => 'Almuerzo', 'reunion' => 'Reunión', 'capacitacion' => 'Capacitación', 'campo' => 'Trabajo de campo', 'remoto' => 'Trabajo remoto'];
            $tipoEvText = $nombresEv[$ev['tipo_evento']] ?? ucfirst($ev['tipo_evento']);

            // El trabajo remoto es trabajo (no una pausa): bloque propio en la línea de tiempo
            if ($ev['tipo_evento'] === 'remoto') {
                $timelineBlocks[] = [
                    'type' => 'remote',
                    'title' => 'Trabajo remoto',
                    'nota' => (string)($ev['nota'] ?? ''),
                    'hora_inicio' => $hEvIni,
                    'hora_fin' => $hEvFin,
                    'duracion_minutos' => $ev['fin_evento'] ? (int)$ev['duracion_minutos'] : max(0, (int)round((time() - $tEvIni) / 60)),
                    'color_class' => 'sgs-tl-remoto',
                    'timestamp' => $tEvIni,
                    'ts_inicio' => $tEvIni, 'ts_fin' => $ev['fin_evento'] ? strtotime($ev['fin_evento']) : null,
                ];
                continue;
            }

            $timelineBlocks[] = [
                'type' => 'pause',
                'title' => $ev['tipo_evento'] === 'almuerzo' ? 'Almuerzo' : $tipoEvText,
                'hora_inicio' => $hEvIni,
                'hora_fin' => $hEvFin,
                'duracion_minutos' => $durEv,
                'color_class' => 'bg-pausa text-white', // mismo color que la leyenda (antes bg-purple, que no existe: el bloque salía invisible)
                'timestamp' => $tEvIni,
                'ts_inicio' => $tEvIni, 'ts_fin' => $ev['fin_evento'] ? strtotime($ev['fin_evento']) : null,
                'tipo_evento' => $ev['tipo_evento'],
            ];
        }

        // Desde el inicio del turno hasta su primera actividad (2026-10-20): antes ese tiempo no se veía ni se sumaba.
        // Si aún no hace nada (hoy, turno abierto), el tramo sigue corriendo.
        if ($asistencia && !$esLicencia) {
            $tTurno = strtotime($asistencia['inicio_turno']);
            $tFinTurno = $asistencia['fin_turno'] ? strtotime($asistencia['fin_turno']) : null;
            $inicios = array_filter(array_column($timelineBlocks, 'ts_inicio'), static fn($t) => $t >= $tTurno);
            $sigueSinActividad = !$inicios && $tFinTurno === null && $fechaSel === date('Y-m-d');
            $tPrimera = $inicios ? min($inicios) : null;
            if ($sigueSinActividad || ($tPrimera !== null && $tPrimera - $tTurno >= 60)) {
                $timelineBlocks[] = [
                    'type' => 'travel', 'primera' => true,
                    'duracion_minutos' => (int)round((($tPrimera ?? time()) - $tTurno) / 60),
                    'timestamp' => $tTurno, 'ts_inicio' => $tTurno, 'ts_fin' => $tPrimera,
                ];
            }
        }

        // Fin de Turno (Flecha Mirando a la Izquierda ⬅️)
        if ($asistencia && !empty($asistencia['fin_turno'])) {
            $hFin = date('H:i', strtotime($asistencia['fin_turno']));
            $timelineBlocks[] = [
                'type' => 'shift_end',
                'title' => 'Fin de Turno',
                'hora' => $hFin,
                'icon' => 'arrow-left-circle-fill',
                'color_class' => 'bg-danger text-white',
                'timestamp' => strtotime($asistencia['fin_turno'])
            ];
        }

        usort($timelineBlocks, function($a, $b) {
            return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
        });

        $resultTimeline[] = [
            'supervisor_id' => $supId,
            'nombre_completo' => $sup['nombre_completo'],
            'nombre_corto' => $shortName,
            'avatar' => $avatarPath,
            'partner' => $sup['nombre_partner'] ?? 'WIN',
            'estado_general' => $estadoGeneral,
            'sub_estado' => $subEstado,
            'es_licencia' => $esLicencia,
            'asistencia' => $asistencia,
            'eventos' => $eventos,
            'supervisiones' => $listItems,
            'timeline_blocks' => $timelineBlocks,
            'lluvias' => $lluvias,
            'total_supervisiones' => $totalSupsCount,
            // Escala de horas (2026-10-13): inicio y fin reales del turno en segundos (fin null = sigue en turno).
            'turno_ts' => $asistencia && !$esLicencia ? ['inicio' => strtotime($asistencia['inicio_turno']), 'fin' => $asistencia['fin_turno'] ? strtotime($asistencia['fin_turno']) : null] : null,
            'primera_accion' => $asistencia && !$esLicencia ? sgsPrimeraAccion($asistencia['inicio_turno'], $eventos, array_map(fn($x) => strtotime($x['fecha_registro']), $supervisiones)) : null
        ];
    }

    ob_clean();
    echo json_encode([
        'status' => 'success',
        'fecha' => $fechaSel,
        'ahora' => time(),
        'es_hoy' => $fechaSel === date('Y-m-d'),
        'puede_reabrir' => ($user['rol'] ?? '') === Auth::ROLE_ADMINISTRADOR,
        'supervisores' => $resultTimeline
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no permitida.']);
