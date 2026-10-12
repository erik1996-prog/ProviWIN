<?php
// 05_TEST/integration/trabajo_remoto_test.php - Trabajo remoto en el turno y primera acción (por HTTP, como un supervisor).
// Crea un supervisor directo temporal (DNI 99999916) y un administrador temporal (DNI 99999917); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/trabajo_remoto_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'RemotoPrueba' . random_int(1000, 9999);
$jarSup = tempnam(sys_get_temp_dir(), 'sgsc');
$jarAdm = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jarSup, $jarAdm) {
    foreach (['99999916', '99999917'] as $dni) {
        $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni='$dni'")->fetchColumn();
        if (!$id) continue;
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['remoto.sup', 'remoto.adm'] as $u)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jarSup); @unlink($jarAdm);
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = new UsuarioService($pdo);
$supId = (int)$svc->guardar(['dni' => '99999916', 'nombres' => 'Remoto', 'apellidos' => 'Supervisor Prueba', 'email' => 'remoto.sup@proviwin.test', 'usuario' => 'remoto.sup',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin)['id'];
$svc->guardar(['dni' => '99999917', 'nombres' => 'Remoto', 'apellidos' => 'Admin Prueba', 'email' => 'remoto.adm@proviwin.test', 'usuario' => 'remoto.adm',
    'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);

$http = function (string $jar, string $url, ?array $json = null, string $csrf = '') {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60];
    if ($json !== null) $opts += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"], CURLOPT_POSTFIELDS => json_encode($json)];
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return [$body, json_decode($body, true)];
};
$entrar = function (string $jar, string $usuario) use ($http, $base, $clave): string {
    $http($jar, "$base/api/auth.php?action=login", ['usuario' => $usuario, 'password' => $clave]);
    [$pagina] = $http($jar, "$base/index.php?page=asistencia");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    return $m[1] ?? '';
};
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Trabajo remoto y primera acción del turno — $base\n";

$csrf = $entrar($jarSup, 'remoto.sup');
$gps = ['latitud' => '-5.1945', 'longitud' => '-80.6328'];
[, $r] = $http($jarSup, "$base/api/asistencia.php?action=start", $gps, $csrf);
$check('el supervisor inicia su turno', ($r['status'] ?? '') === 'success', $r);
// El turno empezó "hace 25 minutos" para medir la primera acción
$pdo->prepare('UPDATE asistencia_supervisores SET inicio_turno = NOW() - INTERVAL 25 MINUTE WHERE supervisor_id = :s')->execute([':s' => $supId]);

[, $r] = $http($jarSup, "$base/api/asistencia.php?action=start_event", ['tipo_evento' => 'remoto', 'nota' => 'Reporte de observaciones'] + $gps, $csrf);
$check('registra trabajo remoto', ($r['status'] ?? '') === 'success' && str_contains($r['message'] ?? '', 'En remoto'), $r);
[, $st] = $http($jarSup, "$base/api/asistencia.php?action=status");
$check('su estado queda "En remoto"', ($st['estado_turno'] ?? '') === 'En remoto', $st['estado_turno'] ?? $st);

$http($jarAdm, "$base/index.php"); $entrar($jarAdm, 'remoto.adm');
[, $tl] = $http($jarAdm, "$base/api/asistencia.php?action=timeline_diaria&fecha=" . date('Y-m-d'));
$fila = null;
foreach ($tl['supervisores'] ?? [] as $s) if ((int)$s['supervisor_id'] === $supId) $fila = $s;
$bloque = $fila ? array_values(array_filter($fila['timeline_blocks'], fn($b) => $b['type'] === 'remote')) : [];
$check('la línea de tiempo muestra el bloque "Trabajo remoto"', count($bloque) === 1, $fila['timeline_blocks'] ?? null);
$pa = $fila['primera_accion'] ?? null;
$check('primera acción = Trabajo remoto, unos 25 min después de iniciar', $pa && $pa['tipo'] === 'Trabajo remoto' && $pa['minutos'] >= 24 && $pa['minutos'] <= 26, $pa);

[, $mon] = $http($jarAdm, "$base/api/asistencia.php?action=admin_summary&fecha=" . date('Y-m-d'));
$item = null;
foreach ($mon['reporte'] ?? [] as $x) if ((int)$x['supervisor']['id'] === $supId) $item = $x;
$check('el monitor lo cuenta "En remoto" y muestra su primera acción', ($mon['kpis']['en_remoto'] ?? 0) >= 1 && ($item['primera_accion']['tipo'] ?? '') === 'Trabajo remoto', [$mon['kpis'] ?? null, $item['primera_accion'] ?? null]);

[, $r] = $http($jarSup, "$base/api/asistencia.php?action=end_event", [], $csrf);
[, $st] = $http($jarSup, "$base/api/asistencia.php?action=status");
$check('al reanudar vuelve a "En turno"', ($st['estado_turno'] ?? '') === 'En turno', $st['estado_turno'] ?? $st);
$check('el resumen del día suma los minutos de remoto', isset($st['minutos_remoto']), $st);

$cleanup();
echo "\n$ok correctas, $fail con error. Usuarios y turno de prueba eliminados.\n";
exit($fail ? 1 : 0);
