<?php
// 05_TEST/integration/registrar_apoyo_test.php - Registrar un técnico de apoyo (T2) desde la supervisión:
// queda como T2 (tipo 2) y con el partner elegido en la supervisión. Por HTTP contra la API real.
// Crea un administrador temporal (DNI 99999915) y un T2 de prueba (DNI 99999931); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/registrar_apoyo_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ApoyoPrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar) {
    $pdo->exec("DELETE FROM tecnicos WHERE dni='99999931'");
    $pdo->exec("DELETE FROM usuarios WHERE dni='99999915'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|apoyo.prueba")]);
    @unlink($jar);
};
$pdo->exec("DELETE FROM tecnicos WHERE dni='99999931'");
$pdo->exec("DELETE FROM usuarios WHERE dni='99999915'");
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
(new UsuarioService($pdo))->guardar(['dni' => '99999915', 'nombres' => 'Apoyo', 'apellidos' => 'Prueba SGS', 'email' => 'apoyo.prueba@proviwin.test', 'usuario' => 'apoyo.prueba',
    'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
$partner = $pdo->query("SELECT id, nombre_partner FROM partners WHERE estado = 1 AND nombre_partner <> 'WIN DIRECTO' ORDER BY id LIMIT 1")->fetch();

$http = function (string $url, array $opts = []) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return [$body, json_decode($body, true)];
};
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Registrar técnico de apoyo (T2) con el partner de la supervisión — $base\n";

$http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['usuario' => 'apoyo.prueba', 'password' => $clave])]);
[$pagina] = $http("$base/index.php?page=supervision_lista");
preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
$crear = fn(array $campos) => $http("$base/api/tecnicos.php?action=crear_apoyo", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')], CURLOPT_POSTFIELDS => $campos])[1];

$r = $crear(['partner_id' => '', 'nombre_tecnico' => 'Apoyo Sin Partner', 'dni' => '99999931']);
$check('sin partner elegido no se registra', ($r['status'] ?? '') === 'error', $r);
$r = $crear(['partner_id' => $partner['id'], 'nombre_tecnico' => 'Apoyo Prueba Automatica', 'dni' => '99999931']);
$check("se registra en el partner elegido ({$partner['nombre_partner']})", ($r['status'] ?? '') === 'success' && (int)($r['data']['partner_id'] ?? 0) === (int)$partner['id'], $r);
$t = $pdo->query("SELECT nombre_tecnico, tipo_tecnico, partner_id, estado_operativo FROM tecnicos WHERE dni='99999931'")->fetch();
$check('en la base queda como T2 (tipo 2) de ese partner y habilitado', $t && (int)$t['tipo_tecnico'] === 2 && (int)$t['partner_id'] === (int)$partner['id'] && $t['estado_operativo'] === 'Habilitado', $t);
$check('el nombre se guarda en mayúsculas', ($t['nombre_tecnico'] ?? '') === 'APOYO PRUEBA AUTOMATICA', $t['nombre_tecnico'] ?? null);
$r = $crear(['partner_id' => $partner['id'], 'nombre_tecnico' => 'Otro Nombre Apoyo', 'dni' => '99999931']);
$check('el mismo DNI no se registra dos veces', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'ya está registrado'), $r);

$cleanup();
echo "\n$ok correctas, $fail con error. Usuario y técnico de prueba eliminados.\n";
exit($fail ? 1 : 0);
