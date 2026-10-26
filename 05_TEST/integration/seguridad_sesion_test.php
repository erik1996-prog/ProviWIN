<?php
// 05_TEST/integration/seguridad_sesion_test.php - (2026-10-26) Cuenta protegida y cierre por inactividad:
//   - otro administrador no puede ver en detalle, editar, desactivar, cambiar la contraseña ni desbloquear la cuenta
//     protegida (Auth::USUARIOS_PROTEGIDOS); la lista la marca como protegida; sus datos no cambian;
//   - 3 intentos fallidos bloquean la cuenta protegida 30 minutos (no de forma indefinida);
//   - una sesión con más de 4 horas sin uso se cierra (401) y el ingreso avisa por qué.
// Por HTTP contra la API real. Crea el administrador de prueba DNI 99999937; no cambia la cuenta protegida (su bloqueo de
// prueba se borra al terminar). Uso (solo CLI): php 05_TEST/integration/seguridad_sesion_test.php [url_base]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$protegido = $pdo->query("SELECT * FROM usuarios WHERE usuario = '" . Auth::USUARIOS_PROTEGIDOS[0] . "'")->fetch(PDO::FETCH_ASSOC);
if (!$protegido) exit("No existe la cuenta protegida en esta base: no se prueba.\n");
$pid = (int)$protegido['id'];
$claveCuenta = hash('sha256', 'sgs-cuenta|' . $pid);
$frasco = tempnam(sys_get_temp_dir(), 'sgss');
$cleanup = function () use ($pdo, $frasco, $claveCuenta) {
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999937'")->fetchColumn();
    if ($id) {
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id = $id");
        $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => $claveCuenta]);
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['seg.admin', Auth::USUARIOS_PROTEGIDOS[0]] as $u)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($frasco);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $clave = 'SegPrueba#' . random_int(1000, 9999);
    $id = (int)(new UsuarioService($pdo))->guardar(['dni' => '99999937', 'nombres' => 'Seg', 'apellidos' => 'Admin Prueba', 'email' => 'seg.admin@proviwin.test',
        'usuario' => 'seg.admin', 'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, (int)$pid)['id'];
    $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $id");
    $http = function (string $url, ?array $post = null, string $csrf = '') use ($frasco): array {
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco];
        if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
        curl_setopt_array($ch, $o); $b = (string)curl_exec($ch);
        $r = (json_decode($b, true) ?? []) + ['_http' => curl_getinfo($ch, CURLINFO_HTTP_CODE), '_cuerpo' => $b]; unset($ch);
        return $r;
    };
    $http("$base/api/auth.php?action=login", ['usuario' => 'seg.admin', 'password' => $clave]);
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $http("$base/index.php")['_cuerpo'], $m);
    $csrf = $m[1] ?? '';
    $api = "$base/api/usuarios.php";

    echo "Cuenta protegida (" . Auth::USUARIOS_PROTEGIDOS[0] . ") — $base\n";
    $check('otro administrador no la ve en detalle', $http("$api?action=detalle&id=$pid")['_http'] === 403);
    $r = $http("$api?action=guardar", ['id' => $pid, 'rol' => 'Supervisor', 'nombres' => 'Cambiado'], $csrf);
    $check('no puede editarla (rol, datos, permisos)', $r['_http'] === 403 && str_contains($r['message'] ?? '', 'protegida'), $r['message'] ?? $r['_http']);
    $check('no puede desactivarla', $http("$api?action=estado", ['id' => $pid, 'activo' => 0], $csrf)['_http'] === 403);
    $check('no puede cambiarle la contraseña', $http("$api?action=restablecer_password", ['id' => $pid], $csrf)['_http'] === 403);
    $check('no puede desbloquearla', $http("$api?action=desbloquear", ['id' => $pid], $csrf)['_http'] === 403);
    $despues = $pdo->query("SELECT * FROM usuarios WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
    $check('sus datos, rol, estado y contraseña siguen iguales', $despues['rol'] === $protegido['rol'] && $despues['nombres'] === $protegido['nombres']
        && (int)$despues['activo'] === (int)$protegido['activo'] && $despues['password_hash'] === $protegido['password_hash']);
    $lista = $http("$api?action=listar&q=" . urlencode(Auth::USUARIOS_PROTEGIDOS[0]));
    $fila = array_values(array_filter($lista['usuarios'] ?? [], fn($u) => (int)$u['id'] === $pid))[0] ?? null;
    $check('la lista la marca como protegida', $fila && !empty($fila['protegido']), $fila ? array_intersect_key($fila, ['protegido' => 1]) : $lista['_http']);

    echo "Intentos fallidos sobre la cuenta protegida\n";
    $otro = tempnam(sys_get_temp_dir(), 'sgss');
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $otro, CURLOPT_COOKIEFILE => $otro,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => Auth::USUARIOS_PROTEGIDOS[0], 'password' => 'mal-' . $i])]);
        $r = json_decode((string)curl_exec($ch), true) ?? []; unset($ch);
    }
    @unlink($otro);
    $hasta = $pdo->prepare('SELECT blocked_until FROM login_attempts WHERE fingerprint = :f'); $hasta->execute([':f' => $claveCuenta]); $hasta = (string)$hasta->fetchColumn();
    $minutos = $hasta ? (strtotime($hasta) - time()) / 60 : 0;
    $check('3 intentos fallidos: bloqueo de 30 minutos, no indefinido', str_contains($r['message'] ?? '', '30 minutos') && $minutos > 25 && $minutos <= 30.5, [$r['message'] ?? null, $hasta]);

    echo "Cierre por inactividad (4 horas)\n";
    $check('con uso reciente la sesión sigue abierta', $http("$api?action=listar")['_http'] === 200);
    preg_match('/SGS_SESSID\s+(\S+)/', (string)file_get_contents($frasco), $s);
    $archivo = STORAGE_PATH . '/sesiones/sess_' . ($s[1] ?? '');
    $datos = (string)@file_get_contents($archivo);
    $viejo = preg_replace('/sgs_ultimo_uso\|i:\d+;/', 'sgs_ultimo_uso|i:' . (time() - SGS_SESION_INACTIVA - 60) . ';', $datos, 1, $n);
    if ($n) file_put_contents($archivo, $viejo);
    $r = $http("$api?action=listar");
    $check('pasadas 4 horas sin uso, la sesión se cierra (401)', $n === 1 && $r['_http'] === 401, [$n, $r['_http']]);
    $check('el ingreso avisa que se cerró por inactividad', str_contains($http("$base/index.php")['_cuerpo'], '4 horas sin actividad'));
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Administrador de prueba y bloqueo de prueba eliminados.\n";
exit($fail ? 1 : 0);
