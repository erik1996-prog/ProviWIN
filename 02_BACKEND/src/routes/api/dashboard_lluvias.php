<?php
// 02_BACKEND/src/routes/api/dashboard_lluvias.php - Indicador de lluvias por zona (2026-10-20). Tabla lluvias_zona.
// Cada lluvia es de una zona: inicio = el primer supervisor que la avisó, fin = el que la cerró (con qué hacía cada uno).
//   GET ?action=dashboard&mes=AAAA-MM[&zona=X] → indicadores del mes, horas por zona, días del calendario y bitácora.
// Administración ve todas las zonas; el supervisor directo, las suyas. Pocas filas por mes: se resumen aquí mismo.
//   POST ?action=eliminar  id → borra un registro de lluvia (permiso "Eliminar registros de lluvia", 2026-10-23).
//     Si era de hoy, avisa en Teams que el aviso se anuló (el inicio ya se había publicado en el grupo).
//   POST ?action=enviar_corte → publica ya en Teams el corte de lluvias (lluvias de los últimos 7 días; desde las 12:00 incluye hoy), el mismo del cron de las
//     08:00 (07_SCRIPTS/corte_lluvias.php). Solo personal administrativo (2026-10-23).
//   Envíos programados del corte (tabla lluvias_cortes_programados, solo la cuenta principal):
//     GET ?action=programas · POST ?action=programa_guardar (hora HH:MM)
//     POST ?action=programa_estado (id, activo 0/1) · POST ?action=programa_eliminar (id)
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
if (($_GET['action'] ?? '') === 'enviar_corte' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esAdmin) $responder(['status' => 'error', 'message' => 'Solo el personal administrativo puede enviar el corte.'], 403);
    require_once __DIR__ . '/../../config/lluvias_corte.php';
    require_once __DIR__ . '/../../config/examen_enlaces.php'; // sgsUrlSistema
    $url = sgsUrlSistema();
    $ok = sgsPublicarCorteLluvias(Database::getConnection(), sgsHastaDelCorte(), str_starts_with($url, 'https://') ? $url : null);
    win_log_error('audit_rain_report_sent', 'Corte de lluvias enviado a Teams a mano', ['actor_id' => (int)$usuario['id'], 'ok' => $ok]);
    $responder($ok ? ['status' => 'success', 'message' => 'Corte enviado al grupo de Teams.']
        : ['status' => 'error', 'message' => 'No se pudo enviar el corte. Revise que esté configurado el grupo de Teams (09_CONFIG/teams_lluvias.local.txt).'], $ok ? 200 : 502);
}
$accion = (string)($_GET['action'] ?? '');
if (str_starts_with($accion, 'programa')) {
    // Solo la cuenta principal programa las horas del corte (2026-10-26)
    if (!$esAdmin || !Auth::esCuentaPrincipal((int)$usuario['id'])) $responder(['status' => 'error', 'message' => 'Solo la cuenta principal programa los envíos del corte.'], 403);
    $pdo = Database::getConnection();
    $lista = static fn() => $pdo->query("SELECT id, TIME_FORMAT(hora, '%H:%i') AS hora, activo, ultimo_envio FROM lluvias_cortes_programados ORDER BY hora")->fetchAll(PDO::FETCH_ASSOC);
    $id = (int)($_POST['id'] ?? 0);
    if ($accion !== 'programas' && $_SERVER['REQUEST_METHOD'] !== 'POST') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 405);
    if ($accion === 'programa_guardar') {
        $hora = (string)($_POST['hora'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $responder(['status' => 'error', 'message' => 'Elija una hora válida.'], 422);
        if (count($lista()) >= 8) $responder(['status' => 'error', 'message' => 'Puede programar hasta 8 envíos al día.'], 422);
        $st = $pdo->prepare('INSERT IGNORE INTO lluvias_cortes_programados (hora, creado_por) VALUES (?, ?)');
        $st->execute(["$hora:00", (int)$usuario['id']]);
        if (!$st->rowCount()) $responder(['status' => 'error', 'message' => "Ya hay un envío programado a las {$hora}."], 409);
        $mensaje = "Envío programado a las {$hora}.";
    } elseif ($accion === 'programa_estado') {
        $pdo->prepare('UPDATE lluvias_cortes_programados SET activo = ? WHERE id = ?')->execute([(int)!empty($_POST['activo']), $id]);
        $mensaje = !empty($_POST['activo']) ? 'Envío activado.' : 'Envío pausado.';
    } elseif ($accion === 'programa_eliminar') {
        $pdo->prepare('DELETE FROM lluvias_cortes_programados WHERE id = ?')->execute([$id]);
        $mensaje = 'Envío eliminado.';
    } elseif ($accion !== 'programas') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 404);
    if (isset($mensaje)) win_log_error('audit_rain_report_schedule', $mensaje, ['actor_id' => (int)$usuario['id'], 'accion' => $accion]);
    $responder(['status' => 'success', 'data' => $lista(), 'message' => $mensaje ?? '']);
}
if ($accion !== 'dashboard') $responder(['status' => 'error', 'message' => 'Acción no válida.'], 404);
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
