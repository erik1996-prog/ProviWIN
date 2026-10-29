<?php
// 05_TEST/integration/cuadrillas_zona_test.php - (2026-10-15) Cuadrillas según la zona del usuario: el supervisor ve solo
// los técnicos líderes de sus departamentos; el administrador ve todos los que tienen zona. Los que aún no tienen zona solo
// los ve el administrador general (2026-10-28; prueba aparte: tecnicos_sin_zona_test). Por HTTP.
// Crea usuarios (DNI 99999924-25) y técnicos (DNI 99999941-43, "PRUEBAZONA") y los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/cuadrillas_zona_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ZonaPrueba' . random_int(1000, 9999);
$jars = ['zona.admin' => tempnam(sys_get_temp_dir(), 'sgsc'), 'zona.sup' => tempnam(sys_get_temp_dir(), 'sgsc')];
$cleanup = function () use ($pdo, $jars) {
    $pdo->exec("DELETE FROM tecnicos WHERE dni IN ('99999941','99999942','99999943')");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999924','99999925')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (array_keys($jars) as $u) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999924', 'nombres' => 'Zona', 'apellidos' => 'Admin Prueba', 'email' => 'zona.admin@proviwin.test', 'usuario' => 'zona.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $svc->guardar(['dni' => '99999925', 'nombres' => 'Zona', 'apellidos' => 'Sup Prueba', 'email' => 'zona.sup@proviwin.test', 'usuario' => 'zona.sup',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, fecha_registro)
        VALUES (:n, :d, 1, 'Habilitado', :p, :prov, :dep, NOW())");
    $ins->execute([':n' => 'P 1 PRUEBAZONA SGI DE PIURA', ':d' => '99999941', ':p' => $partner, ':prov' => 'SULLANA', ':dep' => 'PIURA']);
    $ins->execute([':n' => 'P 2 PRUEBAZONA SGI DE TRUJILLO', ':d' => '99999942', ':p' => $partner, ':prov' => 'TRUJILLO', ':dep' => 'LA LIBERTAD']);
    $ins->execute([':n' => 'P 3 PRUEBAZONA SGI SIN ZONA', ':d' => '99999943', ':p' => $partner, ':prov' => null, ':dep' => null]);

    $http = function (string $u, string $url) use ($jars): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_TIMEOUT => 60]);
        $b = (string)curl_exec($ch); curl_close($ch);
        return [$b, json_decode($b, true)];
    };
    foreach (array_keys($jars) as $u) {
        $http($u, "$base/api/auth.php?action=login"); // abre la sesión antes de autenticarse (igual que partners_test)
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); unset($ch); // en PHP 8 las cookies se guardan al liberar el manejador
    }
    $nombres = fn(?array $j) => array_column($j['data'] ?? [], 'nombre_tecnico');

    echo "Cuadrillas según la zona del usuario — $base\n";
    [$pagina] = $http('zona.sup', "$base/index.php?page=cuadrillas&q=PRUEBAZONA");
    $check('supervisor de PIURA: ve al técnico de PIURA', str_contains($pagina, 'P 1 PRUEBAZONA SGI DE PIURA'));
    $check('supervisor de PIURA: no ve al de LA LIBERTAD', !str_contains($pagina, 'P 2 PRUEBAZONA SGI DE TRUJILLO'));
    $check('supervisor de PIURA: no ve al que aún no tiene zona (solo el administrador general)', !str_contains($pagina, 'P 3 PRUEBAZONA SGI SIN ZONA'));
    $check('supervisor: la pantalla indica su zona', str_contains($pagina, 'Se muestran las cuadrillas de su zona') && str_contains($pagina, 'PIURA'));
    [, $j] = $http('zona.sup', "$base/api/tecnicos.php?action=cuadrillas&q=PRUEBAZONA");
    $n = $nombres($j);
    $check('API para el supervisor: misma regla', in_array('P 1 PRUEBAZONA SGI DE PIURA', $n, true) && !in_array('P 2 PRUEBAZONA SGI DE TRUJILLO', $n, true) && !in_array('P 3 PRUEBAZONA SGI SIN ZONA', $n, true), $n);
    [$pagina] = $http('zona.admin', "$base/index.php?page=cuadrillas&q=PRUEBAZONA");
    $check('administrador: ve las cuadrillas de todas las zonas', str_contains($pagina, 'P 1 PRUEBAZONA SGI DE PIURA') && str_contains($pagina, 'P 2 PRUEBAZONA SGI DE TRUJILLO'));
    [, $j] = $http('zona.admin', "$base/api/tecnicos.php?action=cuadrillas&q=PRUEBAZONA");
    $check('API para el administrador: todas las zonas (sin los que no tienen zona: no es el administrador general)', count(array_intersect($nombres($j), ['P 1 PRUEBAZONA SGI DE PIURA', 'P 2 PRUEBAZONA SGI DE TRUJILLO'])) === 2 && !in_array('P 3 PRUEBAZONA SGI SIN ZONA', $nombres($j), true), $nombres($j));
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y técnicos de prueba eliminados.\n";
exit($fail ? 1 : 0);
