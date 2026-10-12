<?php
// 05_TEST/integration/usuarios_service_test.php - Prueba del módulo Usuarios y roles contra la BD (solo CLI).
// Crea un perfil y dos usuarios de prueba (DNI 9999990x, perfil "ZZ Prueba SGS"), ejecuta los flujos y los BORRA al final.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';

$pdo = Database::getConnection();
$svc = new UsuarioService($pdo);
$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$ok = 0; $fail = 0;
$check = function (string $name, callable $fn) use (&$ok, &$fail) {
    try { $r = $fn(); if ($r === false) throw new RuntimeException('resultado falso'); echo "  ✓ $name\n"; $ok++; }
    catch (Throwable $e) { echo "  ✗ $name — " . $e->getMessage() . "\n"; $fail++; }
};
$expectError = function (callable $fn, string $contains) {
    try { $fn(); } catch (ValidationException $e) { if (str_contains($e->getMessage(), $contains)) return true; throw new RuntimeException('Mensaje inesperado: ' . $e->getMessage()); }
    throw new RuntimeException('Se esperaba un error');
};
$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999902','99999903')");
    $pdo->exec("DELETE FROM sgs_perfil WHERE nombre LIKE 'ZZ Prueba SGS%'");
};
$cleanup();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(nombre_partner) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();

$base = ['dni' => '99999902', 'nombres' => 'Prueba', 'apellidos' => 'Integración SGS', 'email' => 'prueba.integracion@proviwin.test',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'password' => 'ClaveSegura123', 'zonas' => ['ÁNCASH', 'LA LIBERTAD']];

