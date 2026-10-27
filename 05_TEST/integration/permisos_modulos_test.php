<?php
// 05_TEST/integration/permisos_modulos_test.php - (2026-10-23) Ver / editar / eliminar por módulo (Roles y permisos):
//   - por defecto cada rol ve lo mismo que antes (administrador todo; supervisor directo pendientes, clima, exámenes y cuadrillas);
//   - el supervisor de partner no entra a estos módulos aunque su rol tenga el permiso;
//   - un permiso personalizado quita o da acceso a una persona (pantalla y servicio);
//   - eliminar una lluvia exige su permiso.
// Por HTTP contra la API real. Crea usuarios DNI 99999940-43 y una lluvia de prueba; BORRA todo al final.
// Mientras corre, pausa el aviso a Teams (09_CONFIG/teams_lluvias.local.txt) para no publicar la lluvia de prueba en el grupo.
// Uso (solo CLI): php 05_TEST/integration/permisos_modulos_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$dnis = ['99999940', '99999941', '99999942', '99999943'];
$frascos = [];
$cleanup = function () use ($pdo, $dnis, &$frascos) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        $pdo->exec("DELETE FROM lluvias_zona WHERE reportado_por IN ($in)");
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['perm.admin', 'perm.directo', 'perm.partner', 'perm.otro'] as $u) foreach (['::1', '127.0.0.1'] as $ip)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$cleanup();
$teams = dirname(__DIR__, 2) . '/09_CONFIG/teams_lluvias.local.txt';
if (is_file($teams)) { rename($teams, "$teams.pausado"); register_shutdown_function(fn() => rename("$teams.pausado", $teams)); }
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'PermPrueba#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $nuevo = function (string $dni, string $usuario, string $rol, string $org) use ($svc, $admin, $clave, $partner, $pdo): int {
        $in = ['dni' => $dni, 'nombres' => 'Perm', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario, 'rol' => $rol,
            'organizacion' => $org, 'zonas' => ['PIURA'], 'password' => $clave] + ($org === 'partner' ? ['partner_id' => $partner] : []);
        $id = (int)$svc->guardar($in, 0, $admin)['id'];
        $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $id");
        return $id;
    };
    $ids = ['admin' => $nuevo('99999940', 'perm.admin', 'Administrador', 'directo'), 'directo' => $nuevo('99999941', 'perm.directo', 'Supervisor', 'directo'),
        'partner' => $nuevo('99999942', 'perm.partner', 'Supervisor', 'partner'), 'otro' => $nuevo('99999943', 'perm.otro', 'Supervisor', 'directo')];

    // Sesiones
    $sesion = function (string $usuario) use ($base, $clave, &$frascos): callable {
        $frasco = tempnam(sys_get_temp_dir(), 'sgsp'); $frascos[] = $frasco;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); unset($ch);
        $csrf = $m[1] ?? '';
        return function (string $url, ?array $post = null) use ($frasco, $csrf): array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf]];
            curl_setopt_array($ch, $o); $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
            return [$code, json_decode($b, true) ?? [], $b];
        };
    };
    $s = ['admin' => $sesion('perm.admin'), 'directo' => $sesion('perm.directo'), 'partner' => $sesion('perm.partner')];
    $api = [
        'clima' => "$base/api/dashboard_lluvias.php?action=dashboard",
        'pendientes' => "$base/api/dashboard_pendientes.php?action=dashboard",
        'examenes' => "$base/api/examenes.php?action=dashboard",
        'cuadrillas' => "$base/api/tecnicos.php?action=cuadrillas",
        'dash_partners' => "$base/api/dashboard_partners.php?action=dashboard",
        'partners' => "$base/api/partners.php?action=list",
    ];
    $estado = fn(callable $h, string $k) => $h($api[$k])[0];
    $esperado = [ // por defecto: admin todo; directo sin Dashboard de partners ni Partners; partner nada
        'admin' => ['clima' => 200, 'pendientes' => 200, 'examenes' => 200, 'cuadrillas' => 200, 'dash_partners' => 200, 'partners' => 200],
        'directo' => ['clima' => 200, 'pendientes' => 200, 'examenes' => 200, 'cuadrillas' => 200, 'dash_partners' => 403, 'partners' => 403],
        'partner' => ['clima' => 403, 'pendientes' => 403, 'examenes' => 403, 'cuadrillas' => 403, 'dash_partners' => 403, 'partners' => 403],
    ];
    echo "Permisos por defecto — $base\n";
    foreach ($esperado as $quien => $mods) {
        $obtenido = array_map(fn($k) => $estado($s[$quien], $k), array_combine(array_keys($mods), array_keys($mods)));
        $check("$quien: acceso a cada módulo como antes", $obtenido == $mods, $obtenido);
    }

    echo "Permisos personalizados\n";
    $ins = $pdo->prepare('INSERT INTO usuario_permisos (usuario_id, permiso, permitido, otorgado_por) VALUES (:u, :p, :ok, :by)');
    $ins->execute([':u' => $ids['directo'], ':p' => 'clima.view', ':ok' => 0, ':by' => $admin]);
    $ins->execute([':u' => $ids['directo'], ':p' => 'partners.view', ':ok' => 1, ':by' => $admin]);
    $ins->execute([':u' => $ids['partner'], ':p' => 'clima.view', ':ok' => 1, ':by' => $admin]);
    $s['directo'] = $sesion('perm.directo'); $s['partner'] = $sesion('perm.partner');
    $check('al directo se le quita "Ver Clima": el servicio lo rechaza', $estado($s['directo'], 'clima') === 403);
    [, , $html] = $s['directo']("$base/index.php?page=dashboard_lluvias");
    $check('…y la pantalla Clima no se abre ni sale en el menú', !str_contains($html, 'id="dll"') && !str_contains($html, 'page=dashboard_lluvias'));
    $check('al directo se le da "Ver partners": ahora entra a Partners', $estado($s['directo'], 'partners') === 200);
    [$c, $j] = $s['directo']("$base/api/partners.php?action=guardar", ['id' => 0, 'nombre_partner' => 'PRUEBA PERMISOS', 'ruc' => '']);
    $check('…pero sin "Editar partners" no puede crear uno', $c === 403, $j);
    $check('el supervisor de partner no entra aunque se le dé "Ver Clima"', $estado($s['partner'], 'clima') === 403);

    echo "Eliminar una lluvia\n";
    $pdo->prepare("INSERT INTO lluvias_zona (departamento, inicio, fin, reportado_por) VALUES ('PIURA', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 1 HOUR, :u)")->execute([':u' => $ids['otro']]);
    $lluvia = (int)$pdo->lastInsertId();
    [$c] = $s['directo']("$base/api/dashboard_lluvias.php?action=eliminar", ['id' => $lluvia]);
    $check('el directo (sin "Eliminar registros de lluvia") no puede borrarla', $c === 403 && (int)$pdo->query("SELECT COUNT(*) FROM lluvias_zona WHERE id = $lluvia")->fetchColumn() === 1, $c);
    [$c, $j] = $s['admin']("$base/api/dashboard_lluvias.php?action=eliminar", ['id' => $lluvia]);
    $check('el administrador sí la borra', $c === 200 && ($j['status'] ?? '') === 'success' && (int)$pdo->query("SELECT COUNT(*) FROM lluvias_zona WHERE id = $lluvia")->fetchColumn() === 0, $j);

    echo "Envíos programados del corte (solo la cuenta principal, 2026-10-26)\n";
    [, , $html] = $s['admin']("$base/index.php?page=dashboard_lluvias");
    [$c] = $s['admin']("$base/api/dashboard_lluvias.php?action=programa_lista");
    [$c2] = $s['admin']("$base/api/dashboard_lluvias.php?action=programa_guardar", ['hora' => '09:00']);
    $check('otro administrador no ve "Programar envíos" ni puede listar o guardar horarios', !str_contains($html, 'id="mCortes"') && $c === 403 && $c2 === 403, [$c, $c2]);

    echo "Roles y permisos (catálogo)\n";
    $cat = Auth::permissionCatalog();
    $nuevos = ['dashboard.partners', 'pendientes.view', 'clima.view', 'clima.edit', 'clima.delete', 'examenes.view', 'examenes.edit', 'examenes.delete',
        'cuadrillas.view', 'cuadrillas.edit', 'cuadrillas.import', 'partners.view', 'partners.edit', 'partners.delete', 'observation.delete'];
    $check('los permisos nuevos salen en Roles y permisos y se pueden personalizar', !array_diff($nuevos, array_keys(array_filter($cat, fn($d) => !empty($d['delegable'])))),
        array_values(array_diff($nuevos, array_keys($cat))));
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y lluvia de prueba eliminados.\n";
exit($fail ? 1 : 0);
