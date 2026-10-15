<?php
// 05_TEST/integration/timeline_horas_test.php - Línea de tiempo por horas: la API timeline_diaria envía el inicio y el fin
// reales (segundos) del turno y de cada bloque (supervisión, traslado, almuerzo, trabajo remoto) para dibujarlos a escala.
// Por HTTP contra la API real. Usa el día de AYER para no mezclarse con la jornada en curso.
// Crea un administrador (DNI 99999981), dos supervisores directos (99999982-83), sus turnos, pausas y supervisiones
// (PRUEBA-TLH-*); BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/timeline_horas_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$dnis = ['99999981', '99999982', '99999983'];
$jar = tempnam(sys_get_temp_dir(), 'sgst');
$cleanup = function () use ($pdo, $jar, $dnis) {
    $pdo->exec("DELETE FROM supervisiones WHERE ticket_codigo LIKE 'PRUEBA-TLH-%'");
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
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|tlh.admin")]);
    @unlink($jar);
};
$cleanup();

$admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
$partner = (int)$pdo->query("SELECT id FROM partners WHERE estado=1 AND UPPER(TRIM(nombre_partner)) NOT IN ('WIN','WIN DIRECTO') ORDER BY id LIMIT 1")->fetchColumn();
$clave = 'TlhPrueba' . random_int(1000, 9999);
$dia = date('Y-m-d', strtotime('-1 day'));
$ts = fn(string $hora) => strtotime("$dia $hora");
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Línea de tiempo por horas ($dia) — $base\n";

