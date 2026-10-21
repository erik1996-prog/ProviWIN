<?php
// 05_TEST/api/permission_matrix.php - Llama a las acciones GET de la API con cada rol y compara con lo esperado.
// Uso (solo CLI): php 05_TEST/api/permission_matrix.php
// Solo lectura (notificaciones aplica su limpieza periódica, igual que en uso normal).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$roles = ['Administrador', 'Supervisor:directo', 'Supervisor:partner'];
// [endpoint, query, esperado por rol: 'ok' = responde datos, 'no' = rechaza (401/403/404/422)]
$cases = [
    ['auth', 'action=session', ['ok', 'ok', 'ok']],
    ['supervisiones', 'action=list&page_number=1&limit=5&estado=todos', ['ok', 'ok', 'ok']],
    ['supervisiones', 'action=pending_map', ['ok', 'ok', 'ok']], // cada rol ve solo su alcance
    ['supervisiones', 'action=detail&id=999999999', ['no', 'no', 'no']], // inexistente: 404 para todos
    ['usuarios', 'action=listar', ['ok', 'no', 'no']], // solo administrador
    ['usuarios', 'action=catalogos', ['ok', 'no', 'no']],
    ['supervisiones', 'action=indicadores_dashboard', ['ok', 'no', 'no']],
    ['dashboard', 'action=monitoreo_realtime', ['ok', 'no', 'no']],
    ['dashboard', 'action=matriz_mensual', ['ok', 'no', 'no']],
    ['dashboard_partners', 'action=dashboard', ['ok', 'no', 'no']],
    ['lluvias', 'action=estado', ['no', 'ok', 'no']], // solo el supervisor directo
    ['asistencia', 'action=status', ['ok', 'ok', 'ok']],
    ['asistencia', 'action=my_history', ['no', 'ok', 'no']],
    ['asistencia', 'action=admin_summary', ['ok', 'no', 'no']],
    ['asistencia', 'action=timeline_diaria', ['ok', 'no', 'no']],
    ['asistencia', 'action=list_filters', ['ok', 'no', 'no']],
    ['asistencia', 'action=registrar_vacaciones', ['no', 'no', 'no']],
    ['observaciones', 'action=catalogo', ['ok', 'ok', 'ok']],
    ['tecnicos', 'action=list', ['ok', 'ok', 'ok']],
    ['ubigeo', 'action=departamentos', ['ok', 'ok', 'ok']],
    ['notificaciones', 'action=list', ['ok', 'ok', 'ok']],
    ['health', '', ['ok', 'ok', 'ok']],
];
$bad = 0; $skipped = [];
printf("%-48s %-16s %-20s %-20s\n", 'Endpoint', ...$roles);
foreach ($cases as [$ep, $q, $expect]) {
    $cells = [];
    foreach ($roles as $i => $role) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/call_api.php') . ' ' . escapeshellarg($ep) . ' ' . escapeshellarg($q) . ' ' . escapeshellarg($role) . ' 2>&1';
        $out = (string)shell_exec($cmd);
        if (str_contains($out, "Sin usuario activo con rol")) { $cells[] = "- omitido"; $skipped[$role] = true; continue; }
        preg_match('/@@HTTP (\d+)@@/', $out, $m);
        $code = (int)($m[1] ?? 0);
        $json = json_decode(trim(preg_replace('/@@HTTP \d+@@/', '', $out)), true);
        $okBody = is_array($json) && !in_array($json['status'] ?? '', ['error', 'unauthenticated'], true);
        $got = ($code >= 200 && $code < 300 && $okBody) ? 'ok' : 'no';
        $match = $expect[$i] === '*' || $got === $expect[$i];
        if (!$match) $bad++;
        $msg = $got === 'no' ? ' ' . mb_substr((string)($json['message'] ?? ($json === null ? 'sin JSON: ' . mb_substr(trim($out), 0, 60) : '')), 0, 40) : '';
        $cells[] = ($match ? '✓ ' : '✗ ') . $code . ' ' . $got . ($match ? '' : $msg);
    }
    printf("%-48s %-16s %-20s %-20s\n", "$ep?$q", ...$cells);
}
if ($skipped) echo "
Omitido (no hay usuario activo de ese tipo): " . implode(", ", array_keys($skipped)) . "
";
echo $bad ? "\n$bad resultado(s) distinto(s) a lo esperado\n" : "\nTodo coincide con lo esperado\n";
exit($bad ? 1 : 0);
