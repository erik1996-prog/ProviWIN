<?php
// 05_TEST/integration/usuarios_activacion_permisos_test.php - (2026-10-26)
//   - permisos personalizados: sin "Ver" de una pantalla no se guarda editar/eliminar de ella, y un permiso antiguo así no vale;
//   - un usuario creado por un administrador que no es la cuenta principal queda "Por activar": no ingresa, la cuenta
//     principal recibe el aviso, y ese administrador no puede activarlo (sí desactivar).
// Por HTTP contra la API real (necesita la cuenta principal activa en la base). Crea DNI 99999938-39; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/usuarios_activacion_permisos_test.php [url_base]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();
$principal = Auth::cuentaPrincipalId();
if (!$principal) exit("No hay cuenta principal activa en esta base: no se prueba.\n");

$frascos = [];
$cleanup = function () use ($pdo, &$frascos, $principal) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('99999938','99999939') OR usuario = 'act.nuevo'")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    $pdo->exec("DELETE FROM notificaciones WHERE usuario_id = $principal AND titulo = 'Usuario por activar' AND mensaje LIKE '%act.nuevo%'");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (['act.admin', 'act.nuevo'] as $u) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $clave = 'ActPrueba#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $adminId = (int)$svc->guardar(['dni' => '99999938', 'nombres' => 'Act', 'apellidos' => 'Admin Prueba', 'email' => 'act.admin@proviwin.test', 'usuario' => 'act.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $principal)['id'];
    $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $adminId");
    $sesion = function (string $usuario, string $pass) use ($base, &$frascos): array {
        $frasco = tempnam(sys_get_temp_dir(), 'sgsact'); $frascos[] = $frasco;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $pass])]);
        $login = json_decode((string)curl_exec($ch), true) ?? []; unset($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); unset($ch);
        $csrf = $m[1] ?? '';
        $http = function (string $url, ?array $post = null) use ($frasco, $csrf): array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
            curl_setopt_array($ch, $o); $b = (string)curl_exec($ch);
            $r = (json_decode($b, true) ?? []) + ['_http' => curl_getinfo($ch, CURLINFO_HTTP_CODE)]; unset($ch);
            return $r;
        };
        return [$login, $http];
    };
    [, $admin] = $sesion('act.admin', $clave);
    $api = "$base/api/usuarios.php";

    echo "Usuario creado por otro administrador — $base\n";
    $r = $admin("$api?action=guardar", ['id' => 0, 'dni' => '99999939', 'nombres' => 'Act', 'apellidos' => 'Nuevo Prueba', 'email' => 'act.nuevo@proviwin.test',
        'usuario' => 'act.nuevo', 'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave, 'pedir_cambio' => false,
        'permisos_personalizados' => true, 'permisos' => ['clima.edit', 'clima.delete', 'partners.view', 'partners.edit', 'supervision.view']]);
    $nuevo = $pdo->query("SELECT * FROM usuarios WHERE usuario = 'act.nuevo'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('queda "Por activar" (inactivo) y el aviso lo dice', ($r['status'] ?? '') === 'success' && (int)($nuevo['activo'] ?? 1) === 0 && (int)($nuevo['por_activar'] ?? 0) === 1
        && str_contains($r['message'] ?? '', 'por activar'), [$r['message'] ?? null, $nuevo['activo'] ?? null, $nuevo['por_activar'] ?? null]);
    $avisos = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = $principal AND titulo = 'Usuario por activar' AND mensaje LIKE '%act.nuevo%'")->fetchColumn();
    $check('la cuenta principal recibe el aviso en la campana', $avisos === 1, $avisos);
    [$login] = $sesion('act.nuevo', $clave);
    $check('el usuario por activar no puede ingresar', ($login['status'] ?? '') !== 'success', $login['message'] ?? null);
    $lista = $admin("$api?action=listar&estado=por_activar");
    $check('la lista lo muestra en "Por activar"', in_array((int)($nuevo['id'] ?? 0), array_map('intval', array_column($lista['usuarios'] ?? [], 'id')), true) && ($lista['conteos']['por_activar'] ?? 0) >= 1);
    $r = $admin("$api?action=estado", ['id' => (int)$nuevo['id'], 'activo' => 1]);
    $check('ese administrador no puede activarlo', $r['_http'] === 403 && (int)$pdo->query("SELECT activo FROM usuarios WHERE id = {$nuevo['id']}")->fetchColumn() === 0, $r['message'] ?? null);

    echo "Permisos personalizados coherentes\n";
    $guardados = $pdo->query("SELECT permiso FROM usuario_permisos WHERE usuario_id = {$nuevo['id']} AND permitido = 1")->fetchAll(PDO::FETCH_COLUMN);
    $check('sin "Ver Clima" no se guarda editar ni eliminar de Clima', !array_intersect(['clima.edit', 'clima.delete'], $guardados) && in_array('partners.edit', $guardados, true), $guardados);
    $pdo->prepare('INSERT INTO usuario_permisos (usuario_id, permiso, permitido, otorgado_por) VALUES (?, ?, 1, ?), (?, ?, 0, ?) ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)')
        ->execute([(int)$nuevo['id'], 'cuadrillas.edit', $principal, (int)$nuevo['id'], 'cuadrillas.view', $principal]);
    $check('un permiso antiguo "editar" sin "ver" no da acceso', !Auth::can('cuadrillas.edit', ['id' => (int)$nuevo['id'], 'rol' => 'Supervisor']));

    echo "La cuenta principal activa\n";
    $svc->cambiarEstado((int)$nuevo['id'], true, $principal);
    $fila = $pdo->query("SELECT activo, por_activar FROM usuarios WHERE id = {$nuevo['id']}")->fetch(PDO::FETCH_ASSOC);
    [$login] = $sesion('act.nuevo', $clave);
    $check('activado: ya no está por activar y puede ingresar', (int)$fila['activo'] === 1 && (int)$fila['por_activar'] === 0 && ($login['status'] ?? '') === 'success', [$fila, $login['message'] ?? null]);
    $r = $admin("$api?action=estado", ['id' => (int)$nuevo['id'], 'activo' => 0]);
    $check('otro administrador sí puede desactivarlo', ($r['status'] ?? '') === 'success', $r['message'] ?? null);
    $check('solo la cuenta principal puede activar usuarios', Auth::puedeActivarUsuarios($principal) && !Auth::puedeActivarUsuarios($adminId));
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y avisos de prueba eliminados.\n";
exit($fail ? 1 : 0);
