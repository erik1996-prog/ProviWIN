<?php
// 02_BACKEND/src/routes/api/dashboard_pendientes.php - Dashboard "Pendientes y rescates" (2026-10-20): las cancelaciones
// cargadas por Excel y qué pasó con ellas. Es el ÚNICO lugar con esta información (antes se repetía en el Dashboard de
// supervisiones, sección "Pendientes cargadas"). Administración ve todo; el supervisor directo, su zona. Piezas comunes en
// config/dashboard.php. Filtros: desde, hasta (fecha de carga), partner_id, zona, supervisor_id (responsable directo).
//   GET ?action=dashboard        → indicadores, embudo, antigüedad, por zona y partner, motivos, rescates, por día,
//                                  por supervisor directo y por supervisor de partner
//   GET ?action=dashboard_tabla  → órdenes de 50 en 50 (estado, q, pagina)
//   GET ?action=dashboard_excel  → las mismas órdenes, todas, en Excel
// Estados: pendiente (sin tomar) · en_validacion · en_rescate · rescatada · correcta (cancelación correcta, también las
// que fueron a rescate y no se rescataron) · en_partner (un directo la transfirió y el partner aún no la atiende) ·
// partner_atendida. Una transferida sigue siendo del directo que la transfirió (responsable); el partner tiene 24 h.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/dashboard.php';

