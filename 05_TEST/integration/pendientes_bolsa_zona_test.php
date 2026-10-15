<?php
// 05_TEST/integration/pendientes_bolsa_zona_test.php - Pendientes como bolsa común de la zona:
// una cancelación pendiente de LAMBAYEQUE la ven todos los supervisores directos de esa zona (no la de otra zona);
// el primero que la inicia se queda con ella y desaparece de la lista de pendientes de los demás.
// Por HTTP contra la API real. Crea 3 supervisores temporales (DNI 99999941-43), un turno de hoy y una pendiente;
// BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/pendientes_bolsa_zona_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/asignacion_cancelaciones.php';
$pdo = Database::getConnection();

$dnis = ['99999941' => ['bolsa.uno', ['LAMBAYEQUE']], '99999942' => ['bolsa.dos', ['PIURA', 'LAMBAYEQUE']], '99999943' => ['bolsa.tres', ['PIURA']]];
$ticket = 'PRUEBA-BOLSA-' . random_int(1000, 9999);
$jars = [];
$cleanup = function () use ($pdo, $dnis, &$jars) {
    $ids = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", array_keys($dnis)) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-BOLSA-%'");
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM asistencia_eventos WHERE asistencia_id IN (SELECT id FROM asistencia_supervisores WHERE supervisor_id IN ($in))");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach ($dnis as [$usuario]) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$usuario")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'BolsaPrueba' . random_int(1000, 9999);
$ids = [];
foreach ($dnis as $dni => [$usuario, $zonas]) {
    (new UsuarioService($pdo))->guardar(['dni' => $dni, 'nombres' => 'Bolsa', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => $zonas, 'password' => $clave], 0, $admin);
    $ids[$usuario] = (int)$pdo->query("SELECT id FROM usuarios WHERE dni='$dni'")->fetchColumn();
}
$partner = $pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();

