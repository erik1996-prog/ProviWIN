<?php
// 05_TEST/integration/timeline_horas_test.php - Línea de tiempo por horas: la API timeline_diaria envía el inicio y el fin
// reales (segundos) del turno y de cada bloque (supervisión, traslado, almuerzo, trabajo remoto) para dibujarlos a escala.
// 2026-10-20: el tramo del inicio del turno a la primera actividad, y la lluvia por zona (api/lluvias.php): no cambia la
// actividad, anota qué hacía quien avisó y quien cerró, no se duplica, se cierra al terminar el turno de quien la avisó,
// sale en la línea de tiempo de su zona y en el indicador de lluvias (api/dashboard_lluvias.php).
// Zona de prueba: AREQUIPA (no la del supervisor local de pruebas, para no cerrarle su lluvia).
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
        'email' => "$usuario@proviwin.test", 'usuario' => $usuario, 'rol' => $rol, 'organizacion' => 'directo', 'zonas' => ['AREQUIPA'], 'password' => $clave], 0, $admin)['id'];
    $nuevo('99999981', 'tlh.admin', 'Admin Prueba', 'Administrador');
    $d1 = $nuevo('99999982', 'tlh.uno', 'Supervisor Uno', 'Supervisor');
    $d2 = $nuevo('99999983', 'tlh.dos', 'Supervisor Dos', 'Supervisor');
    $ubi = $pdo->query("SELECT departamento, provincia, distrito FROM ubigeo WHERE departamento='AREQUIPA' LIMIT 1")->fetch();

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
    // Lluvia en AREQUIPA (la zona de los dos) de 09:30 a 10:00
    $pdo->prepare("INSERT INTO lluvias_zona (departamento, inicio, fin, reportado_por) VALUES ('AREQUIPA', :i, :f, :u)")->execute([':i' => "$dia 09:30:00", ':f' => "$dia 10:00:00", ':u' => $d1]);

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
    $primera = fn($u) => array_values(array_filter($u['timeline_blocks'] ?? [], fn($b) => !empty($b['primera'])))[0] ?? null;
    $p1 = $primera($u1); $p2 = $primera($u2);
    $check('Uno: del inicio del turno (08:00) a su 1.ª actividad (09:00) se cuenta: 60 min', $p1 && $p1['type'] === 'travel' && $p1['ts_inicio'] === $ts('08:00') && $p1['ts_fin'] === $ts('09:00') && $p1['duracion_minutos'] === 60, $p1);
    $check('Dos: de 07:30 a su 1.ª supervisión (08:15): 45 min', $p2 && $p2['ts_inicio'] === $ts('07:30') && $p2['ts_fin'] === $ts('08:15') && $p2['duracion_minutos'] === 45, $p2);
    $b1 = array_values(array_filter($u1['timeline_blocks'] ?? [], fn($b) => isset($b['ts_inicio']) && empty($b['primera'])));
    $tipos = array_column($b1, 'type');
    $check('Uno: supervisión, traslado, supervisión, almuerzo y remoto, en orden', $tipos === ['supervision', 'travel', 'supervision', 'pause', 'remote'], $tipos);
    $check('supervisión A: 09:00 a 10:30, con su ticket e id', ($b1[0]['ts_inicio'] ?? 0) === $ts('09:00') && ($b1[0]['ts_fin'] ?? 0) === $ts('10:30') && ($b1[0]['ticket'] ?? '') === 'PRUEBA-TLH-A' && (int)($b1[0]['id'] ?? 0) === $sA, $b1[0] ?? null);
    $check('traslado: del fin de A (10:30) al inicio de B (11:00)', ($b1[1]['ts_inicio'] ?? 0) === $ts('10:30') && ($b1[1]['ts_fin'] ?? 0) === $ts('11:00') && ($b1[1]['duracion_minutos'] ?? 0) === 30, $b1[1] ?? null);
    $check('almuerzo 13:00–14:00 y remoto 15:00–16:30', ($b1[3]['ts_inicio'] ?? 0) === $ts('13:00') && ($b1[3]['ts_fin'] ?? 0) === $ts('14:00') && ($b1[3]['tipo_evento'] ?? '') === 'almuerzo'
        && ($b1[4]['ts_inicio'] ?? 0) === $ts('15:00') && ($b1[4]['ts_fin'] ?? 0) === $ts('16:30'), [$b1[3] ?? null, $b1[4] ?? null]);
    $b2 = array_values(array_filter($u2['timeline_blocks'] ?? [], fn($b) => isset($b['ts_inicio']) && empty($b['primera'])));
    $c = array_values(array_filter($b2, fn($b) => $b['type'] === 'supervision'))[0] ?? null;
    $check('Dos: supervisión sin finalizar (sin fin) y reunión 10:00–10:45', $c && $c['estado'] === 'en_curso' && $c['ts_fin'] === null
        && count(array_filter($b2, fn($b) => $b['type'] === 'pause' && $b['ts_inicio'] === $ts('10:00') && $b['ts_fin'] === $ts('10:45'))) === 1, $b2);
    $check('lluvia: los dos (misma zona) la tienen de 09:30 a 10:00', ($u1['lluvias'][0]['ts_inicio'] ?? 0) === $ts('09:30') && ($u1['lluvias'][0]['ts_fin'] ?? 0) === $ts('10:00')
        && ($u1['lluvias'][0]['zona'] ?? '') === 'AREQUIPA' && count($u2['lluvias'] ?? []) === 1, [$u1['lluvias'] ?? null, $u2['lluvias'] ?? null]);

    // Aviso de lluvia desde Mi turno (hoy)
    $uno = tempnam(sys_get_temp_dir(), 'sgsl'); $dos = tempnam(sys_get_temp_dir(), 'sgsl');
    $como = function (string $frasco, string $usuario) use ($base, $clave): callable {
        $llamar = function (string $url, ?array $post = null) use ($frasco, &$csrf): ?array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco, CURLOPT_TIMEOUT => 60];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf]];
            curl_setopt_array($ch, $o); $body = (string)curl_exec($ch); curl_close($ch);
            return json_decode($body, true);
        };
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $clave])]);
        curl_exec($ch); curl_close($ch);
        $ch = curl_init("$base/index.php"); // el token CSRF viene en la página
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); curl_close($ch);
        $csrf = $m[1] ?? '';
        return $llamar;
    };
    $l1 = $como($uno, 'tlh.uno'); $l2 = $como($dos, 'tlh.dos');
    $r = $l1("$base/api/lluvias.php?action=avisar", ['zona' => 'AREQUIPA']);
    $check('sin turno abierto hoy no se avisa la lluvia', ($r['status'] ?? '') === 'error', $r);
    $hoy = date('Y-m-d');
    foreach ([$d1, $d2] as $x) $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno) VALUES (:s, :f, NOW(), 'En turno')")->execute([':s' => $x, ':f' => $hoy]);
    $r = $l1("$base/api/lluvias.php?action=avisar", ['zona' => 'PIURA']);
    $check('no se avisa en una zona que no es suya', ($r['status'] ?? '') === 'error', $r);
    // Uno está supervisando cuando empieza a llover: la lluvia no cambia su actividad y anota qué hacía
    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
        ticket_codigo, tipo_supervision_inicial, partner_id, brevete_poseedor, clasificacion_supervision, area_servicio, observacion_servicio, estado_supervision, tecnico1_nombre)
        VALUES ('SUP-TLH-HOY', NOW(), :sup, 'Líder', 'Regular', :dep, :prov, :dist, 'PRUEBA-TLH-HOY', 'En caliente', :partner, 'Ninguno', 'Instalación', 'Instalación', '', 'En proceso', 'Técnico Prueba')")
        ->execute([':sup' => $d1, ':dep' => $ubi['departamento'], ':prov' => $ubi['provincia'], ':dist' => $ubi['distrito'], ':partner' => $partner]);
    $r = $l1("$base/api/lluvias.php?action=avisar", ['zona' => 'AREQUIPA']);
    $ll = $pdo->query("SELECT * FROM lluvias_zona WHERE departamento = 'AREQUIPA' AND fin IS NULL")->fetch(PDO::FETCH_ASSOC) ?: [];
    $eventos = (int)$pdo->query("SELECT COUNT(*) FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $d1 AND a.fecha = CURDATE()")->fetchColumn();
    $check('Uno avisa la lluvia supervisando: se anota "Supervisando PRUEBA-TLH-HOY" y su actividad no cambia', ($r['status'] ?? '') === 'success'
        && isset($r['data']['abiertas']['AREQUIPA']) && ($ll['comentario_inicio'] ?? '') === 'Supervisando PRUEBA-TLH-HOY' && $eventos === 0, [$r, $ll, $eventos]);
    $r = $l2("$base/api/lluvias.php?action=avisar", ['zona' => 'AREQUIPA']);
    $abiertas = (int)$pdo->query("SELECT COUNT(*) FROM lluvias_zona WHERE departamento = 'AREQUIPA' AND fin IS NULL")->fetchColumn();
    $check('Dos (misma zona) la ve ya avisada por Uno y no se duplica', ($r['status'] ?? '') === 'success' && str_contains($r['message'] ?? '', 'Ya estaba') && $abiertas === 1, [$r, $abiertas]);
    $tl = $http("$base/api/asistencia.php?action=timeline_diaria");
    $hoyU2 = array_column($tl['supervisores'] ?? [], null, 'supervisor_id')[$d2] ?? [];
    $abiertaTl = array_values(array_filter($hoyU2['lluvias'] ?? [], fn($l) => $l['ts_fin'] === null))[0] ?? null;
    $check('línea de tiempo de hoy: Dos tiene la lluvia abierta de su zona, con quién la avisó y qué hacía', $abiertaTl
        && str_contains($abiertaTl['aviso'] ?? '', 'Supervisor Uno') && ($abiertaTl['comentario_inicio'] ?? '') === 'Supervisando PRUEBA-TLH-HOY', $hoyU2['lluvias'] ?? null);
    $l2("$base/api/asistencia.php?action=start_event", ['tipo_evento' => 'almuerzo']);
    $r = $l2("$base/api/lluvias.php?action=paro", ['zona' => 'AREQUIPA']);
    $ll = $pdo->query("SELECT * FROM lluvias_zona WHERE id = " . (int)($ll['id'] ?? 0))->fetch(PDO::FETCH_ASSOC) ?: [];
    $check('Dos la cierra en su almuerzo: fin con "En almuerzo" y él como quien cerró', ($r['status'] ?? '') === 'success' && empty($r['data']['abiertas'])
        && $ll['fin'] !== null && (int)$ll['cerrado_por'] === $d2 && $ll['comentario_fin'] === 'En almuerzo', [$r, $ll]);
    $ind = $http("$base/api/dashboard_lluvias.php?action=dashboard&mes=" . date('Y-m') . '&zona=AREQUIPA');
    $fila = array_values(array_filter($ind['data']['bitacora'] ?? [], fn($x) => (int)$x['id'] === (int)($ll['id'] ?? 0)))[0] ?? null;
    $check('indicador de lluvias: el día cuenta y la bitácora trae quién avisó y quién cerró', ($ind['data']['kpis']['dias'] ?? 0) >= 1 && isset($ind['data']['dias'][date('Y-m-d')])
        && $fila && str_contains($fila['aviso'], 'Supervisor Uno') && str_contains((string)$fila['cerro'], 'Supervisor Dos') && $fila['comentario_fin'] === 'En almuerzo', [$ind['data']['kpis'] ?? $ind, $fila]);
    $r = $l1("$base/api/dashboard_lluvias.php?action=dashboard");
    $check('el supervisor directo también ve el indicador (de su zona)', ($r['status'] ?? '') === 'success', $r);
    $l1("$base/api/lluvias.php?action=avisar", ['zona' => 'AREQUIPA']);
    $l1("$base/api/asistencia.php?action=end", []);
    $abiertas = (int)$pdo->query("SELECT COUNT(*) FROM lluvias_zona WHERE departamento = 'AREQUIPA' AND fin IS NULL")->fetchColumn();
    $auto = $pdo->query("SELECT comentario_fin FROM lluvias_zona WHERE reportado_por = $d1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $check('si nadie la cierra, se cierra al terminar el turno quien la avisó', $abiertas === 0 && $auto === 'Cierre automático al terminar su turno', [$abiertas, $auto]);
    @unlink($uno); @unlink($dos);
    $check('los bloques de inicio y fin de turno no llevan horas de escala (se dibuja la franja del turno)', !array_filter($u1['timeline_blocks'] ?? [], fn($b) => in_array($b['type'], ['shift_start', 'shift_end'], true) && isset($b['ts_inicio'])));
} finally {
    if (getenv('SGS_KEEP')) echo "datos conservados para revisar la pantalla\n"; else $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, turnos y supervisiones de prueba eliminados.\n";
exit($fail ? 1 : 0);
