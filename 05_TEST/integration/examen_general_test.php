<?php
// 05_TEST/integration/examen_general_test.php - (2026-10-20) Link GENERAL de examen y edición de cuadrillas por el supervisor directo.
//   - el link se arma con las cuadrillas filtradas: T1 y su T2; solo las de la zona del supervisor;
//   - si algún técnico no tiene DNI, NO se genera (y se dice quién);
//   - el supervisor directo edita cuadrillas solo de su zona y solo hacia sus zonas;
//   - el técnico entra con su DNI: solo los del grupo; DNI ajeno rechazado; 5 errores bloquean; anulado ya no entra.
// Crea el usuario DNI 99999931 y técnicos DNI 9999996x / PRUEBAEXG; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/examen_general_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'ExgPrueba' . random_int(1000, 9999);
$u = 'exg.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$cleanup = function () use ($pdo, $jar, $u) {
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999931'")->fetchColumn();
    if ($id) {
        foreach ($pdo->query("SELECT id FROM examen_convocatorias WHERE generado_por = $id")->fetchAll(PDO::FETCH_COLUMN) as $c) {
            foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "sgs-examen|{$c}|{$ip}")]);
            $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "sgs-examen|{$c}")]);
        }
        $pdo->exec("DELETE FROM examen_convocatorias WHERE generado_por = $id");
    }
    $pdo->exec("UPDATE tecnicos SET apoyo_id = NULL WHERE nombre_tecnico LIKE '%PRUEBAEXG%'");
    $pdo->exec("DELETE FROM tecnicos WHERE nombre_tecnico LIKE '%PRUEBAEXG%'");
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999931'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar);
};
$cleanup();
$ok = 0; $fail = 0;
$jarsTec = [];
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999931', 'nombres' => 'Exg', 'apellidos' => 'Sup Prueba', 'email' => 'exg.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, apoyo_id, fecha_registro)
        VALUES (:n, :d, :t, 'Habilitado', :p, :prov, :dep, :a, NOW())");
    $nuevo = function (string $n, ?string $d, int $t, ?string $prov, ?string $dep, ?int $a = null) use ($ins, $pdo, $partner): int {
        $ins->execute([':n' => $n, ':d' => $d, ':t' => $t, ':p' => $partner, ':prov' => $prov, ':dep' => $dep, ':a' => $a]);
        return (int)$pdo->lastInsertId();
    };
    $t2B = $nuevo('APOYO B PRUEBAEXG', '99999962', 2, null, null);
    $t1A = $nuevo('P 1 PRUEBAEXG SGI LIDER A', '99999961', 1, 'PIURA', 'PIURA', $t2B);
    $t1C = $nuevo('P 1 PRUEBAEXG SGI LIDER C', null, 1, 'PIURA', 'PIURA');
    $t1D = $nuevo('P 1 PRUEBAEXG SGI LIDER D', '99999963', 1, 'TRUJILLO', 'LA LIBERTAD');

    $csrf = '';
    $http = function (string $url, ?array $post = null, bool $sesion = true) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
        if ($sesion) $op += [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf], CURLOPT_POSTFIELDS => http_build_query($post)];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code]];
    };
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
    curl_exec($ch); unset($ch);
    [$pagina] = $http("$base/index.php?page=cuadrillas");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    $csrf = $m[1] ?? '';
    $api = "$base/api/examenes.php";

    echo "Pantalla Cuadrillas — $base\n";
    $check('el supervisor directo ve el botón del link general y el lápiz para editar', str_contains($pagina, 'id="btnLinkExamen"') && str_contains($pagina, 'data-editar=') && !str_contains($pagina, 'data-examen='), null);
    $check('no ve la carga de técnicos líderes (solo administración)', !str_contains($pagina, 'id="btnCargarLideres"'), null);

    echo "Preparar el link\n";
    [, $j] = $http("$api?action=grupo_preparar", ['lider_ids' => [$t1A, $t1C, $t1D]]);
    $sinDni = array_column($j['data']['sin_dni'] ?? [], 'id');
    $check('entran el T1 y su T2; la cuadrilla de otra zona no', ($j['status'] ?? '') === 'success' && ($j['data']['total'] ?? 0) === 3 && ($j['data']['t2'] ?? 0) === 1, $j);
    $check('avisa quién no tiene DNI', $sinDni === [$t1C], $j['data']['sin_dni'] ?? null);
    [, $j] = $http("$api?action=grupo_generar", ['lider_ids' => [$t1A, $t1C], 'titulo' => 'Examen de prueba', 'horas' => 24]);
    $check('con un técnico sin DNI NO se genera el link', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'DNI') && count($j['sin_dni'] ?? []) === 1, $j);

    echo "Editar cuadrillas (supervisor directo)\n";
    $editar = fn(int $id, array $extra) => $http("$base/api/tecnicos.php?action=cuadrilla_guardar", $extra + ['id' => $id, 'apoyo_id' => 0, 'placa' => '', 'vehiculo_tipo' => '', 'vehiculo_estado' => '', 'estado_operativo' => 'Habilitado']);
    [, $j] = $editar($t1C, ['provincia' => 'PIURA', 't1_dni' => '99999964', 't1_celular' => '']);
    $check('registra el DNI de una cuadrilla de su zona', ($j['status'] ?? '') === 'success' && $pdo->query("SELECT dni FROM tecnicos WHERE id = $t1C")->fetchColumn() === '99999964', $j);
    [, $j] = $editar($t1D, ['provincia' => 'TRUJILLO', 't1_dni' => '99999963']);
    $check('no puede editar una cuadrilla de otra zona', ($j['status'] ?? '') === 'error' && ($j['_http'] ?? 0) === 403, $j);
    [, $j] = $editar($t1A, ['provincia' => 'TRUJILLO', 'apoyo_id' => $t2B, 't1_dni' => '99999961', 't2_dni' => '99999962']);
    $check('no puede mover una cuadrilla a otra zona', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'otra zona')
        && $pdo->query("SELECT departamento FROM tecnicos WHERE id = $t1A")->fetchColumn() === 'PIURA', $j);

    echo "Generar el link\n";
    [, $j] = $http("$api?action=grupo_generar", ['lider_ids' => [$t1A, $t1C, $t1D], 'titulo' => 'Escrito a mano', 'horas' => 24, 'filtro' => 'Prueba']);
    $check('sin elegir un examen de la lista no se genera', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'lista'), $j);
    [, $j] = $http("$api?action=grupo_generar", ['lider_ids' => [$t1A, $t1C, $t1D], 'examen' => 'altura', 'horas' => 24, 'filtro' => 'Prueba']);
    $url = (string)($j['data']['url'] ?? '');
    $check('con todos con DNI se genera un solo link para el grupo', ($j['status'] ?? '') === 'success' && str_contains($url, '/examen.php?g=') && ($j['data']['total'] ?? 0) === 3, $j);
    $conv = (int)($j['data']['id'] ?? 0);
    $check('el link guarda el examen elegido, la huella del código y a los 3 técnicos', $conv
        && (int)$pdo->query("SELECT COUNT(*) FROM examen_convocatoria_tecnicos WHERE convocatoria_id = $conv")->fetchColumn() === 3
        && $pdo->query("SELECT CONCAT(examen, '|', titulo) FROM examen_convocatorias WHERE id = $conv")->fetchColumn() === 'altura|Seguridad: trabajo en altura'
        && !str_contains((string)$pdo->query("SELECT token_hash FROM examen_convocatorias WHERE id = $conv")->fetchColumn(), substr($url, -48)), null);

    // Cada técnico con su propio navegador (cookies) y siguiendo la redirección después del DNI.
    $tec = function (string $quien, string $url, ?array $post = null) use (&$jarsTec): array {
        $jarsTec[$quien] ??= tempnam(sys_get_temp_dir(), 'sgst');
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jarsTec[$quien], CURLOPT_COOKIEFILE => $jarsTec[$quien]];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch);
        unset($ch);
        return [$b, json_decode($b, true) ?? []];
    };
    $bancoAltura = require BACKEND_PATH . '/config/examenes/altura.php';
    $pregunta = fn(string $id) => array_values(array_filter($bancoAltura['preguntas'], fn($p) => $p['id'] === $id))[0];
    $armadoDe = fn(int $tecnicoId) => json_decode((string)$pdo->query("SELECT preguntas FROM examen_convocatoria_tecnicos WHERE convocatoria_id = $conv AND tecnico_id = $tecnicoId")->fetchColumn(), true) ?: [];

    echo "Entrada del técnico con su DNI\n";
    [$b] = $tec('nadie', $url);
    $check('el link pide el DNI', str_contains($b, 'Ingresa con tu DNI') && str_contains($b, 'name="dni"'), substr(strip_tags($b), 0, 200));
    [$b] = $tec('nadie', $url, ['dni' => '99999963']);
    $check('un DNI que no es del grupo no entra', str_contains($b, 'no está en esta convocatoria'), substr(strip_tags($b), 0, 300));
    [$b] = $tec('B', $url, ['dni' => '99999962']);
    $check('el T2 del grupo entra con su DNI y ve las instrucciones', str_contains($b, 'data-vista="instrucciones"')
        && $pdo->query("SELECT ingreso_en FROM examen_convocatoria_tecnicos WHERE convocatoria_id = $conv AND tecnico_id = $t2B")->fetchColumn() !== null, substr(strip_tags($b), 0, 300));
    [, $j] = $tec('nadie', "$url&accion=empezar", []);
    $check('sin haber entrado con su DNI no puede empezar', ($j['codigo'] ?? '') === 'sin_llave', $j);

    echo "Examen: aprobado con todo correcto\n";
    [, $j] = $tec('B', "$url&accion=empezar", []);
    $claves = [];
    array_walk_recursive($j['preguntas'], function ($v, $k) use (&$claves) { $claves[$k] = true; });
    $check('al empezar recibe 20 preguntas y 10 minutos, sin las respuestas correctas', ($j['status'] ?? '') === 'success' && count($j['preguntas'] ?? []) === 20
        && ($j['segundos'] ?? 0) > 590 && !isset($claves['correcta']), ['status' => $j['status'] ?? null, 'campos' => array_keys($claves)]);
    foreach ($armadoDe($t2B) as $n => $a) {
        $p = $pregunta($a['id']);
        $tec('B', "$url&accion=responder", ['n' => $n, 'r' => $p['tipo'] === 'orden' ? array_keys($p['opciones']) : $p['correcta']]);
    }
    [, $j] = $tec('B', "$url&accion=terminar", []);
    $check('todo correcto: 100 % y aprobado', ($j['status'] ?? '') === 'terminado' && (float)($j['resultado']['nota'] ?? 0) === 100.0 && !empty($j['resultado']['aprobado']), $j);
    $check('se guardó una fila por pregunta para el dashboard', (int)$pdo->query("SELECT COUNT(*) FROM examen_respuestas r JOIN examen_convocatoria_tecnicos ct ON ct.id = r.participacion_id
        WHERE ct.convocatoria_id = $conv AND ct.tecnico_id = $t2B AND r.correcta = 1")->fetchColumn() === 20, null);
    [, $j] = $tec('B', "$url&accion=empezar", []);
    [$b] = $tec('B', $url);
    $check('una sola oportunidad: ya no puede volver a rendirlo; al entrar ve su resultado', ($j['status'] ?? '') === 'terminado' && str_contains($b, 'data-vista="resultado"'), $j);

    echo "Examen: no aprobado y tiempo vencido\n";
    $tec('A', $url, ['dni' => '99999961']);
    $tec('A', "$url&accion=empezar", []);
    $primera = $armadoDe($t1A)[0];
    $p = $pregunta($primera['id']);
    $tec('A', "$url&accion=responder", ['n' => 0, 'r' => $p['tipo'] === 'orden' ? array_keys($p['opciones']) : $p['correcta']]);
    [, $j] = $tec('A', "$url&accion=terminar", []);
    $check('con 1 de 20: 5 % y no aprobado, con temas para reforzar', ($j['status'] ?? '') === 'terminado' && (float)($j['resultado']['nota'] ?? -1) === 5.0
        && empty($j['resultado']['aprobado']) && count($j['resultado']['reforzar'] ?? []) > 0, $j);
    $tec('C', $url, ['dni' => '99999964']);
    $tec('C', "$url&accion=empezar", []);
    $pdo->exec("UPDATE examen_convocatoria_tecnicos SET inicio_en = NOW() - INTERVAL 11 MINUTE WHERE convocatoria_id = $conv AND tecnico_id = $t1C");
    [, $j] = $tec('C', "$url&accion=responder", ['n' => 0, 'r' => [0]]);
    $check('pasado el tiempo ya no acepta respuestas: se califica lo que había', ($j['status'] ?? '') === 'terminado' && (float)($j['resultado']['nota'] ?? -1) === 0.0, $j);

    for ($i = 0; $i < 5; $i++) $tec('intruso', $url, ['dni' => '1111111' . $i]);
    [$b] = $tec('intruso', $url, ['dni' => '99999961']);
    $check('tras 5 DNI equivocados se bloquea por 15 minutos (aunque luego sea correcto)', str_contains($b, 'Espera 15 minutos'), substr(strip_tags($b), 0, 300));

    echo "Seguimiento y anulación\n";
    [, $j] = $http("$api?action=grupos");
    $fila = array_values(array_filter($j['data'] ?? [], fn($c) => (int)$c['id'] === $conv))[0] ?? [];
    $check('el historial muestra cuántos rindieron y aprobaron', (int)($fila['total'] ?? 0) === 3 && (int)($fila['rindieron'] ?? 0) === 3 && (int)($fila['aprobados'] ?? 0) === 1, $fila);
    [, $j] = $http("$api?action=grupo&id=$conv");
    $check('el detalle lista a los técnicos con su nota', count($j['data']['tecnicos'] ?? []) === 3 && count(array_filter($j['data']['tecnicos'] ?? [], fn($t) => $t['nota'] !== null)) === 3, $j);
    echo "Dashboard de exámenes\n";
    [, $j] = $http("$api?action=dashboard&zona=PIURA&examen=altura");
    $k = $j['data']['kpis'] ?? [];
    $check('indicadores: 3 convocados, 3 rindieron, 1 aprobado (33 %)', ($k['convocados'] ?? 0) === 3 && ($k['rindieron'] ?? 0) === 3 && ($k['aprobados'] ?? 0) === 1 && ($k['pct_aprobacion'] ?? 0) == 33, $k);
    $check('temas con más errores y notas por rango', count($j['data']['temas'] ?? []) > 0 && array_sum(array_column($j['data']['notas'] ?? [], 'cantidad')) === 3, $j['data']['notas'] ?? null);
    [, $j] = $http("$api?action=dashboard&zona=LA%20LIBERTAD");
    $check('el supervisor no ve técnicos de otra zona', ($j['data']['kpis']['convocados'] ?? -1) === 0, $j['data']['kpis'] ?? $j);
    [, $j] = $http("$api?action=dashboard_tabla&zona=PIURA&examen=altura");
    [, $j2] = $http("$api?action=dashboard_tabla&zona=PIURA&estado=aprobado");
    [, $j3] = $http("$api?action=dashboard_tabla&zona=PIURA&q=" . rawurlencode('APOYO B'));
    $check('la tabla llega por páginas y la filtra el servidor (resultado y búsqueda)', ($j['data']['total'] ?? 0) === 3 && count($j['data']['filas'] ?? []) === 3
        && ($j2['data']['total'] ?? 0) === 1 && ($j2['data']['filas'][0]['estado'] ?? '') === 'aprobado' && ($j3['data']['total'] ?? 0) === 1, [$j['data']['total'] ?? null, $j2['data']['total'] ?? null, $j3['data']['total'] ?? null]);
    [, $j] = $http("$api?action=dashboard&zona=PIURA");
    $check('el dashboard ya no envía la tabla completa (solo totales y gráficos)', !isset($j['data']['filas']) && ($j['data']['cuentas']['aprobado'] ?? -1) === 1, $j['data']['cuentas'] ?? null);
    [$b] = $http("$api?action=dashboard_excel&zona=PIURA");
    $check('descarga el Excel de resultados', str_starts_with($b, 'PK'), substr($b, 0, 80));
    [$pg] = $http("$base/index.php?page=examenes");
    $check('la pantalla Exámenes abre para el supervisor directo', str_contains($pg, 'id="exd"') && str_contains($pg, 'examenes.js'), null);

    [, $j] = $http("$api?action=grupo_anular", ['id' => $conv]);
    [$b] = $tec('nuevo', $url);
    $check('anulado: el link ya no deja entrar', ($j['status'] ?? '') === 'success' && str_contains($b, 'Link anulado'), $j);
} catch (Throwable $e) {
    $check('sin excepciones', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
    foreach ($jarsTec as $j) @unlink($j);
}
echo "\n{$ok} correctas, {$fail} con error. Usuario, técnicos y links de prueba eliminados.\n";
exit($fail ? 1 : 0);
