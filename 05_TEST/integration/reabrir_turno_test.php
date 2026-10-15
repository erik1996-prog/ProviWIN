<?php
// 05_TEST/integration/reabrir_turno_test.php - Turno finalizado por error: reabrir (supervisor dentro de 15 minutos,
// administrador el mismo día). Reproduce el caso real: capacitación y "Finalizar turno" por un toque accidental.
// Crea un supervisor directo temporal (DNI 99999918) y un administrador temporal (DNI 99999919); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/reabrir_turno_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ReabrirPrueba' . random_int(1000, 9999);
$jarSup = tempnam(sys_get_temp_dir(), 'sgsc');
$jarAdm = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jarSup, $jarAdm) {
    foreach (['99999918', '99999919'] as $dni) {
        $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni='$dni'")->fetchColumn();
        if (!$id) continue;
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['reabrir.sup', 'reabrir.adm'] as $u)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jarSup); @unlink($jarAdm);
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = new UsuarioService($pdo);
$supId = (int)$svc->guardar(['dni' => '99999918', 'nombres' => 'Reabrir', 'apellidos' => 'Supervisor Prueba', 'email' => 'reabrir.sup@proviwin.test', 'usuario' => 'reabrir.sup',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin)['id'];
$svc->guardar(['dni' => '99999919', 'nombres' => 'Reabrir', 'apellidos' => 'Admin Prueba', 'email' => 'reabrir.adm@proviwin.test', 'usuario' => 'reabrir.adm',
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
echo "Reabrir un turno finalizado por error — $base\n";

$turno = fn() => $pdo->query("SELECT fin_turno, estado_turno FROM asistencia_supervisores WHERE supervisor_id = $supId ORDER BY id DESC LIMIT 1")->fetch();
$csrf = $entrar($jarSup, 'reabrir.sup');
$gps = ['latitud' => '-5.1945', 'longitud' => '-80.6328'];
$http($jarSup, "$base/api/asistencia.php?action=start", $gps, $csrf);
[, $r] = $http($jarSup, "$base/api/asistencia.php?action=start_event", $gps + ['tipo_evento' => 'capacitacion'], $csrf);
$check('inicia capacitación', ($r['status'] ?? '') === 'success', $r);
[, $r] = $http($jarSup, "$base/api/asistencia.php?action=end", $gps, $csrf);
$check('finaliza el turno (el error del caso real)', ($r['status'] ?? '') === 'success' && !empty($turno()['fin_turno']), $r);
[, $st] = $http($jarSup, "$base/api/asistencia.php?action=status");
$check('el estado avisa que puede reabrirlo', !empty($st['puede_reabrir']), $st['puede_reabrir'] ?? null);
[, $r] = $http($jarSup, "$base/api/asistencia.php?action=reabrir", [], $csrf);
$t = $turno();
$ev = $pdo->query("SELECT COUNT(*) FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $supId AND e.tipo_evento = 'capacitacion' AND e.fin_evento IS NULL")->fetchColumn();
$check('el supervisor lo reabre y sigue en capacitación (la pausa cortada continúa)', ($r['status'] ?? '') === 'success' && $t['fin_turno'] === null && $t['estado_turno'] === 'En capacitación' && (int)$ev === 1, [$r, $t, $ev]);

// Finaliza otra vez y "pasan" 20 minutos
$http($jarSup, "$base/api/asistencia.php?action=end", $gps, $csrf);
$pdo->prepare('UPDATE asistencia_supervisores SET fin_turno = NOW() - INTERVAL 20 MINUTE WHERE supervisor_id = :s')->execute([':s' => $supId]);
[, $r] = $http($jarSup, "$base/api/asistencia.php?action=reabrir", [], $csrf);
$check('pasados 15 minutos el supervisor ya no puede reabrir', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'administrador'), $r);
$csrfAdm = $entrar($jarAdm, 'reabrir.adm');
[, $r] = $http($jarAdm, "$base/api/asistencia.php?action=reabrir", ['supervisor_id' => $supId], $csrfAdm);
$check('el administrador sí lo reabre', ($r['status'] ?? '') === 'success' && $turno()['fin_turno'] === null, $r);
$obs = (string)$pdo->query("SELECT observaciones FROM asistencia_supervisores WHERE supervisor_id = $supId ORDER BY id DESC LIMIT 1")->fetchColumn();
$check('queda anotado cada vez que se reabrió', substr_count($obs, 'Turno reabierto') === 2, $obs);

$cleanup();
echo "\n$ok correctas, $fail con error. Usuarios y turno de prueba eliminados.\n";
exit($fail ? 1 : 0);
