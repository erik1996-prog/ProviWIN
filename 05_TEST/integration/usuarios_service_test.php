<?php
// 05_TEST/integration/usuarios_service_test.php - Prueba del módulo Usuarios y roles contra la BD (solo CLI).
// Crea dos usuarios de prueba (DNI 9999990x), ejecuta los flujos y los BORRA al final.
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
    $pdo->exec("DELETE n FROM notificaciones n WHERE n.titulo = 'Nuevo personal por completar' AND n.mensaje LIKE 'Personal Prueba SGS%'");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999902','99999903','99999906','99999907')");
};
$cleanup();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(nombre_partner) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();

$base = ['dni' => '99999902', 'nombres' => 'Prueba', 'apellidos' => 'Integración SGS', 'email' => 'prueba.integracion@proviwin.test',
    'rol' => 'Supervisor', 'organizacion' => 'directo', 'password' => 'ClaveSegura123', 'zonas' => ['ÁNCASH', 'LA LIBERTAD']];

echo "Usuarios y roles — prueba de integración\n";
$uid = 0;
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
$check('correo libre: se guarda el que trae la persona', function () use ($svc, $base, $admin, &$uid) {
    $svc->guardar(array_merge($base, ['password' => '', 'email' => 'Prueba.Integracion@Gmail.com']), $uid, $admin);
    $ok = $svc->detalle($uid)['email'] === 'prueba.integracion@gmail.com';
    $svc->guardar(array_merge($base, ['password' => '']), $uid, $admin);
    return $ok;
});
$check('rechaza correo inválido', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['password' => '', 'email' => 'sin-arroba']), $uid, $admin), 'correo electrónico válido'));

