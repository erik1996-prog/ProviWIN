<?php
// 05_TEST/integration/jefe_operaciones_test.php - Rol "Jefe de operaciones" (solo partners, solo consulta), por HTTP:
// ve las órdenes asignadas a su partner (en cualquier zona y estado) y a su personal; no atiende órdenes; el rol no se
// puede dar a personal directo WIN. Crea un jefe (DNI 99999961), un supervisor del mismo partner en otra zona (99999962)
// y dos órdenes (PRUEBA-JEFE-*); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/jefe_operaciones_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'JefePrueba' . random_int(1000, 9999);
$jar = tempnam(sys_get_temp_dir(), 'sgsj');
$cleanup = function () use ($pdo, $jar) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-JEFE-%'");
    foreach (['99999961', '99999962', '99999963'] as $dni) {
        $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni='$dni'")->fetchColumn();
        if ($id) { foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id=$id"); $pdo->exec("DELETE FROM usuarios WHERE id=$id"); }
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|jefe.prueba")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
$expectError = function (callable $fn, string $contains) { try { $fn(); } catch (ValidationException $e) { return str_contains($e->getMessage(), $contains); } return false; };
echo "Rol Jefe de operaciones — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(nombre_partner) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
    $base0 = ['nombres' => 'Jefe', 'apellidos' => 'Prueba SGS', 'organizacion' => 'partner', 'partner_id' => $partner, 'password' => $clave];
    $jefe = $svc->guardar($base0 + ['dni' => '99999961', 'email' => 'jefe.prueba@proviwin.test', 'usuario' => 'jefe.prueba', 'rol' => 'Jefe de operaciones', 'zonas' => []], 0, $admin)['id'];
    $check('se crea un Jefe de operaciones de un partner sin zonas', $jefe > 0);
    $sup = $svc->guardar(['dni' => '99999962', 'nombres' => 'Sup', 'apellidos' => 'Partner Prueba', 'email' => 'sup.jefe@proviwin.test', 'usuario' => 'sup.jefe',
        'rol' => 'Supervisor', 'organizacion' => 'partner', 'partner_id' => $partner, 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin)['id'];
    $check('el rol no se puede dar a personal directo WIN', $expectError(fn() => $svc->guardar(['dni' => '99999963', 'nombres' => 'X', 'apellidos' => 'Y', 'email' => 'x.jefe@proviwin.test',
        'rol' => 'Jefe de operaciones', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin), 'solo para personal de un partner'));
    $check('el rol Partner ya no existe', $expectError(fn() => $svc->guardar(['dni' => '99999963', 'nombres' => 'X', 'apellidos' => 'Y', 'email' => 'x.jefe@proviwin.test',
        'rol' => 'Partner', 'organizacion' => 'partner', 'partner_id' => $partner, 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin), 'rol válido'));

    // Dos órdenes de su partner asignadas al supervisor (una pendiente y otra ya finalizada) y una sin asignar
    $ins = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision, fecha_asignacion, estado_atencion_partner)
        VALUES (:c, NOW(), :s, 'Líder', 'Regular', 'PIURA', 'PIURA', 'PIURA', :t, 'En frío', :p, 'Ninguno', 'Instalación', 'Instalación', '', :e, :fa, 'Pendiente')");
    $ins->execute([':c' => 'SUP-JEFE-1', ':s' => $sup, ':t' => 'PRUEBA-JEFE-1', ':p' => $partner, ':e' => 'Pendiente', ':fa' => date('Y-m-d H:i:s')]);
    $ins->execute([':c' => 'SUP-JEFE-2', ':s' => $sup, ':t' => 'PRUEBA-JEFE-2', ':p' => $partner, ':e' => 'Finalizada', ':fa' => date('Y-m-d H:i:s', time() - 86400)]);
    $ins->execute([':c' => 'SUP-JEFE-3', ':s' => $admin, ':t' => 'PRUEBA-JEFE-3', ':p' => $partner, ':e' => 'Pendiente', ':fa' => null]);

    $http = function (string $url, array $opts = []) use ($jar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return [$body, json_decode($body, true)];
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => 'jefe.prueba', 'password' => $clave])]);
    [$pagina] = $http("$base/index.php?page=supervision_lista");
    $check('su menú dice "Órdenes del partner"', str_contains($pagina, 'Órdenes del partner'));
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    [, $r] = $http("$base/api/supervisiones.php?action=list&fecha_inicio=2000-01-01&limit=100");
    $tickets = array_column($r['data'] ?? [], 'ticket_codigo'); sort($tickets);
    $check('ve las órdenes asignadas a su partner (pendiente y finalizada), no las sin asignar', $tickets === ['PRUEBA-JEFE-1', 'PRUEBA-JEFE-2'], $tickets);
    $id1 = (int)$pdo->query("SELECT id FROM supervisiones WHERE ticket_codigo='PRUEBA-JEFE-1'")->fetchColumn();
    [, $r] = $http("$base/api/supervisiones.php?action=partner_update", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['supervision_id' => $id1, 'observacion_atencion_partner' => 'intento del jefe']]);
    $check('no puede registrar la atención (solo consulta)', ($r['status'] ?? '') === 'error', $r);
    // Responder observaciones: solo con el permiso, que el administrador le puede activar
    $cat = $pdo->query('SELECT categoria_id, id FROM subopciones_observacion LIMIT 1')->fetch(PDO::FETCH_NUM);
    $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, creado_por, gravedad, categoria_id, subopcion_id, detalle_observacion, fecha_limite_respuesta)
        VALUES (:s, :u, 'Grave', :c, :o, 'Observación de prueba', DATE_ADD(NOW(), INTERVAL 3 DAY))")->execute([':s' => $id1, ':u' => $admin, ':c' => $cat[0], ':o' => $cat[1]]);
    $obsId = (int)$pdo->lastInsertId();
    $responder = fn() => $http("$base/api/observaciones.php?action=responder_partner", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($m[1] ?? '')],
        CURLOPT_POSTFIELDS => ['observacion_id' => $obsId, 'respuesta_partner' => 'Levantamiento del jefe']])[1];
    $r = $responder();
    $check('sin el permiso no puede responder observaciones', str_contains($r['message'] ?? '', 'Solo los representantes'), $r);
    $permisos = array_values(array_intersect(Auth::roleDefaultPermissions('Jefe de operaciones'), UsuarioService::permisosDelegables()));
    $permisos[] = Auth::PERM_OBSERVATION_RESPOND;
    $svc->guardar(array_merge($svc->detalle($jefe), ['password' => '', 'permisos_personalizados' => true, 'permisos' => $permisos]), $jefe, $admin);
    $r = $responder();
    $check('con el permiso activado ya puede responder (solo le faltan las fotos)', str_contains($r['message'] ?? '', 'fotos'), $r);
    $pdo->exec("DELETE FROM observaciones_supervision WHERE id = $obsId");

    [, $r] = $http("$base/api/usuarios.php?action=listar&estado=");
    $ids = array_map('intval', array_column($r['usuarios'] ?? [], 'id'));
    $check('ve al personal de su partner aunque esté en otra zona', in_array($sup, $ids, true) && !in_array($admin, $ids, true), $ids);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y órdenes de prueba eliminados.\n";
exit($fail ? 1 : 0);
