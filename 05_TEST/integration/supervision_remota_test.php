<?php
// 05_TEST/integration/supervision_remota_test.php - Supervisión remota (videollamada): sin GPS ni vehículo obligatorios,
// pero con captura (foto de evidencia), medio y horas de la llamada para finalizar; cuenta en la modalidad del Dashboard
// y aparece en la línea de tiempo. Por HTTP contra la API real.
// Crea un supervisor directo (DNI 99999991) con su turno de hoy, un administrador (99999992), un técnico del partner y la supervisión
// PRUEBA-REM-1; BORRA todo al final (también la foto subida).
// Uso (solo CLI): php 05_TEST/integration/supervision_remota_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$dnis = ['99999991', '99999992'];
$jars = [];
$foto = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sgs_captura_prueba.png';
file_put_contents($foto, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$cleanup = function () use ($pdo, $dnis, &$jars, $foto) {
    foreach ($pdo->query("SELECT foto_servicio_1 FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-REM-%' AND foto_servicio_1 IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ruta) {
        $archivo = UPLOAD_DIR . basename((string)$ruta);
        if (is_file($archivo)) @unlink($archivo);
    }
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-REM-%'");
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico = 'TECNICO PRUEBA REMOTA'");
    $pdo->exec("DELETE FROM vehiculos WHERE placa = 'PRU-REM'");
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        $pdo->exec("DELETE FROM asistencia_eventos WHERE asistencia_id IN (SELECT id FROM asistencia_supervisores WHERE supervisor_id IN ($in))");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id IN ($in)");
        $pdo->exec("DELETE FROM notificaciones WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_departamentos_acceso WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuario_permisos WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['rem.sup', 'rem.admin'] as $u) foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'RemPrueba' . random_int(1000, 9999);
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Supervisión remota por videollamada — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $sup = (int)$svc->guardar(['dni' => '99999991', 'nombres' => 'Remota', 'apellidos' => 'Supervisor Prueba', 'email' => 'rem.sup@proviwin.test', 'usuario' => 'rem.sup',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin)['id'];
    $svc->guardar(['dni' => '99999992', 'nombres' => 'Remota', 'apellidos' => 'Admin Prueba', 'email' => 'rem.admin@proviwin.test', 'usuario' => 'rem.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin);
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, CURRENT_DATE(), NOW(), 'En turno')")->execute([':s' => $sup]);
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();
    // Técnico líder temporal del partner (el sistema valida que el técnico pertenezca al partner de la orden)
    $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, partner_id) VALUES ('TECNICO PRUEBA REMOTA', '99999993', 1, :p)")->execute([':p' => $partner]);

    $http = function (string $quien, string $url, array $opts = []) use (&$jars): array {
        $jars[$quien] ??= tempnam(sys_get_temp_dir(), 'sgsr');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jars[$quien], CURLOPT_COOKIEFILE => $jars[$quien], CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true) ?? ['crudo' => substr($body, 0, 300)];
    };
    $csrf = [];
    foreach (['rem.sup', 'rem.admin'] as $u) {
        $http($u, "$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        $ch = curl_init("$base/index.php?page=supervision_lista");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jars[$u], CURLOPT_COOKIEJAR => $jars[$u]]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
        $csrf[$u] = $m[1] ?? '';
    }
    $guardar = function (array $campos, bool $conFoto = false) use ($http, $base, $csrf, $foto): array {
        if ($conFoto) $campos['foto_servicio_1'] = new CURLFile($foto, 'image/png', 'captura.png');
        return $http('rem.sup', "$base/api/supervisiones.php?action=create", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf['rem.sup']], CURLOPT_POSTFIELDS => $campos]);
    };
    $datos = ['tipo_supervision' => 'Instalación', 'derivacion_orden' => 'Líder', 'tipo_servicio' => 'Regular', 'departamento' => $ubi['departamento'],
        'provincia' => $ubi['provincia'], 'distrito' => $ubi['distrito'], 'ticket_codigo' => 'PRUEBA-REM-1', 'partner_id' => $partner, 'tipo_supervision_inicial' => 'Remota'];

    // 1. Inicia la supervisión remota (sin GPS)
    $r = $guardar($datos + ['estado_supervision' => 'En proceso']);
    $id = (int)($r['supervision_id'] ?? 0);
    $check('inicia la supervisión remota sin GPS', ($r['status'] ?? '') === 'success' && $id > 0, $r);
    $fin = $datos + ['supervision_id_edit' => $id, 'estado_supervision' => 'Finalizada', 'observacion_servicio' => 'GO por videollamada',
        'tecnico1_nombre' => 'TECNICO PRUEBA REMOTA', 'tecnico1_dni' => '99999993', 'unidad_placa' => 'PRU-REM'];

    // 1b. Coordenadas escritas a mano en la remota (2026-10-15): se validan; coma decimal aceptada
    $avance = $datos + ['supervision_id_edit' => $id, 'estado_supervision' => 'En proceso', 'observacion_servicio' => 'Coordenadas del cliente'];
    $r = $guardar($avance + ['latitud' => 'abc', 'longitud' => '-79.05']);
    $check('coordenada escrita mal se rechaza', ($r['status'] ?? '') === 'error' && ($r['field'] ?? '') === 'latitud', $r);
    $r = $guardar($avance + ['latitud' => '-8.139075']);
    $check('solo la latitud (sin longitud) se rechaza', ($r['status'] ?? '') === 'error' && ($r['field'] ?? '') === 'longitud', $r);
    $r = $guardar($avance + ['latitud' => '-8,139075', 'longitud' => '-79,055686']);
    $coord = $pdo->query("SELECT latitud, longitud FROM supervisiones WHERE id = $id")->fetch();
    $check('coordenadas del cliente escritas a mano se guardan (coma decimal aceptada)', ($r['status'] ?? '') === 'success' && $coord['latitud'] === '-8.139075' && $coord['longitud'] === '-79.055686', [$r, $coord]);

    // 2. Finalizar exige la captura
    $r = $guardar($fin);
    $check('sin captura no finaliza (pide la captura de la videollamada)', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'captura de la videollamada'), $r);
    // 3. Con la captura finaliza: basta indicar que es remota (sin técnicos, vehículo ni GPS; solo el partner)
    $r = $guardar($fin, true);
    $check('con la captura finaliza sin técnicos, vehículo ni GPS', ($r['status'] ?? '') === 'success', $r);
    $fila = $pdo->query("SELECT tipo_supervision_inicial, estado_supervision, latitud, tecnico1_nombre, unidad_placa, partner_id, foto_servicio_1 FROM supervisiones WHERE id = $id")->fetch();
    $check('queda Remota y finalizada, con su partner y la captura; no guarda técnico ni vehículo', $fila && $fila['tipo_supervision_inicial'] === 'Remota'
        && $fila['estado_supervision'] === 'Finalizada' && $fila['latitud'] === null && $fila['tecnico1_nombre'] === null && $fila['unidad_placa'] === null
        && (int)$fila['partner_id'] === $partner && !empty($fila['foto_servicio_1']), $fila);

    // 4. Presencial: exige vehículo y GPS; el vehículo (tipo, placa y estado) queda guardado para la siguiente
    $presencial = array_merge($datos, ['tipo_supervision_inicial' => 'En caliente', 'tecnico1_nombre' => 'TECNICO PRUEBA REMOTA', 'tecnico1_dni' => '99999993',
        'observacion_servicio' => 'Supervisión en campo', 'estado_supervision' => 'Finalizada']);
    $r = $guardar(array_merge($datos, ['ticket_codigo' => 'PRUEBA-REM-2', 'tipo_supervision_inicial' => 'En caliente', 'estado_supervision' => 'En proceso']));
    $id2 = (int)($r['supervision_id'] ?? 0);
    $r = $guardar(array_merge($presencial, ['supervision_id_edit' => $id2, 'ticket_codigo' => 'PRUEBA-REM-2']), true);
    $check('una en caliente sin vehículo ni GPS sigue sin poder finalizar', ($r['status'] ?? '') === 'error' && str_contains($r['message'] ?? '', 'vehículo'), $r);
    $conVehiculo = ['unidad_tipo' => 'Moto', 'unidad_placa' => 'PRU-REM', 'unidad_estado_conservacion' => 'Regular', 'latitud' => '-6.77', 'longitud' => '-79.84'];
    $r = $guardar(array_merge($presencial, $conVehiculo, ['supervision_id_edit' => $id2, 'ticket_codigo' => 'PRUEBA-REM-2']), true);
    $check('con vehículo y GPS finaliza', ($r['status'] ?? '') === 'success', $r);
    $veh = function () use ($http, $base): ?array {
        $l = $http('rem.sup', "$base/api/tecnicos.php?action=list");
        foreach (($l['data'] ?? $l) as $t) if (is_array($t) && ($t['nombre_tecnico'] ?? '') === 'TECNICO PRUEBA REMOTA') return $t;
        return null;
    };
    $t1 = $veh();
    $check('el técnico líder queda con su vehículo: Moto PRU-REM en estado Regular (sale por defecto)', $t1 && $t1['unidad_tipo'] === 'Moto' && $t1['unidad_placa'] === 'PRU-REM' && $t1['unidad_estado'] === 'Regular', $t1);
    // Se edita el estado (y el tipo) en otra supervisión: queda guardado lo último
    $r = $guardar(array_merge($datos, ['ticket_codigo' => 'PRUEBA-REM-3', 'tipo_supervision_inicial' => 'En caliente', 'estado_supervision' => 'En proceso']));
    $id3 = (int)($r['supervision_id'] ?? 0);
    $r = $guardar(array_merge($presencial, $conVehiculo, ['unidad_tipo' => 'Auto', 'unidad_estado_conservacion' => 'Mal estado', 'supervision_id_edit' => $id3, 'ticket_codigo' => 'PRUEBA-REM-3']), true);
    $t2 = $veh();
    $check('si se cambia el tipo o el estado, se guarda para la próxima (Auto, Mal estado)', ($r['status'] ?? '') === 'success' && $t2 && $t2['unidad_tipo'] === 'Auto' && $t2['unidad_estado'] === 'Mal estado', [$r, $t2]);
    // 6. Dashboard (modalidad) y línea de tiempo
    $hoy = date('Y-m-d');
    $d = $http('rem.admin', "$base/api/supervisiones.php?action=indicadores_dashboard&fecha_inicio=$hoy&fecha_fin=$hoy&supervisor_id=$sup");
    $mod = $d['modalidad'] ?? ($d['data']['modalidad'] ?? null);
    $check('el Dashboard la cuenta en la modalidad Remota', is_array($mod) && (int)($mod['Remota'] ?? 0) === 1, $mod ?? $d);
    $t = $http('rem.admin', "$base/api/asistencia.php?action=timeline_diaria&fecha=$hoy");
    $bloques = array_column($t['supervisores'] ?? [], 'timeline_blocks', 'supervisor_id')[$sup] ?? [];
    $remotas = array_filter($bloques, fn($b) => ($b['type'] ?? '') === 'supervision' && ($b['modalidad'] ?? '') === 'Remota');
    $check('la línea de tiempo la muestra como remota', count($remotas) === 1, $bloques);
} finally {
    $cleanup();
    @unlink($foto);
}
echo "\n$ok correctas, $fail con error. Usuarios, turno, supervisiones y captura de prueba eliminados.\n";
exit($fail ? 1 : 0);
