<?php
// 05_TEST/integration/lider_equipo_test.php - Líder técnico con su equipo (2026-10-27). Un Coordinador (rol "Gestión") con
// zona LIMA, área Instalaciones y el permiso "Registrar… a su equipo": ve, registra y da de baja solo a quienes están por
// debajo de su rol, en sus zonas y su área; asigna solo esos roles, zonas, área y sus propios permisos; no se modifica a sí
// mismo; lo que registra entra de inmediato. Además ve solo las supervisiones y supervisores de su zona y área.
// Crea usuarios con DNI 99999970-75 (y 99999976-79 si los registra el líder) y supervisiones PRUEBA-LID-*; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/lider_equipo_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$dnis = ['99999970', '99999971', '99999972', '99999973', '99999974', '99999975', '99999976', '99999977', '99999978', '99999979'];
$jar = tempnam(sys_get_temp_dir(), 'sgsl');
$cleanup = function () use ($pdo, $dnis, $jar) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-LID-%'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|lid.lider")]);
    @unlink($jar);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'LidPrueba#' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Líder técnico con su equipo — $base\n";

try {
    $svc = new UsuarioService($pdo);
    // Permisos del líder: los de su rol + registrar a su equipo
    $permisosLider = array_values(array_unique(array_merge(array_intersect(Auth::roleDefaultPermissions('Coordinador'), UsuarioService::permisosDelegables()), [Auth::PERM_USERS_TEAM])));
    $crear = function (string $dni, string $usuario, string $rol, array $zonas, string $area, array $extra = []) use ($svc, $admin, $clave, $pdo): int {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Lider', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => 'directo', 'zonas' => $zonas, 'area_trabajo' => $area, 'password' => $clave] + $extra, 0, $admin)['id'];
        $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $id");
        return $id;
    };
    $ids = [
        'lider' => $crear('99999970', 'lid.lider', 'Coordinador', ['LIMA'], 'Instalaciones', ['permisos_personalizados' => true, 'permisos' => $permisosLider]),
        'inst' => $crear('99999971', 'lid.inst', 'Supervisor', ['LIMA'], 'Instalaciones'),
        'ave' => $crear('99999972', 'lid.ave', 'Supervisor', ['LIMA'], 'Averías'),
        'norte' => $crear('99999973', 'lid.norte', 'Supervisor', ['NORTE CHICO'], 'Instalaciones'),
        'par' => $crear('99999974', 'lid.par', 'Coordinador', ['LIMA'], 'Instalaciones'),
        'todas' => $crear('99999975', 'lid.todas', 'Supervisor', [], 'Instalaciones', ['todas_zonas' => true]),
    ];
    $lider = ['id' => $ids['lider'], 'rol' => 'Coordinador'];
    $check('el líder (Gestión con zona LIMA e Instalaciones) tiene alcance propio', sgsAlcanceLimitado($lider));

    // Sesión del líder
    $http = function (string $url, ?array $body = null) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60];
        if ($body !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
        curl_setopt_array($ch, $o); $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, json_decode($b, true) ?? []];
    };
    $csrf = '';
    $http("$base/api/auth.php?action=login", ['usuario' => 'lid.lider', 'password' => $clave]);
    $ch = curl_init("$base/index.php?page=usuarios");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $pagina = (string)curl_exec($ch); curl_close($ch);
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m); $csrf = $m[1] ?? '';
    $api = "$base/api/usuarios.php";

    // Lo que ve en Usuarios
    [, $l] = $http("$api?action=listar");
    $vistos = array_map('intval', array_column($l['usuarios'] ?? [], 'id'));
    $check('en Usuarios ve solo a su equipo: el supervisor de LIMA e Instalaciones (no Averías, ni Norte Chico, ni su par, ni "todas las zonas")',
        in_array($ids['inst'], $vistos, true) && !array_intersect($vistos, [$ids['ave'], $ids['norte'], $ids['par'], $ids['todas'], $ids['lider'], $admin]), $vistos);
    [, $c] = $http("$api?action=catalogos");
    $cat = $c['data'] ?? [];
    $check('al registrar, solo le aparecen roles por debajo del suyo, su zona y su área',
        array_column($cat['roles'] ?? [], 'rol') === ['Supervisor', 'Jefe de operaciones'] || array_column($cat['roles'] ?? [], 'rol') === ['Jefe de operaciones', 'Supervisor']
            ? (($cat['departamentos'] ?? []) === ['LIMA'] && ($cat['areas'] ?? []) === ['Instalaciones']) : false, [array_column($cat['roles'] ?? [], 'rol'), $cat['departamentos'] ?? null, $cat['areas'] ?? null]);
    $check('los permisos que puede dar son solo los suyos', !array_diff(array_column($cat['permisos'] ?? [], 'clave'), $permisosLider) && count($cat['permisos'] ?? []) === count($permisosLider));

    // Registrar
    $nuevo = fn(array $cambios) => $http("$api?action=guardar", $cambios + ['id' => 0, 'dni' => '99999976', 'nombres' => 'Nuevo', 'apellidos' => 'Del Líder', 'email' => 'lid.nuevo@proviwin.test',
        'usuario' => 'lid.nuevo', 'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['LIMA'], 'todas_zonas' => false, 'area_trabajo' => 'Instalaciones',
        'password' => 'NuevoLider#2026', 'permisos_personalizados' => false, 'permisos' => []]);
    [$c1, $r1] = $nuevo(['zonas' => ['NORTE CHICO']]);
    [$c2, $r2] = $nuevo(['rol' => 'Coordinador']);
    [$c3, $r3] = $nuevo(['area_trabajo' => 'Averías']);
    [$c4, $r4] = $nuevo(['todas_zonas' => true]);
    [$c5, $r5] = $nuevo(['permisos_personalizados' => true, 'permisos' => [Auth::PERM_PARTNERS_DELETE]]);
    $check('no puede asignar otra zona, un rol igual al suyo, otra área, "todas las zonas" ni un permiso que no tiene',
        $c1 === 422 && $c2 === 422 && $c3 === 422 && $c4 === 422 && $c5 === 422, [$r1['message'] ?? '', $r2['message'] ?? '', $r3['message'] ?? '', $r4['message'] ?? '', $r5['message'] ?? '']);
    [$c6, $r6] = $nuevo([]);
    $creado = $pdo->query("SELECT id, activo, rol FROM usuarios WHERE dni = '99999976'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('registra un supervisor de su zona y área, y entra de inmediato (activo)', $c6 === 200 && (int)($creado['activo'] ?? 0) === 1 && ($creado['rol'] ?? '') === 'Supervisor', [$c6, $r6, $creado]);

    // Editar y dar de baja
    [$c7] = $http("$api?action=detalle&id={$ids['ave']}");
    [$c8, $r8] = $http("$api?action=guardar", ['id' => $ids['lider'], 'dni' => '99999970', 'nombres' => 'Lider', 'apellidos' => 'Prueba lid.lider', 'email' => 'lid.lider@proviwin.test',
        'usuario' => 'lid.lider', 'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['LIMA'], 'area_trabajo' => 'Instalaciones', 'password' => '', 'permisos_personalizados' => false]);
    $check('no abre a alguien fuera de su equipo ni se modifica a sí mismo', $c7 === 422 && $c8 === 422, [$c7, $c8, $r8['message'] ?? '']);
    [$c9, $r9] = $http("$api?action=estado", ['id' => $ids['inst'], 'activo' => false]);
    [$c10] = $http("$api?action=estado", ['id' => $ids['ave'], 'activo' => false]);
    [$c11] = $http("$api?action=estado", ['id' => $ids['inst'], 'activo' => true]);
    $check('da de baja a uno de su equipo; no a alguien de otra área; reactivar queda para la cuenta principal',
        $c9 === 200 && (int)$pdo->query("SELECT activo FROM usuarios WHERE id = {$ids['inst']}")->fetchColumn() === 0 && $c10 === 422 && $c11 === 403, [$c9, $r9['message'] ?? '', $c10, $c11]);
    $pdo->exec("UPDATE usuarios SET activo = 1 WHERE id = {$ids['inst']}");

    // Lo que ve en Supervisiones y en la línea de tiempo
    $ins = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision)
        VALUES (:c, NOW(), :s, 'Líder', 'Regular', :d, :p, :di, :t, 'En caliente', :pa, 'Ninguno', :cl, :cl2, '', 'Finalizada')");
    foreach ([1 => [$ids['inst'], 'LIMA', 'LIMA', 'COMAS', 'Instalación'], 2 => [$ids['ave'], 'LIMA', 'LIMA', 'ATE', 'Avería'], 3 => [$ids['norte'], 'LIMA', 'HUAURA', 'HUACHO', 'Instalación']] as $n => [$sup, $d, $p, $di, $cl]) {
        $ins->execute([':c' => "SUP-LID-$n", ':s' => $sup, ':d' => $d, ':p' => $p, ':di' => $di, ':t' => "PRUEBA-LID-$n", ':pa' => $partner, ':cl' => $cl, ':cl2' => $cl]);
    }
    [, $sl] = $http("$base/api/supervisiones.php?action=list&estado=todos&fecha_inicio=2000-01-01&limit=100");
    $tickets = array_values(array_filter(array_column($sl['data'] ?? [], 'ticket_codigo'), fn($t) => str_starts_with((string)$t, 'PRUEBA-LID-')));
    $check('en Supervisiones ve solo la instalación de LIMA (no la avería ni la de Huacho)', $tickets === ['PRUEBA-LID-1'], $tickets);
    [, $tf] = $http("$base/api/asistencia.php?action=list_filters");
    $sups = array_map('intval', array_column($tf['supervisores'] ?? [], 'id'));
    $check('en la línea de tiempo ve a su supervisor (y a él mismo), no a los demás',
        in_array($ids['inst'], $sups, true) && !array_intersect($sups, [$ids['ave'], $ids['norte'], $ids['par'], $ids['todas']]), $sups);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
