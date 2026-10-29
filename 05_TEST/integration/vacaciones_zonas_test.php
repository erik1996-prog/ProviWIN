<?php
// 05_TEST/integration/vacaciones_zonas_test.php - (2026-10-29) Vacaciones sin dejar zonas vacías (regla del usuario):
//   - en un mismo día a lo más una zona queda sin supervisor (dos zonas de un solo supervisor no salen a la vez);
//   - un supervisor de una zona con dos sí puede salir junto con el de otra zona; los dos de la misma zona, no;
//   - las pendientes ya ocupan los días; una rechazada los libera; el descanso médico no se bloquea;
//   - el supervisor solo registra vacaciones, descanso médico, paternidad y fallecimiento.
// Por HTTP contra la API real. Zonas de prueba: CALLAO y NORTE CHICO (un supervisor cada una) y LIMA (dos).
// Crea usuarios DNI 99999983-86; los BORRA al final (con sus solicitudes).
// Uso (solo CLI): php 05_TEST/integration/vacaciones_zonas_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/ausencias.php';
$pdo = Database::getConnection();
$usuarios = ['99999983' => ['vz.moq', ['CALLAO']], '99999984' => ['vz.tac', ['NORTE CHICO']], '99999985' => ['vz.pas1', ['LIMA']], '99999986' => ['vz.pas2', ['LIMA']]];
$frascos = [];
$limpiar = function () use ($pdo, $usuarios, &$frascos) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", array_keys($usuarios)) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) { $in = implode(',', array_map('intval', $us));
        foreach (['ausencias_supervisores' => 'supervisor_id', 'notificaciones' => 'usuario_id', 'usuario_departamentos_acceso' => 'usuario_id', 'usuario_permisos' => 'usuario_id'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)"); }
    foreach ($usuarios as [$u]) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$limpiar();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Vacaciones sin dejar zonas vacías — $base\n";
try {
    $principal = Auth::cuentaPrincipalId() ?: 1;
    $clave = 'VacZona#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $ids = [];
    foreach ($usuarios as $dni => [$u, $zonas]) {
        $ids[$u] = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'VacZona', 'apellidos' => "Prueba $u", 'email' => "$u@proviwin.test", 'usuario' => $u, 'rol' => 'Supervisor',
            'organizacion' => 'directo', 'zonas' => $zonas, 'password' => $clave], 0, $principal)['id'];
        $pdo->exec("UPDATE usuarios SET activo = 1, por_activar = 0, debe_cambiar_password = 0 WHERE id = {$ids[$u]}");
    }
    $sesion = function (string $u) use ($base, $clave, &$frascos) {
        $fr = tempnam(sys_get_temp_dir(), 'sgsv'); $frascos[] = $fr;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m);
        $csrf = $m[1] ?? '';
        return function (string $tipo, string $ini, string $fin) use ($base, $fr, $csrf): array {
            $ch = curl_init("$base/api/ausencias.php?action=registrar");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $fr, CURLOPT_COOKIEFILE => $fr, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf],
                CURLOPT_POSTFIELDS => ['tipo' => $tipo, 'fecha_inicio' => $ini, 'fecha_fin' => $fin, 'comentario' => 'Prueba de zonas']]);
            return json_decode((string)curl_exec($ch), true) ?? [];
        };
    };
    $d = fn(int $n) => date('Y-m-d', strtotime("+$n days"));
    $moq = $sesion('vz.moq'); $tac = $sesion('vz.tac'); $pas1 = $sesion('vz.pas1'); $pas2 = $sesion('vz.pas2');
    $ok1 = fn(array $r) => ($r['status'] ?? '') === 'success';

    $r = $moq('Vacaciones', $d(40), $d(45));
    $check('CALLAO (un supervisor) pide vacaciones', $ok1($r), $r);
    $r = $tac('Vacaciones', $d(40), $d(41));
    $check('NORTE CHICO (un supervisor) no puede en los mismos días: quedarían dos zonas vacías', !$ok1($r) && str_contains($r['message'] ?? '', 'zonas sin supervisor'), $r);
    $r = $pas1('Vacaciones', $d(40), $d(41));
    $check('LIMA (dos supervisores) sí puede salir junto con CALLAO: su zona sigue cubierta', $ok1($r), $r);
    $r = $pas2('Vacaciones', $d(41), $d(42));
    $check('el otro de LIMA no puede coincidir con su compañero de zona', !$ok1($r) && str_contains($r['message'] ?? '', 'misma zona'), $r);
    $r = $tac('Vacaciones', $d(46), $d(48));
    $check('NORTE CHICO sí puede en días que no se cruzan', $ok1($r), $r);
    $r = $tac('Descanso médico', $d(50), $d(50));
    $check('el descanso médico no se bloquea (pide sustento, no choca)', !$ok1($r) && str_contains($r['message'] ?? '', 'certificado'), $r);
    $r = $tac('Licencia sin goce de haber', $d(52), $d(52));
    $check('el supervisor ya no registra licencia sin goce (ni con goce ni subsidio)', !$ok1($r) && str_contains($r['message'] ?? '', 'tipo'), $r);
    $pdo->exec("UPDATE ausencias_supervisores SET estado = 'Rechazada' WHERE supervisor_id = {$ids['vz.moq']}");
    $r = $tac('Vacaciones', $d(40), $d(41));
    $check('si rechazan las de CALLAO, NORTE CHICO ya puede en esos días', $ok1($r), $r);
    $check('el formulario ofrece solo vacaciones, descanso médico, paternidad y fallecimiento', SGS_TIPOS_AUSENCIA === ['Vacaciones', 'Descanso médico', 'Licencia por paternidad', 'Licencia por fallecimiento'], SGS_TIPOS_AUSENCIA);
} finally { $limpiar(); }
echo "\n$ok correctas, $fail con error. Usuarios y solicitudes de prueba eliminados.\n";
exit($fail ? 1 : 0);
