<?php
// 02_BACKEND/src/routes/api/dashboard.php - API REST para Monitoreo en Tiempo Real, Calendario y Matriz Mensual
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
if (!Auth::can(Auth::PERM_DASHBOARD_VIEW)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Se requieren permisos administrativos.']);
    exit;
}
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();
$action = $_GET['action'] ?? '';

// 1. Monitoreo en Tiempo Real (Estado actual de cada supervisor hoy)
if ($action === 'monitoreo_realtime') {
    // La interfaz consume solo los contadores. Una agregación evita enviar toda
    // la plantilla y elimina el conteo correlacionado por supervisor en cada poll.
    // Estados reales del turno abierto de hoy (2026-10-08: antes se contaba "En break", que no se usa, y quien
    // estaba en almuerzo, reunión o capacitación salía como "Fuera de turno").
    $abierto = "a.id IS NOT NULL AND a.fin_turno IS NULL";
    $summaryRow = $pdo->query("
        SELECT COUNT(*) total_supervisores,
               SUM(CASE WHEN $abierto AND a.estado_turno = 'En turno' THEN 1 ELSE 0 END) en_turno,
               SUM(CASE WHEN $abierto AND a.estado_turno IN ('En almuerzo', 'En break') THEN 1 ELSE 0 END) en_break,
               SUM(CASE WHEN $abierto AND a.estado_turno IN ('En reunión', 'En capacitación', 'En remoto') THEN 1 ELSE 0 END) en_capacitacion,
               SUM(CASE WHEN NOT ($abierto) OR a.estado_turno NOT IN ('En turno', 'En almuerzo', 'En break', 'En reunión', 'En capacitación', 'En remoto') THEN 1 ELSE 0 END) fuera_turno
        FROM usuarios u
        LEFT JOIN partners p ON p.id=u.partner_id
        LEFT JOIN asistencia_supervisores a ON u.id=a.supervisor_id AND a.fecha=CURRENT_DATE()
        WHERE u.rol='Supervisor' AND u.activo=1 AND " . sgsDirectStaffSql() . "
    ")->fetch() ?: [];
    $resumen = [
        'en_turno' => (int)($summaryRow['en_turno'] ?? 0),
        'fuera_turno' => (int)($summaryRow['fuera_turno'] ?? 0),
        'en_capacitacion' => (int)($summaryRow['en_capacitacion'] ?? 0),
        'en_break' => (int)($summaryRow['en_break'] ?? 0),
        'total_supervisores' => (int)($summaryRow['total_supervisores'] ?? 0),
    ];

    echo json_encode([
        'status' => 'success',
        'resumen' => $resumen
    ]);
    exit;
}

/**
 * Período del Dashboard (filtro general): rango fecha_inicio/fecha_fin (Y-m-d) o, si no viene, mes/anio.
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable} inicio y fin (inclusive)
 */
function sgsDashboardPeriodo(): array {
    $valida = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
    if ($valida($_GET['fecha_inicio'] ?? null) && $valida($_GET['fecha_fin'] ?? null)) {
        $ini = new DateTimeImmutable($_GET['fecha_inicio']); $fin = new DateTimeImmutable($_GET['fecha_fin']);
        return $ini <= $fin ? [$ini, $fin] : [$fin, $ini];
    }
    $mes = max(1, min(12, intval($_GET['mes'] ?? date('m'))));
    $anio = max(2020, min(2100, intval($_GET['anio'] ?? date('Y'))));
    $ini = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
    return [$ini, $ini->modify('last day of this month')];
}

// 2. Matriz de supervisiones finalizadas por día y supervisor (período del filtro general, máx. 62 días)
/** Departamento del filtro general (2026-10-11): solo se aceptan departamentos de operación; vacío = todos. */
function sgsDashboardDepartamento(): string {
    $dep = mb_strtoupper(trim((string)($_GET['departamento'] ?? '')), 'UTF-8');
    return in_array($dep, sgsOperationalDepartments(), true) ? $dep : '';
}
/** Deja solo a los supervisores que trabajan ese departamento (zona base o adicional; "todas las zonas" siempre entra). */
function sgsSupervisoresDelDepartamento(array $supervisores, string $dep): array {
    if ($dep === '') return $supervisores;
    return array_values(array_filter($supervisores, static function ($s) use ($dep) {
        $zonas = sgsUserZones((int)$s['id']);
        return $zonas === null || in_array($dep, $zonas, true);
    }));
}

if ($action === 'matriz_mensual') {
    [$ini, $fin] = sgsDashboardPeriodo();
    if ($ini->diff($fin)->days + 1 > 62) {
        echo json_encode(['status' => 'error', 'message' => 'La matriz diaria muestra hasta 62 días. Elija un mes o un rango más corto.']);
        exit;
    }
    $supervisorFiltro = max(0, intval($_GET['supervisor_id'] ?? 0));
    $diasEsp = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $columnasDias = [];
    for ($d = $ini; $d <= $fin; $d = $d->modify('+1 day')) {
        $columnasDias[] = ['dia_num' => $d->format('Y-m-d'), 'fecha' => $d->format('Y-m-d'),
            'etiqueta' => $diasEsp[(int)$d->format('w')] . ' ' . $d->format('d') . ($ini->format('m') !== $fin->format('m') ? '/' . $d->format('m') : '')];
    }
    $sqlSup = "SELECT u.id, u.nombre_completo, u.dni FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
        WHERE u.rol = 'Supervisor' AND u.activo = 1 AND " . sgsDirectStaffSql() . ($supervisorFiltro ? ' AND u.id = :sid' : '') . " ORDER BY u.nombre_completo ASC";
    $stmtSups = $pdo->prepare($sqlSup);
    $stmtSups->execute($supervisorFiltro ? [':sid' => $supervisorFiltro] : []);
    $depFiltro = sgsDashboardDepartamento();
    $supervisores = sgsSupervisoresDelDepartamento($stmtSups->fetchAll(), $depFiltro);

    $stmtCounts = $pdo->prepare("SELECT supervisor_id, DATE(fecha_registro) AS dia, COUNT(*) AS total FROM supervisiones
        WHERE estado_supervision = 'Finalizada' AND fecha_registro >= :inicio AND fecha_registro < :fin" . ($depFiltro !== '' ? ' AND departamento = :dep' : '') . "
        GROUP BY supervisor_id, DATE(fecha_registro)");
    $stmtCounts->execute([':inicio' => $ini->format('Y-m-d'), ':fin' => $fin->modify('+1 day')->format('Y-m-d')] + ($depFiltro !== '' ? [':dep' => $depFiltro] : []));
    $matrizData = [];
    foreach ($stmtCounts->fetchAll() as $row) $matrizData[$row['supervisor_id']][$row['dia']] = (int)$row['total'];

    $filasMatriz = [];
    foreach ($supervisores as $sup) {
        $conteo = []; $total = 0;
        foreach ($columnasDias as $c) { $n = $matrizData[$sup['id']][$c['fecha']] ?? 0; $conteo[$c['fecha']] = $n; $total += $n; }
        $filasMatriz[] = ['supervisor_id' => $sup['id'], 'nombre_completo' => $sup['nombre_completo'], 'dni' => $sup['dni'], 'conteo_dias' => $conteo, 'total_mes' => $total];
    }
    echo json_encode(['status' => 'success', 'fecha_inicio' => $ini->format('Y-m-d'), 'fecha_fin' => $fin->format('Y-m-d'),
        'dias_mes' => $columnasDias, 'filas' => $filasMatriz], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Cumplimiento de partners: los supervisores de contratas solo se evalúan por tiempo de respuesta.
// Regla: una orden asignada debe atenderse dentro de 24 h; si no, queda bloqueada (incumplimiento).
// 3. Meta de productividad mensual del personal directo.
//    Meta: SGS_META_SUPERVISIONES_DIA supervisiones finalizadas por día.
//    a) Por días laborables: lunes a sábado transcurridos del mes + domingos en que el supervisor trabajó.
//    b) Por días trabajados: días con turno iniciado (incluye domingos trabajados).
if ($action === 'productividad_mensual') {
    [$inicio, $finMes] = sgsDashboardPeriodo();
    $supervisorFiltro = max(0, intval($_GET['supervisor_id'] ?? 0));
    $meta = SGS_META_SUPERVISIONES_DIA;
    $hoy = new DateTimeImmutable('today');
    $corte = $finMes < $hoy ? $finMes : $hoy;            // período en curso: hasta hoy
    $habilesTranscurridos = 0; $habilesMes = 0;
    for ($d = $inicio; $d <= $finMes; $d = $d->modify('+1 day')) {
        if ($d->format('w') === '0') continue;            // domingo
        $habilesMes++;
        if ($d <= $corte) $habilesTranscurridos++;
    }

    $stmtLista = $pdo->prepare("SELECT u.id, u.nombre_completo FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id
        WHERE u.rol = 'Supervisor' AND u.activo = 1 AND " . sgsDirectStaffSql() . ($supervisorFiltro ? ' AND u.id = :sid' : '') . " ORDER BY u.nombre_completo");
    $stmtLista->execute($supervisorFiltro ? [':sid' => $supervisorFiltro] : []);
    $depFiltro = sgsDashboardDepartamento();
    $supervisores = sgsSupervisoresDelDepartamento($stmtLista->fetchAll(), $depFiltro);
    $rango = [':ini' => $inicio->format('Y-m-d'), ':fin' => $finMes->modify('+1 day')->format('Y-m-d')];
    $stmtSup = $pdo->prepare("SELECT supervisor_id, COUNT(*) total FROM supervisiones
        WHERE estado_supervision = 'Finalizada' AND fecha_registro >= :ini AND fecha_registro < :fin" . ($depFiltro !== '' ? ' AND departamento = :dep' : '') . " GROUP BY supervisor_id");
    $stmtSup->execute($rango + ($depFiltro !== '' ? [':dep' => $depFiltro] : []));
    $finalizadas = $stmtSup->fetchAll(PDO::FETCH_KEY_PAIR);
    $stmtDias = $pdo->prepare("SELECT supervisor_id, COUNT(DISTINCT fecha) dias, COUNT(DISTINCT CASE WHEN DAYOFWEEK(fecha) = 1 THEN fecha END) domingos
        FROM asistencia_supervisores WHERE fecha >= :ini AND fecha < :fin AND inicio_turno IS NOT NULL GROUP BY supervisor_id");
    $stmtDias->execute($rango);
    $dias = [];
    foreach ($stmtDias->fetchAll() as $row) $dias[(int)$row['supervisor_id']] = $row;

    $filas = []; $totSup = 0; $totDias = 0; $cumplen = 0; $conTurnos = 0;
    foreach ($supervisores as $s) {
        $id = (int)$s['id'];
        $n = (int)($finalizadas[$id] ?? 0);
        $diasTrab = (int)($dias[$id]['dias'] ?? 0);
        $domingos = (int)($dias[$id]['domingos'] ?? 0);
        $promedio = $diasTrab > 0 ? round($n / $diasTrab, 2) : null;
        $metaFecha = round($meta * ($habilesTranscurridos + $domingos), 1);
        $avance = $metaFecha > 0 ? (int)round($n / $metaFecha * 100) : null;
        $estado = $promedio === null ? 'sin_turnos' : ($promedio >= $meta ? 'cumple' : ($promedio >= $meta * 0.8 ? 'cerca' : 'bajo'));
        if ($estado === 'cumple') $cumplen++;
        if ($diasTrab > 0) $conTurnos++;
        $totSup += $n; $totDias += $diasTrab;
        $filas[] = ['supervisor_id' => $id, 'nombre' => $s['nombre_completo'], 'finalizadas' => $n, 'dias_trabajados' => $diasTrab,
            'domingos_trabajados' => $domingos, 'promedio_dia_trabajado' => $promedio, 'meta_a_la_fecha' => $metaFecha,
            'avance_pct' => $avance, 'estado' => $estado];
    }
    usort($filas, static fn($a, $b) => [$b['promedio_dia_trabajado'] ?? -1, $b['finalizadas']] <=> [$a['promedio_dia_trabajado'] ?? -1, $a['finalizadas']]);
    echo json_encode(['status' => 'success', 'data' => [
        'fecha_inicio' => $inicio->format('Y-m-d'), 'fecha_fin' => $finMes->format('Y-m-d'), 'meta_diaria' => $meta,
        'dias_habiles_mes' => $habilesMes, 'dias_habiles_transcurridos' => $habilesTranscurridos,
        'resumen' => ['promedio_equipo' => $totDias > 0 ? round($totSup / $totDias, 2) : null, 'finalizadas' => $totSup,
            'supervisores' => count($filas), 'con_turnos' => $conTurnos, 'cumplen' => $cumplen],
        'supervisores' => $filas,
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'cumplimiento_partners') {
    $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_inicio'] ?? '')) ? $_GET['fecha_inicio'] : date('Y-m-01');
    $fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_fin'] ?? '')) ? $_GET['fecha_fin'] : date('Y-m-d');
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    // Misma definición que el listado (es_asignacion_partner): asignada y el supervisor pertenece al partner de la orden.
    $where = "s.fecha_asignacion IS NOT NULL
        AND EXISTS (SELECT 1 FROM usuarios su WHERE su.id = s.supervisor_id AND su.partner_id = s.partner_id)
        AND s.fecha_asignacion >= :inicio AND s.fecha_asignacion < DATE_ADD(:fin, INTERVAL 1 DAY)
        AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN','WIN DIRECTO')";
    $params = [':inicio' => $inicio, ':fin' => $fin];
    if ($partnerId > 0) { $where .= ' AND p.id = :partner'; $params[':partner'] = $partnerId; }
    $depFiltro = sgsDashboardDepartamento();
    if ($depFiltro !== '') { $where .= ' AND s.departamento = :dep'; $params[':dep'] = $depFiltro; }

    $limite = 'DATE_ADD(s.fecha_asignacion, INTERVAL 24 HOUR)';
    $metricas = "COUNT(*) AS asignadas,
        SUM(s.estado_atencion_partner = 'Atendida' AND s.fecha_atencion_partner <= {$limite}) AS a_tiempo,
        SUM(COALESCE(s.estado_atencion_partner,'Pendiente') <> 'Atendida' AND NOW() > {$limite}) AS bloqueadas,
        SUM(COALESCE(s.estado_atencion_partner,'Pendiente') <> 'Atendida' AND NOW() <= {$limite}) AS en_plazo,
        ROUND(AVG(CASE WHEN s.estado_atencion_partner = 'Atendida' THEN TIMESTAMPDIFF(MINUTE, s.fecha_asignacion, s.fecha_atencion_partner) END)) AS minutos_promedio";

    $stmt = $pdo->prepare("SELECT p.id, p.nombre_partner, {$metricas}
        FROM supervisiones s JOIN partners p ON p.id = s.partner_id
        WHERE {$where} GROUP BY p.id, p.nombre_partner ORDER BY bloqueadas DESC, asignadas DESC");
    $stmt->execute($params);
    $partners = $stmt->fetchAll();

    $stmtTotal = $pdo->prepare("SELECT {$metricas} FROM supervisiones s JOIN partners p ON p.id = s.partner_id WHERE {$where}");
    $stmtTotal->execute($params);
    $resumen = $stmtTotal->fetch() ?: [];

    $stmtBloq = $pdo->prepare("SELECT s.id, s.codigo_supervision, s.departamento, s.fecha_asignacion, p.nombre_partner,
            u.nombre_completo AS supervisor_partner, a.nombre_completo AS asignado_por,
            TIMESTAMPDIFF(HOUR, {$limite}, NOW()) AS horas_vencida
        FROM supervisiones s
        JOIN partners p ON p.id = s.partner_id
        LEFT JOIN usuarios u ON u.id = s.supervisor_id
        LEFT JOIN usuarios a ON a.id = s.asignado_por
        WHERE {$where} AND COALESCE(s.estado_atencion_partner,'Pendiente') <> 'Atendida' AND NOW() > {$limite}
        ORDER BY s.fecha_asignacion ASC LIMIT 50");
    $stmtBloq->execute($params);

    $toInt = static fn(array $r): array => array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $r);

    // Por supervisor del partner (2026-10-13): quién atiende a tiempo y quién deja bloquear sus órdenes.
    $stmtSup = $pdo->prepare("SELECT u.id, u.nombre_completo, p.nombre_partner, {$metricas}
        FROM supervisiones s JOIN partners p ON p.id = s.partner_id JOIN usuarios u ON u.id = s.supervisor_id
        WHERE {$where} GROUP BY u.id, u.nombre_completo, p.nombre_partner ORDER BY bloqueadas DESC, asignadas DESC LIMIT 50");
    $stmtSup->execute($params);
    $supervisoresPartner = array_map(static function ($r) use ($toInt) {
        $r = $toInt($r);
        $cerradas = $r['a_tiempo'] + $r['bloqueadas'];
        $r['cumplimiento'] = $cerradas > 0 ? round($r['a_tiempo'] * 100 / $cerradas, 1) : null;
        return $r;
    }, $stmtSup->fetchAll());

    $partners = array_map(static function ($r) use ($toInt) {
        $r = $toInt($r);
        $cerradas = $r['a_tiempo'] + $r['bloqueadas'];
        $r['cumplimiento'] = $cerradas > 0 ? round($r['a_tiempo'] * 100 / $cerradas, 1) : null;
        return $r;
    }, $partners);
    $resumen = $toInt($resumen);
    $cerradas = ($resumen['a_tiempo'] ?? 0) + ($resumen['bloqueadas'] ?? 0);
    $resumen['cumplimiento'] = $cerradas > 0 ? round($resumen['a_tiempo'] * 100 / $cerradas, 1) : null;

    echo json_encode([
        'status' => 'success',
        'periodo' => ['inicio' => $inicio, 'fin' => $fin],
        'resumen' => $resumen,
        'partners' => $partners,
        'bloqueadas' => array_map($toInt, $stmtBloq->fetchAll()),
        'supervisores' => $supervisoresPartner,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pendientes cargadas por Excel y su atención por los supervisores directos (2026-10-13).
// Responsable: el supervisor directo que la tomó; si la transfirió a un partner, sigue siendo suya (asignado_por)
// y se muestra además quién la atendió en el partner.
if ($action === 'pendientes_directos') {
    $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_inicio'] ?? '')) ? $_GET['fecha_inicio'] : date('Y-m-01');
    $fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_fin'] ?? '')) ? $_GET['fecha_fin'] : date('Y-m-d');
    $where = "s.origen_importacion = 1 AND s.es_cancelacion = 1 AND s.fecha_carga >= :ini AND s.fecha_carga < DATE_ADD(:fin, INTERVAL 1 DAY)";
    $params = [':ini' => $inicio, ':fin' => $fin];
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    if ($partnerId > 0) { $where .= ' AND s.partner_id = :partner'; $params[':partner'] = $partnerId; }
    $depFiltro = sgsDashboardDepartamento();
    if ($depFiltro !== '') { $where .= ' AND s.departamento = :dep'; $params[':dep'] = $depFiltro; }

    $transferida = "(s.fecha_asignacion IS NOT NULL AND u.partner_id = s.partner_id AND UPPER(TRIM(COALESCE(p.nombre_partner,''))) NOT IN ('WIN','WIN DIRECTO'))";
    $base = "SELECT s.id, s.codigo_supervision, s.ticket_codigo, s.departamento, s.fecha_carga, s.fecha_asignacion, p.nombre_partner,
            COALESCE(rr.nombre_completo, u.nombre_completo) atendio_nombre,
            CASE WHEN s.rescate_estado = 'En rescate' THEN 'en_rescate'
                 WHEN s.rescate_estado IS NOT NULL THEN 'atendida'
                 WHEN {$transferida} AND (s.estado_atencion_partner = 'Atendida' OR s.estado_supervision = 'Finalizada') THEN 'partner_atendida'
                 WHEN {$transferida} AND NOW() > DATE_ADD(s.fecha_asignacion, INTERVAL 24 HOUR) THEN 'partner_vencida'
                 WHEN {$transferida} THEN 'partner_en_plazo'
                 WHEN s.estado_supervision = 'Finalizada' THEN 'atendida'
                 WHEN s.estado_supervision IN ('Iniciada','En proceso') THEN 'en_proceso'
                 ELSE 'sin_tomar' END situacion,
            CASE WHEN s.rescate_estado IS NOT NULL THEN s.rescate_responsable_id WHEN {$transferida} THEN s.asignado_por
                 WHEN s.estado_supervision <> 'Pendiente' THEN s.supervisor_id END responsable_id,
            s.rescate_estado,
            CASE WHEN s.rescate_estado IS NOT NULL AND s.rescate_estado <> 'En rescate' THEN s.rescate_fin
                 WHEN {$transferida} THEN s.fecha_atencion_partner
                 WHEN s.estado_supervision = 'Finalizada' THEN (SELECT MIN(h.fecha_cambio) FROM supervision_estado_historial h WHERE h.supervision_id = s.id AND h.estado_nuevo = 'Finalizada')
            END fecha_atencion
        FROM supervisiones s JOIN usuarios u ON u.id = s.supervisor_id LEFT JOIN partners p ON p.id = s.partner_id
        LEFT JOIN usuarios rr ON rr.id = s.rescate_responsable_id
        WHERE {$where}";
    $supFiltro = (int)($_GET['supervisor_id'] ?? 0);
    $externo = $supFiltro > 0 ? ' WHERE x.responsable_id = :sup' : '';
    if ($supFiltro > 0) $params[':sup'] = $supFiltro;

    $metricas = "COUNT(*) cargadas,
        SUM(x.situacion = 'sin_tomar') sin_tomar,
        SUM(x.situacion = 'en_proceso') en_proceso,
        SUM(x.situacion = 'en_rescate') en_rescate,
        SUM(x.rescate_estado = 'Rescatado') rescatadas,
        SUM(x.rescate_estado = 'No rescatado') no_rescatadas,
        SUM(x.situacion = 'atendida') atendidas_directo,
        SUM(x.situacion LIKE 'partner_%') transferidas,
        SUM(x.situacion = 'partner_atendida') atendidas_partner,
        SUM(x.situacion = 'partner_en_plazo') partner_en_plazo,
        SUM(x.situacion = 'partner_vencida') partner_vencidas,
        ROUND(AVG(CASE WHEN x.fecha_atencion IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, x.fecha_carga, x.fecha_atencion) END)) minutos_atencion";
    $toInt = static fn(array $r): array => array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $r);
    $conPct = static function (array $r) use ($toInt): array {
        $r = $toInt($r);
        $r['atendidas'] = ($r['atendidas_directo'] ?? 0) + ($r['atendidas_partner'] ?? 0);
        $tomadas = ($r['cargadas'] ?? 0) - ($r['sin_tomar'] ?? 0);
        $r['tomadas'] = $tomadas;
        $r['pct_atencion'] = ($r['cargadas'] ?? 0) > 0 ? round($r['atendidas'] * 100 / $r['cargadas'], 1) : null;
        return $r;
    };
    $st = $pdo->prepare("SELECT {$metricas} FROM ({$base}) x{$externo}"); $st->execute($params);
    $resumen = $conPct($st->fetch() ?: []);
    $st = $pdo->prepare("SELECT x.responsable_id id, r.nombre_completo, {$metricas} FROM ({$base}) x JOIN usuarios r ON r.id = x.responsable_id
        " . ($supFiltro > 0 ? 'WHERE x.responsable_id = :sup' : '') . " GROUP BY x.responsable_id, r.nombre_completo ORDER BY cargadas DESC, r.nombre_completo");
    $st->execute($params);
    $supervisores = array_map($conPct, $st->fetchAll());
    $st = $pdo->prepare("SELECT x.id, x.codigo_supervision, x.ticket_codigo, x.departamento, x.fecha_carga, x.situacion, x.rescate_estado, x.nombre_partner, x.fecha_atencion,
            r.nombre_completo responsable, IF(x.situacion LIKE 'partner_%' OR x.situacion IN ('atendida','en_proceso','en_rescate'), x.atendio_nombre, NULL) atendio
        FROM ({$base}) x LEFT JOIN usuarios r ON r.id = x.responsable_id{$externo} ORDER BY x.fecha_carga DESC, x.id DESC LIMIT 200");
    $st->execute($params);

    echo json_encode(['status' => 'success', 'periodo' => ['inicio' => $inicio, 'fin' => $fin], 'resumen' => $resumen,
        'supervisores' => $supervisores, 'detalle' => $st->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Observaciones y descargos de los partners (2026-10-13). Misma regla que la pantalla Observaciones: cada hallazgo de
// campo y cada ítem de checklist con comentario o foto es una observación, con el estado del descargo de su partner.
if ($action === 'observaciones_partners') {
    $inicio = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_inicio'] ?? '')) ? $_GET['fecha_inicio'] : date('Y-m-01');
    $fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha_fin'] ?? '')) ? $_GET['fecha_fin'] : date('Y-m-d');
    $partnerId = (int)($_GET['partner_id'] ?? 0);
    $depFiltro = sgsDashboardDepartamento();
    $params = [];
    // Prepares nativos: cada parte del UNION lleva sus propios marcadores.
    $filtro = static function (string $fecha, string $n) use (&$params, $inicio, $fin, $partnerId, $depFiltro): string {
        $sql = "{$fecha} >= :ini{$n} AND {$fecha} < DATE_ADD(:fin{$n}, INTERVAL 1 DAY) AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN','WIN DIRECTO')";
        $params[":ini{$n}"] = $inicio; $params[":fin{$n}"] = $fin;
        if ($partnerId > 0) { $sql .= " AND p.id = :partner{$n}"; $params[":partner{$n}"] = $partnerId; }
        if ($depFiltro !== '') { $sql .= " AND s.departamento = :dep{$n}"; $params[":dep{$n}"] = $depFiltro; }
        return $sql;
    };
    $base = "SELECT p.id partner_id, p.nombre_partner, 'Campo' tipo, o.gravedad,
            COALESCE(sub.descripcion_subopcion, c.nombre_categoria, 'Observación') motivo,
            o.fecha_registro, o.fecha_limite_respuesta limite, o.fecha_respuesta_partner respuesta,
            CASE WHEN o.estado_respuesta IN ('Apelación Aprobada','Levantamiento validado') THEN 'validada'
                 WHEN o.estado_respuesta IN ('Penalidad Directa','Apelación Rechazada','Levantamiento rechazado') THEN 'penalizada'
                 WHEN o.estado_respuesta IN ('Respondido','En revisión') THEN 'revision' ELSE 'pendiente' END grupo
        FROM observaciones_supervision o JOIN supervisiones s ON s.id = o.supervision_id JOIN partners p ON p.id = s.partner_id
        LEFT JOIN categorias_observacion c ON c.id = o.categoria_id LEFT JOIN subopciones_observacion sub ON sub.id = o.subopcion_id
        WHERE " . $filtro('o.fecha_registro', '1') . "
        UNION ALL
        SELECT p.id, p.nombre_partner, 'Checklist', NULL, cr.criterio, ct.fecha_registro, ct.fecha_limite_respuesta, ct.fecha_respuesta_partner,
            CASE ct.estado_respuesta WHEN 'Validado' THEN 'validada' WHEN 'Penalizado' THEN 'penalizada' WHEN 'En revisión' THEN 'revision' ELSE 'pendiente' END
        FROM checklist_respuestas cr JOIN checklists_tecnicos ct ON ct.id = cr.checklist_id JOIN supervisiones s ON s.id = ct.supervision_id
        JOIN partners p ON p.id = s.partner_id
        WHERE ((cr.observacion IS NOT NULL AND TRIM(cr.observacion) <> '') OR cr.foto_evidencia IS NOT NULL) AND " . $filtro('ct.fecha_registro', '2');

    $metricas = "COUNT(*) total,
        SUM(r.respuesta IS NOT NULL) respondidas,
        SUM(r.respuesta IS NOT NULL AND (r.limite IS NULL OR r.respuesta <= r.limite)) a_tiempo,
        SUM(r.respuesta IS NULL AND r.grupo = 'pendiente' AND (r.limite IS NULL OR NOW() <= r.limite)) en_plazo,
        SUM(r.respuesta IS NULL AND r.limite IS NOT NULL AND NOW() > r.limite) sin_respuesta_vencidas,
        SUM(r.grupo = 'revision') en_revision,
        SUM(r.grupo = 'validada') validadas,
        SUM(r.grupo = 'penalizada') penalizadas,
        ROUND(AVG(CASE WHEN r.respuesta IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.fecha_registro, r.respuesta) END)) minutos_respuesta";
    $toInt = static fn(array $r): array => array_map(static fn($v) => is_numeric($v) ? (int)$v : $v, $r);
    $conPorcentajes = static function (array $r) use ($toInt): array {
        $r = $toInt($r);
        $r['pct_a_tiempo'] = ($r['total'] ?? 0) > 0 ? round($r['a_tiempo'] * 100 / $r['total'], 1) : null;
        $evaluadas = ($r['validadas'] ?? 0) + ($r['penalizadas'] ?? 0);
        $r['pct_penalizadas'] = $evaluadas > 0 ? round($r['penalizadas'] * 100 / $evaluadas, 1) : null;
        return $r;
    };

    $st = $pdo->prepare("SELECT {$metricas} FROM ({$base}) r"); $st->execute($params);
    $resumen = $conPorcentajes($st->fetch() ?: []);
    $st = $pdo->prepare("SELECT r.partner_id, r.nombre_partner, {$metricas} FROM ({$base}) r GROUP BY r.partner_id, r.nombre_partner ORDER BY penalizadas DESC, total DESC");
    $st->execute($params);
    $partners = array_map($conPorcentajes, $st->fetchAll());
    $st = $pdo->prepare("SELECT r.motivo, r.tipo, COUNT(*) total, SUM(r.grupo = 'penalizada') penalizadas FROM ({$base}) r GROUP BY r.motivo, r.tipo ORDER BY total DESC LIMIT 8");
    $st->execute($params);
    $motivos = array_map($toInt, $st->fetchAll());
    $st = $pdo->prepare("SELECT COALESCE(r.gravedad, 'Checklist') gravedad, COUNT(*) total FROM ({$base}) r GROUP BY COALESCE(r.gravedad, 'Checklist')");
    $st->execute($params);
    $gravedad = array_map($toInt, $st->fetchAll());

    echo json_encode(['status' => 'success', 'periodo' => ['inicio' => $inicio, 'fin' => $fin], 'resumen' => $resumen,
        'partners' => $partners, 'motivos' => $motivos, 'gravedad' => $gravedad], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción de dashboard no válida.']);
