<?php
// 05_TEST/integration/matriz_asistencia_test.php - (2026-10-28) Matriz de asistencia mensual de los supervisores directos:
//   - A = inició turno; licencias aprobadas con su código (V, SUB, LSG…); D = domingo; FT = día pasado sin nada; antes del
//     alta en el sistema, vacío; desde la fecha de cese, C (y el cesado sale de los meses siguientes);
//   - el administrador corrige un día (queda marcado) y lo devuelve a lo calculado; el supervisor no ve la matriz;
//   - la descarga en Excel; al desactivar a un usuario se anota su fecha de cese y al reactivarlo se borra.
// Por HTTP contra la API real. Mes de prueba: septiembre de 2026 (pasado). Zona AREQUIPA. Crea usuarios DNI 99999951-53;
// BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/matriz_asistencia_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/asistencia_matriz.php';
$pdo = Database::getConnection();

$dnis = ['99999951', '99999952', '99999953'];
$frascos = [];
$cleanup = function () use ($pdo, $dnis, &$frascos) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        foreach (['asistencia_ajustes' => 'supervisor_id', 'ausencias_supervisores' => 'supervisor_id', 'asistencia_supervisores' => 'supervisor_id',
            'notificaciones' => 'usuario_id', 'usuario_departamentos_acceso' => 'usuario_id', 'usuario_permisos' => 'usuario_id'] as $t => $c) $pdo->exec("DELETE FROM $t WHERE $c IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['mas.admin', 'mas.uno', 'mas.dos'] as $u) foreach (['::1', '127.0.0.1'] as $ip)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
echo "Matriz de asistencia — $base\n";

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'MasPrueba#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $nuevo = function (string $dni, string $usuario, string $rol, string $alta) use ($svc, $admin, $clave, $pdo): int {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Matriz', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => 'directo', 'zonas' => ['AREQUIPA'], 'password' => $clave], 0, $admin)['id'];
        $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 0, activo = 1, por_activar = 0, fecha_creacion = :a WHERE id = :id')->execute([':a' => $alta, ':id' => $id]);
        return $id;
    };
    $ids = ['admin' => $nuevo('99999951', 'mas.admin', 'Administrador', '2026-01-01 08:00:00'),
        'uno' => $nuevo('99999952', 'mas.uno', 'Supervisor', '2026-08-01 08:00:00'), 'dos' => $nuevo('99999953', 'mas.dos', 'Supervisor', '2026-09-10 08:00:00')];
    // Septiembre 2026 de "uno": turno el 1 y el 2, vacaciones del 3 al 4, subsidio el 8, licencia sin goce el 9 (aprobadas) y
    // una licencia rechazada el 10 (no cuenta). "dos" entró el 10 y cesó el 20.
    foreach (['2026-09-01', '2026-09-02'] as $f) $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, fin_turno, estado_turno) VALUES (:s, :f, :i, :x, 'Fuera de turno')")
        ->execute([':s' => $ids['uno'], ':f' => $f, ':i' => "$f 08:00:00", ':x' => "$f 17:00:00"]);
    $aus = $pdo->prepare("INSERT INTO ausencias_supervisores (supervisor_id, tipo, fecha_inicio, fecha_fin, estado) VALUES (:s, :t, :i, :f, :e)");
    foreach ([['Vacaciones', '2026-09-03', '2026-09-04', 'Aprobada'], ['Subsidio', '2026-09-08', '2026-09-08', 'Aprobada'],
        ['Licencia sin goce de haber', '2026-09-09', '2026-09-09', 'Aprobada'], ['Descanso médico', '2026-09-10', '2026-09-10', 'Rechazada']] as [$t, $i, $f, $e])
        $aus->execute([':s' => $ids['uno'], ':t' => $t, ':i' => $i, ':f' => $f, ':e' => $e]);
    $pdo->prepare("UPDATE usuarios SET activo = 0, fecha_cese = '2026-09-20' WHERE id = :id")->execute([':id' => $ids['dos']]);

    $viewer = $pdo->query("SELECT * FROM usuarios WHERE id = {$ids['admin']}")->fetch(PDO::FETCH_ASSOC);
    $m = sgsMatrizAsistencia($pdo, $viewer, '2026-09', 'AREQUIPA');
    $fila = array_column($m['filas'], null, 'id');
    $c = fn(string $quien, string $f) => $fila[$ids[$quien]]['celdas'][$f]['codigo'] ?? '?';
    $check('el mes tiene sus 30 días', count($m['dias']) === 30, count($m['dias']));
    $check('inició turno → A', $c('uno', '2026-09-01') === 'A' && $c('uno', '2026-09-02') === 'A', [$c('uno', '2026-09-01'), $c('uno', '2026-09-02')]);
    $check('vacaciones aprobadas → V en cada día', $c('uno', '2026-09-03') === 'V' && $c('uno', '2026-09-04') === 'V');
    $check('subsidio → SUB y licencia sin goce → LSG', $c('uno', '2026-09-08') === 'SUB' && $c('uno', '2026-09-09') === 'LSG');
    $check('una licencia rechazada no cuenta: día pasado sin turno → FT', $c('uno', '2026-09-10') === 'FT', $c('uno', '2026-09-10'));
    $check('domingo → D', $c('uno', '2026-09-06') === 'D' && $c('uno', '2026-09-13') === 'D', [$c('uno', '2026-09-06'), $c('uno', '2026-09-13')]);
    $check('día laborable pasado sin nada → FT', $c('uno', '2026-09-07') === 'FT', $c('uno', '2026-09-07'));
    $check('antes de su alta en el sistema → vacío', $c('dos', '2026-09-09') === '' && $c('dos', '2026-09-11') === 'FT', [$c('dos', '2026-09-09'), $c('dos', '2026-09-11')]);
    $check('desde su fecha de cese → C', $c('dos', '2026-09-19') === 'FT' && $c('dos', '2026-09-20') === 'C' && $c('dos', '2026-09-30') === 'C');
    $check('totales por código', $fila[$ids['uno']]['totales']['A'] === 2 && $fila[$ids['uno']]['totales']['V'] === 2 && $fila[$ids['uno']]['totales']['D'] === 4, $fila[$ids['uno']]['totales']);
    $m10 = sgsMatrizAsistencia($pdo, $viewer, '2026-10', 'AREQUIPA');
    $check('el cesado ya no sale en los meses siguientes', !in_array($ids['dos'], array_column($m10['filas'], 'id'), true) && in_array($ids['uno'], array_column($m10['filas'], 'id'), true));
    $check('el filtro de zona saca a quien no trabaja esa zona', !in_array($ids['uno'], array_column(sgsMatrizAsistencia($pdo, $viewer, '2026-09', 'PIURA')['filas'], 'id'), true));

    // Por la API: permisos, corrección y Excel
    $sesion = function (string $usuario) use ($base, $clave, &$frascos): callable {
        $frasco = tempnam(sys_get_temp_dir(), 'sgsm'); $frascos[] = $frasco;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $mm); unset($ch);
        $csrf = $mm[1] ?? '';
        return function (string $url, ?array $post = null) use ($frasco, $csrf): array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf]];
            curl_setopt_array($ch, $o); $b = (string)curl_exec($ch);
            $r = ['_http' => curl_getinfo($ch, CURLINFO_HTTP_CODE), '_tipo' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), '_largo' => strlen($b)]; unset($ch);
            return (json_decode($b, true) ?? []) + $r;
        };
    };
    $api = "$base/api/asistencia_matriz.php";
    $adm = $sesion('mas.admin'); $sup = $sesion('mas.uno');
    $r = $sup("$api?action=matriz&mes=2026-09");
    $check('un supervisor no ve la matriz', $r['_http'] === 403, $r['_http']);
    $r = $sup("$api?action=ajustar", ['supervisor_id' => $ids['uno'], 'fecha' => '2026-09-07', 'codigo' => 'LG']);
    $check('un supervisor no corrige la matriz', $r['_http'] === 403, $r['_http']);
    $r = $adm("$api?action=matriz&mes=2026-09&zona=AREQUIPA");
    $check('el administrador ve la matriz por la API', ($r['status'] ?? '') === 'success' && count($r['data']['filas'] ?? []) >= 2, $r['message'] ?? $r['_http']);
    $r = $adm("$api?action=ajustar", ['supervisor_id' => $ids['uno'], 'fecha' => '2026-09-07', 'codigo' => 'XX']);
    $check('un código que no es de la leyenda se rechaza', $r['_http'] === 422, $r);
    $r = $adm("$api?action=ajustar", ['supervisor_id' => $ids['uno'], 'fecha' => '2026-09-07', 'codigo' => 'LG', 'comentario' => 'Licencia por trámite']);
    $m = sgsMatrizAsistencia($pdo, $viewer, '2026-09', 'AREQUIPA');
    $celda = array_column($m['filas'], null, 'id')[$ids['uno']]['celdas']['2026-09-07'];
    $check('el administrador corrige un día: queda LG y marcado como corregido', ($r['status'] ?? '') === 'success' && $celda['codigo'] === 'LG' && $celda['ajuste'] === 'Licencia por trámite', [$r['message'] ?? '', $celda]);
    $r = $adm("$api?action=ajustar", ['supervisor_id' => $ids['uno'], 'fecha' => '2026-09-13', 'codigo' => 'A']);
    $m = sgsMatrizAsistencia($pdo, $viewer, '2026-09', 'AREQUIPA');
    $check('un domingo trabajado se corrige a A', array_column($m['filas'], null, 'id')[$ids['uno']]['celdas']['2026-09-13']['codigo'] === 'A');
    $r = $adm("$api?action=ajustar", ['supervisor_id' => $ids['uno'], 'fecha' => '2026-09-07', 'codigo' => '']);
    $m = sgsMatrizAsistencia($pdo, $viewer, '2026-09', 'AREQUIPA');
    $celda = array_column($m['filas'], null, 'id')[$ids['uno']]['celdas']['2026-09-07'];
    $check('quitar la corrección vuelve a lo calculado (FT)', ($r['status'] ?? '') === 'success' && $celda['codigo'] === 'FT' && $celda['ajuste'] === null, $celda);
    $r = $adm("$api?action=excel&mes=2026-09&zona=AREQUIPA");
    $check('descarga la matriz en Excel', str_contains($r['_tipo'], 'spreadsheetml') && $r['_largo'] > 1000, [$r['_tipo'], $r['_largo']]);

    // Fecha de cese al desactivar y reactivar
    $svc->cambiarEstado($ids['uno'], false, $admin);
    $cese = $pdo->query("SELECT fecha_cese FROM usuarios WHERE id = {$ids['uno']}")->fetchColumn();
    $svc->cambiarEstado($ids['uno'], true, $admin);
    $ceseDespues = $pdo->query("SELECT fecha_cese FROM usuarios WHERE id = {$ids['uno']}")->fetchColumn();
    $check('al desactivar se anota la fecha de cese (hoy) y al reactivar se borra', $cese === date('Y-m-d') && $ceseDespues === null, [$cese, $ceseDespues]);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, turnos, licencias y correcciones de prueba eliminados.\n";
exit($fail ? 1 : 0);
