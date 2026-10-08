<?php
// 05_TEST/integration/login_bloqueo_test.php - Ingreso: 3 intentos fallidos bloquean la cuenta; el administrador la libera.
// Prueba por HTTP contra la API real. Crea un usuario temporal (DNI 99999909) y BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/login_bloqueo_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'BloqueoPrueba' . random_int(1000, 9999);
$cleanup = function () use ($pdo) {
    $id = $pdo->query("SELECT id FROM usuarios WHERE dni='99999909'")->fetchColumn();
    if ($id) Auth::unlockAccount((int)$id);
    $pdo->exec("DELETE FROM usuarios WHERE dni='99999909'");
    // Contador por IP + usuario de la API de ingreso
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|bloqueo.prueba")]);
};
$cleanup();
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = new UsuarioService($pdo);
$r = $svc->guardar(['dni' => '99999909', 'nombres' => 'Bloqueo', 'apellidos' => 'Prueba SGS', 'email' => 'bloqueo.prueba@proviwin.test', 'usuario' => 'bloqueo.prueba',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
$uid = (int)$r['id'];

$login = function (string $pass) use ($base): array {
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'bloqueo.prueba', 'password' => $pass])]);
    $body = (string)curl_exec($ch); curl_close($ch);
    return json_decode($body, true) ?? ['raw' => mb_substr($body, 0, 150)];
};

$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Ingreso con bloqueo a los 3 intentos — $base\n";
$a = $login('incorrecta-1'); $check('1.er intento fallido avisa que quedan 2', str_contains($a['message'] ?? '', 'quedan 2'), $a);
$a = $login('incorrecta-2'); $check('2.º intento fallido avisa que queda 1', str_contains($a['message'] ?? '', 'queda 1'), $a);
$a = $login('incorrecta-3'); $check('3.er intento fallido bloquea y pide contactar al administrador', !empty($a['locked']) && str_contains($a['message'] ?? '', 'administrador'), $a);
$a = $login($clave);        $check('con la contraseña correcta sigue bloqueado', !empty($a['locked']), $a);
$lista = $svc->listar(['q' => 'bloqueo.prueba', 'rol' => '', 'estado' => '', 'organizacion' => ''])['usuarios'];
$check('Usuarios y roles lo muestra como bloqueado', !empty($lista[0]['bloqueado']), $lista[0] ?? null);
Auth::unlockAccount($uid);
$a = $login($clave);        $check('tras desbloquear, ingresa con su contraseña', ($a['status'] ?? '') === 'success', $a);
$login('incorrecta-4'); $login('incorrecta-5'); $login('incorrecta-6');
$svc->restablecerPassword($uid);
$check('restablecer la contraseña también desbloquea', !Auth::accountLocked($uid));

$cleanup();
echo "\n$ok correctas, $fail con error. Usuario de prueba eliminado.\n";
exit($fail ? 1 : 0);
