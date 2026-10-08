<?php
// 05_TEST/api/turno_control_test.php - Verifica que el turno solo lo registre el propio supervisor directo (solo CLI).
// No crea turnos: usa acciones que el control rechaza o que fallan por lógica antes de escribir.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);

function runCase(string $root, string $role, string $action, string $preview = 'normal'): array
{
    $code = <<<'PHP'
[, $root, $role, $action, $preview] = $argv;
[$roleName, $kind] = array_pad(explode(':', $role, 2), 2, '');
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/ProviWIN/api/asistencia.php'; $_SERVER['REQUEST_URI'] = "/ProviWIN/api/asistencia.php?action=$action";
$_GET['action'] = $action; $_SESSION = [];
require "$root/02_BACKEND/src/config/config.php";
require "$root/02_BACKEND/src/config/auth.php";
$extra = $kind === 'directo' ? ' AND ' . sgsDirectStaffSql() : '';
$st = Database::getConnection()->prepare("SELECT u.*, p.nombre_partner FROM usuarios u LEFT JOIN partners p ON p.id=u.partner_id WHERE u.rol=:r AND u.activo=1{$extra} ORDER BY u.id LIMIT 1");
$st->execute([':r' => $roleName]); $u = $st->fetch();
$_SESSION += ['user_id'=>$u['id'],'user_dni'=>$u['dni'],'user_usuario'=>$u['usuario'],'user_nombre'=>$u['nombre_completo'],'user_rol'=>$u['rol'],
    'user_partner_id'=>$u['partner_id'],'user_partner_nombre'=>$u['nombre_partner'] ?? '', 'csrf_token'=>'t', 'preview_role_mode'=>$preview];
if ($preview === 'directo') {
    $sup = Database::getConnection()->query("SELECT u.id FROM usuarios u LEFT JOIN partners p ON p.id=u.partner_id WHERE u.rol='Supervisor' AND u.activo=1 AND " . sgsDirectStaffSql() . " ORDER BY u.id LIMIT 1")->fetchColumn();
    $_SESSION['preview_supervisor_id'] = (int)$sup;
}
$_SERVER['HTTP_X_CSRF_TOKEN'] = 't';
register_shutdown_function(static function () { fwrite(STDERR, "\n@@HTTP " . (http_response_code() ?: 200) . "@@\n"); });
require "$root/02_BACKEND/src/routes/api/asistencia.php";
PHP;
    $tmp = tempnam(sys_get_temp_dir(), 'sgs');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($role) . ' ' . escapeshellarg($action) . ' ' . escapeshellarg($preview) . ' 2>&1');
    @unlink($tmp);
    preg_match('/@@HTTP (\d+)@@/', $out, $m);
    $json = json_decode(trim(preg_replace('/@@HTTP \d+@@/', '', $out)), true) ?: [];
    return [(int)($m[1] ?? 0), (string)($json['message'] ?? $out)];
}

$cases = [
    ['Administrador', 'start', 'normal', 403, 'administrador no registra turno'],
    ['Administrador', 'start', 'directo', 403, 'vista provisional no registra turno'],
    ['Administrador', 'end', 'directo', 403, 'vista provisional no finaliza turno'],
    ['Supervisor:directo', 'end', 'normal', null, 'supervisor directo pasa el control'],
];
$fail = 0;
foreach ($cases as [$role, $action, $preview, $expect, $label]) {
    [$code, $msg] = runCase($root, $role, $action, $preview);
    $ok = $expect === null ? $code !== 403 : $code === $expect;
    if (!$ok) $fail++;
    printf("  %s %-38s HTTP %d  %s\n", $ok ? '✓' : '✗', $label, $code, mb_substr($msg, 0, 90));
}
echo $fail ? "\n$fail fallo(s)\n" : "\nControl del turno correcto\n";
exit($fail ? 1 : 0);
