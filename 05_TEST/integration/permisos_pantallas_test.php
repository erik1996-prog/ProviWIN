<?php
// 05_TEST/integration/permisos_pantallas_test.php - (2026-11-10) Cada pantalla depende solo de su permiso, no del tipo de usuario:
//   Línea de tiempo (sin el Dashboard), Monitor de turnos, Control y recurrencia, Dashboard de supervisiones y Órdenes cercanas,
//   dados a un supervisor directo con un perfil de prueba; y el aviso de lluvias separado del indicador Clima.
// Crea el perfil "Prueba pantallas" y el usuario DNI 99999934; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/permisos_pantallas_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'PantallaPrueba' . random_int(1000, 9999);
$u = 'pantalla.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999934'");
    $pdo->exec("DELETE FROM perfiles WHERE nombre = 'Prueba pantallas'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

echo "Catálogo de Permisos\n";
$cat = Auth::permissionCatalog();
$check('Registro de lluvias es su propia pantalla (no la del indicador Clima)', $cat[Auth::PERM_CLIMA_EDIT]['group'] === 'Registro de lluvias' && Auth::permisoRequerido(Auth::PERM_CLIMA_EDIT) === Auth::PERM_ATTENDANCE_SELF);
$check('Control y recurrencia, Monitor de turnos y Órdenes cercanas tienen su permiso', $cat[Auth::PERM_OBSERVATION_REVIEW]['group'] === 'Control y recurrencia'
    && $cat[Auth::PERM_TURNOS_MONITOR]['group'] === 'Monitor de turnos' && isset($cat[Auth::PERM_ORDENES_CERCANAS]));
$sinTipo = array_diff(array_unique(array_column($cat, 'group')), array_keys(UsuarioService::TIPO_PANTALLA));
$check('cada pantalla dice si es Registro, Indicador o Gestión', !$sinTipo, array_values($sinTipo));

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO perfiles (nombre, codigo, descripcion, rol_base, alcance, fecha_registro) VALUES ('Prueba pantallas', 'PPANTA', 'Prueba', 'Supervisor', 'zonas', NOW())");
    $perfil = (int)$pdo->lastInsertId();
    $dar = function (array $permisos) use ($pdo, $perfil) {
        $pdo->exec("DELETE FROM perfil_permisos WHERE perfil_id = $perfil");
        $ins = $pdo->prepare('INSERT INTO perfil_permisos (perfil_id, permiso) VALUES (:p, :x)');
        foreach (array_merge(['supervision.view'], $permisos) as $x) $ins->execute([':p' => $perfil, ':x' => $x]);
    };
    $dar([]);
    (new UsuarioService($pdo))->guardar(['dni' => '99999934', 'nombres' => 'Pantalla', 'apellidos' => 'Sup Prueba', 'email' => 'pantalla.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'perfil_id' => $perfil, 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);

    $http = function (string $url) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $b = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
    curl_exec($ch); unset($ch);

    echo "Sin esos permisos — $base\n";
    [$menu] = $http("$base/index.php?page=supervision_lista");
    $check('no ve Línea de tiempo, Monitor, Control, Dashboard ni Órdenes cercanas', !preg_match('/page=(timeline_supervisores|admin_dashboard|control_observaciones)"/', $menu)
        && !str_contains($menu, 'Monitor de turnos') && !str_contains($menu, 'modalOrdenesCercanas'), null);
    [, $j] = $http("$base/api/asistencia.php?action=admin_summary");
    $check('la API del Monitor de turnos no le responde', ($j['_http'] ?? 0) === 403, $j['_http'] ?? null);

    echo "Con cada permiso, aunque sea de tipo Supervisor\n";
    $dar(['timeline.view', 'turnos.monitor', 'observation.review', 'ordenes.cercanas']);
    [$menu] = $http("$base/index.php?page=supervision_lista");
    $check('ve Línea de tiempo, Monitor de turnos, Control y recurrencia y Órdenes cercanas', str_contains($menu, 'page=timeline_supervisores"') && str_contains($menu, 'Monitor de turnos')
        && str_contains($menu, 'page=control_observaciones"') && str_contains($menu, 'modalOrdenesCercanas'), null);
    $check('sin "Ver" del Dashboard no lo ve', !str_contains($menu, 'page=admin_dashboard"'), null);
    [, $j] = $http("$base/api/dashboard.php?action=monitoreo_realtime");
    $check('la Línea de tiempo carga sin el permiso del Dashboard', ($j['_http'] ?? 0) === 200, $j['_http'] ?? null);
    [, $j] = $http("$base/api/dashboard.php?action=matriz_mensual");
    $check('el resto del Dashboard sigue pidiendo su permiso', ($j['_http'] ?? 0) === 403, $j['_http'] ?? null);
    [, $j] = $http("$base/api/asistencia.php?action=admin_summary");
    $check('la API del Monitor de turnos le responde', ($j['_http'] ?? 0) === 200, $j['_http'] ?? null);
    [$pg] = $http("$base/index.php?page=control_observaciones");
    $check('abre Control y recurrencia', !str_contains($pg, 'No tiene permiso para ver Control'), null);
    $dar(['dashboard.view']);
    [$pg] = $http("$base/index.php?page=admin_dashboard");
    $check('con "Ver" del Dashboard lo abre', str_contains($pg, 'page=admin_dashboard"') && !str_contains($pg, 'No tiene permiso para ver el Dashboard'), null);

    echo "Acciones que antes eran solo del Administrador (2026-11-11)\n";
    $dar([]);
    [$pg] = $http("$base/index.php?page=supervision_lista");
    $check('sin los permisos: no corrige supervisiones, no gestiona rescates de otros ni filtra por supervisor',
        str_contains($pg, '"puedeCorregir":false') && str_contains($pg, '"gestionaRescates":false') && !str_contains($pg, 'id="adminFilterSupervisor"'), null);
    $dar(['supervision.filters', 'supervision.correct', 'rescates.manage', 'supervision.filter_people', 'turnos.reopen', 'clima.view', 'clima.teams']);
    [$pg] = $http("$base/index.php?page=supervision_lista");
    $check('con los permisos: corrige supervisiones, gestiona rescates de otros y filtra por departamento y supervisor',
        str_contains($pg, '"puedeCorregir":true') && str_contains($pg, '"gestionaRescates":true') && str_contains($pg, 'id="adminFilterSupervisor"'), null);
    [$pg] = $http("$base/index.php?page=dashboard_lluvias");
    $check('envía el corte de lluvias a Teams con su permiso', str_contains($pg, 'id="dllEnviarCorte"'), null);
    // "Reabrir turnos" (independiente): la Línea de tiempo ofrece reabrir solo con ese permiso.
    $fecha = date('Y-m-d');
    $dar(['timeline.view', 'turnos.reopen']);
    [, $j] = $http("$base/api/asistencia.php?action=timeline_diaria&fecha=$fecha");
    $check('con "Reabrir turnos" la Línea de tiempo ofrece reabrir', ($j['puede_reabrir'] ?? null) === true, $j['puede_reabrir'] ?? $j);
    $dar(['timeline.view']);
    [, $j] = $http("$base/api/asistencia.php?action=timeline_diaria&fecha=$fecha");
    $check('sin "Reabrir turnos" no lo ofrece', ($j['puede_reabrir'] ?? null) === false, $j['puede_reabrir'] ?? $j);

    echo "Perfiles existentes\n";
    $sup = $pdo->query("SELECT COUNT(*) FROM perfil_permisos pp JOIN perfiles p ON p.id = pp.perfil_id WHERE p.rol_base = 'Supervisor' AND pp.permiso IN ('dashboard.view', 'timeline.view', 'observation.review') AND p.nombre <> 'Prueba pantallas'")->fetchColumn();
    $check('a los perfiles de tipo Supervisor no se les abrió nada nuevo', (int)$sup === 0, $sup);
} catch (Throwable $e) {
    $fail++;
    echo '  ✗ Error: ' . $e->getMessage() . "\n";
} finally {
    $cleanup();
}
echo "\n{$ok} correctas, {$fail} con error. Perfil y usuario de prueba eliminados.\n";
exit($fail ? 1 : 0);