$http = function (string $quien, string $url, array $opts = []) use (&$jars): array {
    $jars[$quien] ??= tempnam(sys_get_temp_dir(), 'sgsb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$quien], CURLOPT_COOKIEFILE => $jars[$quien], CURLOPT_TIMEOUT => 60] + $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return [$body, json_decode($body, true)];
};
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Pendientes como bolsa común de la zona — $base\n";

try {
    // Los avisos de importación llegan a todos los supervisores de la zona
    $zona = array_map(fn($u) => (int)$u['id'], supervisoresZonaCancelacion($pdo, 'LAMBAYEQUE'));
    $check('el aviso de importación va a los dos supervisores de LAMBAYEQUE', in_array($ids['bolsa.uno'], $zona, true) && in_array($ids['bolsa.dos'], $zona, true), $zona);
    $check('el supervisor de PIURA no recibe el aviso de LAMBAYEQUE', !in_array($ids['bolsa.tres'], $zona, true), $zona);

    // Pendiente importada, asignada por carga al supervisor uno
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, asignado_por, zona_asignacion, derivacion_orden, tipo_servicio,
        departamento, provincia, distrito, ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio,
        observacion_servicio, estado_servicio_caliente, es_cancelacion, tipificacion_cancelacion, origen_importacion, estado_supervision, fecha_asignacion)
        VALUES (:code, NOW(), :sup, :sup2, 'Chiclayo', 'Líder', 'Regular', :dep, :prov, :dist, :ticket, 'En frío', :partner, 'Ninguno', 'Instalación', 'Instalación',
        '', 'Cancelada', 1, (SELECT descripcion FROM tipificaciones_cancelacion LIMIT 1), 1, 'Pendiente', NOW())")
        ->execute([':code' => 'SUP-PRUEBA-' . random_int(1000, 9999), ':sup' => $ids['bolsa.uno'], ':sup2' => $ids['bolsa.uno'], ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'],
            ':dist' => $ubi['distrito'], ':ticket' => $ticket, ':partner' => $partner]);
    $ordenId = (int)$pdo->lastInsertId();

    $csrf = [];
    foreach ($ids as $usuario => $id) {
        $http($usuario, "$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $clave])]);
        [$pagina] = $http($usuario, "$base/index.php?page=supervision_lista");
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
        $csrf[$usuario] = $m[1] ?? '';
    }
    $vePendiente = function (string $usuario) use ($http, $base, $ordenId): bool {
        [, $r] = $http($usuario, "$base/api/supervisiones.php?action=list&estado=pendiente&fecha_inicio=2000-01-01&limit=100");
        foreach (($r['data'] ?? []) as $fila) if ((int)($fila['id'] ?? 0) === $ordenId) return true;
        return false;
    };
    $check('el supervisor uno (asignado por carga) la ve en pendientes', $vePendiente('bolsa.uno'));
    $check('el supervisor dos (LAMBAYEQUE como zona adicional) también la ve', $vePendiente('bolsa.dos'));
    $check('el supervisor de PIURA no la ve', !$vePendiente('bolsa.tres'));

    // El supervisor dos la inicia: necesita su turno de hoy abierto
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, CURRENT_DATE(), NOW(), 'En turno')")->execute([':s' => $ids['bolsa.dos']]);
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, CURRENT_DATE(), NOW(), 'En turno')")->execute([':s' => $ids['bolsa.uno']]);
    $iniciar = function (string $usuario) use ($http, $base, $csrf, $ordenId, $ticket, $ubi, $partner) {
        return $http($usuario, "$base/api/supervisiones.php?action=create", [CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf[$usuario]],
            CURLOPT_POSTFIELDS => json_encode(['supervision_id_edit' => $ordenId, 'estado_supervision' => 'Iniciada', 'tipo_supervision' => 'Instalación', 'tipo_supervision_inicial' => 'En frío',
                'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'],
                'distrito' => $ubi['distrito'], 'ticket_codigo' => $ticket, 'partner_id' => $partner, 'latitud' => '-6.77', 'longitud' => '-79.84'])])[1];
    };
    // Una supervisión NUEVA con el código de la pendiente (aunque lo escriba en minúsculas o con espacios) se rechaza
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-BOLSA-%'")->fetchColumn();
    [, $r] = $http('bolsa.dos', "$base/api/supervisiones.php?action=create", [CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf['bolsa.dos']],
        CURLOPT_POSTFIELDS => json_encode(['estado_supervision' => 'Iniciada', 'tipo_supervision' => 'Instalación', 'tipo_supervision_inicial' => 'En caliente',
            'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'], 'provincia' => $ubi['provincia'],
            'distrito' => $ubi['distrito'], 'ticket_codigo' => ' ' . strtolower($ticket) . ' ', 'partner_id' => $partner, 'latitud' => '-6.77', 'longitud' => '-79.84'])]);
    $despues = (int)$pdo->query("SELECT COUNT(*) FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-BOLSA-%'")->fetchColumn();
    $check('una supervisión nueva con el código de la pendiente se rechaza ("se encuentra en Pendientes")',
        ($r['codigo'] ?? '') === 'en_pendientes' && (int)($r['pendiente_id'] ?? 0) === $ordenId && str_contains($r['message'] ?? '', 'se encuentra en Pendientes') && $antes === $despues, $r);

    $r = $iniciar('bolsa.dos');
    $check('el supervisor dos inicia la pendiente',($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT supervisor_id, estado_supervision FROM supervisiones WHERE id=$ordenId")->fetch();
    $check('la orden queda a nombre del supervisor dos y deja de estar pendiente', (int)$fila['supervisor_id'] === $ids['bolsa.dos'] && $fila['estado_supervision'] !== 'Pendiente', $fila);
    $check('desaparece de las pendientes del supervisor uno', !$vePendiente('bolsa.uno'));
    $r = $iniciar('bolsa.uno');
    $check('si el supervisor uno intenta iniciarla, se le dice que ya fue tomada', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'ya fue tomada'), $r);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Supervisores, turnos y pendiente de prueba eliminados.\n";
exit($fail ? 1 : 0);
