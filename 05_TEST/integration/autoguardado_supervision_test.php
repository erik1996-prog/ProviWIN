<?php
// 05_TEST/integration/autoguardado_supervision_test.php - (2026-10-16) Supervisión que se guarda sola, con "Cancelar" y "Finalizar".
//   - "Nueva supervisión" la crea al instante En proceso (código SUP-…) y, si ya hay una en proceso, devuelve esa;
//   - el guardado automático acepta datos a medias (sin ticket, partner ni ubicación) y conserva lo ya guardado;
//   - finalizar sigue exigiendo todo;
//   - Cancelar: la nueva se elimina; la pendiente iniciada vuelve a Pendiente con sus datos originales.
// Crea el usuario DNI 99999930 (con turno de hoy) y supervisiones SUP-PRUEBA-AUTO*; BORRA todo al final.
// Uso (solo CLI): php 05_TEST/integration/autoguardado_supervision_test.php [url_base=http://localhost/ProviWIN]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/ProviWIN', '/');
$_SESSION = [];
require dirname(__DIR__, 2) . '/02_BACKEND/src/config/config.php';
require BACKEND_PATH . '/config/auth.php';
require BACKEND_PATH . '/services/UsuarioService.php';
$pdo = Database::getConnection();

$clave = 'AutoPrueba' . random_int(1000, 9999);
$u = 'auto.sup';
$jar = tempnam(sys_get_temp_dir(), 'sgsc');
$png = tempnam(sys_get_temp_dir(), 'sgsp') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$cleanup = function () use ($pdo, $jar, $png, $u) {
    $id = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999930'")->fetchColumn();
    $ids = $pdo->query("SELECT id FROM supervisiones WHERE codigo_supervision LIKE 'SUP-PRUEBA-AUTO%'" . ($id ? " OR supervisor_id = $id" : ''))->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $s) {
        foreach ($pdo->query("SELECT foto_servicio_1, foto_servicio_2, foto_servicio_3 FROM supervisiones WHERE id = $s")->fetch(PDO::FETCH_NUM) ?: [] as $f) if ($f) @unlink(SHARED_FILES_PATH . '/' . $f);
        $pdo->exec("DELETE FROM supervisiones WHERE id = $s");
    }
    if ($id) {
        $pdo->exec("DELETE e FROM asistencia_eventos e JOIN asistencia_supervisores a ON a.id = e.asistencia_id WHERE a.supervisor_id = $id");
        $pdo->exec("DELETE FROM asistencia_supervisores WHERE supervisor_id = $id");
    }
    $pdo->exec("UPDATE tecnicos SET apoyo_id = NULL WHERE dni IN ('99999953','99999954')");
    $pdo->exec("DELETE FROM tecnicos WHERE dni IN ('99999953','99999954')");
    $pdo->exec("DELETE FROM usuarios WHERE dni = '99999930'");
    foreach (['::1', '127.0.0.1'] as $ip) $pdo->prepare('DELETE FROM login_attempts WHERE fingerprint = :f')->execute([':f' => hash('sha256', "$ip|$u")]);
    @unlink($jar); @unlink($png);
};
$cleanup();
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
$ok = 0; $fail = 0;
$check = function (string $name, bool $cond, $got = null) use (&$ok, &$fail) { echo ($cond ? '  ✓ ' : '  ✗ ') . $name . ($cond ? '' : ' → ' . json_encode($got, JSON_UNESCAPED_UNICODE)) . "\n"; $cond ? $ok++ : $fail++; };
$fila = function (int $id) use ($pdo) { $st = $pdo->prepare('SELECT * FROM supervisiones WHERE id = :id'); $st->execute([':id' => $id]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; };

try {
    $admin = (int)$pdo->query("SELECT id FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY id LIMIT 1")->fetchColumn();
    (new UsuarioService($pdo))->guardar(['dni' => '99999930', 'nombres' => 'Auto', 'apellidos' => 'Sup Prueba', 'email' => 'auto.sup@proviwin.test', 'usuario' => $u,
        'rol' => 'Supervisor', 'organizacion' => 'directo', 'zonas' => ['PIURA'], 'password' => $clave], 0, $admin);
    $supId = (int)$pdo->query("SELECT id FROM usuarios WHERE dni = '99999930'")->fetchColumn();
    $partner = (int)$pdo->query("SELECT id FROM partners WHERE estado = 1 AND UPPER(nombre_partner) NOT LIKE 'WIN%' ORDER BY id LIMIT 1")->fetchColumn();

    $csrf = '';
    $http = function (string $url, ?array $post = null) use ($jar, &$csrf): array {
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar];
        if ($post !== null) $op += [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $csrf], CURLOPT_POSTFIELDS => $post];
        curl_setopt_array($ch, $op);
        $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // en PHP 8 las cookies se guardan al liberar el manejador
        return [$b, (json_decode($b, true) ?? []) + ['_http' => $code, '_raw' => substr($b, 0, 300)]];
    };
    $ch = curl_init("$base/api/auth.php?action=login");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['usuario' => $u, 'password' => $clave])]);
    curl_exec($ch); unset($ch);
    [$pagina] = $http("$base/index.php?page=cuadrillas");
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', $pagina, $m);
    $csrf = $m[1] ?? '';
    $api = "$base/api/supervisiones.php";

    echo "Nueva supervisión — $base\n";
    [, $j] = $http("$api?action=iniciar_nueva", []);
    $check('sin turno iniciado no se puede empezar', ($j['_http'] ?? 0) === 403 && str_contains($j['message'] ?? '', 'turno'), $j);
    $pdo->prepare("INSERT INTO asistencia_supervisores (supervisor_id, fecha, inicio_turno, estado_turno, fecha_registro) VALUES (:u, CURDATE(), NOW() - INTERVAL 1 HOUR, 'En turno', NOW())")->execute([':u' => $supId]);
    [, $j] = $http("$api?action=iniciar_nueva", []);
    $nueva = (int)($j['id'] ?? 0);
    $r = $nueva ? $fila($nueva) : null;
    $check('con turno: se crea al instante En proceso con código SUP-', ($j['status'] ?? '') === 'success' && $r && $r['estado_supervision'] === 'En proceso' && str_starts_with((string)$r['codigo_supervision'], 'SUP-') && empty($r['ticket_codigo']), $j);
    $check('toma la zona del supervisor (PIURA)', ($r['departamento'] ?? '') === 'PIURA', $r['departamento'] ?? null);
    [, $j] = $http("$api?action=iniciar_nueva", []);
    $check('otra "Nueva supervisión": devuelve la que está en proceso (una a la vez)', ($j['status'] ?? '') === 'activa' && (int)($j['id'] ?? 0) === $nueva, $j);

    echo "Guardado automático\n";
    $parcial = fn(array $datos) => $http("$api?action=create", $datos + ['action' => 'create', 'supervision_id_edit' => $nueva, 'estado_supervision' => 'En proceso']);
    [, $j] = $parcial(['observacion_servicio' => 'Avance sin datos generales']);
    $r = $fila($nueva);
    $check('guarda con la sección 1 vacía, sin ticket ni partner', ($j['status'] ?? '') === 'success' && $r['observacion_servicio'] === 'Avance sin datos generales' && $r['estado_supervision'] === 'En proceso', $j);
    $check('la ubicación no elegida conserva la guardada (no queda "LIMA")', $r['departamento'] === 'PIURA', $r['departamento']);
    [, $j] = $parcial(['tipo_supervision' => 'Instalación', 'derivacion_orden' => 'Autogestión', 'tipo_servicio' => 'Regular', 'tipo_supervision_inicial' => 'En caliente',
        'departamento' => 'PIURA', 'provincia' => 'PIURA', 'distrito' => 'PIURA', 'ticket_codigo' => 'ZZAUTO1', 'partner_id' => $partner,
        'observacion_servicio' => 'Avance con datos', 'foto_servicio_1' => new CURLFile($png, 'image/png', 'evidencia.png')]);
    $r = $fila($nueva);
    $check('guarda los datos generales, el ticket y la foto apenas se eligen', ($j['status'] ?? '') === 'success' && $r['ticket_codigo'] === 'ZZAUTO1' && $r['provincia'] === 'PIURA'
        && (int)$r['partner_id'] === $partner && str_starts_with((string)$r['foto_servicio_1'], 'uploads/'), $j + ['fila' => $r]);
    $fotoNueva = (string)$r['foto_servicio_1'];
    [, $j] = $parcial(['observacion_servicio' => 'Avance sin volver a mandar la foto']);
    $check('un guardado sin foto no borra la ya subida', $fila($nueva)['foto_servicio_1'] === $fotoNueva, $fila($nueva)['foto_servicio_1']);
    [, $j] = $http("$api?action=create", ['action' => 'create', 'supervision_id_edit' => $nueva, 'estado_supervision' => 'Finalizada', 'tipo_supervision' => 'Instalación',
        'derivacion_orden' => 'Autogestión', 'tipo_servicio' => 'Regular', 'tipo_supervision_inicial' => 'En caliente', 'departamento' => 'PIURA', 'provincia' => 'PIURA', 'distrito' => 'PIURA',
        'ticket_codigo' => 'ZZAUTO1', 'observacion_servicio' => 'x']);
    $check('finalizar sigue exigiendo lo obligatorio (sin partner: no finaliza)', ($j['status'] ?? '') === 'error' && $fila($nueva)['estado_supervision'] === 'En proceso', $j);

    echo "Técnico de apoyo (T2) y su DNI (2026-10-19)\n";
    $insTec = $pdo->prepare("INSERT INTO tecnicos (nombre_tecnico, dni, tipo_tecnico, estado_operativo, partner_id, provincia, departamento, fecha_registro)
        VALUES (:n, :d, :t, 'Habilitado', :p, 'PIURA', 'PIURA', NOW())");
    $insTec->execute([':n' => 'P 1 PRUEBAAUTO SGI LIDER PIURA', ':d' => '99999953', ':t' => 1, ':p' => $partner]);
    $insTec->execute([':n' => 'APOYO PRUEBAAUTO', ':d' => '99999954', ':t' => 2, ':p' => $partner]);
    [, $j] = $parcial(['partner_id' => $partner, 'tecnico1_nombre' => 'P 1 PRUEBAAUTO SGI LIDER PIURA', 'tecnico1_dni' => '99999953', 'tecnico2_nombre' => 'APOYO PRUEBAAUTO', 'tecnico2_dni' => '99999954']);
    $r = $fila($nueva);
    $check('el T2 con DNI válido se guarda en la supervisión', ($j['status'] ?? '') === 'success' && $r['tecnico2_nombre'] === 'APOYO PRUEBAAUTO' && $r['tecnico2_dni'] === '99999954', $j + ['t2' => $r['tecnico2_nombre'] ?? null]);
    // La pantalla no envía al T2 mientras su DNI esté incompleto: el servidor conserva el ya guardado (antes lo borraba).
    [, $j] = $parcial(['partner_id' => $partner, 'tecnico1_nombre' => 'P 1 PRUEBAAUTO SGI LIDER PIURA', 'tecnico1_dni' => '99999953', 'observacion_servicio' => 'DNI del T2 a medio escribir']);
    $r = $fila($nueva);
    $check('con el DNI del T2 a medias, el T2 ya guardado no se borra', ($j['status'] ?? '') === 'success' && $r['tecnico2_nombre'] === 'APOYO PRUEBAAUTO' && $r['tecnico2_dni'] === '99999954', $j + ['t2' => $r['tecnico2_nombre'] ?? null]);
    [, $j] = $parcial(['partner_id' => $partner, 'tecnico1_nombre' => 'P 1 PRUEBAAUTO SGI LIDER PIURA', 'tecnico1_dni' => '99999953', 'tecnico2_nombre' => '', 'tecnico2_dni' => '']);
    $r = $fila($nueva);
    $check('"Sin técnico de apoyo" elegido a propósito: el T2 se quita', ($j['status'] ?? '') === 'success' && empty($r['tecnico2_nombre']) && empty($r['tecnico2_id']), $j + ['t2' => $r['tecnico2_nombre'] ?? null]);
    [, $j] = $http("$api?action=create", ['action' => 'create', 'supervision_id_edit' => $nueva, 'estado_supervision' => 'En proceso', 'partner_id' => $partner,
        'tecnico1_nombre' => 'P 1 PRUEBAAUTO SGI LIDER PIURA', 'tecnico1_dni' => '99999953', 'tecnico2_nombre' => 'APOYO PRUEBAAUTO', 'tecnico2_dni' => '123']);
    $check('un DNI mal enviado se rechaza con un mensaje claro', ($j['status'] ?? '') === 'error' && str_contains($j['message'] ?? '', 'DNI'), $j);

    echo "Cancelar\n";
    [, $j] = $http("$api?action=cancelar", ['id' => $nueva]);
    $check('una supervisión nueva cancelada se elimina con su foto', ($j['status'] ?? '') === 'success' && empty($j['volvio_a_pendiente']) && $fila($nueva) === null
        && !is_file(SHARED_FILES_PATH . '/' . $fotoNueva), $j);

    $pdo->prepare("INSERT INTO supervisiones (codigo_supervision, fecha_registro, supervisor_id, derivacion_orden, tipo_servicio, departamento, provincia, distrito,
            tipo_supervision_inicial, clasificacion_supervision, area_servicio, estado_supervision, ticket_codigo, partner_id, es_cancelacion, origen_importacion,
            tipificacion_cancelacion, observacion_servicio, tecnico1_nombre)
        VALUES ('SUP-PRUEBA-AUTO-P', NOW(), :quien, 'Autogestión', 'Regular', 'PIURA', 'PIURA', 'PIURA', 'En frío', 'Instalación', 'Instalación', 'Pendiente', 'ZZPEND1', :p, 1, 1,
            :tipif, 'Texto original de la carga', 'TECNICO DE LA CARGA')")->execute([':p' => $partner, ':quien' => $admin,
            ':tipif' => $pdo->query('SELECT descripcion FROM tipificaciones_cancelacion ORDER BY 1 LIMIT 1')->fetchColumn() ?: null]);
    $pend = (int)$pdo->lastInsertId();
    [, $j] = $http("$api?action=create", ['action' => 'create', 'supervision_id_edit' => $pend, 'estado_supervision' => 'Iniciada', 'tipo_supervision' => 'Instalación',
        'derivacion_orden' => 'Autogestión', 'tipo_servicio' => 'Regular', 'tipo_supervision_inicial' => 'En frío', 'departamento' => 'PIURA', 'provincia' => 'PIURA',
        'distrito' => 'PIURA', 'ticket_codigo' => 'ZZPEND1', 'partner_id' => $partner, 'es_cancelacion' => 1, 'observacion_servicio' => 'Texto original de la carga']);
    $r = $fila($pend);
    $check('al iniciar una pendiente se guarda su copia original', ($j['status'] ?? '') === 'success' && (int)$r['supervisor_id'] === $supId && !empty($r['datos_pendiente']), $j + ['estado' => $r['estado_supervision'] ?? null]);
    [, $j] = $http("$api?action=create", ['action' => 'create', 'supervision_id_edit' => $pend, 'estado_supervision' => 'En proceso', 'es_cancelacion' => 1,
        'observacion_servicio' => 'Avance que se descarta', 'foto_servicio_1' => new CURLFile($png, 'image/png', 'evidencia.png')]);
    $r = $fila($pend);
    $fotoPend = (string)$r['foto_servicio_1'];
    $check('la pendiente iniciada también se guarda sola', ($j['status'] ?? '') === 'success' && $r['observacion_servicio'] === 'Avance que se descarta' && $r['ticket_codigo'] === 'ZZPEND1'
        && $r['tecnico1_nombre'] === 'TECNICO DE LA CARGA', $j + ['fila' => $r]);
    $sub = $pdo->query('SELECT id, categoria_id FROM subopciones_observacion ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO observaciones_supervision (supervision_id, gravedad, categoria_id, subopcion_id, detalle_observacion, fecha_registro, fecha_limite_respuesta)
        VALUES (:s, 'Leve', :c, :o, 'Hallazgo del intento', NOW(), NOW() + INTERVAL 3 DAY)")->execute([':s' => $pend, ':c' => $sub['categoria_id'], ':o' => $sub['id']]);
    [, $j] = $http("$api?action=cancelar", ['id' => $pend]);
    $r = $fila($pend);
    $obs = (int)$pdo->query("SELECT COUNT(*) FROM observaciones_supervision WHERE supervision_id = $pend")->fetchColumn();
    $check('cancelar una pendiente iniciada: vuelve a Pendiente, con quien la cargó', ($j['status'] ?? '') === 'success' && !empty($j['volvio_a_pendiente'])
        && $r['estado_supervision'] === 'Pendiente' && (int)$r['supervisor_id'] === $admin && $r['datos_pendiente'] === null, $j + ['fila' => $r]);
    $check('…con sus datos originales, sin la foto ni la observación del intento', $r['observacion_servicio'] === 'Texto original de la carga' && empty($r['foto_servicio_1'])
        && $obs === 0 && !is_file(SHARED_FILES_PATH . '/' . $fotoPend), ['obs' => $obs, 'foto' => $r['foto_servicio_1']]);
    [, $j] = $http("$api?action=cancelar", ['id' => $pend]);
    $check('una pendiente sin iniciar no se puede "cancelar"', ($j['status'] ?? '') === 'error', $j);
} catch (Throwable $e) {
    $check('sin excepciones', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
echo "\n{$ok} correctas, {$fail} con error\n";
exit($fail ? 1 : 0);
