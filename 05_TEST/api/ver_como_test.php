<?php
// 05_TEST/api/ver_como_test.php - (2026-10-28) "Ver como": el administrador general (cuenta principal) ve el sistema como otro
// usuario, solo para mirar.
//   - solo la cuenta principal; nadie puede ver como una cuenta protegida ni como un usuario inactivo;
//   - la sesión pasa a ser la del usuario (rol, zona…) y al salir vuelve la del administrador;
//   - mientras ve como otro, todo lo que guarda (POST) se rechaza, salvo salir; leer (GET) sí se puede.
// Sin HTTP: usa la sesión en memoria y procesos hijo para requireLogin. Crea usuarios DNI 99999971-72; los BORRA al final.
// Uso (solo CLI): php 05_TEST/api/ver_como_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$dnis = ['99999971', '99999972'];
$limpiar = function () use ($pdo, $dnis) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Ver como\n";

$principal = Auth::cuentaPrincipalId();
if (!$principal) { echo "  (sin cuenta principal en esta base: prueba omitida)\n"; exit(0); }
$filaSesion = static fn(int $id) => Database::getConnection()->query("SELECT u.*, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id = u.partner_id WHERE u.id = $id")->fetch(PDO::FETCH_ASSOC);
// Sesión como la del inicio de sesión (mismas claves que Auth::cargarSesionDe)
$entrarComo = function (int $id) use ($filaSesion) {
    $_SESSION = [];
    $u = $filaSesion($id);
    $carga = new ReflectionMethod(Auth::class, 'cargarSesionDe');
    $carga->invoke(null, $u);
};
$intentar = function (callable $f): ?string { try { $f(); return null; } catch (RuntimeException $e) { return $e->getMessage(); } };

try {
    $svc = new UsuarioService($pdo);
    $ids = [];
    foreach ([['99999971', 'vc.sup', 'Supervisor', ['AREQUIPA']], ['99999972', 'vc.admin', 'Administrador', []]] as [$dni, $u, $rol, $zonas]) {
        $ids[$u] = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'VerComo', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => $rol,
            'organizacion' => 'directo', 'zonas' => $zonas, 'password' => 'VerComo#' . random_int(1000, 9999)], 0, $principal)['id'];
        $pdo->exec("UPDATE usuarios SET activo = 1, por_activar = 0 WHERE id = {$ids[$u]}");
    }

    // Otro administrador (no principal): no puede
    $entrarComo($ids['vc.admin']);
    $check('otro administrador (no la cuenta principal) no puede ver como', $intentar(fn() => Auth::verComo($ids['vc.sup'])) !== null && !Auth::verComoActivo());

    // La cuenta principal
    $entrarComo($principal);
    $antes = $_SESSION;
    $check('nadie puede ver como una cuenta protegida (ni como sí mismo)', $intentar(fn() => Auth::verComo($principal)) !== null);
    $pdo->exec("UPDATE usuarios SET activo = 0 WHERE id = {$ids['vc.sup']}");
    $check('no se puede ver como un usuario inactivo', $intentar(fn() => Auth::verComo($ids['vc.sup'])) !== null);
    $pdo->exec("UPDATE usuarios SET activo = 1 WHERE id = {$ids['vc.sup']}");

    $error = $intentar(fn() => Auth::verComo($ids['vc.sup']));
    $u = Auth::getLoggedInUser();
    $check('la cuenta principal ve como el supervisor: la sesión es la suya (rol y usuario)', $error === null && (int)$u['id'] === $ids['vc.sup'] && $u['rol'] === 'Supervisor' && $u['usuario'] === 'vc.sup', [$error, $u['rol'] ?? null]);
    $check('se sabe que es "Ver como" y de quién', (Auth::verComoActivo()['admin_id'] ?? 0) === $principal && str_contains((string)(Auth::verComoActivo()['objetivo'] ?? ''), 'Prueba vc.sup'));
    $verComo = $_SESSION;

    // Solo mirar: requireLogin en un proceso aparte con esta sesión
    $probar = function (string $metodo, string $script) use ($verComo): string {
        $datos = tempnam(sys_get_temp_dir(), 'sgsv');
        file_put_contents($datos, serialize($verComo));
        // El proceso hijo va en un archivo (pasar código por la consola de Windows rompe las comillas).
        $hijo = $datos . '.php';
        file_put_contents($hijo, '<?php $_SESSION = []; $_SERVER["REQUEST_METHOD"] = ' . var_export($metodo, true) . '; $_SERVER["SCRIPT_NAME"] = ' . var_export($script, true) . ';'
            . ' require ' . var_export(BACKEND_PATH . '/config/config.php', true) . '; require ' . var_export(BACKEND_PATH . '/config/auth.php', true) . ';'
            . ' $_SESSION = unserialize(file_get_contents(' . var_export($datos, true) . ')); $_SESSION["sgs_ultimo_uso"] = time(); $_SESSION["sgs_revalidated_at"] = time();'
            . ' Auth::requireLogin(); echo "PASA";');
        $salida = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($hijo) . ' 2>&1');
        @unlink($datos); @unlink($hijo);
        return $salida;
    };
    $check('mientras ve como otro, guardar (POST) se rechaza: "solo puedes mirar"', str_contains($probar('POST', '/ProviWIN/api/supervisiones.php'), 'solo puedes mirar'));
    $check('leer (GET) sí se puede', str_contains($probar('GET', '/ProviWIN/api/supervisiones.php'), 'PASA'));
    $check('salir de "Ver como" (POST) sí se puede', str_contains($probar('POST', '/ProviWIN/api/ver_como.php'), 'PASA'));

    // Cambiar a otro usuario sin salir conserva la cuenta original; al salir vuelve todo
    Auth::salirDeVerComo();
    $check('al salir vuelve la sesión del administrador general', (int)$_SESSION['user_id'] === $principal && $_SESSION['user_rol'] === $antes['user_rol'] && !Auth::verComoActivo());
    $check('al salir no queda nada del usuario visto', ($_SESSION['user_usuario'] ?? '') === ($antes['user_usuario'] ?? ''));
    $log = implode('', array_map('file_get_contents', glob(BASE_PATH . '/08_LOGS/backend/*' . date('Y-m-d') . '*.log') ?: []));
    $check('queda en la auditoría (inicio y fin)', preg_match_all('/"type":"audit_ver_como".{0,200}"message":"(Inicio|Fin) de/', $log) >= 2 || substr_count($log, 'audit_ver_como') >= 2, 'sin registro');
} finally {
    $limpiar();
}
echo "\n$ok correctas, $fail con error. Usuarios de prueba eliminados.\n";
exit($fail ? 1 : 0);