// Personal: un supervisor de partner (zona LAMBAYEQUE) con "Agregar y editar personal"
$supPartner = 0; $nuevo = 0;
$check('personal: el partner registra un trabajador → "Sin acceso", su partner, turno Día, presencial y su área', function () use ($svc, $base, $admin, $partner, $pdo, &$supPartner, &$nuevo) {
    $supPartner = $svc->guardar(array_merge($base, ['dni' => '99999906', 'email' => 'partner.sgs@proviwin.test', 'usuario' => 'partner.sgs',
        'organizacion' => 'partner', 'partner_id' => $partner, 'zonas' => ['LAMBAYEQUE']]), 0, $admin)['id'];
    $actor = ['id' => $supPartner, 'rol' => 'Supervisor', 'partner_id' => $partner, 'is_win_directo' => false, 'nombre_completo' => 'Partner SGS'];
    $r = $svc->guardarPersonal(['dni' => '99999907', 'nombres' => 'Personal', 'apellidos' => 'Prueba SGS', 'email' => 'personal.sgs@gmail.com',
        'organizacion' => 'directo', 'partner_id' => 999, 'zona' => 'LAMBAYEQUE', 'usuario' => 'intruso', 'rol' => 'Administrador',
        'area_trabajo' => 'VT (visitas técnicas)', 'cargo' => 'Supervisor de campo', 'turno' => 'Noche', 'modalidad' => 'REMOTO'], 0, $actor);
    $nuevo = $r['id'];
    $u = $pdo->query("SELECT usuario, rol, activo, partner_id, turno, modalidad, area_trabajo, cargo FROM usuarios WHERE id=$nuevo")->fetch();
    return $u['usuario'] === null && $u['rol'] === 'Supervisor' && (int)$u['activo'] === 0 && (int)$u['partner_id'] === $partner
        && $u['turno'] === 'Día' && $u['modalidad'] === 'PRESENCIAL' && $u['area_trabajo'] === 'VT (visitas técnicas)' && $u['cargo'] === 'Supervisor de campo';
});
$check('personal: se avisa a los administradores', fn() => (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE titulo='Nuevo personal por completar' AND mensaje LIKE 'Personal Prueba SGS%'")->fetchColumn() > 0);
$check('personal: el partner no registra fuera de sus zonas', function () use ($svc, $partner, &$supPartner, $expectError) {
    $actor = ['id' => $supPartner, 'rol' => 'Supervisor', 'partner_id' => $partner, 'is_win_directo' => false];
    return $expectError(fn() => $svc->guardarPersonal(['dni' => '99999903', 'nombres' => 'Otra', 'apellidos' => 'Zona', 'email' => 'otra@proviwin.test', 'zona' => 'PIURA'], 0, $actor), 'sus zonas');
});
$check('personal: el partner ve solo a su partner (no al personal directo)', function () use ($svc, $partner, &$supPartner, &$uid, &$nuevo) {
    $actor = ['id' => $supPartner, 'rol' => 'Supervisor', 'partner_id' => $partner, 'is_win_directo' => false];
    $ids = array_map('intval', array_column($svc->listar(['estado' => ''], $actor)['usuarios'], 'id'));
    return in_array($nuevo, $ids, true) && !in_array($uid, $ids, true);
});
$check('personal: editar solo cambia datos personales', function () use ($svc, $partner, $pdo, &$supPartner, &$nuevo) {
    $actor = ['id' => $supPartner, 'rol' => 'Supervisor', 'partner_id' => $partner, 'is_win_directo' => false];
    $svc->guardarPersonal(['dni' => '99999907', 'nombres' => 'Personal', 'apellidos' => 'Prueba SGS', 'email' => 'personal.nuevo@gmail.com', 'telefono_personal' => '999111222', 'rol' => 'Administrador'], $nuevo, $actor);
    $u = $pdo->query("SELECT email, telefono_personal, rol, activo FROM usuarios WHERE id=$nuevo")->fetch();
    return $u['email'] === 'personal.nuevo@gmail.com' && $u['telefono_personal'] === '999111222' && $u['rol'] === 'Supervisor' && (int)$u['activo'] === 0;
});
$check('personal: el partner no edita al personal directo', function () use ($svc, $partner, &$supPartner, &$uid, $expectError) {
    $actor = ['id' => $supPartner, 'rol' => 'Supervisor', 'partner_id' => $partner, 'is_win_directo' => false];
    return $expectError(fn() => $svc->guardarPersonal(['dni' => '99999902', 'nombres' => 'X', 'apellidos' => 'Y', 'email' => 'x@proviwin.test'], $uid, $actor), 'no pertenece');
});
$check('el administrador completa el acceso: exige contraseña y lo activa', function () use ($svc, $admin, $pdo, $expectError, &$nuevo) {
    $d = $svc->detalle($nuevo);
    $in = array_merge($d, ['usuario' => '', 'password' => '', 'zonas' => ['LAMBAYEQUE'], 'rol' => 'Supervisor']);
    $sinClave = $expectError(fn() => $svc->guardar($in, $nuevo, $admin), 'Asigne una contraseña');
    $r = $svc->guardar(array_merge($in, ['password' => 'ClaveSegura456']), $nuevo, $admin);
    $u = $pdo->query("SELECT usuario, activo FROM usuarios WHERE id=$nuevo")->fetch();
    return $sinClave && $u['usuario'] !== null && $u['usuario'] === $r['usuario'] && (int)$u['activo'] === 1;
});
$check('área y cargo: acepta valores de la lista', function () use ($svc, $base, $admin, &$uid) {
    $svc->guardar(array_merge($base, ['password' => '', 'area_trabajo' => 'Almacén', 'cargo' => 'Almacenero']), $uid, $admin);
    $d = $svc->detalle($uid); return $d['area_trabajo'] === 'Almacén' && $d['cargo'] === 'Almacenero';
});
$check('área: rechaza un valor fuera de la lista', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['password' => '', 'area_trabajo' => 'Inventada']), $uid, $admin), 'área de trabajo'));
$check('cargo: rechaza un valor fuera de la lista', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['password' => '', 'cargo' => 'Presidente']), $uid, $admin), 'cargo'));
$check('área: conserva un valor anterior que no está en la lista', function () use ($svc, $base, $admin, $pdo, &$uid) {
    $pdo->exec("UPDATE usuarios SET area_trabajo = 'SUPERVISOR UM' WHERE id = $uid");
    $svc->guardar(array_merge($base, ['password' => '', 'area_trabajo' => 'SUPERVISOR UM']), $uid, $admin);
    return $svc->detalle($uid)['area_trabajo'] === 'SUPERVISOR UM';
});
$check('procesos: marca algunos y "Todos" marca todos', function () use ($svc, $base, $admin, &$uid) {
    $svc->guardar(array_merge($base, ['password' => '', 'procesos' => ['Penalidades', 'Inventado']]), $uid, $admin);
    $uno = $svc->detalle($uid)['procesos'] === ['Penalidades'];
    $svc->guardar(array_merge($base, ['password' => '', 'procesos' => ['Todos']]), $uid, $admin);
    return $uno && $svc->detalle($uid)['procesos'] === ['Liquidaciones', 'Penalidades', 'Gestión Phoenix'];
});
$check('turno: acepta Día y Noche', function () use ($svc, $base, $admin, &$uid) {
    $svc->guardar(array_merge($base, ['password' => '', 'turno' => 'Noche']), $uid, $admin);
    return $svc->detalle($uid)['turno'] === 'Noche';
});
$check('turno: Mañana (anterior) se guarda como Día', function () use ($svc, $base, $admin, &$uid) { $svc->guardar(array_merge($base, ['password' => '', 'turno' => 'Mañana']), $uid, $admin); return $svc->detalle($uid)['turno'] === 'Día'; });
$check('turno: rechaza un valor inventado', fn() => $expectError(fn() => $svc->guardar(array_merge($base, ['password' => '', 'turno' => 'Madrugada']), $uid, $admin), 'Día o Noche'));
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
        $in = array_merge($d, ['rol' => 'Supervisor', 'password' => '', 'zonas' => $d['zonas'] ?: ['LA LIBERTAD']]);
        return $expectError(fn() => $svc->guardar($in, $admin, $uid), 'único administrador');
    });
}

$cleanup();
$left = (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE dni IN ('99999902','99999903','99999906','99999907')")->fetchColumn();
echo "\n$ok correctas, $fail con error. Datos de prueba eliminados: " . ($left === 0 ? 'sí' : "NO ($left quedan)") . "\n";
exit($fail ? 1 : 0);
