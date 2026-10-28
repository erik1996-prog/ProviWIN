<?php
// 05_TEST/integration/dashboard_pendientes_test.php - (2026-10-20) Dashboard "Pendientes y rescates".
//   - estados: pendiente, en validación, en rescate, rescatada y cancelación correcta; % de rescate y antigüedad;
//   - el supervisor directo solo ve su zona; tabla por páginas con filtro y búsqueda; Excel; pantalla y menú.
// Crea el usuario DNI 99999932 y cancelaciones SUP-PRUEBA-DPD*; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/dashboard_pendientes_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'DpdPrueba' . random_int(1000, 9999);
$u = 'dpd.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $pdo->exec("DELETE FROM supervisiones WHERE codigo_supervision LIKE 'SUP-PRUEBA-DPD%'");
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999932'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999932', 'nombres' => 'Dpd', 'apellidos' => 'Sup Prueba', 'email' => 'dpd.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $sup = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999932'")->fetchColumn();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();
    $tip = $pdo->query('SELECT descripcion FROM tipificaciones_cancelacion ORDER BY 1 LIMIT 1')->fetchColumn() ?: null;
    $ins = $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, fecha_carga, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            tipo_supervision_inicial, clasificacion_supervision, area_servicio, estado_supervision, ticket_codigo, partner_id, es_cancelacion, origen_importacion, tipificacion_cancelacion,
            estado_cancelacion, rescate_estado, rescate_responsable_id, rescate_inicio, rescate_fin)
        VALUES (:c, NOW(), NOW() - INTERVAL :dias DAY, :s, 'Autogestión', 'Regular', :dep, :dep2, :dep3, 'En frío', 'Instalación', 'Instalación', :estado, :t, :p, 1, 1, :tip,
            :ec, :re, :rr, :ri, :rf)");
    $nueva = function (string $codigo, string $estado, int $dias, string $dep = 'PIURA', ?string $ec = null, ?string $re = null, bool $fin = false) use ($ins, $pdo, $sup, $admin, $partner, $tip): int {
        $ins->execute([':c' => "SUP-PRUEBA-DPD-$codigo", ':dias' => $dias, ':s' => $estado === 'Pendiente' ? $admin : $sup, ':dep' => $dep, ':dep2' => $dep, ':dep3' => $dep,
            ':estado' => $estado, ':t' => "ZZDPD$codigo", ':p' => $partner, ':tip' => $tip, ':ec' => $ec, ':re' => $re, ':rr' => $re ? $sup : null,
            ':ri' => $re ? date('Y-m-d H:i:s', strtotime("-$dias days")) : null, ':rf' => $fin ? date('Y-m-d H:i:s') : null]);
        return (int)$pdo->lastInsertId();
    };
    $nueva('1', 'Pendiente', 5);                                             // pendiente, más de 3 días
    $nueva('2', 'Pendiente', 0);                                             // pendiente, menos de 24 h
    $nueva('3', 'En proceso', 1);                                            // en validación
    $nueva('4', 'Pendiente', 2, 'PIURA', 'En proceso de rescate', 'En rescate'); // en rescate
    $nueva('5', 'Finalizada', 2, 'PIURA', 'Rescatado', 'Rescatado', true);      // rescatada (cerrada en ~48 h)
    $correcta = $nueva('6', 'Finalizada', 1, 'PIURA', 'Cancelación correcta'); // cancelación correcta (cierre por historial)
    $pdo->prepare("INSERT INTO supervision_estado_historial (supervision_id, estado_anterior, estado_nuevo, cambiado_por, fecha_cambio) VALUES (:s, 'En proceso', 'Finalizada', :u, NOW())")
        ->execute([':s' => $correcta, ':u' => $sup]);
    $nueva('7', 'Pendiente', 1, 'LA LIBERTAD');                              // otra zona: el supervisor no la ve

    $http = function (string $url) use ($jar): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
    curl_exec($ch); unset($ch);
    $api = "$base/api/dashboard_pendientes.php";

    echo "Pendientes y rescates — $base\n";
    [, $j] = $http("$api?action=dashboard&partner_id=$partner");
    $k = $j['data']['kpis'] ?? [];
    $check('cuenta cada estado (solo su zona: no la de LA LIBERTAD)', ($k['cargadas'] ?? 0) === 6 && $k['pendiente'] === 2 && $k['en_validacion'] === 1 && $k['en_rescate'] === 1
        && $k['rescatada'] === 1 && $k['correcta'] === 1, $k);
    $check('% de rescate: 1 rescatada de 2 cerradas = 50 %', ($k['pct_rescate'] ?? null) === 50, $k);
    $check('tiempo de atención promedio de las cerradas', ($k['horas_promedio'] ?? 0) > 20, $k['horas_promedio'] ?? null);
    $ant = array_column($j['data']['antiguedad'] ?? [], 'cantidad');
    $check('antigüedad de las pendientes sin tomar: 1 de menos de 24 h y 1 de más de 3 días', $ant === [1, 0, 1], $ant);
    $emb = array_column($j['data']['embudo'] ?? [], 'cantidad');
    $check('embudo: 6 cargadas → 4 atendidas → 2 pasaron a rescate → 1 rescatada', $emb === [6, 4, 2, 1], $emb);
    $check('por zona, por partner, motivos y rescates por supervisor', count($j['data']['por_zona'] ?? []) === 1 && ($j['data']['por_partner'][0]['pct_rescate'] ?? -1) === 50
        && ($j['data']['motivos'][0]['cantidad'] ?? 0) === 6 && ($j['data']['rescates'][0]['cantidad'] ?? 0) === 1, $j['data'] ?? $j);

    [, $j] = $http("$api?action=dashboard_tabla&partner_id=$partner");
    [, $j2] = $http("$api?action=dashboard_tabla&partner_id=$partner&estado=pendiente");
    [, $j3] = $http("$api?action=dashboard_tabla&partner_id=$partner&q=ZZDPD5");
    $check('tabla por páginas, con filtro por estado y búsqueda', ($j['data']['total'] ?? 0) === 6 && ($j2['data']['total'] ?? 0) === 2
        && ($j3['data']['filas'][0]['estado'] ?? '') === 'rescatada', [$j['data']['total'] ?? null, $j2['data']['total'] ?? null, $j3['data']['filas'] ?? null]);
    $pend = array_values(array_filter($j2['data']['filas'] ?? [], fn($f) => $f['ticket'] === 'ZZDPD1'))[0] ?? [];
    $check('la pendiente sin tomar dice "Aún no gestionado"', ($pend['responsable'] ?? '') === 'Aún no gestionado', $pend);
    [$b] = $http("$api?action=dashboard_excel&partner_id=$partner");
    $check('descarga el Excel', str_starts_with($b, 'PK'), substr($b, 0, 60));
    [$pg] = $http("$base/index.php?page=dashboard_pendientes");
    $check('la pantalla abre y el menú tiene la sección Indicadores', str_contains($pg, 'id="dpd"') && str_contains($pg, 'Indicadores') && str_contains($pg, 'Técnicos y cuadrillas'), null);
} catch (Throwable $e) {
    $check('sin excepciones', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
echo "\n{$ok} correctas, {$fail} con error. Usuario y cancelaciones de prueba eliminados.\n";
exit($fail ? 1 : 0);
