<?php
// 05_TEST/integration/dashboard_partners_test.php - Dashboard, vista Partners: cumplimiento por supervisor del partner
// y "Observaciones y descargos" (a tiempo, vencidas, en plazo, por revisar, validadas, penalizadas, motivos, gravedad).
// Por HTTP contra la API real. Usa fechas de 2001 para no mezclarse con datos reales.
// Crea un administrador (DNI 99999961), un supervisor de partner (DNI 99999962), 3 órdenes (PRUEBA-DASHP-*),
// 6 observaciones y un checklist; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/dashboard_partners_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$jar = tempnam(sys_get_temp_dir(), 'sgsd');
$cleanup = function () use ($pdo, $jar) {
    $ids = $pdo->query("SELECT id FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-DASHP-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM checklists_tecnicos WHERE supervision_id IN ($in)"); // sus ítems se borran en cascada
        $pdo->exec("DELETE FROM observaciones_supervision WHERE supervision_id IN ($in)");
        $pdo->exec("DELETE FROM supervisiones WHERE id IN ($in)");
    }
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('99999961','99999962')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_permisos WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|dashp.admin")]);
    @unlink($jar);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'DashPrueba' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Dashboard, vista Partners — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999961', 'nombres' => 'Dash', 'apellidos' => 'Admin Prueba', 'email' => 'dashp.admin@proviwin.test', 'usuario' => 'dashp.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin);
    $sup = (int)$svc->guardar(['dni' => '99999962', 'nombres' => 'Dash', 'apellidos' => 'Partner Prueba', 'email' => 'dashp.partner@proviwin.test', 'usuario' => 'dashp.partner',
        'rol' => 'Supervisor', 'organizacion' => 'partner', 'partner_id' => $partner, 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin)['id'];
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();

    // Órdenes asignadas el 15/01/2001 10:00: A atendida en 2 h (a tiempo), B atendida a las 48 h (tarde), C sin atender (bloqueada)
    $orden = function (string $suf, string $estado, ?string $atencion) use ($pdo, $sup, $partner, $admin, $ubi): int {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision,
            fecha_asignacion, estado_atencion_partner, fecha_atencion_partner)
            VALUES (:code, '2001-01-15 10:00:00', :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, :ticket, 'En frío', :partner, 'Ninguno', 'Instalación', 'Instalación', '', 'Pendiente',
            '2001-01-15 10:00:00', :estado, :atencion)")
            ->execute([':code' => 'SUP-DASHP-' . $suf, ':sup' => $sup, ':adm' => $admin, ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'],
                ':ticket' => 'PRUEBA-DASHP-' . $suf, ':partner' => $partner, ':estado' => $estado, ':atencion' => $atencion]);
        return (int)$pdo->lastInsertId();
    };
    $a = $orden('A', 'Atendida', '2001-01-15 12:00:00');
    $orden('B', 'Atendida', '2001-01-17 10:00:00');
    $orden('C', 'Pendiente', null);

    // Observaciones de campo sobre la orden A (registradas el 15/01/2001 11:00)
    $sub = $pdo->query("SELECT id, categoria_id, descripcion_subopcion FROM subopciones_observacion ORDER BY id LIMIT 1")->fetch();
    $obs = function (string $estado, string $limite, ?string $respuesta, string $gravedad = 'Leve') use ($pdo, $a, $sub) {
        $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, gravedad, categoria_id, subopcion_id, detalle_observacion, fecha_registro, fecha_limite_respuesta,
            estado_respuesta, fecha_respuesta_partner) VALUES (:s, :g, :c, :sub, 'Prueba dashboard partners', '2001-01-15 11:00:00', :lim, :est, :resp)")
            ->execute([':s' => $a, ':g' => $gravedad, ':c' => $sub['categoria_id'], ':sub' => $sub['id'], ':lim' => $limite, ':est' => $estado, ':resp' => $respuesta]);
    };
    $obs('Pendiente', '2099-12-31 00:00:00', null);                                        // en plazo
    $obs('Penalidad Directa', '2001-01-18 11:00:00', null);                                // vencida sin respuesta: el sistema la pasa sola a Penalidad Directa
    $obs('Respondido', '2001-01-18 11:00:00', '2001-01-16 09:00:00');                      // a tiempo, por revisar
    $obs('Levantamiento validado', '2001-01-18 11:00:00', '2001-01-17 09:00:00');          // a tiempo, validada
    $obs('Penalidad Directa', '2001-01-18 11:00:00', null, 'Muy Grave');                   // vencida, penalizada
    $obs('Levantamiento rechazado', '2001-01-18 11:00:00', '2001-01-20 09:00:00');         // respondida tarde, penalizada

    // Checklist validado y respondido a tiempo: 2 ítems observados (cuentan) y 1 sin observación (no cuenta)
    $pdo->prepare("INSERT INTO checklists_tecnicos (supervision_id, supervisor_id, tecnico_nombre, tecnico_dni, unidad_tipo, unidad_placa, estado_respuesta,
        fecha_registro, fecha_limite_respuesta, fecha_respuesta_partner) VALUES (:s, :sup, 'Técnico Prueba', '00000000', 'Moto', 'PRU-001', 'Validado',
        '2001-01-15 12:00:00', '2001-01-18 12:00:00', '2001-01-16 12:00:00')")->execute([':s' => $a, ':sup' => $admin]);
    $ck = (int)$pdo->lastInsertId();
    $item = $pdo->prepare("INSERT INTO checklist_respuestas (checklist_id, criterio, estado, observacion) VALUES (:c, :cr, 'Malo', :obs)");
    $item->execute([':c' => $ck, ':cr' => 'Criterio PRUEBA DASHP 1', ':obs' => 'Falta']);
    $item->execute([':c' => $ck, ':cr' => 'Criterio PRUEBA DASHP 2', ':obs' => 'Dañado']);
    $item->execute([':c' => $ck, ':cr' => 'Criterio PRUEBA DASHP 3', ':obs' => null]);

    $http = function (string $url, array $opts = []) use ($jar): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true);
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'dashp.admin', 'password' => $clave])]);
    $q = "fecha_inicio=2001-01-15&fecha_fin=2001-01-15&partner_id=$partner";

    // Cumplimiento por supervisor del partner
    $c = $http("$base/api/dashboard.php?action=cumplimiento_partners&$q");
    $fila = array_values(array_filter($c['supervisores'] ?? [], fn($s) => (int)$s['id'] === $sup))[0] ?? null;
    $check('el supervisor del partner aparece con sus 3 órdenes', $fila && $fila['asignadas'] === 3, $c['supervisores'] ?? $c);
    $check('1 a tiempo, 1 bloqueada, 0 en plazo', $fila && $fila['a_tiempo'] === 1 && $fila['bloqueadas'] === 1 && $fila['en_plazo'] === 0, $fila);
    $check('cumplimiento 50% (1 a tiempo de 2 cerradas) y respuesta promedio 25 h', $fila && (float)$fila['cumplimiento'] === 50.0 && $fila['minutos_promedio'] === 1500, $fila);

    // Observaciones y descargos
    $o = $http("$base/api/dashboard.php?action=observaciones_partners&$q");
    $r = $o['resumen'] ?? [];
    $check('8 observaciones: 6 de campo + 2 ítems de checklist observados (el ítem sin observación no cuenta)', ($r['total'] ?? 0) === 8, $o);
    $check('4 respondidas a tiempo (2 de campo + 2 del checklist)', ($r['a_tiempo'] ?? 0) === 4, $r);
    $check('2 vencidas sin respuesta, 1 pendiente en plazo, 1 por revisar', ($r['sin_respuesta_vencidas'] ?? 0) === 2 && ($r['en_plazo'] ?? 0) === 1 && ($r['en_revision'] ?? 0) === 1, $r);
    $check('3 validadas y 3 penalizadas', ($r['validadas'] ?? 0) === 3 && ($r['penalizadas'] ?? 0) === 3, $r);
    $check('respuesta a tiempo 50% y penalidad sobre evaluadas 50% (3 de 6)', (float)($r['pct_a_tiempo'] ?? 0) === 50.0 && (float)($r['pct_penalizadas'] ?? 0) === 50.0, $r);
    $check('la tabla por partner trae una fila con los mismos números', count($o['partners'] ?? []) === 1 && ($o['partners'][0]['total'] ?? 0) === 8 && ($o['partners'][0]['penalizadas'] ?? 0) === 3, $o['partners'] ?? null);
    $mot = array_column($o['motivos'] ?? [], null, 'motivo');
    $check('motivos: el de campo con 6 (3 penalizadas) y cada criterio del checklist con 1',
        ($mot[$sub['descripcion_subopcion']]['total'] ?? 0) === 6 && ($mot[$sub['descripcion_subopcion']]['penalizadas'] ?? 0) === 3 && ($mot['Criterio PRUEBA DASHP 1']['total'] ?? 0) === 1, $o['motivos'] ?? null);
    $grav = array_column($o['gravedad'] ?? [], 'total', 'gravedad');
    $check('gravedad: 5 Leve, 1 Muy Grave, 2 de checklist', ($grav['Leve'] ?? 0) === 5 && ($grav['Muy Grave'] ?? 0) === 1 && ($grav['Checklist'] ?? 0) === 2, $grav);
    $o2 = $http("$base/api/dashboard.php?action=observaciones_partners&fecha_inicio=2001-01-15&fecha_fin=2001-01-15&partner_id=$partner&departamento=PIURA");
    $check('con otro departamento no cuenta nada', ($o2['resumen']['total'] ?? -1) === 0, $o2['resumen'] ?? $o2);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, órdenes, observaciones y checklist de prueba eliminados.\n";
exit($fail ? 1 : 0);