echo "Usuarios y roles — prueba de integración\n";
$uid = 0; $pid = 0;
$check('crear supervisor directo (usuario autogenerado)', function () use ($svc, $base, $admin, &$uid) { $r = $svc->guardar($base, 0, $admin); $uid = $r['id']; return $r['usuario'] === 'pintegracion' || str_starts_with($r['usuario'], 'pintegracion'); });
$check('detalle: organización directo, zona adicional y permisos por defecto', function () use ($svc, &$uid) {
    $d = $svc->detalle($uid); return $d['organizacion'] === 'directo' && $d['zonas'] === ['ÁNCASH', 'LA LIBERTAD'] && !$d['todas_zonas'] && !$d['permisos_personalizados'] && in_array(Auth::PERM_ATTENDANCE_SELF, $d['permisos'], true);
});
$check('zona efectiva = ÁNCASH (base) + LA LIBERTAD', function () use (&$uid) { $z = sgsUserZones($uid); sort($z); return $z === ['LA LIBERTAD', 'ÁNCASH']; });
$check('todas las zonas = sin restricción territorial', function () use ($svc, &$uid, $admin) {    $d = $svc->detalle($uid); $svc->guardar(array_merge($d, ['password' => '', 'todas_zonas' => true, 'zonas' => []]), $uid, $admin);    $t = $svc->detalle($uid); $ok = $t['todas_zonas'] === true && $t['zonas'] === [];    $svc->guardar(array_merge($svc->detalle($uid), ['password' => '', 'todas_zonas' => false, 'zonas' => ['ÁNCASH', 'LA LIBERTAD']]), $uid, $admin);    return $ok && $svc->detalle($uid)['zonas'] === ['ÁNCASH', 'LA LIBERTAD'];});
$check('rechaza DNI duplicado', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['email' => 'otro@proviwin.test', 'usuario' => 'otro.usuario']), 0, $admin), 'mismo DNI'));
$check('rechaza contraseña débil', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['dni' => '99999903', 'email' => 'x@proviwin.test', 'password' => 'corta']), 0, $admin), 'al menos 10'));
$check('rechaza supervisor sin zonas de trabajo', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['dni' => '99999903', 'email' => 'x@proviwin.test', 'zonas' => []]), 0, $admin), 'zona de trabajo'));
$check('rechaza partner con rol Gerente', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['dni' => '99999903', 'email' => 'x@proviwin.test', 'organizacion' => 'partner', 'partner_id' => $partner, 'rol' => 'Gerente']), 0, $admin), 'solo puede tener rol'));
$check('permisos personalizados se guardan', function () use ($svc, $base, $admin, &$uid) {
    $svc->guardar(array_merge($base, ['password' => '', 'permisos_personalizados' => true, 'permisos' => [Auth::PERM_SUPERVISION_VIEW]]), $uid, $admin);
    $d = $svc->detalle($uid); return $d['permisos_personalizados'] && $d['permisos'] === [Auth::PERM_SUPERVISION_VIEW];
});
$check('Auth::can respeta el permiso personalizado', function () use (&$uid) {
    return Auth::can(Auth::PERM_SUPERVISION_VIEW, ['id' => $uid, 'rol' => 'Supervisor']) && !Auth::can(Auth::PERM_SUPERVISION_CREATE, ['id' => $uid, 'rol' => 'Supervisor']);
});
$check('crear perfil personalizado', function () use ($svc, $admin, &$pid) {
    $pid = $svc->guardarPerfil(['nombre' => 'ZZ Prueba SGS', 'descripcion' => 'Perfil de prueba', 'rol_base' => 'Coordinador', 'permisos' => [Auth::PERM_DASHBOARD_VIEW, Auth::PERM_TIMELINE_VIEW]], 0, $admin);
    return $pid > 0;
});
$check('rechaza perfil con nombre de rol del sistema', fn() => $expectError(fn() => $svc->guardarPerfil(['nombre' => 'Supervisor', 'rol_base' => 'Supervisor'], 0, $admin), 'rol del sistema'));
$check('asignar perfil: rol pasa al rol base y permisos del perfil', function () use ($svc, $base, $admin, &$uid, &$pid) {
    $svc->guardar(array_merge($base, ['password' => '', 'perfil_id' => $pid]), $uid, $admin);
    $d = $svc->detalle($uid); sort($d['permisos']);
    $exp = [Auth::PERM_DASHBOARD_VIEW, Auth::PERM_TIMELINE_VIEW]; sort($exp);
    return $d['rol'] === 'Coordinador' && $d['perfil_id'] === $pid && $d['permisos'] === $exp;
});
$check('editar el perfil actualiza a sus usuarios', function () use ($svc, $admin, &$uid, &$pid) {
    $svc->guardarPerfil(['nombre' => 'ZZ Prueba SGS', 'rol_base' => 'Gerente', 'permisos' => [Auth::PERM_DASHBOARD_VIEW]], $pid, $admin);
    $d = $svc->detalle($uid); return $d['rol'] === 'Gerente' && $d['permisos'] === [Auth::PERM_DASHBOARD_VIEW];
});
$check('no se desactiva un perfil con usuarios', fn() => $expectError(fn() => $svc->cambiarEstadoPerfil($pid, false), 'Hay usuarios'));
$check('restablecer contraseña devuelve temporal válida', function () use ($svc, $pdo, &$uid) {
    $t = $svc->restablecerPassword($uid); $h = $pdo->query("SELECT password_hash FROM usuarios WHERE id=$uid")->fetchColumn(); return password_verify($t, $h);
});
$check('cambiar contraseña propia exige la actual', fn() => $expectError(fn() => $svc->cambiarPasswordPropia($uid, 'incorrecta', 'NuevaClave12345'), 'actual no es correcta'));
$check('desactivar y reactivar usuario', function () use ($svc, $pdo, $admin, &$uid) {
    $svc->cambiarEstado($uid, false, $admin); $a = (int)$pdo->query("SELECT activo FROM usuarios WHERE id=$uid")->fetchColumn();
    $svc->cambiarEstado($uid, true, $admin); $b = (int)$pdo->query("SELECT activo FROM usuarios WHERE id=$uid")->fetchColumn();
    return $a === 0 && $b === 1;
});
$check('no puede desactivarse a sí mismo', fn() => $expectError(fn() => $svc->cambiarEstado($admin, false, $admin), 'propia cuenta'));
if ((int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='Administrador' AND activo=1")->fetchColumn() === 1) {
    $check('no deja sin administrador (cambio de rol del único)', function () use ($svc, $admin, $expectError, $uid) {
        $d = $svc->detalle($admin);
        $in = array_merge($d, ['rol' => 'Supervisor', 'perfil_id' => 0, 'password' => '', 'zonas' => $d['zonas'] ?: ['LA LIBERTAD']]);
        return $expectError(fn() => $svc->guardar($in, $admin, $uid), 'único administrador');
    });
}

$cleanup();
$left = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE dni IN ('99999902','99999903')")->fetchColumn() + (int)$pdo->query("SELECT COUNT(*) FROM SGS_PERFIL WHERE NOMBRE LIKE 'ZZ Prueba SGS%'")->fetchColumn();
echo "\n$ok correctas, $fail con error. Datos de prueba eliminados: " . ($left === 0 ? 'sí' : "NO ($left quedan)") . "\n";
exit($fail ? 1 : 0);
