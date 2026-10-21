<?php
// 02_BACKEND/src/routes/api/dashboard_lluvias.php - Indicador de lluvias por zona (2026-10-20). Tabla lluvias_zona.
// Cada lluvia es de una zona: inicio = el primer supervisor que la avisó, fin = el que la cerró (con qué hacía cada uno).
//   GET ?action=dashboard&mes=AAAA-MM[&zona=X] → indicadores del mes, horas por zona, días del calendario y bitácora.
// Administración ve todas las zonas; el supervisor directo, las suyas. Pocas filas por mes: se resumen aquí mismo.
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/lluvias.php';

Auth::requireLogin();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!$esAdmin && !(($usuario['rol'] ?? '') === Auth::ROLE_SUPERVISOR && !empty($usuario['is_win_directo']))) {
    $responder(['status' => 'error', 'message' => 'Acceso reservado al personal administrativo y a los supervisores directos.'], 403);
}
if (($_GET['action'] ?? '') !== 'dashboard') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 404);
$pdo = Database::getConnection();
sgsCerrarLluviasViejas($pdo);

$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
$desde = "{$mes}-01";
$hasta = date('Y-m-d', strtotime("{$desde} +1 month"));
$where = ['l.inicio >= :desde', 'l.inicio < :hasta'];
$params = [':desde' => $desde, ':hasta' => $hasta];
$zona = mb_strtoupper(trim((string)($_GET['zona'] ?? '')), 'UTF-8');
if ($zona !== '') { $where[] = 'l.departamento = :zona'; $params[':zona'] = $zona; }
if (!$esAdmin && sgsUserZones((int)$usuario['id']) !== null) {
    $zp = [];
    $where[] = sgsZoneSql('l.departamento', (int)$usuario['id'], $zp, ':uz');
    $params += $zp;
}
$st = $pdo->prepare("SELECT l.id, l.departamento AS zona, l.inicio, l.fin, ua.nombre_completo AS aviso, l.comentario_inicio,
        uc.nombre_completo AS cerro, l.comentario_fin, TIMESTAMPDIFF(MINUTE, l.inicio, COALESCE(l.fin, NOW())) AS minutos
    FROM lluvias_zona l JOIN usuarios ua ON ua.id = l.reportado_por LEFT JOIN usuarios uc ON uc.id = l.cerrado_por
    WHERE " . implode(' AND ', $where) . ' ORDER BY l.inicio DESC LIMIT 1000');
$st->execute($params);
$bitacora = array_map(static fn($r) => ['minutos' => max(0, (int)$r['minutos']), 'sigue' => $r['fin'] === null] + $r, $st->fetchAll(PDO::FETCH_ASSOC));

// Resúmenes: por zona y por día (fecha de inicio de la lluvia)
$porZona = []; $dias = [];
foreach ($bitacora as $l) {
    $z = &$porZona[$l['zona']];
    $z = ['nombre' => $l['zona'], 'lluvias' => ($z['lluvias'] ?? 0) + 1, 'minutos' => ($z['minutos'] ?? 0) + $l['minutos'], 'dias' => ($z['dias'] ?? []) + [substr($l['inicio'], 0, 10) => 1]];
    unset($z);
    $d = &$dias[substr($l['inicio'], 0, 10)];
    $d = ['lluvias' => ($d['lluvias'] ?? 0) + 1, 'minutos' => ($d['minutos'] ?? 0) + $l['minutos'], 'zonas' => array_values(array_unique(array_merge($d['zonas'] ?? [], [$l['zona']])))];
    unset($d);
}
$porZona = array_values(array_map(static fn($z) => ['dias' => count($z['dias'])] + $z, $porZona));
usort($porZona, static fn($a, $b) => $b['minutos'] <=> $a['minutos']);
$maximo = max([1, ...array_column($porZona, 'minutos')]);
$porZona = array_map(static fn($z) => $z + ['pct' => (int)round($z['minutos'] * 100 / $maximo)], $porZona);
$minutos = array_sum(array_column($bitacora, 'minutos'));

$responder(['status' => 'success', 'data' => [
    'mes' => $mes,
    'kpis' => ['lluvias' => count($bitacora), 'dias' => count($dias), 'minutos' => $minutos,
        'promedio' => $bitacora ? (int)round($minutos / count($bitacora)) : null,
        'zona_mas' => $porZona[0]['nombre'] ?? null, 'abiertas' => count(array_filter($bitacora, static fn($l) => $l['sigue']))],
    'por_zona' => $porZona,
    'dias' => $dias,
    'bitacora' => $bitacora,
]]);
