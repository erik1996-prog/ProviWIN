<?php
// 05_TEST/integration/cierre_turno_supervision_abierta_test.php - (2026-10-26) No se cierra el turno con una supervisión abierta:
//   - con una supervisión En proceso, "Finalizar turno" responde 409 con el código de la supervisión y el turno sigue abierto;
//   - una supervisión en rescate (Pendiente) no bloquea;
//   - cerrada (cancelada) la supervisión, el turno se cierra.
// Por HTTP contra la API real. Crea el usuario DNI 99999931 (con turno de hoy) y su supervisión; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/cierre_turno_supervision_abierta_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'CierrePrueba' . random_int(1000, 9999);
$u = 'cierre.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999931'")->fetchColumn();
    if ($id) {
        $pdo->exec("DELETE FROM supervisiones WHERE supervisor_id = $id");
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999931', 'nombres' => 'Cierre', 'apellidos' => 'Sup Prueba', 'email' => 'cierre.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $supId = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999931'")->fetchColumn();
    $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $supId");
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno, fecha_registro) VALUES (:u, CURDATE(), NOW() - INTERVAL 2 HOUR, 'En turno', NOW())")->execute([':u' => $supId]);

    $csrf = '';
    $http = function (string $url, $post = null, bool $json = false) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json ? json_encode($post) : $post,
            CURLOPT_HTTPHEADER => array_merge(['X-CSRF-Token: ' . $csrf], $json ? ['Content-Type: application/json'] : [])];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return (json_decode($b, true) ?? []) + ['_http' => $code];
    };
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
    curl_exec($ch); unset($ch);
    $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); unset($ch);
    $csrf = $m[1] ?? '';
    $turnoAbierto = fn() => (int)$pdo->query("SELECT COUNT(*) FROM asistencia_supervisores WHERE supervisor_id = $supId AND fecha = CURDATE() AND fin_turno IS NULL")->fetchColumn();
    $cerrar = fn() => $http("$base/api/asistencia.php?action=end", ['latitud' => -5.19, 'longitud' => -80.63], true);

    echo "Cierre de turno con supervisión abierta — $base\n";
    $nueva = $http("$base/api/supervisiones.php?action=iniciar_nueva", []);
    $sup = (int)($nueva['id'] ?? 0);
    $codigo = (string)$pdo->query("SELECT codigo_supervision FROM supervisiones WHERE id = $sup")->fetchColumn();
    $r = $cerrar();
    $check('con una supervisión En proceso no se cierra el turno (409, dice cuál)', $sup && $r['_http'] === 409 && ($r['supervision_abierta'] ?? 0) === $sup
        && str_contains($r['message'] ?? '', $codigo) && $turnoAbierto() === 1, $r);

    $pdo->exec("UPDATE supervisiones SET estado_supervision = 'Pendiente', rescate_estado = 'En rescate' WHERE id = $sup");
    $r = $cerrar();
    $check('una supervisión en rescate (Pendiente) no bloquea el cierre', ($r['status'] ?? '') === 'success' && $turnoAbierto() === 0, $r);

    // Otra vez en turno, con una supervisión que se cancela antes de cerrar
    $pdo->prepare("UPDATE asistencia_supervisores SET fin_turno = NULL, estado_turno = 'En turno' WHERE supervisor_id = :u AND fecha = CURDATE()")->execute([':u' => $supId]);
    $pdo->exec("DELETE FROM supervisiones WHERE id = $sup");
    $sup = (int)($http("$base/api/supervisiones.php?action=iniciar_nueva", [])['id'] ?? 0);
    $check('vuelve a bloquear con otra supervisión abierta', $sup && $cerrar()['_http'] === 409 && $turnoAbierto() === 1);
    $c = $http("$base/api/supervisiones.php?action=cancelar", ['id' => $sup, 'supervision_id' => $sup]);
    $r = $cerrar();
    $check('cancelada la supervisión, el turno se cierra', ($c['status'] ?? '') === 'success' && ($r['status'] ?? '') === 'success' && $turnoAbierto() === 0, [$c, $r]);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuario, turno y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
