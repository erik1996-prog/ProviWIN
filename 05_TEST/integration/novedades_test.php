<?php
// 05_TEST/integration/novedades_test.php - (2026-11-08) Aviso "El sistema se actualizó" y permisos separados de los indicadores.
//   - cada usuario ve solo las novedades que puede usar, una sola vez, nunca las anteriores a su creación ni las viejas;
//   - quien solo genera links de examen lo hace sin ver los resultados; sin "Ver Exámenes" no entra al tablero.
// Crea el perfil "Prueba novedades" y el usuario DNI 99999933; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/novedades_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/config/novedades.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'NovedadPrueba' . random_int(1000, 9999);
$u = 'novedad.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999933'");
    $pdo->exec("DELETE FROM perfiles WHERE nombre = 'Prueba novedades'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

echo "Qué novedades le tocan a cada usuario\n";
$catalogo = [
    ['id' => 1, 'fecha' => '2026-01-01', 'puede' => fn($x) => true],
    ['id' => 2, 'fecha' => '2026-03-10', 'puede' => fn($x) => $x['sirve']],
    ['id' => 3, 'fecha' => '2026-03-20', 'puede' => fn($x) => !$x['sirve']],
    ['id' => 4, 'fecha' => '2026-03-25', 'puede' => fn($x) => true],
];
$ids = fn(array $u, int $vista, string $alta) => array_column(sgsNovedadesPara($catalogo, $u, $vista, $alta, '2026-04-01'), 'id');
$check('solo las que puede usar y recientes', $ids(['sirve' => true], 0, '2025-01-01') === [2, 4], $ids(['sirve' => true], 0, '2025-01-01'));
$check('otro usuario ve las suyas', $ids(['sirve' => false], 0, '2025-01-01') === [3, 4], $ids(['sirve' => false], 0, '2025-01-01'));
$check('no repite las ya vistas', $ids(['sirve' => true], 2, '2025-01-01') === [4]);
$check('no avisa lo anterior a la creación del usuario', $ids(['sirve' => true], 0, '2026-03-22 10:00:00') === [4]);
$ids2 = array_column(sgsNovedades(), 'id');
$check('el catálogo real tiene ids crecientes y sin repetir', $ids2 === array_values(array_unique($ids2)) && $ids2 === (function ($x) { sort($x); return $x; })($ids2), $ids2);

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $perfilCon = (int)$pdo->query("SELECT perfil_id FROM perfil_permisos WHERE permiso = 'cuadrillas.view' AND perfil_id IN (SELECT id FROM perfiles WHERE rol_base = 'Supervisor') ORDER BY perfil_id LIMIT 1")->fetchColumn();
    // Supervisor que genera links y avisa lluvias, sin ver los indicadores de Exámenes ni Clima.
    $pdo->exec("INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance, fecha_registro) VALUES ('Prueba novedades', 'PNOVED', 'Prueba', 'Supervisor', 'zonas', NOW())");
    $perfil = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO perfil_permisos (perfil_id, permiso) SELECT $perfil, permiso FROM perfil_permisos WHERE perfil_id = $perfilCon AND permiso NOT IN ('examenes.view', 'examenes.delete', 'examenes.purge', 'clima.view', 'clima.delete')");
    $pdo->exec("INSERT IGNORE INTO perfil_permisos (perfil_id, permiso) VALUES ($perfil, 'examenes.edit'), ($perfil, 'clima.edit'), ($perfil, 'attendance.self'), ($perfil, 'cuadrillas.view')");
    (new UsuarioService($pdo))->guardar(['dni' => '99999933', 'nombres' => 'Novedad', 'apellidos' => 'Sup Prueba', 'email' => 'novedad.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'perfil_id' => $perfil, 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $uid = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999933'")->fetchColumn();
    $pdo->exec("UPDATE usuarios SET fecha_creacion = '2025-01-01 00:00:00' WHERE id = $uid"); // para que le toquen las novedades recientes

    $csrf = '';
    $http = function (string $url, ?array $post = null) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf], CURLOPT_POSTFIELDS => http_build_query($post)];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    $ingresar = function () use ($base, $jar, $u, $clave, $http, &$csrf): string {
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        [$pagina] = $http("$base/index.php?page=cuadrillas");
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
        $csrf = $m[1] ?? '';
        return $pagina;
    };
    $pagina = $ingresar();
    $api = "$base/api/examenes.php";

    echo "Permisos separados de los indicadores — $base\n";
    $check('sin "Ver Exámenes" ni "Ver Clima" no salen en su menú', !str_contains($pagina, 'page=examenes') && !str_contains($pagina, 'page=dashboard_lluvias'), null);
    $check('tiene el botón de link de examen en Técnicos y cuadrillas', str_contains($pagina, 'id="btnLinkExamen"'), null);
    [, $j] = $http("$api?action=grupos");
    $check('puede ver sus links generados', ($j['status'] ?? '') === 'success', $j);
    [, $j] = $http("$api?action=dashboard");
    $check('no puede abrir los resultados de Exámenes', ($j['_http'] ?? 0) === 403, $j);
    [, $j] = $http("$base/api/dashboard_lluvias.php?action=resumen");
    $check('no puede abrir el indicador Clima', ($j['_http'] ?? 0) === 403, $j);
    [, $j] = $http("$base/api/lluvias.php?action=estado");
    $check('sí puede usar el aviso de lluvias', ($j['status'] ?? '') === 'success', $j);

    echo "Recorrido al iniciar sesión\n";
    $u1 = ['id' => $uid, 'rol' => 'Supervisor', 'is_win_directo' => true, 'partner_nombre' => ''];
    $tocan = sgsNovedadesPara(sgsNovedades(), $u1, 0, '2025-01-01', date('Y-m-d'));
    $check('sale al iniciar sesión solo si hay algo para él', str_contains($pagina, 'id="sgsNovedades"') === (bool)$tocan, array_column($tocan, 'id'));
    [$otra] = $http("$base/index.php?page=cuadrillas");
    $check('no interrumpe al seguir navegando', !str_contains($otra, 'id="sgsNovedades"'), null);
    $pagina2 = $ingresar();
    $check('si no lo terminó, sale otra vez en el siguiente ingreso', str_contains($pagina2, 'id="sgsNovedades"') === (bool)$tocan, null);
    $check('no le avisa de lo que no puede usar', !str_contains($pagina, 'Seguimiento en vivo de los exámenes') && !str_contains($pagina, 'Permisos separados'), null);
    [, $j] = $http("$base/api/novedades.php?action=vistas", []);
    $check('al cerrar queda marcada', ($j['status'] ?? '') === 'success' && (int)$pdo->query("SELECT novedad_vista FROM usuarios WHERE id = $uid")->fetchColumn() === (int)max($ids2), $j);
    $pagina = $ingresar();
    $check('una vez visto, no sale en el siguiente ingreso', !str_contains($pagina, 'id="sgsNovedades"'), null);
} catch (Throwable $e) {
    $fail++;
    echo '  ✗ Error: ' . $e->getMessage() . "\n";
} finally {
    $cleanup();
}
echo "\n{$ok} correctas, {$fail} con error. Perfil y usuario de prueba eliminados.\n";
exit($fail ? 1 : 0);
