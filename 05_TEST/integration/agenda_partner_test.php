<?php
// 05_TEST/integration/agenda_partner_test.php - "Mi agenda" del partner: filtros Pendientes, En proceso y No revisados
// (según el plazo de 24 horas desde la asignación) y sus contadores. Por HTTP contra la API real.
// Crea un supervisor de partner temporal (DNI 99999951) y 3 órdenes asignadas (PRUEBA-AGENDA-*); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/agenda_partner_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$jar = tempnam(sys_get_temp_dir(), 'sgsa');
$cleanup = function () use ($pdo, $jar) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-AGENDA-%'");
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni='99999951'")->fetchColumn();
    if ($id) {
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id=$id");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id=$id");
        $pdo->exec("DELETE FROM usuarios WHERE id=$id");
    }
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|agenda.prueba")]);
    @unlink($jar);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'AgendaPrueba' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Mi agenda del partner: Pendientes, En proceso y No revisados — $base\n";

try {
    $r = (new UsuarioService($pdo))->guardar(['dni' => '99999951', 'nombres' => 'Agenda', 'apellidos' => 'Prueba SGS', 'email' => 'agenda.prueba@proviwin.test', 'usuario' => 'agenda.prueba',
        'rol' => 'Supervisor', 'organizacion' => 'partner', 'partner_id' => $partner, 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin);
    $sup = (int)$r['id'];
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();
    $crear = function (string $sufijo, string $asignada, string $atencion) use ($pdo, $sup, $partner, $admin, $ubi) {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, asignado_por, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision,
            fecha_asignacion, estado_atencion_partner)
            VALUES (:code, NOW(), :sup, :adm, 'Líder', 'Regular', :dep, :prov, :dist, :ticket, 'En frío', :partner, 'Ninguno', 'Instalación', 'Instalación', '', 'Pendiente', $asignada, :atencion)")
            ->execute([':code' => 'SUP-AGENDA-' . $sufijo, ':sup' => $sup, ':adm' => $admin, ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'],
                ':ticket' => 'PRUEBA-AGENDA-' . $sufijo, ':partner' => $partner, ':atencion' => $atencion]);
    };
    $crear('PEND', 'NOW()', 'Pendiente');                                  // asignada hace un momento, sin atender
    $crear('PROC', 'DATE_SUB(NOW(), INTERVAL 2 HOUR)', 'Atendida');        // el partner ya registró su atención
    $crear('VENC', 'DATE_SUB(NOW(), INTERVAL 30 HOUR)', 'Pendiente');      // pasaron las 24 horas sin atención

    $http = function (string $url, array $opts = []) use ($jar): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true);
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'agenda.prueba', 'password' => $clave])]);
    $listar = function (string $estado) use ($http, $base): array {
        $r = $http("$base/api/supervisiones.php?action=list&estado=$estado&fecha_inicio=2000-01-01&limit=100");
        return ['tickets' => array_values(array_filter(array_column($r['data'] ?? [], 'ticket_codigo'), fn($t) => str_starts_with((string)$t, 'PRUEBA-AGENDA-'))), 'counts' => $r['meta']['counts'] ?? []];
    };

    $todas = $listar('todos');
    sort($todas['tickets']);
    $check('Todas muestra las 3 órdenes asignadas', $todas['tickets'] === ['PRUEBA-AGENDA-PEND', 'PRUEBA-AGENDA-PROC', 'PRUEBA-AGENDA-VENC'], $todas['tickets']);
    $c = $todas['counts'];
    $check('contadores: 1 pendiente, 1 en proceso, 1 no revisado', ($c['partner_pendiente'] ?? -1) === 1 && ($c['partner_en_proceso'] ?? -1) === 1 && ($c['partner_vencida'] ?? -1) === 1, $c);
    $check('Pendientes = asignada y dentro de las 24 h', $listar('partner_pendiente')['tickets'] === ['PRUEBA-AGENDA-PEND'], $listar('partner_pendiente')['tickets']);
    $check('En proceso = el partner ya registró su atención', $listar('partner_en_proceso')['tickets'] === ['PRUEBA-AGENDA-PROC'], $listar('partner_en_proceso')['tickets']);
    $check('No revisados = pasaron las 24 h sin atención', $listar('partner_vencida')['tickets'] === ['PRUEBA-AGENDA-VENC'], $listar('partner_vencida')['tickets']);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Supervisor de partner y órdenes de prueba eliminados.\n";
exit($fail ? 1 : 0);
