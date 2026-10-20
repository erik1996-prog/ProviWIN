<?php
// 05_TEST/integration/examen_enlaces_test.php - (2026-10-15) Links de examen individuales y editor de cuadrillas (T1 y T2). Por HTTP.
//   - links individuales ya enviados: abren sin sesión hasta vencer; anulado, vencido, inválido; ya no se generan nuevos
//     (2026-10-20: los reemplaza el link general, examen_general_test.php);
//   - editar cuadrilla: DNI y celular del T1 y del T2, registrar un T2 nuevo, DNI repetido rechazado.
// Crea usuarios (DNI 99999926-27) y técnicos (DNI 99999944-47, "PRUEBAEXAMEN") y los BORRA al final.
// Uso (solo CLI): php 05_TEST/integration/examen_enlaces_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ExamenPrueba' . random_int(1000, 9999);
$jars = ['examen.admin' => tempnam(sys_get_temp_dir(), 'sgsc'), 'examen.sup' => tempnam(sys_get_temp_dir(), 'sgsc')];
$cleanup = function () use ($pdo, $jars) {
    $pdo->exec("UPDATE tecnicos SET apoyo_id = NULL WHERE nombre_tecnico LIKE '%PRUEBAEXAMEN%'");
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico LIKE '%PRUEBAEXAMEN%' OR dni IN ('99999944','99999945','99999946','99999947')");
    $pdo->exec("DELETE FROM usuarios WHERE dni IN ('99999926','99999927')");
    foreach (['::1', '127.0.0.1'] as $ip) foreach (array_keys($jars) as $u) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    foreach ($jars as $j) @unlink($j);
};
$cleanup();
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    $svc = new UsuarioService($pdo);
    $svc->guardar(['dni' => '99999926', 'nombres' => 'Examen', 'apellidos' => 'Admin Prueba', 'email' => 'examen.admin@proviwin.test', 'usuario' => 'examen.admin',
        'rol' => 'Administrador', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $svc->guardar(['dni' => '99999927', 'nombres' => 'Examen', 'apellidos' => 'Sup Prueba', 'email' => 'examen.sup@proviwin.test', 'usuario' => 'examen.sup',
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, fecha_registro)
        VALUES (:n, :d, :tipo, 'Habilitado', :p, :prov, :dep, NOW())");
    $ins->execute([':n' => 'P 1 PRUEBAEXAMEN SGI JUAN PEREZ PRUEBA', ':d' => '99999944', ':tipo' => 1, ':p' => $partner, ':prov' => 'TRUJILLO', ':dep' => 'LA LIBERTAD']);
    $lider = (int)$pdo->lastInsertId();
    $ins->execute([':n' => 'ANA PRUEBAEXAMEN APOYO', ':d' => '99999945', ':tipo' => 2, ':p' => $partner, ':prov' => null, ':dep' => null]);
    $apoyo = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE tecnicos SET apoyo_id = $apoyo WHERE id = $lider");

    $csrf = [];
    $http = function (string $url, ?string $u = null, ?array $post = null) use ($jars, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
        if ($u) $op += [CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u]];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . ($csrf[$u] ?? '')], CURLOPT_POSTFIELDS => $post];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // en PHP 8 las cookies se guardan al liberar el manejador
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    foreach (array_keys($jars) as $u) {
        $ch = curl_init("$base/api/auth.php?action=login");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jars[$u], CURLOPT_COOKIEFILE => $jars[$u],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
        curl_exec($ch); unset($ch);
        [$pagina] = $http("$base/index.php?page=cuadrillas", $u);
        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
        $csrf[$u] = $m[1] ?? '';
    }

    // Link individual (2026-10-15): desde 2026-10-20 ya no se generan (los reemplaza el link general, ver
    // examen_general_test.php); los ya enviados siguen abriendo hasta vencer. Se crean directo en la base.
    echo "Links individuales ya enviados — $base\n";
    $legado = function (int $tecnicoId, string $vence = '+1 day', ?string $anulado = null) use ($pdo): string {
        $codigo = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO examen_enlaces (tecnico_id, token_hash, titulo, creado_en, vence_en, anulado_en) VALUES (:t, :h, :ti, NOW(), :v, :a)')
            ->execute([':t' => $tecnicoId, ':h' => hash('sha256', $codigo), ':ti' => 'Seguridad en altura', ':v' => date('Y-m-d H:i:s', strtotime($vence)), ':a' => $anulado]);
        return $codigo;
    };
    $codigo1 = $legado($lider);
    [$pag] = $http("$base/examen.php?tecnico=juan-perez-prueba&t=$codigo1");
    $check('un link ya enviado abre sin iniciar sesión y muestra el nombre', str_contains($pag, 'Hola, Juan Perez Prueba') && str_contains($pag, 'Seguridad en altura'), mb_substr(strip_tags($pag), 0, 200));
    $check('queda registrado que lo abrió', $pdo->query("SELECT abierto_en IS NOT NULL FROM examen_enlaces WHERE token_hash = '" . hash('sha256', $codigo1) . "'")->fetchColumn() == 1);
    [$pag] = $http("$base/examen.php?t=" . $legado($apoyo, '+1 day', date('Y-m-d H:i:s')));
    $check('anulado: ya no abre', str_contains($pag, 'Link anulado'));
    [$pag] = $http("$base/examen.php?t=" . $legado($apoyo, '-1 minute'));
    $check('vencido: ya no abre', str_contains($pag, 'Link vencido'));
    [$pag] = $http("$base/examen.php?t=" . str_repeat('a', 48));
    $check('un código inventado no abre nada', str_contains($pag, 'Link no válido'));
    [, $j] = $http("$base/api/examenes.php?action=generar", 'examen.admin', ['tecnico_id' => $lider, 'horas' => 24]);
    $check('ya no se generan links individuales (solo el general)', ($j['status'] ?? '') === 'error' && ($j['_http'] ?? 0) === 400, $j);
    $pdo->exec("UPDATE tecnicos SET departamento = 'PIURA', provincia = 'PIURA' WHERE id = $lider");
    echo "Editar cuadrilla: T1 y T2\n";
    $guardar = fn(array $campos) => $http("$base/api/tecnicos.php?action=cuadrilla_guardar", 'examen.admin', $campos + ['id' => $lider, 'placa' => '', 'estado_operativo' => 'Habilitado', 'provincia' => 'PIURA'])[1];
    $f = fn(int $id) => $pdo->query("SELECT nombre_tecnico, dni, celular, partner_id, departamento, apoyo_id FROM tecnicos WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $j = $guardar(['t1_dni' => '99999946', 't1_celular' => '987 654 321', 'apoyo_id' => $apoyo, 't2_dni' => '99999945', 't2_celular' => '912345678']);
    $check('guarda DNI y celular del T1 y del T2', ($j['status'] ?? '') === 'success' && $f($lider)['dni'] === '99999946' && $f($lider)['celular'] === '987654321' && $f($apoyo)['celular'] === '912345678', [$j, $f($lider), $f($apoyo)]);
    $check('el T2 pasa a la zona del líder', $f($apoyo)['departamento'] === 'PIURA', $f($apoyo));
    $j = $guardar(['t1_dni' => '99999945', 't1_celular' => '', 'apoyo_id' => $apoyo, 't2_dni' => '99999945', 't2_celular' => '']);
    $check('rechaza un DNI que ya tiene otro técnico', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', '99999945'), $j);
    $j = $guardar(['t1_dni' => '99999946', 't1_celular' => '12345', 'apoyo_id' => 0]);
    $check('rechaza un celular inválido', ($j['status'] ?? '') === 'error', $j);
    $j = $guardar(['t1_dni' => '99999946', 't1_celular' => '987654321', 'apoyo_id' => 'nuevo', 't2_nombre' => 'luis pruebaexamen nuevo', 't2_dni' => '99999947', 't2_celular' => '']);
    $nuevo = (int)$pdo->query("SELECT id FROM tecnicos WHERE dni = '99999947'")->fetchColumn();
    $check('registra un T2 nuevo y lo deja en la cuadrilla', ($j['status'] ?? '') === 'success' && $nuevo && (int)$f($lider)['apoyo_id'] === $nuevo
        && $f($nuevo)['nombre_tecnico'] === 'LUIS PRUEBAEXAMEN NUEVO' && (int)$f($nuevo)['partner_id'] === $partner && $f($nuevo)['departamento'] === 'PIURA', [$j, $nuevo ? $f($nuevo) : null]);
    $j = $guardar(['t1_dni' => '99999946', 'apoyo_id' => 'nuevo', 't2_nombre' => 'X', 't2_dni' => '']);
    $check('T2 nuevo sin nombre ni DNI: se rechaza', ($j['status'] ?? '') === 'error', $j);
    [$pagina] = $http("$base/index.php?page=cuadrillas&q=PRUEBAEXAMEN", 'examen.sup');
    $check('el supervisor directo ve el link general (no el de cada fila) y el lápiz de su zona (2026-10-20)', str_contains($pagina, 'id="btnLinkExamen"') && !str_contains($pagina, 'data-examen=') && str_contains($pagina, 'data-editar='));
} finally {
    $cleanup();
}
echo "\n$ok correctas, $fail con error. Usuarios, técnicos y links de prueba eliminados.\n";
exit($fail ? 1 : 0);
