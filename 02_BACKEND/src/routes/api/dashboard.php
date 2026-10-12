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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción de dashboard no válida.']);
