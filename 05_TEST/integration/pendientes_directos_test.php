<?php
// 05_TEST/integration/pendientes_directos_test.php - Dashboard "Pendientes y rescates" (2026-10-20; antes sección "Pendientes
// cargadas" del Dashboard de supervisiones): cuántas atendieron los supervisores directos y, si las transfirieron a un
// partner, que sigan siendo suyas; indicadores por supervisor de partner (atendidas a tiempo, en plazo, vencidas).
// Por HTTP contra la API real. Usa fecha de carga 10/02/2001 para no mezclarse con datos reales.
// Crea un administrador (DNI 99999971), dos supervisores directos (99999972-73), un supervisor de partner (99999974)
// y 6 pendientes (PRUEBA-PDIR-*); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/pendientes_directos_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$dnis = ['99999971', '99999972', '99999973', '99999974'];
$jar = tempnam(sys_get_temp_dir(), 'sgsp');
$cleanup = function () use ($pdo, $jar, $dnis) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-PDIR-%'"); // su historial se borra en cascada
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_permisos WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|pdir.admin")]);
    @unlink($jar);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'PdirPrueba' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Pendientes y rescates: supervisores directos y de partner — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $nuevo = function (string $dni, string $usuario, string $apellido, string $rol, string $org) use ($svc, $admin, $partner, $clave): int {
        return (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Pdir', 'apellidos' => $apellido, 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => $org, 'partner_id' => $partner, 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin)['id'];
    };
    $nuevo('99999971', 'pdir.admin', 'Admin Prueba', 'Administrador', 'directo');
    $d1 = $nuevo('99999972', 'pdir.directo1', 'Directo Uno', 'Supervisor', 'directo');
    $d2 = $nuevo('99999973', 'pdir.directo2', 'Directo Dos', 'Supervisor', 'directo');
    $p = $nuevo('99999974', 'pdir.partner', 'Partner Prueba', 'Supervisor', 'partner');
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();

    $orden = function (string $suf, int $supervisor, int $asignadoPor, string $estado, ?string $asignacion = null, string $atencion = 'Pendiente', ?string $fechaAtencion = null)
        use ($pdo, $partner, $ubi): int {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_servicio_caliente,
            es_cancelacion, origen_importacion, estado_supervision, fecha_asignacion, estado_atencion_partner, fecha_atencion_partner)
            VALUES (:code, '2001-02-10 08:00:00', '2001-02-10 08:00:00', :sup, :por, 'Líder', 'Regular', :dep, :prov, :dist, :ticket, 'En frío', :partner, 'Ninguno',
            'Instalación', 'Instalación', '', 'Cancelada', 1, 1, :estado, " . ($asignacion ?? 'NULL') . ", :atencion, :fat)")
            ->execute([':code' => 'SUP-PDIR-' . $suf, ':sup' => $supervisor, ':por' => $asignadoPor, ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'],
                ':dist' => $ubi['distrito'], ':ticket' => 'PRUEBA-PDIR-' . $suf, ':partner' => $partner, ':estado' => $estado, ':atencion' => $atencion, ':fat' => $fechaAtencion]);
        return (int)$pdo->lastInsertId();
    };
    $orden('1', $d1, $admin, 'Pendiente');                                                            // sin tomar (en la bolsa de la zona)
    $orden('2', $d1, $admin, 'En proceso');                                                           // D1 la tomó, en proceso
    $fin = $orden('3', $d1, $admin, 'Finalizada');                                                    // D1 la atendió (4 h después de la carga)
    $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por, fecha_cambio) VALUES (:s, 'En proceso', 'Finalizada', :u, '2001-02-10 12:00:00')")
        ->execute([':s' => $fin, ':u' => $d1]);
    $orden('4', $p, $d1, 'Pendiente', "'2001-02-10 09:00:00'", 'Atendida', '2001-02-10 10:00:00');   // D1 la transfirió; el partner la atendió (2 h)
    $orden('5', $p, $d2, 'Pendiente', "'2001-02-10 09:00:00'");                                       // D2 la transfirió; venció en el partner
    $orden('6', $p, $d2, 'Pendiente', 'NOW()');                                                       // D2 la transfirió; aún en plazo

    $http = function (string $url, array $opts = []) use ($jar): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true);
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'pdir.admin', 'password' => $clave])]);
    $q = "desde=2001-02-10&hasta=2001-02-10&partner_id=$partner";
    $api = "$base/api/dashboard_pendientes.php";

    $o = $http("$api?action=dashboard&$q");
    $k = $o['data']['kpis'] ?? [];
    $check('6 cargadas: 1 sin tomar, 1 en validación, 1 cancelación correcta', ($k['cargadas'] ?? 0) === 6 && ($k['pendiente'] ?? 0) === 1 && ($k['en_validacion'] ?? 0) === 1 && ($k['correcta'] ?? 0) === 1, $k);
    $check('transferidas: 2 en el partner (1 vencida) y 1 atendida por el partner', ($k['en_partner'] ?? 0) === 2 && ($k['vencidas_partner'] ?? 0) === 1 && ($k['partner_atendida'] ?? 0) === 1, $k);
    $check('tiempo de atención: 4 h (carga 08:00, cierre 12:00)', (float)($k['horas_promedio'] ?? 0) === 4.0, $k);

    $sup = array_column($o['data']['directos'] ?? [], null, 'id');
    $check('Directo Uno: 3 tomadas (incluye la que transfirió), atendió 1, el partner 1 = 67 %', ($sup[$d1]['tomadas'] ?? 0) === 3 && ($sup[$d1]['cerradas'] ?? 0) === 1
        && ($sup[$d1]['transferidas'] ?? 0) === 1 && ($sup[$d1]['atendidas_partner'] ?? 0) === 1 && ($sup[$d1]['pct_atencion'] ?? 0) === 67, $sup[$d1] ?? null);
    $check('Directo Dos: las 2 que transfirió siguen siendo suyas (1 vencida en el partner)', ($sup[$d2]['tomadas'] ?? 0) === 2 && ($sup[$d2]['transferidas'] ?? 0) === 2
        && ($sup[$d2]['vencidas_partner'] ?? 0) === 1 && ($sup[$d2]['atendidas'] ?? 0) === 0, $sup[$d2] ?? null);
    $check('el supervisor del partner no aparece como responsable directo', !isset($sup[$p]), array_keys($sup));

    $t = $http("$api?action=dashboard_tabla&$q");
    $det = array_column($t['data']['filas'] ?? [], null, 'ticket');
    $t4 = $det['PRUEBA-PDIR-4'] ?? [];
    $check('tabla, la transferida: responsable el directo, la atiende el del partner', ($t4['estado'] ?? '') === 'partner_atendida'
        && str_contains($t4['responsable'] ?? '', 'Directo Uno') && str_contains($t4['atiende_partner'] ?? '', 'Partner Prueba'), $t4);
    $check('tabla, la sin tomar: "Aún no gestionado"', ($det['PRUEBA-PDIR-1']['estado'] ?? '') === 'pendiente' && ($det['PRUEBA-PDIR-1']['responsable'] ?? '') === 'Aún no gestionado', $det['PRUEBA-PDIR-1'] ?? null);

    $o2 = $http("$api?action=dashboard&$q&supervisor_id=$d2");
    $check('filtro por supervisor directo: solo las 2 de Directo Dos', ($o2['data']['kpis']['cargadas'] ?? 0) === 2 && count($o2['data']['directos'] ?? []) === 1, $o2['data']['kpis'] ?? $o2);
    $o3 = $http("$api?action=dashboard&desde=2001-02-11&hasta=2001-02-28&partner_id=$partner");
    $check('otro período (por fecha de carga): nada', ($o3['data']['kpis']['cargadas'] ?? -1) === 0, $o3['data']['kpis'] ?? $o3);
    $d = $http("$base/api/dashboard.php?action=pendientes_directos");
    $check('el Dashboard de supervisiones ya no repite esta información', ($d['status'] ?? '') !== 'success', $d);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios y pendientes de prueba eliminados.\n";
exit($fail ? 1 : 0);