try {
    $svc = new UsuarioService($pdo);
    $nuevo = fn(string $dni, string $usuario, string $apellido, string $rol) => (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Tlh', 'apellidos' => $apellido,
        'email' => "$usuario@proviwin.test", 'usuario' => $usuario, 'rol' => $rol, 'organizacion' => 'directo', 'zonas' => ['LAMBAYEQUE'], 'password' => $clave], 0, $admin)['id'];
    $nuevo('99999981', 'tlh.admin', 'Admin Prueba', 'Administrador');
    $d1 = $nuevo('99999982', 'tlh.uno', 'Supervisor Uno', 'Supervisor');
    $d2 = $nuevo('99999983', 'tlh.dos', 'Supervisor Dos', 'Supervisor');
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='LAMBAYEQUE' LIMIT 1")->fetch();

    $turno = function (int $sup, string $ini, ?string $fin) use ($pdo, $dia): int {
        $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, fin_turno, estado_turno) VALUES (:s, :f, :i, :fin, :e)")
            ->execute([':s' => $sup, ':f' => $dia, ':i' => "$dia $ini", ':fin' => $fin ? "$dia $fin" : null, ':e' => $fin ? 'Fuera de turno' : 'En turno']);
        return (int)$pdo->lastInsertId();
    };
    $evento = function (int $asis, string $tipo, string $ini, string $fin, ?string $nota = null) use ($pdo, $dia) {
        $min = (int)round((strtotime("$dia $fin") - strtotime("$dia $ini")) / 60);
        $pdo->prepare("INSERT INTO asistencia_eventos (asistencia_id, tipo_evento, inicio_evento, fin_evento, duracion_minutos, nota) VALUES (:a, :t, :i, :f, :m, :n)")
            ->execute([':a' => $asis, ':t' => $tipo, ':i' => "$dia $ini", ':f' => "$dia $fin", ':m' => $min, ':n' => $nota]);
    };
    $sup = function (int $supervisor, string $suf, string $ini, ?string $fin) use ($pdo, $dia, $partner, $ubi): int {
        $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision, tecnico1_nombre)
            VALUES (:code, :ini, :sup, 'Líder', 'Regular', :dep, :prov, :dist, :ticket, 'En caliente', :partner, 'Ninguno', 'Instalación', 'Instalación', '', :estado, 'Técnico Prueba')")
            ->execute([':code' => 'SUP-TLH-' . $suf, ':ini' => "$dia $ini", ':sup' => $supervisor, ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'],
                ':ticket' => 'PRUEBA-TLH-' . $suf, ':partner' => $partner, ':estado' => $fin ? 'Finalizada' : 'En proceso']);
        $id = (int)$pdo->lastInsertId();
        if ($fin) $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, fecha_cambio) VALUES (:s, 'En proceso', 'Finalizada', :f)")
            ->execute([':s' => $id, ':f' => "$dia $fin"]);
        return $id;
    };
    // Supervisor Uno: turno 08:00–18:00; supervisiones 09:00–10:30 y 11:00–12:00; almuerzo 13:00–14:00; remoto 15:00–16:30
    $a1 = $turno($d1, '08:00:00', '18:00:00');
    $sA = $sup($d1, 'A', '09:00:00', '10:30:00');
    $sup($d1, 'B', '11:00:00', '12:00:00');
    $evento($a1, 'almuerzo', '13:00:00', '14:00:00');
    $evento($a1, 'remoto', '15:00:00', '16:30:00', 'Informe');
    // Supervisor Dos: turno 07:30 sin cerrar (el sistema lo cierra a las 23:59); supervisión sin finalizar desde 08:15; reunión 10:00–10:45
    $a2 = $turno($d2, '07:30:00', null);
    $sup($d2, 'C', '08:15:00', null);
    $evento($a2, 'reunion', '10:00:00', '10:45:00');

    $http = function (string $url, array $opts = []) use ($jar): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60] + $opts);
        $body = (string)curl_exec($ch); curl_close($ch);
        return json_decode($body, true);
    };
    $http("$base/api/auth.php?action=login", [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['usuario' => 'tlh.admin', 'password' => $clave])]);
    $r = $http("$base/api/asistencia.php?action=timeline_diaria&fecha=$dia");
    $check('responde con la hora del servidor, no es hoy y el administrador puede reabrir', ($r['status'] ?? '') === 'success' && is_int($r['ahora'] ?? null) && ($r['es_hoy'] ?? true) === false && ($r['puede_reabrir'] ?? false) === true, array_diff_key($r ?? [], ['supervisores' => 1]));
    $porId = array_column($r['supervisores'] ?? [], null, 'supervisor_id');
    $u1 = $porId[$d1] ?? null; $u2 = $porId[$d2] ?? null;
    $check('turno de Uno: 08:00 a 18:00 en segundos reales', $u1 && ($u1['turno_ts']['inicio'] ?? 0) === $ts('08:00') && ($u1['turno_ts']['fin'] ?? 0) === $ts('18:00'), $u1['turno_ts'] ?? null);
    // Un turno de un día pasado que quedó abierto lo cierra el sistema a las 23:59 (tarea periódica: puede no haber corrido aún)
    $check('turno de Dos (quedó abierto ayer): sin fin, o cerrado por el sistema a las 23:59', $u2 && ($u2['turno_ts']['inicio'] ?? 0) === $ts('07:30')
        && array_key_exists('fin', $u2['turno_ts'] ?? []) && in_array($u2['turno_ts']['fin'], [null, $ts('23:59')], true), $u2['turno_ts'] ?? null);
    $b1 = array_values(array_filter($u1['timeline_blocks'] ?? [], fn($b) => isset($b['ts_inicio'])));
    $tipos = array_column($b1, 'type');
    $check('Uno: supervisión, traslado, supervisión, almuerzo y remoto, en orden', $tipos === ['supervision', 'travel', 'supervision', 'pause', 'remote'], $tipos);
    $check('supervisión A: 09:00 a 10:30, con su ticket e id', ($b1[0]['ts_inicio'] ?? 0) === $ts('09:00') && ($b1[0]['ts_fin'] ?? 0) === $ts('10:30') && ($b1[0]['ticket'] ?? '') === 'PRUEBA-TLH-A' && (int)($b1[0]['id'] ?? 0) === $sA, $b1[0] ?? null);
    $check('traslado: del fin de A (10:30) al inicio de B (11:00)', ($b1[1]['ts_inicio'] ?? 0) === $ts('10:30') && ($b1[1]['ts_fin'] ?? 0) === $ts('11:00') && ($b1[1]['duracion_minutos'] ?? 0) === 30, $b1[1] ?? null);
    $check('almuerzo 13:00–14:00 y remoto 15:00–16:30', ($b1[3]['ts_inicio'] ?? 0) === $ts('13:00') && ($b1[3]['ts_fin'] ?? 0) === $ts('14:00') && ($b1[3]['tipo_evento'] ?? '') === 'almuerzo'
        && ($b1[4]['ts_inicio'] ?? 0) === $ts('15:00') && ($b1[4]['ts_fin'] ?? 0) === $ts('16:30'), [$b1[3] ?? null, $b1[4] ?? null]);
    $b2 = array_values(array_filter($u2['timeline_blocks'] ?? [], fn($b) => isset($b['ts_inicio'])));
    $c = array_values(array_filter($b2, fn($b) => $b['type'] === 'supervision'))[0] ?? null;
    $check('Dos: supervisión sin finalizar (sin fin) y reunión 10:00–10:45', $c && $c['estado'] === 'en_curso' && $c['ts_fin'] === null
        && count(array_filter($b2, fn($b) => $b['type'] === 'pause' && $b['ts_inicio'] === $ts('10:00') && $b['ts_fin'] === $ts('10:45'))) === 1, $b2);
    $check('los bloques de inicio y fin de turno no llevan horas de escala (se dibuja la franja del turno)', !array_filter($u1['timeline_blocks'] ?? [], fn($b) => in_array($b['type'], ['shift_start', 'shift_end'], true) && isset($b['ts_inicio'])));
} finally {
    if (getenv('SGS_KEEP')) echo "datos conservados para revisar la pantalla\n"; else $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, turnos y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
