<?php
// 05_TEST/integration/ausencias_test.php - (2026-10-26) Vacaciones, descansos médicos y licencias de los supervisores:
//   - el supervisor directo registra; el descanso médico exige sustento; no se cruzan fechas; queda Pendiente y avisa;
//   - el sustento es privado: no se abre por la web; sí por la API para el dueño y los jefes, no para otro supervisor;
//   - el jefe aprueba / rechaza (con motivo) / anula; con una aprobada hoy no se inicia turno y el Monitor la muestra.
// Por HTTP contra la API real. Crea usuarios DNI 99999932-34; BORRA todo al final (incluidos los archivos de sustento).
// Uso (solo CLI): php 05_TEST/integration/ausencias_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
require BACKEND_PATH . '/config/ausencias.php';
$pdo = Database::getConnection();

$dnis = ['99999932', '99999933', '99999934'];
$frascos = [];
$pdf = tempnam(sys_get_temp_dir(), 'sgsa') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$cleanup = function () use ($pdo, $dnis, &$frascos, $pdf) {
    $us = $pdo->query("SELECT id FROM usuarios WHERE dni IN ('" . implode("','", $dnis) . "')")->fetchAll(PDO::FETCH_COLUMN);
    if ($us) {
        $in = implode(',', array_map('intval', $us));
        foreach ($pdo->query("SELECT sustento FROM ausencias_supervisores WHERE supervisor_id IN ($in) AND sustento IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $s) @unlink(sgsCarpetaAusencias() . '/' . $s);
        $pdo->exec("DELETE FROM ausencias_supervisores WHERE supervisor_id IN ($in)");
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id IN ($in)");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id IN ($in)");
        foreach (['notificaciones', 'usuario_departamentos_acceso', 'usuario_permisos'] as $t) $pdo->exec("DELETE FROM $t WHERE usuario_id IN ($in)");
        $pdo->exec("DELETE FROM usuarios WHERE id IN ($in)");
    }
    foreach (['aus.jefe', 'aus.sup', 'aus.otro'] as $u) foreach (['::1', '127.0.0.1'] as $ip)
        $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($frascos as $f) @unlink($f);
    @unlink($pdf);
};
$cleanup();
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $clave = 'AusPrueba#' . random_int(1000, 9999);
    $svc = new UsuarioService($pdo);
    $nuevo = function (string $dni, string $usuario, string $rol) use ($svc, $admin, $clave, $pdo): int {
        $id = (int)$svc->guardar(['dni' => $dni, 'nombres' => 'Aus', 'apellidos' => "Prueba $usuario", 'email' => "$usuario@proviwin.test", 'usuario' => $usuario,
            'rol' => $rol, 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin)['id'];
        $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $id");
        return $id;
    };
    $ids = ['jefe' => $nuevo('99999932', 'aus.jefe', 'Coordinador'), 'sup' => $nuevo('99999933', 'aus.sup', 'Supervisor'), 'otro' => $nuevo('99999934', 'aus.otro', 'Supervisor')];
    $sesion = function (string $usuario) use ($base, $clave, &$frascos): callable {
        $frasco = tempnam(sys_get_temp_dir(), 'sgsa'); $frascos[] = $frasco;
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $usuario, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        $ch = curl_init("$base/index.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco]);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string)curl_exec($ch), $m); unset($ch);
        $csrf = $m[1] ?? '';
        return function (string $url, $post = null, bool $json = false) use ($frasco, $csrf): array {
            $ch = curl_init($url);
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $frasco, CURLOPT_COOKIEFILE => $frasco];
            if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json ? json_encode($post) : $post,
                CURLOPT_HTTPHEADER => array_merge(['X-CSRF-Token: ' . $csrf], $json ? ['Content-Type: application/json'] : [])];
            curl_setopt_array($ch, $o); $b = (string)curl_exec($ch);
            $r = ['_http' => curl_getinfo($ch, CURLINFO_HTTP_CODE), '_tipo' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), '_cuerpo' => $b]; unset($ch);
            return (json_decode($b, true) ?? []) + $r;
        };
    };
    $sup = $sesion('aus.sup'); $jefe = $sesion('aus.jefe'); $otro = $sesion('aus.otro');
    $api = "$base/api/ausencias.php";
    $hoy = date('Y-m-d'); $en = fn(int $d) => date('Y-m-d', strtotime("+$d days"));

    echo "Registro del supervisor — $base\n";
    $r = $sup("$api?action=registrar", ['tipo' => 'Descanso médico', 'fecha_inicio' => $en(10), 'fecha_fin' => $en(11)]);
    $check('descanso médico sin certificado: no se registra', $r['_http'] === 422 && str_contains($r['message'] ?? '', 'certificado'), $r['message'] ?? $r['_http']);
    $r = $sup("$api?action=registrar", ['tipo' => 'Vacaciones', 'fecha_inicio' => $en(3), 'fecha_fin' => $en(1)]);
    $check('fin antes que inicio: no se registra', $r['_http'] === 422, $r['message'] ?? null);
    $r = $sup("$api?action=registrar", ['tipo' => 'Vacaciones', 'fecha_inicio' => $hoy, 'fecha_fin' => $en(2), 'comentario' => 'Vacaciones programadas']);
    $check('vacaciones de hoy a 2 días: quedan Pendientes', ($r['status'] ?? '') === 'success', $r['message'] ?? null);
    $r = $sup("$api?action=registrar", ['tipo' => 'Descanso médico', 'fecha_inicio' => $en(1), 'fecha_fin' => $en(5), 'sustento' => new CURLFile($pdf, 'application/pdf', 'certificado.pdf')]);
    $check('fechas que se cruzan con otra solicitud: no se registra', $r['_http'] === 409, $r['message'] ?? null);
    $r = $sup("$api?action=registrar", ['tipo' => 'Descanso médico', 'fecha_inicio' => $en(10), 'fecha_fin' => $en(11), 'sustento' => new CURLFile($pdf, 'application/pdf', 'certificado.pdf')]);
    $check('descanso médico con certificado PDF: se registra', ($r['status'] ?? '') === 'success', $r['message'] ?? null);
    $avisos = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = {$ids['jefe']} AND titulo = 'Solicitud de ausencia'")->fetchColumn();
    $check('los jefes reciben el aviso en la campana', $avisos === 2, $avisos);
    $mias = $sup("$api?action=mias");
    $check('el supervisor ve sus 2 solicitudes, sin ausencia aprobada hoy', count($mias['data'] ?? []) === 2 && $mias['hoy'] === null, $mias);
    $check('un supervisor no ve la lista de los jefes', $sup("$api?action=lista")['_http'] === 403);

    echo "Sustento privado\n";
    $medico = (int)$pdo->query("SELECT id FROM ausencias_supervisores WHERE supervisor_id = {$ids['sup']} AND tipo = 'Descanso médico'")->fetchColumn();
    $archivo = (string)$pdo->query("SELECT sustento FROM ausencias_supervisores WHERE id = $medico")->fetchColumn();
    $ch = curl_init("$base/11_PRIVADO/ausencias/$archivo"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true]); curl_exec($ch);
    $web = curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    $check('el archivo no se abre por la web (403)', $web === 403, $web);
    $r = $sup("$api?action=sustento&id=$medico");
    $check('el dueño lo abre por el sistema (PDF)', $r['_http'] === 200 && str_starts_with($r['_tipo'], 'application/pdf') && str_starts_with($r['_cuerpo'], '%PDF'), [$r['_http'], $r['_tipo']]);
    $check('el jefe también lo abre', $jefe("$api?action=sustento&id=$medico")['_http'] === 200);
    $check('otro supervisor no lo abre', $otro("$api?action=sustento&id=$medico")['_http'] === 404);

    echo "Decisión de los jefes\n";
    $lista = $jefe("$api?action=lista&estado=Pendiente");
    $check('el jefe ve las 2 por aprobar', ($lista['pendientes'] ?? 0) >= 2 && count(array_filter($lista['data'] ?? [], fn($a) => (int)$a['supervisor_id'] === $ids['sup'])) === 2, $lista['pendientes'] ?? $lista);
    $vac = (int)$pdo->query("SELECT id FROM ausencias_supervisores WHERE supervisor_id = {$ids['sup']} AND tipo = 'Vacaciones'")->fetchColumn();
    $r = $jefe("$api?action=decidir", ['id' => $medico, 'decision' => 'Rechazada']);
    $check('rechazar sin motivo: no se puede', $r['_http'] === 422, $r['message'] ?? null);
    $r = $jefe("$api?action=decidir", ['id' => $medico, 'decision' => 'Rechazada', 'motivo' => 'Certificado ilegible']);
    $check('rechazar con motivo: Rechazada y el supervisor recibe el aviso', ($r['status'] ?? '') === 'success'
        && (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = {$ids['sup']} AND titulo = 'Ausencia Rechazada'")->fetchColumn() === 1, $r['message'] ?? null);
    $r = $jefe("$api?action=decidir", ['id' => $vac, 'decision' => 'Aprobada']);
    $check('aprobar las vacaciones', ($r['status'] ?? '') === 'success', $r['message'] ?? null);
    $mias = $sup("$api?action=mias");
    $check('el supervisor ve que hoy está de vacaciones', str_contains((string)($mias['hoy'] ?? ''), 'Vacaciones'), $mias['hoy'] ?? null);
    $r = $sup("$api?action=anular", ['id' => $vac]);
    $check('el supervisor no puede anular una ya aprobada', $r['_http'] === 409, $r['message'] ?? null);

    echo "Turno y Monitor de turnos\n";
    $r = $sup("$base/api/asistencia.php?action=start", ['latitud' => -5.19, 'longitud' => -80.63], true);
    $check('con vacaciones aprobadas hoy no se inicia turno', $r['_http'] === 409 && str_contains($r['message'] ?? '', 'Vacaciones')
        && !(int)$pdo->query("SELECT COUNT(*) FROM asistencia_supervisores WHERE supervisor_id = {$ids['sup']}")->fetchColumn(), $r['message'] ?? $r['_http']);
    $mon = $jefe("$base/api/asistencia.php?action=admin_summary&fecha=$hoy");
    $fila = array_values(array_filter($mon['reporte'] ?? $mon['data']['reporte'] ?? [], fn($x) => (int)($x['supervisor']['id'] ?? 0) === $ids['sup']))[0] ?? null;
    $check('el Monitor lo muestra "Vacaciones", no "Sin iniciar"', $fila && $fila['estado_actual'] === 'Vacaciones' && str_contains((string)$fila['ausencia'], 'Vacaciones'), $fila);
    $r = $jefe("$api?action=decidir", ['id' => $vac, 'decision' => 'Anulada', 'motivo' => 'Regresó antes']);
    $r2 = $sup("$base/api/asistencia.php?action=start", ['latitud' => -5.19, 'longitud' => -80.63], true);
    $check('anulada por el jefe: ya puede iniciar turno', ($r['status'] ?? '') === 'success' && ($r2['status'] ?? '') === 'success', [$r['message'] ?? null, $r2['message'] ?? null]);
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, solicitudes, turnos y archivos de prueba eliminados.\n";
exit($fail ? 1 : 0);
