<?php
// 05_TEST/integration/partners_test.php - (2026-10-15) Registro de partners: crear, validar, editar, desactivar,
// eliminar solo sin registros, WIN DIRECTO protegido y acceso solo del administrador. Por HTTP contra la API real.
// Crea usuarios (DNI 99999922-23) y partners "PRUEBA PARTNER SGS…" y los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/partners_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'PartnerPrueba' . random_int(1000, 9999);
$jars = ['pt.admin' => tempnam(sys_get_temp_dir(), 'sgsc'), 'pt.sup' => tempnam(sys_get_temp_dir(), 'sgsc')];
$cleanup = function () use ($pdo, $jars) {
    $pdo->exec("DELETE FROM tecnicos WHERE dni = '99999939'");
    $pdo->exec("DELETE FROM partners WHERE nombre_partner LIKE 'PRUEBA PARTNER SGS%'");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999922','99999923')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (array_keys($jars) as $u) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999922', 'nombres' => 'Partner', 'apellidos' => 'Admin Prueba', 'email' => 'pt.admin@proviwin.test', 'usuario' => 'pt.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $svc->guardar(['dni' => '99999923', 'nombres' => 'Partner', 'apellidos' => 'Sup Prueba', 'email' => 'pt.sup@proviwin.test', 'usuario' => 'pt.sup',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $csrf = [];
    $http = function (string $u, string $url, ?array $cuerpo = null) use ($jars, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_TIMEOUT => 60];
        if ($cuerpo !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf[$u] ?? '')], CURLOPT_POSTFIELDS => json_encode($cuerpo)];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return (json_decode($b, true) ?? ['raw' => mb_substr($b, 0, 200)]) + ['_http' => $code];
    };
    foreach (array_keys($jars) as $u) {
        $http($u, "$base/api/auth.php?action=login", null);
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); curl_close($ch);
        $ch = curl_init("$base/index.php?page=supervision_lista");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u]]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
        $csrf[$u] = $m[1] ?? '';
    }
    $api = fn(string $accion, ?array $cuerpo = null, string $u = 'pt.admin') => $http($u, "$base/api/partners.php?action=$accion", $cuerpo);
    $buscar = function (string $nombre) use ($api): ?array { foreach ($api('list')['data'] ?? [] as $p) if ($p['nombre_partner'] === $nombre) return $p; return null; };

    echo "Partners — $base\n";
    $r = $api('guardar', ['nombre_partner' => '  prueba   partner sgs ', 'ruc' => '20999999991']);
    $p = $buscar('PRUEBA PARTNER SGS');
    $check('crea el partner en mayúsculas y sin espacios de más', ($r['status'] ?? '') === 'success' && $p && $p['ruc'] === '20999999991' && (int)$p['estado'] === 1, [$r, $p]);
    $r = $api('guardar', ['nombre_partner' => 'Prueba Partner SGS']);
    $check('rechaza un nombre repetido', ($r['status'] ?? '') === 'error' && ($r['field'] ?? '') === 'nombre_partner', $r);
    $r = $api('guardar', ['nombre_partner' => 'PRUEBA PARTNER SGS 2', 'ruc' => '12345']);
    $check('rechaza un RUC inválido', ($r['status'] ?? '') === 'error' && ($r['field'] ?? '') === 'ruc', $r);
    $r = $api('guardar', ['nombre_partner' => 'PRUEBA PARTNER SGS 2', 'ruc' => '20999999991']);
    $check('rechaza un RUC ya registrado en otro partner', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'PRUEBA PARTNER SGS'), $r);
    $r = $api('guardar', ['id' => $p['id'], 'nombre_partner' => 'PRUEBA PARTNER SGS EDITADO', 'ruc' => '']);
    $p2 = $buscar('PRUEBA PARTNER SGS EDITADO');
    $check('edita el nombre y quita el RUC', ($r['status'] ?? '') === 'success' && $p2 && $p2['ruc'] === null, [$r, $p2]);
    $r = $api('estado', ['id' => $p['id'], 'activo' => false]);
    $check('se desactiva', ($r['status'] ?? '') === 'success' && (int)$buscar('PRUEBA PARTNER SGS EDITADO')['estado'] === 0, $r);
    $check('un partner sin registros se puede eliminar', !empty($buscar('PRUEBA PARTNER SGS EDITADO')['puede_eliminar']));
    // Con un técnico ya no se puede eliminar
    $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, fecha_registro) VALUES ('P 1 PRUEBA PARTNER SGS TECNICO', '99999939', 1, 'Habilitado', :p, NOW())")->execute([':p' => $p['id']]);
    $r = $api('eliminar', ['id' => $p['id']]);
    $check('con técnicos registrados no se puede eliminar', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'desactivarlo'), $r);
    $pdo->exec("DELETE FROM tecnicos WHERE dni = '99999939'");
    $r = $api('eliminar', ['id' => $p['id']]);
    $check('sin registros se elimina', ($r['status'] ?? '') === 'success' && $buscar('PRUEBA PARTNER SGS EDITADO') === null, $r);

    $win = (int)$pdo->query("SELECT id FROM partners WHERE UPPER(nombre_partner) = 'WIN DIRECTO' LIMIT 1")->fetchColumn();
    if ($win) {
        $r = $api('estado', ['id' => $win, 'activo' => false]);
        $check('WIN DIRECTO no se puede desactivar', ($r['status'] ?? '') === 'error' && (int)$pdo->query("SELECT estado FROM partners WHERE id = $win")->fetchColumn() === 1, $r);
        $r = $api('guardar', ['id' => $win, 'nombre_partner' => 'OTRO NOMBRE']);
        $check('WIN DIRECTO no se puede renombrar', ($r['status'] ?? '') === 'error', $r);
    }
    $r = $api('guardar', ['nombre_partner' => 'WIN DIRECTO']);
    $check('no se puede crear otro "WIN DIRECTO"', ($r['status'] ?? '') === 'error', $r);
    $r = $api('list', null, 'pt.sup');
    $check('un supervisor no puede usar la API de partners (403)', ($r['_http'] ?? 0) === 403, $r);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y partners de prueba eliminados.\n";
exit($fail ? 1 : 0);
