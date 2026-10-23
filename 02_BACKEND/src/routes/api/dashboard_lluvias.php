<?php
// 02_BACKEND/src/routes/api/dashboard_lluvias.php - Indicador de lluvias por zona (2026-10-20). Tabla lluvias_zona.
// Cada lluvia es de una zona: inicio = el primer supervisor que la avisó, fin = el que la cerró (con qué hacía cada uno).
//   GET ?action=dashboard&mes=AAAA-MM[&zona=X] → indicadores del mes, horas por zona, días del calendario y bitácora.
// Administración ve todas las zonas; el supervisor directo, las suyas. Pocas filas por mes: se resumen aquí mismo.
//   POST ?action=eliminar  id → borra un registro de lluvia (permiso "Eliminar registros de lluvia", 2026-10-23).
//     Si era de hoy, avisa en Teams que el aviso se anuló (el inicio ya se había publicado en el grupo).
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/lluvias.php';

Auth::requireLogin();
Auth::requireCsrf();
$usuario = Auth::getLoggedInUser();
$esAdmin = Auth::isAdministrative();
$responder = static function (array $datos, int $codigo = 200): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
};
if (!Auth::puede(Auth::PERM_CLIMA_VIEW, $usuario)) {
    $responder(['status' => 'error', 'message' => 'No tiene permiso para ver Clima.'], 403);
}
if (($_GET['action'] ?? '') === 'eliminar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::puede(Auth::PERM_CLIMA_DELETE, $usuario)) $responder(['status' => 'error', 'message' => 'No tiene permiso para eliminar lluvias.'], 403);
    $id = (int)($_POST['id'] ?? 0);
    $pdo = Database::getConnection();
    $st = $pdo->prepare('SELECT departamento, inicio FROM lluvias_zona WHERE id = ?');
    $st->execute([$id]);
    $lluvia = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lluvia) $responder(['status' => 'error', 'message' => 'Esa lluvia ya no existe.'], 404);
    $pdo->prepare('DELETE FROM lluvias_zona WHERE id = ?')->execute([$id]);
    if (date('Y-m-d', strtotime($lluvia['inicio'])) === date('Y-m-d')) sgsAvisoTeamsLluvia("Aviso de lluvia anulado — {$lluvia['departamento']}",
        'El inicio de lluvia de las ' . date('H:i', strtotime($lluvia['inicio'])) . ' se registró por error. Anulado por: ' . $usuario['nombre']);
    win_log_error('audit_rain_deleted', 'Registro de lluvia eliminado', ['actor_id' => (int)$usuario['id'], 'lluvia_id' => $id]);
    $responder(['status' => 'success', 'message' => 'Lluvia eliminada.']);
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