Auth::requireLogin();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
$esDirecto = ($usuario['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($usuario['is_win_directo']);
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$esAdmin && !$esDirecto) $responder(['status' => 'error', 'message' => 'Solo el personal administrativo y los supervisores directos ven este dashboard.'], 403);
$action = $_GET['action'] ?? '';

try {
    $pdo = Database::getConnection();
    // Transferida a partner: la tiene asignada un supervisor de ese partner (no WIN).
    $transferida = "(s.fecha_asignacion IS NOT NULL AND us.partner_id IS NOT NULL AND us.partner_id = s.partner_id AND UPPER(TRIM(COALESCE(p.nombre_partner, ''))) NOT IN ('WIN', 'WIN DIRECTO'))";
    $atendidaPartner = "(s.estado_atencion_partner = 'Atendida')";
    $estadoSql = "CASE WHEN s.rescate_estado = 'En rescate' THEN 'en_rescate'
        WHEN s.rescate_estado = 'Rescatado' OR s.estado_cancelacion = 'Rescate' THEN 'rescatada'
        WHEN s.estado_supervision IN ('Finalizada', 'Culminada', 'Finalizado') THEN 'correcta'
        WHEN s.estado_supervision IN ('Iniciada', 'En proceso') THEN 'en_validacion'
        WHEN {$transferida} AND {$atendidaPartner} THEN 'partner_atendida'
        WHEN {$transferida} THEN 'en_partner' ELSE 'pendiente' END";
    // Responsable directo: quien la rescata, quien la transfirió o quien la tomó.
    $responsableSql = "CASE WHEN s.rescate_responsable_id IS NOT NULL THEN s.rescate_responsable_id WHEN {$transferida} THEN s.asignado_por
        WHEN s.estado_supervision <> 'Pendiente' THEN s.supervisor_id END";
    // Cierre: fin del rescate o, si no hubo, cuando se finalizó la validación (historial de estados).
    $cierreSql = "COALESCE(s.rescate_fin, (SELECT MAX(h.fecha_cambio) FROM supervision_estado_historial h WHERE h.supervision_id = s.id AND h.estado_nuevo = 'Finalizada'))";
    $vencidaPartner = "({$transferida} AND NOT {$atendidaPartner} AND s.estado_supervision = 'Pendiente' AND NOW() > s.fecha_asignacion + INTERVAL 24 HOUR)";

    [$where, $params] = sgsFiltrosDashboard(['fecha' => 's.fecha_carga', 'partner' => 's.partner_id', 'zona' => 's.departamento'], $usuario, $esAdmin);
    array_unshift($where, 's.es_cancelacion = 1', 's.origen_importacion = 1');
    if (($sup = (int)($_GET['supervisor_id'] ?? 0)) > 0) { $where[] = "({$responsableSql}) = :sup"; $params[':sup'] = $sup; }
    $consulta = sgsConsultaDashboard($pdo, "FROM supervisiones s LEFT JOIN partners p ON p.id = s.partner_id
        LEFT JOIN usuarios us ON us.id = s.supervisor_id LEFT JOIN usuarios ur ON ur.id = s.rescate_responsable_id
        LEFT JOIN usuarios rd ON rd.id = ({$responsableSql})", $where, $params);
    $etiquetas = ['pendiente' => 'Pendiente', 'en_validacion' => 'En validación', 'en_rescate' => 'En rescate', 'rescatada' => 'Rescatada',
        'correcta' => 'Cancelación correcta', 'en_partner' => 'En partner', 'partner_atendida' => 'Atendida por partner'];
    $grupos = array_map(static fn($e) => [$e], array_combine(array_keys($etiquetas), array_keys($etiquetas)));
    $relativo = static fn(array $lista, string $campo): array => array_map(static fn($x) => $x + ['pct' => sgsPorcentaje($x[$campo], max(1, ...array_column($lista, $campo)))], $lista);
    $enteros = static fn(array $x): array => array_map(static fn($v) => is_numeric($v) ? $v + 0 : $v, $x);

    if ($action === 'dashboard') {
        // GROUP BY por posición: "estado" también es una columna de partners y la base agruparía por esa.
        $cuentas = array_map('intval', array_column($consulta("{$estadoSql} AS estado, COUNT(*) AS n", 'GROUP BY 1'), 'n', 'estado')) + array_fill_keys(array_keys($etiquetas), 0);
        $cargadas = array_sum($cuentas);
        $cerradas = $cuentas['rescatada'] + $cuentas['correcta'];
        $k = $consulta("ROUND(AVG(CASE WHEN ({$estadoSql}) IN ('rescatada', 'correcta') THEN TIMESTAMPDIFF(MINUTE, s.fecha_carga, {$cierreSql}) END) / 60, 1) AS horas,
            COALESCE(SUM(s.rescate_estado IS NOT NULL AND s.rescate_estado <> ''), 0) AS pasaron_rescate, COALESCE(SUM({$vencidaPartner}), 0) AS vencidas_partner,
            COALESCE(SUM(({$estadoSql}) = 'pendiente' AND s.fecha_carga >= NOW() - INTERVAL 1 DAY), 0) AS a1,
            COALESCE(SUM(({$estadoSql}) = 'pendiente' AND s.fecha_carga < NOW() - INTERVAL 1 DAY AND s.fecha_carga >= NOW() - INTERVAL 3 DAY), 0) AS a2,
            COALESCE(SUM(({$estadoSql}) = 'pendiente' AND s.fecha_carga < NOW() - INTERVAL 3 DAY), 0) AS a3")[0];
        $porGrupo = static fn(string $expr): array => array_map(static fn($x) => ['nombre' => $x['nombre'], 'cargadas' => (int)$x['cargadas'], 'pendientes' => (int)$x['pendientes'],
            'rescatadas' => (int)$x['rescatadas'], 'cerradas' => (int)$x['cerradas'], 'pct_rescate' => sgsPorcentaje($x['rescatadas'], $x['cerradas'])],
            $consulta("{$expr} AS nombre, COUNT(*) AS cargadas, SUM(({$estadoSql}) = 'pendiente') AS pendientes, SUM(({$estadoSql}) = 'rescatada') AS rescatadas,
                SUM(({$estadoSql}) IN ('rescatada', 'correcta')) AS cerradas", 'GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT 12'));
        $motivos = array_map(static fn($x) => ['nombre' => $x['nombre'], 'cantidad' => (int)$x['cantidad']],
            $consulta("COALESCE(NULLIF(s.tipificacion_cancelacion, ''), 'Sin motivo') AS nombre, COUNT(*) AS cantidad", 'GROUP BY 1 ORDER BY 2 DESC LIMIT 10'));
        $rescates = array_map(static fn($x) => ['nombre' => $x['nombre'], 'cantidad' => (int)$x['rescatadas'], 'en_curso' => (int)$x['en_curso']],
            $consulta("COALESCE(ur.nombre_completo, 'Sin responsable') AS nombre, SUM(s.rescate_estado = 'Rescatado') AS rescatadas, SUM(s.rescate_estado = 'En rescate') AS en_curso",
                "AND s.rescate_estado IS NOT NULL AND s.rescate_estado <> '' GROUP BY 1 ORDER BY 2 DESC, 3 DESC LIMIT 12"));
        // Por supervisor directo (responsable): tomadas, lo que atendió él y lo que transfirió al partner.
        $directos = array_map(static function ($x) use ($enteros) {
            $x = $enteros($x);
            $x['atendidas'] = $x['cerradas'] + $x['atendidas_partner'];
            $x['pct_atencion'] = sgsPorcentaje($x['atendidas'], $x['tomadas']);
            return $x;
        }, $consulta("rd.id, rd.nombre_completo AS nombre, COUNT(*) AS tomadas, SUM(({$estadoSql}) IN ('correcta', 'rescatada') AND NOT {$transferida}) AS cerradas,
                SUM(({$estadoSql}) = 'en_validacion') AS en_validacion, SUM(({$estadoSql}) = 'en_rescate') AS en_rescate, SUM(({$estadoSql}) = 'rescatada') AS rescatadas,
                SUM({$transferida}) AS transferidas, SUM({$transferida} AND ({$atendidaPartner} OR s.estado_supervision IN ('Finalizada', 'Culminada', 'Finalizado'))) AS atendidas_partner,
                SUM({$vencidaPartner}) AS vencidas_partner,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, s.fecha_carga, CASE WHEN ({$estadoSql}) IN ('correcta', 'rescatada') THEN {$cierreSql}
                    WHEN {$transferida} AND {$atendidaPartner} THEN s.fecha_atencion_partner END)) / 60, 1) AS horas",
            'AND rd.id IS NOT NULL GROUP BY rd.id, rd.nombre_completo ORDER BY tomadas DESC, nombre LIMIT 50'));
        // Por supervisor de partner: lo que le transfirieron y si lo atendió en sus 24 h.
        $partners = array_map(static function ($x) use ($enteros) {
            $x = $enteros($x);
            $x['pct_a_tiempo'] = sgsPorcentaje($x['a_tiempo'], $x['asignadas']);
            return $x;
        }, $consulta("us.id, us.nombre_completo AS nombre, COALESCE(p.nombre_partner, 'Sin partner') AS partner, COUNT(*) AS asignadas,
                SUM({$atendidaPartner} OR s.estado_supervision IN ('Finalizada', 'Culminada', 'Finalizado')) AS atendidas,
                SUM(NOT {$atendidaPartner} AND s.estado_supervision = 'Pendiente' AND NOW() <= s.fecha_asignacion + INTERVAL 24 HOUR) AS en_plazo,
                SUM({$vencidaPartner}) AS vencidas,
                SUM({$atendidaPartner} AND s.fecha_atencion_partner <= s.fecha_asignacion + INTERVAL 24 HOUR) AS a_tiempo,
                ROUND(AVG(CASE WHEN {$atendidaPartner} THEN TIMESTAMPDIFF(MINUTE, s.fecha_asignacion, s.fecha_atencion_partner) END) / 60, 1) AS horas",
            "AND {$transferida} GROUP BY us.id, us.nombre_completo, p.nombre_partner ORDER BY asignadas DESC, nombre LIMIT 50"));

        $responder(['status' => 'success', 'data' => [
            'kpis' => ['cargadas' => $cargadas, 'pct_rescate' => $cerradas ? sgsPorcentaje($cuentas['rescatada'], $cerradas) : null,
                'horas_promedio' => $k['horas'] !== null ? (float)$k['horas'] : null, 'vencidas_partner' => (int)$k['vencidas_partner']] + $cuentas,
            'cuentas' => ['' => $cargadas] + $cuentas,
            'embudo' => array_map(static fn($x) => $x + ['pct' => sgsPorcentaje($x['cantidad'], $cargadas)], [
                ['nombre' => 'Cargadas', 'cantidad' => $cargadas],
                ['nombre' => 'Atendidas o transferidas', 'cantidad' => $cargadas - $cuentas['pendiente']],
                ['nombre' => 'Pasaron a rescate', 'cantidad' => (int)$k['pasaron_rescate']],
                ['nombre' => 'Rescatadas', 'cantidad' => $cuentas['rescatada']],
            ]),
            'antiguedad' => [['nombre' => 'Menos de 24 h', 'cantidad' => (int)$k['a1']], ['nombre' => '1 a 3 días', 'cantidad' => (int)$k['a2']], ['nombre' => 'Más de 3 días', 'cantidad' => (int)$k['a3']]],
            'por_zona' => $relativo($porGrupo("COALESCE(NULLIF(s.departamento, ''), 'Sin zona')"), 'cargadas'),
            'por_partner' => $porGrupo("COALESCE(p.nombre_partner, 'Sin partner')"),
            'motivos' => $relativo($motivos, 'cantidad'),
            'rescates' => $relativo($rescates, 'cantidad'),
            'por_dia' => array_reverse(array_map(static fn($x) => ['fecha' => $x['fecha'], 'cantidad' => (int)$x['cantidad']],
                $consulta('DATE(s.fecha_carga) AS fecha, COUNT(*) AS cantidad', 'GROUP BY 1 ORDER BY 1 DESC LIMIT 31'))),
            'directos' => $directos, 'partners' => $partners, 'etiquetas' => $etiquetas,
        ]]);
    }

    if (in_array($action, ['dashboard_tabla', 'dashboard_excel'], true)) {
        [$extra, $mas] = sgsFiltroTablaDashboard($estadoSql, $grupos, ['s.ticket_codigo', 's.codigo_supervision', 'p.nombre_partner', 's.tipificacion_cancelacion']);
        $columnas = "s.id, s.codigo_supervision, s.ticket_codigo, s.fecha_carga, s.departamento, s.distrito, s.tipificacion_cancelacion, p.nombre_partner,
            rd.nombre_completo AS responsable, IF({$transferida}, us.nombre_completo, NULL) AS atiende_partner, {$estadoSql} AS estado,
            {$cierreSql} AS cierre, s.fecha_atencion_partner";
        $fila = static function (array $x): array {
            $cierre = in_array($x['estado'], ['rescatada', 'correcta'], true) ? $x['cierre'] : ($x['estado'] === 'partner_atendida' ? $x['fecha_atencion_partner'] : null);
            return ['id' => (int)$x['id'], 'ticket' => $x['ticket_codigo'] ?: $x['codigo_supervision'], 'carga' => $x['fecha_carga'],
                'zona' => $x['departamento'] ?: 'Sin zona', 'distrito' => $x['distrito'], 'motivo' => $x['tipificacion_cancelacion'] ?: '—', 'partner' => $x['nombre_partner'] ?: 'Sin partner',
                'responsable' => $x['estado'] === 'pendiente' ? 'Aún no gestionado' : $x['responsable'],
                'atiende_partner' => $x['atiende_partner'],
                'estado' => $x['estado'], 'cierre' => $cierre,
                'horas' => round(((($cierre ? strtotime($cierre) : time()) - strtotime((string)$x['fecha_carga'])) / 3600), 1)];
        };
        $orden = 's.fecha_carga DESC, s.id DESC';
        if ($action === 'dashboard_tabla') {
            $responder(['status' => 'success', 'data' => sgsPaginaDashboard($consulta, $columnas, $extra, $mas, $orden, $fila) + ['etiquetas' => $etiquetas]]);
        }
        $hoja = [['PEDIDO / TICKET', 'FECHA DE CARGA', 'ZONA', 'DISTRITO', 'PARTNER', 'MOTIVO DE CANCELACIÓN', 'ESTADO', 'RESPONSABLE', 'ATIENDE EN EL PARTNER', 'CIERRE', 'HORAS']];
        foreach (array_map($fila, $consulta($columnas, "{$extra} ORDER BY {$orden} LIMIT 20000", $mas)) as $x) {
            $hoja[] = [$x['ticket'], date('d/m/Y H:i', strtotime($x['carga'])), $x['zona'], $x['distrito'] ?? '', $x['partner'], $x['motivo'], $etiquetas[$x['estado']],
                $x['responsable'] ?? '', $x['atiende_partner'] ?? '', $x['cierre'] ? date('d/m/Y H:i', strtotime($x['cierre'])) : '', (string)$x['horas']];
        }
        sgsEnviarXlsx($hoja, 'Pendientes', 'pendientes_y_rescates');
    }

    $responder(['status' => 'error', 'message' => 'Acción no válida.'], 400);
} catch (Throwable $e) {
    win_log_error('pending_dashboard_error', $e->getMessage(), ['action' => $action]);
    $responder(['status' => 'error', 'message' => 'No se pudo cargar el dashboard.', 'code' => function_exists('sgs_error_code') ? sgs_error_code() : null], 500);
}
