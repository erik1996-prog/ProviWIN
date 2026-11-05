<?php
// 05_TEST/integration/turno_permiso_test.php - (2026-11-05) "Registrar turno" en Permisos decide todo el turno:
//   - con el permiso: el supervisor directo ve "Mi turno" y sin turno iniciado no puede empezar una supervisión;
//   - sin el permiso: no ve "Mi turno", la pantalla lo deriva a Supervisiones, la API no le deja registrar turno y
//     puede empezar supervisiones sin turno (no se le pide).
// Crea el perfil "Prueba sin turno" y el usuario DNI 99999932; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/turno_permiso_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'TurnoPrueba' . random_int(1000, 9999);
$u = 'turno.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999932'")->fetchColumn();
    if ($id) {
        $pdo->exec("DELETE FROM supervisiones WHERE supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
    $pdo->exec("DELETE FROM perfiles WHERE nombre = 'Prueba sin turno'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    // Perfil de tipo Supervisor con lo del supervisor, pero SIN "Registrar turno".
    $pdo->exec("INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance, fecha_registro) VALUES ('Prueba sin turno', 'PSTURNO', 'Prueba', 'Supervisor', 'zonas', NOW())");
    $perfilSin = (int)$pdo->lastInsertId();
    $perfilCon = (int)$pdo->query("SELECT perfil_id FROM perfil_permisos WHERE permiso = 'attendance.self' ORDER BY perfil_id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO perfil_permisos (perfil_id, permiso) SELECT $perfilSin, permiso FROM perfil_permisos WHERE perfil_id = $perfilCon AND permiso <> 'attendance.self'");
    (new UsuarioService($pdo))->guardar(['dni' => '99999932', 'nombres' => 'Turno', 'apellidos' => 'Sup Prueba', 'email' => 'turno.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'perfil_id' => $perfilCon, 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $uid = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999932'")->fetchColumn();

    $csrf = '';
    $http = function (string $url, ?array $post = null) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf], CURLOPT_POSTFIELDS => http_build_query($post)];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch);
        unset($ch);
        return [$b, json_decode($b, true) ?? []];
    };
    $entrar = function () use ($base, $jar, $u, $clave, $http, &$csrf): string {
        @unlink($jar);
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        [$pagina] = $http("$base/index.php?page=supervision_lista");
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
        $csrf = $m[1] ?? '';
        return $pagina;
    };

    echo "Con \"Registrar turno\" — $base\n";
    $pagina = $entrar();
    $check('ve "Mi turno" en el menú', str_contains($pagina, 'Mi turno'), null);
    $check('la pantalla de supervisiones pide turno', str_contains($pagina, '"registraTurno":true'), null);
    [, $j] = $http("$base/api/supervisiones.php?action=iniciar_nueva", []);
    $check('sin turno iniciado no puede empezar una supervisión', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'turno'), $j);

    echo "Sin \"Registrar turno\"\n";
    $pdo->exec("UPDATE usuarios SET perfil_id = $perfilSin WHERE id = $uid");
    $pagina = $entrar();
    $check('no ve "Mi turno" en el menú', !str_contains($pagina, 'Mi turno'), null);
    $check('la pantalla de supervisiones no pide turno', str_contains($pagina, '"registraTurno":false'), null);
    [$b] = $http("$base/index.php?page=asistencia");
    $check('si abre Mi turno por la dirección, lo lleva a Supervisiones', !str_contains($b, '<strong>Iniciar turno</strong>') && str_contains($b, 'SGS_SUPERVISION'), null);
    [, $j] = $http("$base/api/asistencia.php?action=start", ['latitud' => '-5.19', 'longitud' => '-80.63']);
    $check('la API no le deja registrar turno', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'Registrar turno'), $j);
    [, $j] = $http("$base/api/supervisiones.php?action=iniciar_nueva", []);
    $check('empieza una supervisión sin turno (no se le pide)', ($j['status'] ?? '') === 'success' || !str_contains($j['message'] ?? '', 'turno'), $j);
} catch (Throwable $e) {
    $fail++;
    echo '  ✗ Error: ' . $e->getMessage() . "\n";
} finally {
    $cleanup();
}
echo "\n{$ok} correctas, {$fail} con error. Perfil, usuario y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
