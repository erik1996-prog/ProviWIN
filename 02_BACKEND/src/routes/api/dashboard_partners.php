<?php
// 02_BACKEND/src/routes/api/dashboard_partners.php - Dashboard de partners (2026-10-20). Antes era la vista "Partners" del
// Dashboard de supervisiones; se separó para no mezclar y no repetir: aquí está todo lo de los partners.
//   GET ?action=dashboard&desde&hasta&partner_id&zona → cumplimiento (atención en 24 h de lo que se les transfiere, por
//       partner y por supervisor del partner, y órdenes bloqueadas) y observaciones (descargos a tiempo, validados, penalizados).
// Solo administración (permiso del dashboard). Los totales se calculan en la base.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';

Auth::requireLogin();
if (!Auth::puede(Auth::PERM_PARTNERS_DASHBOARD)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Se requieren permisos administrativos.']);
    exit;
}
$pdo = Database::getConnection();
if (($_GET['action'] ?? '') !== 'dashboard') {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}

// Filtros: período (por defecto el mes en curso), partner y zona.
$fecha = static fn(string $v, string $defecto): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $defecto;
$inicio = $fecha((string)($_GET['desde'] ?? ''), date('Y-m-01'));
$fin = $fecha((string)($_GET['hasta'] ?? ''), date('Y-m-d'));
if ($inicio > $fin) [$inicio, $fin] = [$fin, $inicio];
$zona = mb_strtoupper(trim((string)($_GET['zona'] ?? '')), 'UTF-8');
$filtros = [$inicio, $fin, (int)($_GET['partner_id'] ?? 0), in_array($zona, sgsOperationalDepartments(), true) ? $zona : ''];

/** Cumplimiento: órdenes transferidas a supervisores de partners y si las atendieron dentro de las 24 h. */
function sgsCumplimientoPartners(PDO $pdo, array $f): array {
    [$inicio, $fin, $partnerId, $depFiltro] = $f;
    // Misma definición que el listado (es_asignacion_partner): asignada y el supervisor pertenece al partner de la orden.
    $where = "s.fecha_asignacion IS NOT NULL
        AND EXISTS (SELECT 1 FROM usuarios su WHERE su.id = s.supervisor_id AND su.partner_id = s.partner_id)
        AND s.fecha_asignacion >= :inicio AND s.fecha_asignacion < DATE_ADD(:fin, INTERVAL 1 DAY)
        AND UPPER(TRIM(p.nombre_partner)) NOT IN ('WIN','WIN DIRECTO')";
    $params = [':inicio' => $inicio, ':fin' => $fin];
    if ($partnerId > 0) { $where .= ' AND p.id = :partner'; $params[':partner'] = $partnerId; }
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

    return [
        'resumen' => $resumen,
        'partners' => $partners,
        'bloqueadas' => array_map($toInt, $stmtBloq->fetchAll()),
        'supervisores' => $supervisoresPartner,
    ];
}

/**
 * Observaciones y descargos (2026-10-13). Misma regla que la pantalla Observaciones: cada hallazgo de campo y cada ítem
 * de checklist con comentario o foto es una observación, con el estado del descargo de su partner.
 */
function sgsObservacionesPartners(PDO $pdo, array $f): array {
    [$inicio, $fin, $partnerId, $depFiltro] = $f;
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

    return ['resumen' => $resumen,
        'partners' => $partners, 'motivos' => $motivos, 'gravedad' => $gravedad];
}

echo json_encode(['status' => 'success', 'data' => [
    'periodo' => ['inicio' => $inicio, 'fin' => $fin],
    'cumplimiento' => sgsCumplimientoPartners($pdo, $filtros),
    'observaciones' => sgsObservacionesPartners($pdo, $filtros),
]], JSON_UNESCAPED_UNICODE);
